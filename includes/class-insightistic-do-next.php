<?php
/**
 * Unified "Do this next" queue for Insightistic.
 *
 * Merges the signals the plugin already produces — SEO Opportunity Finder
 * queries, GA4 anomaly movements, 404 monitor hits, and PageSpeed/Speed Test
 * issues — into one prioritized, actionable queue at the top of the
 * dashboard. Nothing here fetches new data: every collector reads the same
 * cached payloads the existing features render from.
 *
 * Priority model (documented constants):
 *   impact — 1-5, how much the site gains when the action is done.
 *   effort — 1-5, how much work the action takes.
 *   score  = impact × effort (1-25). The queue sorts by score, then impact,
 *   then cheapest effort, so quick wins surface above slow ones on ties.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and renders the dashboard "Do this next" queue.
 *
 * Stateless by design (same pattern as Insightistic_Addons): every method is
 * static, the assembled queue is cached in a transient, and per-user snoozes
 * live in user meta applied at read time.
 */
class Insightistic_Do_Next {

	/** Queue cache TTL — mirrors the 15-minute GA4/GSC data cache. */
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/** How many items are visible after sorting. */
	const VISIBLE_CAP = 10;

	/** Snooze window, in days. */
	const SNOOZE_DAYS = 7;

	/** User meta key holding array( item_id => expiry timestamp ). */
	const SNOOZE_META = 'insightistic_do_next_snoozed';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_insightistic_snooze_do_next', array( 'Insightistic_Do_Next', 'ajax_snooze' ) );

