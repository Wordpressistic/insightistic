<?php
/**
 * IndexNow pipeline: pushes public, canonical URLs of published/updated
 * content through the Insightistic SaaS IndexNow endpoint (connector v1).
 *
 * SaaS contract (HMAC-signed exactly like every other connector call):
 *  - GET  /api/connector/v1/indexnow/key              -> { key, key_location, key_file_confirmed }
 *  - POST /api/connector/v1/indexnow/key?confirmed=1  -> { key_file_confirmed: true }
 *  - POST /api/connector/v1/indexnow/submit {urls[]}  -> { submitted, accepted, failed, skipped_duplicate,
 *                                                         skipped_invalid_host }
 *       | 422 { code: 'indexnow_not_initialized' }
 *
 * Flow (doc 07 rule: never a synchronous submit inside the save request):
 *  save_post -> eligibility gate -> ONE Action Scheduler action per post
 *  -> per-URL option queue (max 50, deduped) -> flushed to the SaaS in
 *  chunks of 25 once the queue reaches 25, or on the 15-minute flush cron
 *  otherwise. Failures never die loudly: they land in the rolling sync log
 *  and retry on the next cron tick.
 *
 * Storage (all non-autoloaded, same idiom as the sync/license options):
 *  - insightistic_indexnow        { key, key_location, key_file_confirmed,
 *                                   auto_submit_enabled, last_submitted }
 *  - insightistic_indexnow_queue  flat list of canonical URLs
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_IndexNow
 */
class Insightistic_IndexNow {

	/** Settings option (key, key_location, key_file_confirmed, toggles). */
	const OPT = 'insightistic_indexnow';

	/** Pending-URL queue option (flat list, deduped, capped). */
	const QUEUE_OPT = 'insightistic_indexnow_queue';

	/** Action Scheduler / fallback action for one post's URL. */
	const SUBMIT_HOOK = 'insightistic_indexnow_submit_single';

	/** Queue flush hook — both an Action Scheduler action and a WP-Cron event. */
	const FLUSH_HOOK = 'insightistic_indexnow_flush';

	/** Registered cron recurrence for the flush (see add_cron_schedule()). */
	const CRON_SCHEDULE = 'insightistic_15min';

	/** Hard cap on queued URLs (oldest dropped when exceeded). */
	const QUEUE_MAX = 50;

	/** URLs sent per /indexnow/submit call (contract allows <= 1000). */
	const FLUSH_CHUNK = 25;

	/** Queue size that triggers an immediate (still asynchronous) flush. */
	const FLUSH_AT = 25;

