<?php
/**
 * Engagement Tracking class for Insightistic.
 *
 * Injects a lightweight frontend tracking script for custom GA4 events and
 * forwards those events to the GA4 Measurement Protocol *server-side*, so the
 * Measurement Protocol API secret is never exposed in page source.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Engagement
 */
class Insightistic_Engagement {

	/** AJAX action used by the frontend tracker. */
	const ACTION = 'insightistic_track';

	/** Per-IP requests permitted inside the rate-limit window. */
	const RATE_LIMIT = 120;

	/** Rate-limit window, in seconds. */
	const RATE_WINDOW = 60;

	/** Events the proxy is willing to forward. */
	const ALLOWED_EVENTS = array(
		'outbound_link_click',
		'file_download',
		'scroll_depth',
		'element_click',
		'form_submit',
		'site_search',
		'video_play',
		'content_copy',
	);

	/** Distinct 404 paths kept in the rolling log; oldest-by-last-seen evicted past this. */
	const MAX_404_ENTRIES = 200;

	/**
	 * Register hooks.
	 */
	public function init() {
		// The collector endpoint is always registered so cached pages keep working,
		// but it short-circuits unless engagement tracking is configured.
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_track' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle_track' ) );

		if ( get_option( 'insightistic_engagement_enabled' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking_script' ) );
		}

		// 404 & broken-link monitor: pure server-side (template_redirect), no
		// script and no dependency on the GA4 tracker above  free, on by
		// default, and unaffected by ad-blockers or JS being disabled.
		if ( get_option( 'insightistic_404_monitor_enabled', 1 ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_log_404' ) );
		}
		add_action( 'wp_ajax_insightistic_get_404_report', array( $this, 'ajax_get_404_report' ) );
		add_action( 'wp_ajax_insightistic_clear_404_log', array( $this, 'ajax_clear_404_log' ) );
	}

	/**
	 * Record a 404 hit: request path, distinct referring hosts, a hit count,
	 * and first/last-seen timestamps. No query string, no IP, no user agent
	 * just enough to spot and prioritise broken links. Stored in a single
	 * capped option (no custom table, matching the rest of this plugin).
	 */
	public function maybe_log_404() {
		if ( ! is_404() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = strtok( $path, '?' ); // Group by path; ignore query string variance.
		if ( ! $path ) {
			return;
		}
		$path = mb_substr( $path, 0, 300 );

		$referrer_host = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$ref_host  = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST );
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $ref_host && $ref_host !== $site_host ) {
				$referrer_host = sanitize_text_field( $ref_host );
			}
		}

		$log = get_option( 'insightistic_404_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$key = md5( $path );
		$now = time();
		if ( isset( $log[ $key ] ) ) {
			++$log[ $key ]['count'];
			$log[ $key ]['last_seen'] = $now;
			if ( $referrer_host ) {
				$log[ $key ]['referrers'][ $referrer_host ] = true;
			}
		} else {
			$log[ $key ] = array(
				'path'       => $path,
				'count'      => 1,
				'first_seen' => $now,
				'last_seen'  => $now,
				'referrers'  => $referrer_host ? array( $referrer_host => true ) : array(),
			);
		}

		if ( count( $log ) > self::MAX_404_ENTRIES ) {
			uasort(
				$log,
				function ( $a, $b ) {
					return $a['last_seen'] <=> $b['last_seen'];
				}
			);
			$log = array_slice( $log, count( $log ) - self::MAX_404_ENTRIES, null, true );
		}

		update_option( 'insightistic_404_log', $log, false );
	}

	/**
	 * Reshape the local 404 log into connector v2's sync/broken-links
	 * payload shape. Empty array (never null/WP_Error) when there's
	 * nothing to report — the 404 monitor is on by default, unlike GA4/
	 * GSC/PageSpeed, so an empty log is a normal "no broken links yet"
	 * state, not a "not configured" one.
	 *
	 * @return array
	 */
	public function get_broken_links_payload() {
		$log = get_option( 'insightistic_404_log', array() );
		if ( ! is_array( $log ) || ! $log ) {
			return array();
		}

		$rows = array();
		foreach ( $log as $entry ) {
			$rows[] = array(
				'path'           => $entry['path'],
				'first_seen_at'  => gmdate( 'c', (int) $entry['first_seen'] ),
				'last_seen_at'   => gmdate( 'c', (int) $entry['last_seen'] ),
				'hit_count'      => (int) $entry['count'],
				'referrer_hosts' => array_keys( is_array( $entry['referrers'] ?? null ) ? $entry['referrers'] : array() ),
			);
		}
		return $rows;
	}

	/**
	 * AJAX: return the top 404 paths by hit count for the dashboard card.
	 */
	public function ajax_get_404_report() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$log = get_option( 'insightistic_404_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$rows = array_values( $log );
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		$rows = array_slice( $rows, 0, 50 );

		$out = array_map(
			function ( $row ) {
				return array(
					'path'      => $row['path'],
					'count'     => (int) $row['count'],
					'referrers' => array_keys( (array) ( $row['referrers'] ?? array() ) ),
					'last_seen' => (int) $row['last_seen'],
				);
			},
			$rows
		);

		wp_send_json_success(
			array(
				'rows'        => $out,
				'total_paths' => count( $log ),
			)
		);
	}

	/**
	 * AJAX: clear the 404 log.
	 */
	public function ajax_clear_404_log() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}
		delete_option( 'insightistic_404_log' );
		wp_send_json_success( __( '404 log cleared.', 'insightistic' ) );
	}

	/**
	 * Enqueue the frontend tracking script.
	 *
	 * Only the public proxy URL is exposed to the browser  never the
	 * Measurement Protocol secret.
	 */
	public function enqueue_tracking_script() {
		$measurement_id     = get_option( 'insightistic_measurement_id', '' );
		$enc_secret         = get_option( 'insightistic_measurement_secret', '' );
		$measurement_secret = $enc_secret ? Insightistic_Encryption::decrypt( $enc_secret ) : '';

		// Without both halves the proxy has nothing to forward, so skip the script.
		if ( ! $measurement_id || ! $measurement_secret ) {
			return;
		}

		// Load minified version in production; readable source in SCRIPT_DEBUG mode.
		$script_file = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG )
			? 'assets/js/tracking.src.js'
			: 'assets/js/tracking.min.js';

		wp_enqueue_script(
			'insightistic-tracking',
			INSIGHTISTIC_URL . $script_file,
			array(),
			INSIGHTISTIC_VERSION,
			true
		);

		wp_localize_script(
			'insightistic-tracking',
			'ispTracking',
			array(
				'endpoint'       => admin_url( 'admin-ajax.php' ),
				'action'         => self::ACTION,
				'nonce'          => wp_create_nonce( self::ACTION ),
				'trackOutbound'  => true,
				'trackScroll'    => true,
				'trackDownloads' => true,
				'trackEvents'    => true,
				'eventSelectors' => array( '.isp-track' ),
			)
		);
	}

	/**
	 * AJAX collector: validate, rate-limit, then forward to GA4 server-side.
	 */
	public function handle_track() {
		// Soft nonce check: full-page caching can serve a stale nonce to
		// anonymous visitors, so a bad nonce is not fatal  the same-origin
		// check and per-IP rate limit are the real abuse controls. A valid
		// nonce simply relaxes nothing here; it is verified for logged-in hits.
		if ( is_user_logged_in() ) {
			check_ajax_referer( self::ACTION, 'nonce' );
		}

		if ( ! get_option( 'insightistic_engagement_enabled' ) ) {
			wp_send_json_error( 'disabled', 403 );
		}

		if ( ! $this->is_same_origin() ) {
			wp_send_json_error( 'bad_origin', 403 );
		}

		if ( ! $this->within_rate_limit() ) {
			wp_send_json_error( 'rate_limited', 429 );
		}

		// This public collector deliberately does not hard-require a nonce: full
		// page caching serves stale nonces to anonymous visitors. Abuse is
		// instead bounded by the same-origin check, the per-IP rate limit, and
		// the event allowlist above. Every value below is sanitised on read.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$event = sanitize_key( wp_unslash( $_POST['event'] ?? '' ) );
		if ( ! in_array( $event, self::ALLOWED_EVENTS, true ) ) {
			wp_send_json_error( 'bad_event', 400 );
		}

		$measurement_id     = get_option( 'insightistic_measurement_id', '' );
		$enc_secret         = get_option( 'insightistic_measurement_secret', '' );
		$measurement_secret = $enc_secret ? Insightistic_Encryption::decrypt( $enc_secret ) : '';
		if ( ! $measurement_id || ! $measurement_secret ) {
			wp_send_json_error( 'not_configured', 503 );
		}

		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+$/', $client_id ) ) {
			// Generate a stable-ish anonymous fallback if the cookie was unreadable.
			$client_id = wp_rand( 1000000000, 9999999999 ) . '.' . time();
		}

		// Unslashed here; each field is sanitised inside sanitize_params().
		$params = $this->sanitize_params( wp_unslash( $_POST['params'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$endpoint = add_query_arg(
			array(
				'measurement_id' => $measurement_id,
				'api_secret'     => $measurement_secret,
			),
			'https://www.google-analytics.com/mp/collect'
		);

		wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 4,
				'blocking'    => false,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'client_id' => $client_id,
						'events'    => array(
							array(
								'name'   => $event,
								'params' => $params,
							),
						),
					)
				),
			)
		);

		wp_send_json_success( 'ok' );
	}

	/**
	 * Decode + sanitise the event params object from the request.
	 *
	 * @param mixed $raw JSON string of params.
	 * @return array
	 */
	private function sanitize_params( $raw ) {
		// $raw is already unslashed by the caller.
		$raw = is_string( $raw ) ? $raw : '';
		$arr = json_decode( $raw, true );
		if ( ! is_array( $arr ) ) {
			return array();
		}

		$clean = array();
		$count = 0;
		foreach ( $arr as $key => $value ) {
			if ( $count++ >= 15 ) {
				break; // Cap the number of params forwarded.
			}
			$k = sanitize_key( $key );
			if ( '' === $k ) {
				continue;
			}
			if ( is_numeric( $value ) ) {
				$clean[ $k ] = 0 + $value;
			} else {
				$clean[ $k ] = mb_substr( sanitize_text_field( (string) $value ), 0, 200 );
			}
		}
		return $clean;
	}

	/**
	 * Require the request to originate from this site.
	 *
	 * @return bool
	 */
	private function is_same_origin() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST );
			return $origin === $host;
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST );
			return $referer === $host;
		}
		// No Origin/Referer at all  reject to be safe.
		return false;
	}

	/**
	 * Simple fixed-window per-IP rate limiter backed by a transient.
	 *
	 * @return bool True when the request is within the allowance.
	 */
	private function within_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'insightistic_track_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}
}