		// Invalidate the queue when the underlying data refreshes. This plugin
		// has no dedicated do_action for data refreshes — its refresh flows are
		// these AJAX endpoints — so hook them at priority 5, BEFORE the real
		// handlers (those may terminate the request via wp_send_json_*()).
		// The callback is self-contained: a failed nonce or capability check
		// quietly skips invalidation, it never blocks the refresh itself.
		foreach ( array(
			'wp_ajax_insightistic_get_data',        // GA4 dashboard refresh.
			'wp_ajax_insightistic_get_gsc_data',    // Search Console refresh.
			'wp_ajax_insightistic_get_pagespeed',   // Dashboard PageSpeed run.
			'wp_ajax_insightistic_speed_test',      // Speed Test page run.
			'wp_ajax_insightistic_clear_404_log',   // 404 log cleared.
		) as $refresh_hook ) {
			add_action( $refresh_hook, array( 'Insightistic_Do_Next', 'maybe_invalidate_cache' ), 5 );
		}
	}

	/*
	------------------------------------------------------------------ */
	/*
	Queue assembly                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The visible queue: full pool, minus this user's snoozed items,
	 * sorted and capped. Cheap — the pool comes from the transient.
	 *
	 * @param int $limit Max items to return.
	 * @return array[] Queue item arrays; see build_item() for the shape.
	 */
	public static function get_queue( $limit = self::VISIBLE_CAP ) {
		$items = self::get_pool();

		$snoozed = self::get_snoozed();
		$now     = time();
		$items   = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $snoozed, $now ) {
					$expiry = isset( $snoozed[ $item['id'] ] ) ? (int) $snoozed[ $item['id'] ] : 0;
					return $expiry <= $now;
				}
			)
		);

		usort(
			$items,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				if ( $a['impact'] !== $b['impact'] ) {
					return $b['impact'] <=> $a['impact'];
				}
				if ( $a['effort'] !== $b['effort'] ) {
					return $a['effort'] <=> $b['effort'];
				}
				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Full (uncapped) item pool, cached in a 15-minute transient keyed by the
	 * current configuration fingerprint — same keying approach as the GA4/GSC
	 * data caches. Snoozes are applied per user at read time, so snoozing
	 * never invalidates the shared cache.
	 *
	 * @return array[]
	 */
	private static function get_pool() {
		$cached = get_transient( self::cache_key() );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$snapshot = self::get_snapshot();
		$pool     = array_merge(
			self::items_setup( $snapshot ),
			self::items_seo_opportunities( $snapshot ),
			self::items_anomalies( $snapshot ),
			self::items_broken_links( $snapshot ),
			self::items_speed( $snapshot )
		);

		set_transient( self::cache_key(), $pool, self::CACHE_TTL );
		return $pool;
	}

	/**
	 * Transient key for the assembled queue. Includes a fingerprint of the
	 * options that decide which sources have data, so a settings change
	 * starts a fresh cache without explicit invalidation.
	 *
	 * @return string
	 */
	private static function cache_key() {
		$fingerprint = implode(
			'|',
			array(
				get_option( 'insightistic_property_id', '' ),
				get_option( 'insightistic_gsc_property_url', '' ),
				get_option( 'insightistic_pagespeed_api_key_enc', '' ) ? 'psi' : '',
				get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) ),
			)
		);
		return 'insightistic_do_next_' . md5( $fingerprint );
	}

	/**
	 * Raw snapshot of every source the queue reads, cached together with the
	 * pool. Read-only lookups against the same option/transient keys the
	 * existing features use — no network calls, no writes.
	 *
	 * @return array
	 */
	private static function get_snapshot() {
		return array(
			// Newest cached GA4 payload (same transient series Insightistic_Addons reads).
			'ga'        => self::latest_ga_cache(),
			// Newest cached GSC payload.
			'gsc'       => self::latest_gsc_cache(),
			// Top 404 rows by hit count.
			'log404'    => self::top_404_rows(),
			// Detailed Speed Test cache for the configured default URL.
			'speedtest' => self::latest_speedtest_cache(),
			// Dashboard PageSpeed widget cache for the same URL.
			'psi'       => self::latest_psi_cache(),
			'flags'     => array(
				'ga_configured'  => (bool) get_option( 'insightistic_property_id' ),
				'gsc_configured' => (bool) get_option( 'insightistic_gsc_property_url' ),
				'psi_configured' => (bool) get_option( 'insightistic_pagespeed_api_key_enc' ),
				'monitor_404'    => (bool) get_option( 'insightistic_404_monitor_enabled', 1 ),
			),
		);
	}

	/**
	 * Delete the cached pool. Runs before the data-refresh AJAX handlers;
	 * the next dashboard render rebuilds from whatever the sources hold then.
	 */
	public static function maybe_invalidate_cache() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'insightistic_nonce' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_transient( self::cache_key() );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Collectors                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Setup / data-loading items for sources that are not configured or have
	 * no cached data yet. Mirrors the setup nudges the features themselves
	 * show ("Load Search Console data" in the SEO finder) plus the fix links
	 * the System Status page uses.
	 *
	 * Impact: connecting a source unlocks entire signal families.
	 * Effort: configuration is minutes, data loading is one click.
	 *
	 * @param array $snapshot Snapshot.
	 * @return array[]
	 */
	private static function items_setup( $snapshot ) {
		$flags      = $snapshot['flags'];
		$items      = array();
		$system_url = admin_url( 'admin.php?page=insightistic-system-status' );

		if ( ! $flags['ga_configured'] ) {
			$items[] = self::build_item(
				array(
					'id'           => 'setup-ga4',
					'title'        => __( 'Connect Google Analytics 4', 'insightistic' ),
					'source'       => 'setup',
					'source_label' => __( 'Setup', 'insightistic' ),
					'why'          => __( 'Every insight in this queue reads your analytics. Connecting GA4 unlocks the dashboard, anomaly alerts, and email digests in one step.', 'insightistic' ),
					'impact'       => 5,
					'effort'       => 2,
					'action_url'   => admin_url( 'admin.php?page=insightistic-settings&active_tab=ga4#ga4' ),
					'action_label' => __( 'Open Settings', 'insightistic' ),
					'explain_url'  => $system_url,
					'explain_label' => __( 'See what is missing', 'insightistic' ),
				)
			);
		} elseif ( empty( $snapshot['ga'] ) ) {
			$items[] = self::build_item(
				array(
					'id'            => 'setup-ga4-load',
					'title'         => __( 'Load your GA4 data', 'insightistic' ),
					'source'        => 'setup',
					'source_label'  => __( 'Setup', 'insightistic' ),
					'why'           => __( 'There is no cached GA4 data yet. One refresh pulls your traffic, pages, and channels so the rest of this queue can fill in.', 'insightistic' ),
					'impact'        => 4,
					'effort'        => 1,
					'action_url'    => admin_url( 'admin.php?page=insightistic' ),
					'action_label'  => __( 'Open Overview', 'insightistic' ),
					'explain_url'   => $system_url,
					'explain_label' => __( 'Check requirements', 'insightistic' ),
				)
			);
		}

		if ( ! $flags['gsc_configured'] ) {
			$items[] = self::build_item(
				array(
					'id'            => 'setup-gsc',
					'title'         => __( 'Connect Search Console', 'insightistic' ),
					'source'        => 'setup',
					'source_label'  => __( 'Setup', 'insightistic' ),
					'why'           => __( 'Search Console shows which queries see your site but never click through — connecting it enables the SEO Opportunity Finder.', 'insightistic' ),
					'impact'        => 4,
					'effort'        => 2,
					'action_url'    => admin_url( 'admin.php?page=insightistic-settings&active_tab=gsc#gsc' ),
					'action_label'  => __( 'Connect Search Console', 'insightistic' ),
					'explain_url'   => admin_url( 'admin.php?page=insightistic-addons' ),
					'explain_label' => __( 'About the SEO finder', 'insightistic' ),
				)
			);
		} elseif ( empty( $snapshot['gsc'] ) ) {
			$items[] = self::build_item(
				array(
					'id'            => 'setup-gsc-load',
					'title'         => __( 'Load Search Console data', 'insightistic' ),
					'source'        => 'setup',
					'source_label'  => __( 'Setup', 'insightistic' ),
					'why'           => __( 'No cached Search Console data yet. Load it once and the SEO Opportunity Finder starts scoring your queries.', 'insightistic' ),
					'impact'        => 3,
					'effort'        => 1,
					'action_url'    => admin_url( 'admin.php?page=insightistic' ),
					'action_label'  => __( 'Open Search Console tab', 'insightistic' ),
					'explain_url'   => admin_url( 'admin.php?page=insightistic-addons' ),
					'explain_label' => __( 'About the SEO finder', 'insightistic' ),
				)
			);
		}

		if ( ! $flags['psi_configured'] ) {
			$items[] = self::build_item(
				array(
					'id'            => 'setup-psi',
					'title'         => __( 'Add a PageSpeed API key', 'insightistic' ),
					'source'        => 'setup',
					'source_label'  => __( 'Setup', 'insightistic' ),
					'why'           => __( 'A free Google API key unlocks Core Web Vitals and the full Speed Test, so speed problems can reach this queue too.', 'insightistic' ),
					'impact'        => 3,
					'effort'        => 1,
					'action_url'    => admin_url( 'admin.php?page=insightistic-settings&active_tab=pagespeed#pagespeed' ),
					'action_label'  => __( 'Add PageSpeed key', 'insightistic' ),
					'explain_url'   => admin_url( 'admin.php?page=insightistic-speed-test' ),
					'explain_label' => __( 'About the Speed Test', 'insightistic' ),
				)
			);
		}

		return $items;
	}

	/**
	 * SEO opportunity items from the cached GSC queries, using the exact
	 * thresholds of the SEO Opportunity Finder (impressions >= 100,
	 * CTR < 3%, position <= 20; CTR stored as a percent).
	 *
	 * Impact: 3 base, +1 per impression tier (>=1k, >=5k), +1 when the query
	 * already sits on page one (position <= 10) — those clicks are cheapest
	 * to win. Effort: 2 (a title/meta rewrite).
	 *
	 * @param array $snapshot Snapshot.
	 * @return array[]
	 */
	private static function items_seo_opportunities( $snapshot ) {
		$queries = $snapshot['gsc']['top_queries'] ?? array();
		$rows    = array();

		foreach ( $queries as $row ) {
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$ctr         = (float) ( $row['ctr'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );
			$query       = (string) ( $row['query'] ?? '' );

			if ( '' === $query || $impressions < 100 || $ctr >= 3 || $position > 20 ) {
				continue;
			}
			$rows[] = compact( 'impressions', 'ctr', 'position', 'query' );
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		$rows = array_slice( $rows, 0, 5 );

		$items = array();
		foreach ( $rows as $row ) {
			$impact = 3;
			if ( $row['impressions'] >= 1000 ) {
				++$impact;
			}
			if ( $row['impressions'] >= 5000 ) {
				++$impact;
			}
			if ( $row['position'] <= 10 ) {
				++$impact;
			}

			$items[] = self::build_item(
				array(
					'id'            => 'seo-' . md5( $row['query'] ),
					'title'         => $row['query'],
					'source'        => 'seo_opportunities',
					'source_label'  => __( 'SEO Opportunity Finder', 'insightistic' ),
					/* translators: 1: number of impressions, 2: CTR percentage, 3: average position */
					'why'           => sprintf( __( 'Your site appears for %1$s searches but only %2$s%% click through (average position #%3$s). A sharper title and meta description can win those clicks.', 'insightistic' ), number_format_i18n( $row['impressions'] ), $row['ctr'], $row['position'] ),
					'impact'        => min( 5, $impact ),
					'effort'        => 2,
					'action_url'    => admin_url( 'admin.php?page=insightistic' ),
					'action_label'  => __( 'Open Search Console tab', 'insightistic' ),
					'explain_url'   => admin_url( 'admin.php?page=insightistic-addons' ),
					'explain_label' => __( 'Why this matters', 'insightistic' ),
				)
			);
		}
		return $items;
	}

	/**
	 * Anomaly items from the cached GA4 period-over-period changes, using the
	 * exact thresholds of the Anomaly Alerts addon (|change| >= 15% is bad
	 * traffic-wise, >= 20% is a win; bounce rate inverted).
	 *
	 * Impact: revenue 5, sessions/users 4, pageviews/bounce 3.
	 * Effort: 3 — acting on an anomaly is investigation, not a click.
	 *
	 * @param array $snapshot Snapshot.
	 * @return array[]
	 */
	private static function items_anomalies( $snapshot ) {
		$overview = $snapshot['ga']['overview'] ?? array();
		$items    = array();

		$watch = array(
			'revenue'      => array( 'label' => __( 'Revenue', 'insightistic' ), 'impact' => 5 ),
			'sessions'     => array( 'label' => __( 'Sessions', 'insightistic' ), 'impact' => 4 ),
			'unique_users' => array( 'label' => __( 'Users', 'insightistic' ), 'impact' => 4 ),
			'pageviews'    => array( 'label' => __( 'Pageviews', 'insightistic' ), 'impact' => 3 ),
			'bounce_rate'  => array( 'label' => __( 'Bounce Rate', 'insightistic' ), 'impact' => 3 ),
		);

		foreach ( $watch as $key => $meta ) {
			if ( ! isset( $overview[ $key ]['change'] ) ) {
				continue;
			}
			$change = (float) $overview[ $key ]['change'];
			$is_bad = 'bounce_rate' === $key ? $change >= 15 : $change <= -15;
			$is_win = 'bounce_rate' === $key ? $change <= -15 : $change >= 20;

			if ( ! $is_bad && ! $is_win ) {
				continue;
			}

			$items[] = self::build_item(
				array(
					'id'            => 'anomaly-' . $key . '-' . (string) round( $change ),
					'title'         => $is_bad
						/* translators: 1: metric label, 2: percentage change */
						? sprintf( __( '%1$s dropped %2$s%%', 'insightistic' ), $meta['label'], abs( $change ) )
						/* translators: 1: metric label, 2: percentage change */
						: sprintf( __( '%1$s grew %2$s%%', 'insightistic' ), $meta['label'], $change ),
					'source'        => 'anomaly_alerts',
					'source_label'  => __( 'Anomaly Alerts', 'insightistic' ),
					'why'           => $is_bad
						? __( 'A sharp movement versus the previous period is worth investigating before it becomes a trend. Compare your top channels and pages to locate the cause.', 'insightistic' )
						: __( 'Something you did is working. Capture what changed and repeat it on your other pages and channels.', 'insightistic' ),
					'impact'        => $meta['impact'],
					'effort'        => 3,
					'action_url'    => admin_url( 'admin.php?page=insightistic' ),
					'action_label'  => __( 'Investigate', 'insightistic' ),
					'explain_url'   => admin_url( 'admin.php?page=insightistic-addons' ),
					'explain_label' => __( 'About Anomaly Alerts', 'insightistic' ),
				)
			);
		}
		return $items;
	}

	/**
	 * Broken-link items from the 404 monitor log (top rows by hit count,
	 * same data the dashboard's 404 card renders).
	 *
	 * Impact: 2 base, +1 at >= 10 hits, +1 when external sites link to the
	 * missing page (lost referral + SEO value). Effort: 1 — a redirect or a
	 * corrected link is a minutes fix.
	 *
	 * @param array $snapshot Snapshot.
	 * @return array[]
	 */
	private static function items_broken_links( $snapshot ) {
		if ( empty( $snapshot['flags']['monitor_404'] ) ) {
			return array();
		}

		$items = array();
		foreach ( array_slice( $snapshot['log404'], 0, 3 ) as $row ) {
			$count      = (int) ( $row['count'] ?? 0 );
			$path       = (string) ( $row['path'] ?? '' );
			$referrers  = array_keys( is_array( $row['referrers'] ?? null ) ? $row['referrers'] : array() );
			$impact     = 2 + ( $count >= 10 ? 1 : 0 ) + ( $referrers ? 1 : 0 );

			$items[] = self::build_item(
				array(
					'id'            => '404-' . md5( $path ),
					/* translators: 1: number of hits */
					'title'         => sprintf( __( 'Broken page hit %1$s times', 'insightistic' ), number_format_i18n( $count ) ),
					'source'        => '404_monitor',
					'source_label'  => __( '404 Monitor', 'insightistic' ),
					'why'           => sprintf(
						/* translators: 1: request path, 2: extra context about external referrers */
						__( 'Visitors land on %1$s and see an error%2$s. Add a redirect or fix the link so those visits stop leaking.', 'insightistic' ),
						$path,
						$referrers ? __( ' — and external websites are sending people there', 'insightistic' ) : ''
					),
					'impact'        => min( 5, $impact ),
					'effort'        => 1,
					'action_url'    => admin_url( 'admin.php?page=insightistic#isp-404-card' ),
					'action_label'  => __( 'Review broken URLs', 'insightistic' ),
					'explain_url'   => admin_url( 'admin.php?page=insightistic-settings&active_tab=engagement#engagement' ),
					'explain_label' => __( 'About the 404 monitor', 'insightistic' ),
				)
			);
		}
		return $items;
	}

	/**
	 * Speed items from the Speed Test cache (preferred, has opportunities) or
	 * the dashboard PageSpeed cache (Core Web Vitals only), both for the
	 * configured default URL and both mobile strategy.
	 *
	 * Impact: 4 for opportunities saving >= 1s, 3 below that or for poor
	 * Core Web Vitals. Effort: 2 (image/script fixes); running the first
	 * test is effort 1.
	 *
	 * @param array $snapshot Snapshot.
	 * @return array[]
	 */
	private static function items_speed( $snapshot ) {
		$flags  = $snapshot['flags'];
		$speed  = $snapshot['speedtest']['mobile'] ?? array();
		$psi    = $snapshot['psi']['mobile'] ?? array();
		$items  = array();

		if ( ! $flags['psi_configured'] ) {
			return $items;
		}

		$speed_page = admin_url( 'admin.php?page=insightistic-speed-test' );

		if ( empty( $speed ) && empty( $psi ) ) {
			$items[] = self::build_item(
				array(
					'id'            => 'speed-first-run',
					'title'         => __( 'Run your first Speed Test', 'insightistic' ),
					'source'        => 'speed_test',
					'source_label'  => __( 'Speed Test', 'insightistic' ),
					'why'           => __( 'Your PageSpeed key is configured but no test has run yet. One run scores your homepage and lists exactly what slows it down.', 'insightistic' ),
					'impact'        => 3,
					'effort'        => 1,
					'action_url'    => $speed_page,
					'action_label'  => __( 'Open Speed Test', 'insightistic' ),
					'explain_url'   => $speed_page,
					'explain_label' => __( 'What it measures', 'insightistic' ),
				)
			);
			return $items;
		}

		// Lighthouse opportunities with measurable savings, biggest first
		// (same array the Speed Test page renders, already sorted).
		foreach ( array_slice( $speed['opportunities'] ?? array(), 0, 2 ) as $op ) {
			$savings = (int) ( $op['savings_ms'] ?? 0 );
			if ( $savings < 300 || '' === ( $op['title'] ?? '' ) ) {
				continue;
			}
			$items[] = self::build_item(
				array(
					'id'            => 'speed-' . md5( $op['title'] ),
					/* translators: 1: Lighthouse opportunity title */
					'title'         => sprintf( __( 'Speed up: %s', 'insightistic' ), $op['title'] ),
					'source'        => 'speed_test',
					'source_label'  => __( 'Speed Test', 'insightistic' ),
					'why'           => sprintf(
						/* translators: 1: estimated time savings */
						__( 'Fixing this could save about %1$s on mobile page load. Faster pages keep visitors and rank better.', 'insightistic' ),
						self::format_ms( $savings )
					),
					'impact'        => $savings >= 1000 ? 4 : 3,
					'effort'        => 2,
					'action_url'    => $speed_page,
					'action_label'  => __( 'Open Speed Test', 'insightistic' ),
					'explain_url'   => $speed_page,
					'explain_label' => __( 'Why this matters', 'insightistic' ),
				)
			);
		}

		// Poor Core Web Vitals: one guidance item each for the three ranking
		// metrics (falls back to the dashboard widget cache when no Speed
		// Test has run). Guidance mirrors the thresholds in
		// Insightistic_PageSpeed::parse_metric().
		$cwv = ! empty( $speed['cwv'] ) ? $speed['cwv'] : ( $psi['cwv'] ?? array() );
		$why = array(
			'lcp' => __( 'Largest Contentful Paint is slower than the 2.5 s Google considers good. Compress and lazy-load the main image, and add caching.', 'insightistic' ),
			'inp' => __( 'Interaction response is slower than the 200 ms Google considers good. Defer heavy JavaScript so taps respond quickly.', 'insightistic' ),
			'cls' => __( 'The layout shifts while loading, past the 0.1 Google considers good. Set width and height on images and ads to stop the jumping.', 'insightistic' ),
		);
		foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
			if ( count( $items ) >= 4 ) {
				break;
			}
			$entry = $cwv[ $metric ] ?? null;
			if ( ! is_array( $entry ) || 'poor' !== ( $entry['status'] ?? '' ) ) {
				continue;
			}
			$items[] = self::build_item(
				array(
					'id'            => 'speed-cwv-' . $metric,
					'title'         => sprintf(
						/* translators: 1: metric label, 2: measured value */
						__( 'Fix %1$s (%2$s on mobile)', 'insightistic' ),
						(string) ( $entry['label'] ?? strtoupper( $metric ) ),
						(string) ( $entry['display'] ?? '' )
					),
					'source'        => 'speed_test',
					'source_label'  => __( 'Speed Test', 'insightistic' ),
					'why'           => $why[ $metric ],
					'impact'        => 3,
					'effort'        => 2,
					'action_url'    => $speed_page,
					'action_label'  => __( 'Open Speed Test', 'insightistic' ),
					'explain_url'   => $speed_page,
					'explain_label' => __( 'Why this matters', 'insightistic' ),
				)
			);
		}

		return $items;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Support methods                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalize one raw collector row into a queue item (adds the score).
	 *
	 * @param array $raw Raw fields: id, title, source, source_label, why,
	 *                    impact, effort, action_url, action_label,
	 *                    explain_url, explain_label.
	 * @return array Complete queue item.
	 */
	private static function build_item( $raw ) {
		$impact = max( 1, min( 5, (int) ( $raw['impact'] ?? 1 ) ) );
		$effort = max( 1, min( 5, (int) ( $raw['effort'] ?? 1 ) ) );

		return array(
			'id'            => (string) ( $raw['id'] ?? md5( (string) ( $raw['title'] ?? '' ) ) ),
			'title'         => (string) ( $raw['title'] ?? '' ),
			'source'        => (string) ( $raw['source'] ?? '' ),
			'source_label'  => (string) ( $raw['source_label'] ?? '' ),
			'why'           => (string) ( $raw['why'] ?? '' ),
			'impact'        => $impact,
			'effort'        => $effort,
			'score'         => $impact * $effort,
			'action_url'    => (string) ( $raw['action_url'] ?? '' ),
			'action_label'  => (string) ( $raw['action_label'] ?? '' ),
			'explain_url'   => (string) ( $raw['explain_url'] ?? '' ),
			'explain_label' => (string) ( $raw['explain_label'] ?? '' ),
		);
	}

	/**
	 * Human-readable duration from milliseconds ("1.2 s" / "450 ms").
	 *
	 * @param int $ms Milliseconds.
	 * @return string
	 */
	private static function format_ms( $ms ) {
		if ( $ms >= 1000 ) {
			/* translators: 1: seconds */
			return sprintf( __( '%s s', 'insightistic' ), number_format_i18n( $ms / 1000, 1 ) );
		}
		/* translators: 1: milliseconds */
		return sprintf( __( '%s ms', 'insightistic' ), number_format_i18n( $ms ) );
	}

	/**
	 * Newest cached GA4 payload for the current property.
	 * Mirrors Insightistic_Addons::latest_ga_cache() (private there).
	 *
	 * @return array
	 */
	private static function latest_ga_cache() {
		$property_id = get_option( 'insightistic_property_id', '' );
		foreach ( array( 28, 30, 7, 90, 180 ) as $days ) {
			$data = get_transient( 'insightistic_data_' . $days . '_' . md5( (string) $property_id ) );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * Newest cached GSC payload for the current property.
	 * Mirrors Insightistic_Addons::latest_gsc_cache() (private there).
	 *
	 * @return array
	 */
	private static function latest_gsc_cache() {
		$site_url = get_option( 'insightistic_gsc_property_url', '' );
		foreach ( array( 28, 30, 7, 90, 180 ) as $days ) {
			$data = get_transient( 'insightistic_gsc_' . $days . '_' . md5( (string) $site_url ) );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * Detailed Speed Test cache for the configured default URL (the URL the
	 * Speed Test page and dashboard widget test by default).
	 *
	 * @return array
	 */
	private static function latest_speedtest_cache() {
		$url  = get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) );
		$data = get_transient( 'insightistic_speedtest_' . md5( (string) $url ) );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Dashboard PageSpeed widget cache for the configured default URL.
	 *
	 * @return array
	 */
	private static function latest_psi_cache() {
		$url  = get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) );
		$data = get_transient( 'insightistic_psi_' . md5( (string) $url ) );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Top 404 rows by hit count (empty when the monitor is disabled or the
	 * log is empty — an empty log is a normal state, matching the monitor's
	 * own "no broken links yet" handling).
	 *
	 * @return array[]
	 */
	private static function top_404_rows() {
		if ( ! get_option( 'insightistic_404_monitor_enabled', 1 ) ) {
			return array();
		}
		$log = get_option( 'insightistic_404_log', array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$rows = array_values( $log );
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 );
			}
		);
		return array_slice( $rows, 0, 3 );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Snooze                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * This user's snooze map (item_id => expiry), expired entries pruned.
	 *
	 * @return array
	 */
	private static function get_snoozed() {
		$meta = get_user_meta( get_current_user_id(), self::SNOOZE_META, true );
		if ( ! is_array( $meta ) ) {
			return array();
		}
		$now     = time();
		$clean   = array_filter(
			$meta,
			static function ( $expiry ) use ( $now ) {
				return (int) $expiry > $now;
			}
		);
		if ( count( $clean ) !== count( $meta ) ) {
			update_user_meta( get_current_user_id(), self::SNOOZE_META, $clean );
		}
		return $clean;
	}

	/**
	 * AJAX: snooze one queue item for this user for 7 days.
	 *
	 * Follows the plugin's existing AJAX pattern (insightistic_nonce +
	 * manage_options) and validates the id against the live pool, so only
	 * real, current items can be snoozed.
	 */
	public static function ajax_snooze() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$item_id = sanitize_text_field( wp_unslash( $_POST['item_id'] ?? '' ) );
		$valid   = wp_list_pluck( self::get_pool(), 'id' );
		if ( '' === $item_id || ! in_array( $item_id, $valid, true ) ) {
			wp_send_json_error( __( 'Unknown queue item.', 'insightistic' ) );
		}

		$snoozed               = self::get_snoozed();
		$snoozed[ $item_id ]   = time() + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		update_user_meta( get_current_user_id(), self::SNOOZE_META, $snoozed );

		wp_send_json_success(
			array(
				'message'   => __( 'Snoozed for 7 days.', 'insightistic' ),
				'item_id'   => $item_id,
				'expires_at' => $snoozed[ $item_id ],
			)
		);
	}

	/*
	------------------------------------------------------------------ */
	/*
	Render                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the "Do this next" section. Nothing renders when every item is
	 * snoozed or the queue is empty — no empty section, no nag.
	 */
	public static function render_section() {
		$items = self::get_queue();
		if ( empty( $items ) ) {
			return;
		}
		?>
		<!-- ================================================== DO THIS NEXT QUEUE -->
		<div class="isp-dtn" id="isp-do-next">
			<div class="isp-section-header">
				<h2 class="isp-section-title"><?php esc_html_e( 'Do this next', 'insightistic' ); ?></h2>
				<span class="isp-detail-card-sub"><?php esc_html_e( 'Your highest-leverage actions, ranked by impact × effort.', 'insightistic' ); ?></span>
			</div>
			<div class="isp-detail-card isp-dtn-card">
				<div class="isp-dtn-list">
					<?php foreach ( $items as $item ) : ?>
						<div class="isp-dtn-item" data-item-id="<?php echo esc_attr( $item['id'] ); ?>">
							<span class="isp-dtn-score" title="<?php echo esc_attr( sprintf( /* translators: 1: priority score, 2: maximum score */ __( 'Priority %1$s of %2$s (impact × effort)', 'insightistic' ), $item['score'], 25 ) ); ?>"><?php echo esc_html( (string) $item['score'] ); ?></span>
							<div class="isp-dtn-body">
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<span class="isp-dtn-source"><?php echo esc_html( $item['source_label'] ); ?></span>
								<span class="isp-dtn-why"><?php echo esc_html( $item['why'] ); ?></span>
							</div>
							<div class="isp-dtn-actions">
								<a href="<?php echo esc_url( $item['action_url'] ); ?>" class="isp-btn isp-btn-primary isp-dtn-action"><?php echo esc_html( $item['action_label'] ); ?></a>
								<a href="<?php echo esc_url( $item['explain_url'] ); ?>" class="isp-link-btn"><?php echo esc_html( $item['explain_label'] ); ?></a>
								<button type="button" class="isp-link-btn isp-dtn-snooze" data-item-id="<?php echo esc_attr( $item['id'] ); ?>"><?php esc_html_e( 'Snooze 7 days', 'insightistic' ); ?></button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