	/**
	 * Register hooks. The flush cron is only scheduled while the site is
	 * connected and auto-submit is on, mirroring the license-validate and
	 * sync cron pattern in Insightistic_License_Manager.
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'save_post', array( $this, 'maybe_submit_post' ), 20, 3 );
		add_action( self::SUBMIT_HOOK, array( $this, 'handle_submit_single' ), 10, 1 );
		add_action( self::FLUSH_HOOK, array( $this, 'flush_queue' ) );

		if (
			class_exists( 'Insightistic_License_Manager' )
			&& Insightistic_License_Manager::is_connected()
			&& self::is_enabled()
			&& ! wp_next_scheduled( self::FLUSH_HOOK )
		) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::CRON_SCHEDULE, self::FLUSH_HOOK );
		}
	}

	/**
	 * Register the 15-minute recurrence used by the flush cron.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes (Insightistic IndexNow)', 'insightistic' ),
			);
		}

		return $schedules;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Settings & state                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Settings with defaults applied (same shape idiom as Sync::settings()).
	 *
	 * @return array
	 */
	public static function settings() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'key'                 => '',
				'key_location'        => '',
				'key_file_confirmed'  => false,
				'auto_submit_enabled' => 'yes',
				'last_submitted'      => '',
			)
		);
	}

	/**
	 * Whether auto-submit is on. The filter is the supported kill switch
	 * (hosting-level control, multisite overrides, ...) — there is no admin
	 * toggle by design; status surfaces via the rolling sync log.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::settings();

		$enabled = ( 'yes' === $settings['auto_submit_enabled'] );

		/**
		 * Filters whether IndexNow auto-submit is enabled for this site.
		 *
		 * @param bool $enabled Default: the stored auto-submit setting.
		 */
		return (bool) apply_filters( 'insightistic_indexnow_enabled', $enabled );
	}

	/**
	 * Pending URLs awaiting submission (oldest first).
	 *
	 * @return string[]
	 */
	public static function queue() {
		$queue = get_option( self::QUEUE_OPT, array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/*
	------------------------------------------------------------------ */
	/*
	Entry point: save_post                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * The save_post callback. Runs the eligibility gate and — and only then —
	 * defers the real work to a background action. All the cheap checks run
	 * here so the save request stays fast; no network I/O ever happens here.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object (may be null for some meta saves).
	 * @param bool    $update  Whether this is an existing post.
	 */
	public function maybe_submit_post( $post_id, $post = null, $update = true ) {
		unset( $update );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! is_object( $post ) ) {
			$post = get_post( $post_id );
		}
		if ( ! $post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! class_exists( 'Insightistic_License_Manager' ) || ! Insightistic_License_Manager::is_connected() ) {
			return;
		}

		if ( ! self::post_is_eligible( $post ) ) {
			return;
		}

		// Background it (Action Scheduler, or the inline no-op fallback —
		// the handler itself only queues a URL and defers the HTTP submit).
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SUBMIT_HOOK, array( 'post_id' => (int) $post_id ), 'insightistic' );
		} else {
			do_action( self::SUBMIT_HOOK, (int) $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Resolves to the prefixed constant insightistic_indexnow_submit_single; the sniff cannot see class constants.
		}
	}

	/**
	 * Background handler for one post: resolve the CURRENT permalink, re-check
	 * eligibility (status may have changed between save and the async run),
	 * queue the URL and flush when the queue is big enough.
	 *
	 * @param int $post_id Post ID.
	 */
	public function handle_submit_single( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || ! self::post_is_eligible( $post ) ) {
			return;
		}

		$url = self::canonicalize_url( (string) get_permalink( $post ) );
		if ( '' === $url || ! self::is_submittable_url( $url ) ) {
			return;
		}

		if ( ! self::queue_add( $url ) ) {
			return; // Already queued — nothing to do.
		}

		self::maybe_flush();
	}

	/*
	------------------------------------------------------------------ */
	/*
	Queue & flush                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Add a URL to the queue. Deduped by exact URL; capped at QUEUE_MAX with
	 * the oldest entries dropped (fresh URLs matter more for IndexNow).
	 *
	 * @param string $url Canonical URL.
	 * @return bool True when newly added, false when it was already queued.
	 */
	public static function queue_add( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}

		$queue = self::queue();

		if ( in_array( $url, $queue, true ) ) {
			return false;
		}

		$queue[] = $url;
		if ( count( $queue ) > self::QUEUE_MAX ) {
			$queue = array_slice( $queue, count( $queue ) - self::QUEUE_MAX );
		}

		update_option( self::QUEUE_OPT, array_values( $queue ), false );
		return true;
	}

	/**
	 * Flush now when the queue reached the immediate-flush threshold. Still
	 * never inline: goes through Action Scheduler, or a near-term cron event
	 * on sites without it.
	 */
	public static function maybe_flush() {
		if ( count( self::queue() ) < self::FLUSH_AT ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::FLUSH_HOOK, array(), 'insightistic' );
		} elseif ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::FLUSH_HOOK );
		}
	}

	/**
	 * Drain the queue to the SaaS in chunks of FLUSH_CHUNK. Bounded by the
	 * queue cap (QUEUE_MAX / FLUSH_CHUNK = 2 requests max), so it is safe to
	 * run from a cron tick or an Action Scheduler action.
	 *
	 * On failure the remaining URLs stay queued and the next cron tick
	 * retries — never a hard failure.
	 */
	public function flush_queue() {
		if ( ! class_exists( 'Insightistic_License_Manager' ) || ! Insightistic_License_Manager::is_connected() ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		if ( empty( self::queue() ) ) {
			return; // Nothing to send — no key fetch either.
		}

		if ( ! self::ensure_key() ) {
			// Key not ready yet (SaaS not initialized / transient error) —
			// the queue stays intact and the next flush retries.
			return;
		}

		$sent = 0;

		while ( true ) {
			$queue = self::queue();
			if ( empty( $queue ) ) {
				break;
			}

			$chunk = array_slice( $queue, 0, self::FLUSH_CHUNK );
			$res   = Insightistic_Saas_Client::indexnow_submit( $chunk );

			if ( ! $res['ok'] ) {
				$code = ( is_array( $res['data'] ) && ! empty( $res['data']['code'] ) )
					? (string) $res['data']['code']
					: '';
				self::log(
					sprintf( 'IndexNow submit failed%son %d URL(s): %s', $code ? " [{$code}] " : ' ', count( $chunk ), (string) $res['error'] ),
					'error'
				);
				break; // Keep the queue; retry on the next cron tick.
			}

			$remainder = array_slice( $queue, count( $chunk ) );
			update_option( self::QUEUE_OPT, array_values( $remainder ), false );
			$sent += count( $chunk );

			if ( empty( $remainder ) ) {
				break;
			}
		}

		if ( $sent > 0 ) {
			$settings                   = self::settings();
			$settings['last_submitted'] = current_time( 'mysql' );
			update_option( self::OPT, $settings, false );
			self::log( sprintf( 'IndexNow: submitted %d URL(s) to the SaaS.', $sent ) );
		}
	}

	/**
	 * WP-Cron tick: make sure the key exists and is confirmed (self-healing
	 * retry path for ensure_key() failures), then drain whatever queued up.
	 */
	public function cron_flush() {
		$this->flush_queue();
	}

	/*
	------------------------------------------------------------------ */
	/*
	Key provisioning                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Make sure a SaaS-issued IndexNow key exists, is written to
	 * ABSPATH/{key}.txt (Bing verifies it there) and is confirmed back to the
	 * SaaS. Safe to call repeatedly; each failed step logs and defers to the
	 * next flush/cron tick.
	 *
	 * @return bool True when a confirmed key is in place.
	 */
	public static function ensure_key() {
		if ( ! class_exists( 'Insightistic_License_Manager' ) || ! Insightistic_License_Manager::is_connected() ) {
			return false; // Silent — same policy as Sync's disconnected guard.
		}

		$settings = self::settings();

		if ( '' !== $settings['key'] && preg_match( '/^[a-f0-9]{32}$/', $settings['key'] ) ) {
			if ( $settings['key_file_confirmed'] ) {
				// Self-heal: the key file must keep being served from the root.
				if ( ! self::key_file_exists( $settings['key'] ) ) {
					self::write_key_file( $settings['key'] );
				}
				return true;
			}

			// Key cached but never confirmed — finish that step only.
			return self::confirm_key( $settings['key'] );
		}

		$res = Insightistic_Saas_Client::indexnow_get_key();
		if ( ! $res['ok'] || ! is_array( $res['data'] ) || empty( $res['data']['key'] ) ) {
			self::log( 'IndexNow key fetch failed: ' . (string) $res['error'], 'error' );
			return false;
		}

		$key = sanitize_text_field( (string) $res['data']['key'] );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			self::log( 'IndexNow: SaaS returned an unexpected key format.', 'error' );
			return false;
		}

		$settings['key']          = $key;
		$settings['key_location'] = ! empty( $res['data']['key_location'] )
			? sanitize_text_field( (string) $res['data']['key_location'] )
			: home_url( '/' . $key . '.txt' );
		update_option( self::OPT, $settings, false );

		if ( ! self::write_key_file( $key ) ) {
			self::log( 'IndexNow: could not write the key file to the site root.', 'error' );
			return false;
		}

		self::log( 'IndexNow key file written: ' . $key . '.txt' );

		return self::confirm_key( $key );
	}

	/**
	 * Tell the SaaS the key file is being served, and remember the result.
	 *
	 * @param string $key IndexNow key (32 hex chars).
	 * @return bool
	 */
	private static function confirm_key( $key ) {
		$res       = Insightistic_Saas_Client::indexnow_confirm_key();
		$confirmed = $res['ok'] && is_array( $res['data'] ) && ! empty( $res['data']['key_file_confirmed'] );

		$settings                       = self::settings();
		$settings['key']                = $key;
		$settings['key_file_confirmed'] = (bool) $confirmed;
		update_option( self::OPT, $settings, false );

		if ( $confirmed ) {
			self::log( 'IndexNow key file confirmed by the SaaS.' );
		} else {
			self::log( 'IndexNow key confirmation failed: ' . (string) $res['error'], 'error' );
		}

		return $confirmed;
	}

	/**
	 * Whether ABSPATH/{key}.txt exists (best effort).
	 *
	 * @param string $key IndexNow key.
	 * @return bool
	 */
	private static function key_file_exists( $key ) {
		return file_exists( self::key_file_path( $key ) );
	}

	/**
	 * Absolute path of the key file at the site root.
	 *
	 * @param string $key IndexNow key.
	 * @return string
	 */
	private static function key_file_path( $key ) {
		return rtrim( ABSPATH, '/\\' ) . '/' . $key . '.txt';
	}

	/**
	 * Write the key file via WP_Filesystem, falling back to a direct write
	 * when the filesystem API cannot initialize (credentials required).
	 *
	 * @param string $key IndexNow key.
	 * @return bool
	 */
	private static function write_key_file( $key ) {
		$path    = self::key_file_path( $key );
		$content = $key; // The file's body must be exactly the key.

		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', 0644 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, defined defensively for the filesystem API fallback.
		}

		if ( ! function_exists( 'WP_Filesystem' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( function_exists( 'WP_Filesystem' ) ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
				WP_Filesystem();
			}
			if ( $wp_filesystem && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'put_contents' ) ) {
				if ( $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
					return true;
				}
			}
		}

		// Direct fallback — ABSPATH is writable in the overwhelming majority
		// of installs (that is where wp-config.php lives). A failed write
		// returns false and is logged by the caller.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Direct write is the documented fallback when the filesystem API cannot initialize (credentials required).
		return false !== @file_put_contents( $path, $content );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Eligibility (pure helpers, unit-tested standalone)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether a post is eligible for IndexNow submission: public post type,
	 * published (attachments: inherited + publicly attached parent), not
	 * password-protected, not marked noindex by an SEO plugin.
	 *
	 * @param WP_Post|object $post Post object.
	 * @return bool
	 */
	public static function post_is_eligible( $post ) {
		if ( ! is_object( $post ) || empty( $post->post_status ) ) {
			return false;
		}

		$status = (string) $post->post_status;
		$type   = isset( $post->post_type ) ? (string) $post->post_type : '';

		if ( 'attachment' === $type ) {
			// Media items keep the 'inherit' status; only submit when they are
			// not attached to a non-public post.
			if ( 'inherit' !== $status ) {
				return false;
			}
			if ( ! self::attachment_parent_public( $post ) ) {
				return false;
			}
		} else {
			if ( 'publish' !== $status ) {
				return false; // Drafts and every other non-published status stay out.
			}
			$type_obj = function_exists( 'get_post_type_object' ) ? get_post_type_object( $type ) : null;
			if ( ! $type_obj || empty( $type_obj->public ) ) {
				return false; // Internal/non-public post types.
			}
		}

		$password = isset( $post->post_password ) ? (string) $post->post_password : '';
		if ( '' !== $password ) {
			return false; // Password-protected: not publicly indexable.
		}

		if ( self::post_is_noindex( (int) $post->ID ) ) {
			return false;
		}

		/**
		 * Filters final IndexNow eligibility for a post.
		 *
		 * @param bool  $eligible Default eligibility verdict.
		 * @param object $post    Post object.
		 */
		return (bool) apply_filters( 'insightistic_indexnow_post_is_eligible', true, $post );
	}

	/**
	 * Whether an attachment's parent is public. Unattached media (parent 0)
	 * and orphaned attachments are publicly served, so they pass.
	 *
	 * @param object $post Attachment post.
	 * @return bool
	 */
	private static function attachment_parent_public( $post ) {
		$parent_id = isset( $post->post_parent ) ? (int) $post->post_parent : 0;
		if ( $parent_id <= 0 ) {
			return true;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent ) {
			return true;
		}

		return 'publish' === $parent->post_status;
	}

	/**
	 * Whether an SEO plugin marks the post noindex. Best-effort reads of the
	 * common meta keys; anything else can hook the filter below. Absent
	 * signals mean "assume indexable".
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_is_noindex( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		// Yoast SEO.
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		// Rank Math.
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $robots ) && in_array( 'noindex', array_map( 'strval', $robots ), true ) ) {
			return true;
		}

		// All in One SEO (v3 postmeta; v4 keeps settings in its own table).
		$aioseo = get_post_meta( $post_id, '_aioseo_robot_settings', true );
		if ( $aioseo ) {
			$settings = is_array( $aioseo ) ? $aioseo : json_decode( (string) $aioseo, true );
			if ( is_array( $settings ) ) {
				$block   = isset( $settings['robots'] ) && is_array( $settings['robots'] ) ? $settings['robots'] : $settings;
				$noindex = isset( $block['noindex'] ) ? $block['noindex'] : null;
				if ( in_array( $noindex, array( true, 1, '1', 'on', 'enabled', 'true' ), true ) ) {
					return true;
				}
			}
		}

		/**
		 * Filters whether a post is noindex for IndexNow purposes.
		 *
		 * @param bool $noindex Default detection result.
		 * @param int  $post_id Post ID.
		 */
		return (bool) apply_filters( 'insightistic_indexnow_post_is_noindex', false, $post_id );
	}

	/**
	 * Strip the fragment from a URL (IndexNow URLs are page addresses, and
	 * fragments never reach the server).
	 *
	 * @param string $url Raw URL.
	 * @return string Canonicalized URL ('' when empty).
	 */
	public static function canonicalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		return $url;
	}

	/**
	 * Whether a URL is one we should ever submit: absolute http(s) URL that
	 * is not an admin/login/AJAX path. (get_permalink() already resolves
	 * ?page_id= style URLs to the permalink, so query-built URLs never reach
	 * here for published content.)
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_submittable_url( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		if ( 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '/wp-login.php' ) ) {
			return false;
		}
		if ( 'admin-ajax.php' === basename( $path ) ) {
			return false;
		}

		return true;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Logging (same rolling-20 idiom as Insightistic_Sync, shared option)   */
	/* ------------------------------------------------------------------ */

	/**
	 * Append to the rolling sync log (last 20 entries) — surfaced on the
	 * License page by the existing log view.
	 *
	 * @param string $message Log line.
	 * @param string $level   info|error.
	 */
	private static function log( $message, $level = 'info' ) {
		$logs = get_option( 'insightistic_sync_log', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		array_unshift(
			$logs,
			array(
				'time'    => current_time( 'mysql' ),
				'level'   => $level,
				'message' => $message,
			)
		);
		update_option( 'insightistic_sync_log', array_slice( $logs, 0, 20 ), false );
	}
}
