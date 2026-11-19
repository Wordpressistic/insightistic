<?php
/**
 * "Do this next" queue test suite.
 *
 * Covers the pool assembly from the four existing data sources (SEO
 * Opportunity Finder thresholds, anomaly thresholds, 404 monitor log,
 * PageSpeed/Speed Test caches), the impact × effort scoring constants,
 * sort order and the 10-item visible cap, per-user snooze storage and
 * expiry, the transient cache lifecycle (keyed by config fingerprint,
 * invalidated on the data-refresh actions), and the snooze AJAX guards.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/lib.php';

if ( ! defined( 'INSIGHTISTIC_VERSION' ) ) {
	define( 'INSIGHTISTIC_VERSION', '4.7.1-test' );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return ! empty( $GLOBALS['insightistic_test_nonce_ok'] ) ? 1 : false;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $value, $decimals = 0 ) {
		return number_format( (float) $value, (int) $decimals );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test/' . $path;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$out = array();
		foreach ( $list as $item ) {
			if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
				$out[] = $item[ $field ];
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['insightistic_test_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['insightistic_test_user_meta'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['insightistic_test_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-insightistic-do-next.php';

/**
 * Reset all in-memory state between scenarios.
 */
function dtn_reset() {
	$GLOBALS['insightistic_test_options']   = array();
	$GLOBALS['insightistic_test_transients'] = array();
	$GLOBALS['insightistic_test_user_meta']  = array();
	$GLOBALS['insightistic_test_user_id']    = 1;
	$GLOBALS['insightistic_test_nonce_ok']   = false;
	$GLOBALS['insightistic_test_current_user_can'] = false;
	$_POST = array();
}

/**
 * Invoke a private static method of Insightistic_Do_Next.
 *
 * @param string $method Method name.
 * @param array  $args   Positional arguments.
 * @return mixed
 */
function dtn_call( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'Insightistic_Do_Next', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

/**
 * Configure a GSC-connected, GA4-connected site (no cached data yet).
 */
function dtn_site() {
	update_option( 'insightistic_property_id', '123456789' );
	update_option( 'insightistic_gsc_property_url', 'https://example.test/' );
	update_option( 'insightistic_pagespeed_api_key_enc', 'enc' );
	update_option( 'insightistic_pagespeed_default_url', 'https://example.test/' );
}

/**
 * Seed the newest GSC data transient with top_queries rows.
 *
 * @param array $queries Rows: query, impressions, ctr (percent), position.
 */
function dtn_seed_gsc( array $queries ) {
	set_transient(
		'insightistic_gsc_28_' . md5( 'https://example.test/' ),
		array( 'top_queries' => $queries ),
		900
	);
}

/**
 * Seed the newest GA4 data transient with an overview payload.
 *
 * @param array $overview Overview: metric => array( 'change' => float ).
 */
function dtn_seed_ga( array $overview ) {
	set_transient(
		'insightistic_data_28_' . md5( '123456789' ),
		array( 'overview' => $overview ),
		900
	);
}

/**
 * Seed the detailed Speed Test transient for the default URL.
 *
 * @param array $mobile Mobile payload (opportunities, cwv).
 */
function dtn_seed_speedtest( array $mobile ) {
	set_transient(
		'insightistic_speedtest_' . md5( 'https://example.test/' ),
		array( 'mobile' => $mobile ),
		3600
	);
}

dtn_reset();

/* ------------------------------------------------------------------ */
/* SEO opportunities: thresholds mirror the finder, impact tiers.      */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
dtn_seed_gsc(
	array(
		array( 'query' => 'in the money', 'impressions' => 6000, 'ctr' => 1.0, 'position' => 5.0 ),
		array( 'query' => 'page two query', 'impressions' => 120, 'ctr' => 2.9, 'position' => 25.0 ),
		array( 'query' => 'too few impressions', 'impressions' => 90, 'ctr' => 1.0, 'position' => 5.0 ),
		array( 'query' => 'healthy ctr', 'impressions' => 300, 'ctr' => 3.5, 'position' => 4.0 ),
	)
);
$pool = dtn_call( 'get_pool' );
$seo  = array_values(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'seo_opportunities' === $item['source'];
		}
	)
);
assert_same( 1, count( $seo ), 'seo: only queries matching the finder thresholds enter the pool' );
assert_same( 'in the money', $seo[0]['title'], 'seo: surviving item is the qualifying query' );
assert_same( 5, $seo[0]['impact'], 'seo: top-tier impressions + page-one position cap impact at 5' );
assert_same( 10, $seo[0]['score'], 'seo: impact 5 × effort 2 = score 10' );
assert_same( 'seo-' . md5( 'in the money' ), $seo[0]['id'], 'seo: item id is stable per query' );
assert_true( false !== strpos( $seo[0]['why'], '6,000' ), 'seo: why text carries the localized impression count' );

dtn_reset();
dtn_site();
dtn_seed_gsc(
	array(
		array( 'query' => 'mid tier', 'impressions' => 1500, 'ctr' => 1.0, 'position' => 15.0 ),
	)
);
$pool = dtn_call( 'get_pool' );
$seo  = array_values(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'seo_opportunities' === $item['source'];
		}
	)
);
assert_same( 4, $seo[0]['impact'], 'seo: mid-tier impressions without page one = impact 4' );

/* ------------------------------------------------------------------ */
/* Anomalies: thresholds mirror the addon, impact per metric.          */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
dtn_seed_ga(
	array(
		'revenue'      => array( 'change' => -22.0 ),
		'sessions'     => array( 'change' => 25.0 ),
		'pageviews'    => array( 'change' => -5.0 ),
		'bounce_rate'  => array( 'change' => 18.0 ),
	)
);
$pool     = dtn_call( 'get_pool' );
$anomaly  = array_values(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'anomaly_alerts' === $item['source'];
		}
	)
);
$by_title = array();
foreach ( $anomaly as $item ) {
	$by_title[ $item['title'] ] = $item;
}
assert_same( 3, count( $anomaly ), 'anomaly: two bad movements + one win enter the pool, quiet metrics do not' );
assert_true( isset( $by_title['Revenue dropped 22%'] ), 'anomaly: revenue drop item present with plain-language title' );
assert_same( 5, $by_title['Revenue dropped 22%']['impact'], 'anomaly: revenue impact is 5' );
assert_true( isset( $by_title['Sessions grew 25%'] ), 'anomaly: +25% sessions is a win item, not a drop' );
assert_true( isset( $by_title['Bounce Rate dropped 18%'] ), 'anomaly: bounce rate thresholds are inverted (+18% is bad)' );
assert_same( 15, $by_title['Revenue dropped 22%']['score'], 'anomaly: impact 5 × effort 3 = score 15' );

/* ------------------------------------------------------------------ */
/* 404 monitor: top rows by hits, impact bumps, disabled monitor.      */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
update_option(
	'insightistic_404_log',
	array(
		'aaa' => array( 'path' => '/old-page', 'count' => 5, 'referrers' => array() ),
		'bbb' => array( 'path' => '/very-hit', 'count' => 12, 'referrers' => array( 'other-site.test' => true ) ),
		'ccc' => array( 'path' => '/once', 'count' => 1, 'referrers' => array() ),
	)
);
$pool    = dtn_call( 'get_pool' );
$broken  = array_values(
	array_filter(
		$pool,
		static function ( $item ) {
			return '404_monitor' === $item['source'];
		}
	)
);
assert_same( 3, count( $broken ), '404: all three logged paths enter the pool' );
$by_why = array();
foreach ( $broken as $item ) {
	$by_why[ $item['impact'] ] = $item;
}
assert_same( 4, $by_why[4]['impact'], '404: >=10 hits + external referrer = impact 4' );
assert_same( 2, $by_why[2]['impact'], '404: single low-traffic path stays at impact 2' );
assert_true( false !== strpos( $by_why[4]['why'], '/very-hit' ), '404: why text names the broken path' );
assert_same( 4, $by_why[4]['score'], '404: quick win impact 4 × effort 1 = score 4' );

dtn_reset();
dtn_site();
update_option( 'insightistic_404_monitor_enabled', 0 );
update_option(
	'insightistic_404_log',
	array( 'aaa' => array( 'path' => '/old-page', 'count' => 5, 'referrers' => array() ) )
);
$pool   = dtn_call( 'get_pool' );
$broken = array_filter(
	$pool,
	static function ( $item ) {
		return '404_monitor' === $item['source'];
	}
);
assert_same( 0, count( $broken ), '404: disabled monitor contributes nothing' );

/* ------------------------------------------------------------------ */
/* Speed: opportunities + poor CWV, first-run nudge, unconfigured.     */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
dtn_seed_speedtest(
	array(
		'opportunities' => array(
			array( 'title' => 'Reduce unused JavaScript', 'savings_ms' => 1200 ),
			array( 'title' => 'Tiny saving', 'savings_ms' => 100 ),
		),
		'cwv'           => array(
			'lcp' => array( 'status' => 'poor', 'display' => '5.1 s', 'label' => 'Largest Contentful Paint' ),
			'inp' => array( 'status' => 'good', 'display' => '80 ms', 'label' => 'Interaction to Next Paint' ),
		),
	)
);
$pool  = dtn_call( 'get_pool' );
$speed = array_values(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'speed_test' === $item['source'];
		}
	)
);
assert_same( 2, count( $speed ), 'speed: >=300ms opportunity + poor CWV enter, tiny savings do not' );
assert_true( false !== strpos( $speed[0]['title'], 'Reduce unused JavaScript' ), 'speed: opportunity title surfaces in the item' );
$big = array_values(
	array_filter(
		$speed,
		static function ( $item ) {
			return false !== strpos( $item['title'], 'Reduce unused JavaScript' );
		}
	)
);
assert_same( 4, $big[0]['impact'], 'speed: >=1s savings = impact 4' );
assert_true( false !== strpos( $speed[1]['title'], 'Largest Contentful Paint (5.1 s on mobile)' ), 'speed: poor LCP item carries label and measured value' );

dtn_reset();
dtn_site();
$pool  = dtn_call( 'get_pool' );
$speed = array_values(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'speed_test' === $item['source'];
		}
	)
);
assert_same( 1, count( $speed ), 'speed: configured but never run nudges the first Speed Test' );
assert_same( 'speed-first-run', $speed[0]['id'], 'speed: first-run item has a stable id' );

dtn_reset();
update_option( 'insightistic_property_id', '123456789' );
$pool  = dtn_call( 'get_pool' );
$speed = array_filter(
	$pool,
	static function ( $item ) {
		return 'speed_test' === $item['source'];
	}
);
assert_same( 0, count( $speed ), 'speed: unconfigured PageSpeed never nags' );

/* ------------------------------------------------------------------ */
/* Sort order and the 10-item visible cap.                             */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
$queries = array();
for ( $i = 0; $i < 12; $i++ ) {
	$queries[] = array( 'query' => 'query ' . $i, 'impressions' => 100 + $i, 'ctr' => 1.0, 'position' => 5.0 );
}
dtn_seed_gsc( $queries );
dtn_seed_ga(
	array(
		'revenue'      => array( 'change' => -22.0 ),
		'sessions'     => array( 'change' => 25.0 ),
		'unique_users' => array( 'change' => -30.0 ),
		'pageviews'    => array( 'change' => 21.0 ),
		'bounce_rate'  => array( 'change' => 18.0 ),
	)
);
update_option(
	'insightistic_404_log',
	array(
		'aaa' => array( 'path' => '/a', 'count' => 5, 'referrers' => array() ),
		'bbb' => array( 'path' => '/b', 'count' => 12, 'referrers' => array( 'other.test' => true ) ),
		'ccc' => array( 'path' => '/c', 'count' => 1, 'referrers' => array() ),
	)
);
// Pool: 5 SEO (collector cap) + 5 anomalies + 3 x 404 + setup-ga4-load + speed-first-run = 15.
$queue = Insightistic_Do_Next::get_queue();
assert_same( 10, count( $queue ), 'queue: visible list is capped at 10' );
$scores = wp_list_pluck( $queue, 'score' );
$sorted = $scores;
rsort( $sorted );
assert_same( $sorted, $scores, 'queue: items are sorted by score descending' );
assert_same( 'Revenue dropped 22%', $queue[0]['title'], 'queue: highest score (revenue anomaly, 15) leads' );

/* ------------------------------------------------------------------ */
/* Per-user snooze: storage, filtering, expiry, isolation, AJAX guards. */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
dtn_seed_gsc(
	array(
		array( 'query' => 'snoozable query', 'impressions' => 500, 'ctr' => 1.0, 'position' => 5.0 ),
	)
);
$queue  = Insightistic_Do_Next::get_queue();
$target = $queue[0];
assert_same( 'seo-' . md5( 'snoozable query' ), $target['id'], 'snooze: target item is the SEO query' );
assert_same( 3, count( $queue ), 'snooze: scenario starts with three visible items' );

// Snooze via the AJAX handler (stub fires wp_send_json_success → RuntimeException).
$GLOBALS['insightistic_test_nonce_ok']       = true;
$GLOBALS['insightistic_test_current_user_can'] = true;
$_POST['item_id'] = $target['id'];
$sent = null;
try {
	Insightistic_Do_Next::ajax_snooze();
} catch ( RuntimeException $e ) {
	$sent = $e->getMessage();
}
assert_same( 'wp_send_json_success', $sent, 'snooze: valid id + nonce + cap succeeds' );
$queue = Insightistic_Do_Next::get_queue();
assert_same( 2, count( $queue ), 'snooze: snoozed item disappears, the other two remain' );
assert_true( time() + 6.9 * DAY_IN_SECONDS <= (int) $GLOBALS['insightistic_test_user_meta'][1]['insightistic_do_next_snoozed'][ $target['id'] ], 'snooze: expiry is ~7 days out' );

// Another user still sees all three.
dtn_user_switch( 2 );
$queue = Insightistic_Do_Next::get_queue();
assert_same( 3, count( $queue ), 'snooze: snoozes are per-user' );
dtn_user_switch( 1 );

// Expired snooze resurfaces the item.
$GLOBALS['insightistic_test_user_meta'][1]['insightistic_do_next_snoozed'][ $target['id'] ] = time() - 10;
$queue = Insightistic_Do_Next::get_queue();
assert_same( 3, count( $queue ), 'snooze: expired snooze resurfaces the item' );

// Unknown id is rejected and stores nothing.
$_POST['item_id'] = 'forged-id';
$sent = null;
try {
	Insightistic_Do_Next::ajax_snooze();
} catch ( RuntimeException $e ) {
	$sent = $e->getMessage();
}
assert_same( 0, strpos( $sent, 'wp_send_json_error' ), 'snooze: forged id is rejected' );
$meta = $GLOBALS['insightistic_test_user_meta'][1]['insightistic_do_next_snoozed'];
assert_true( ! isset( $meta[ $target['id'] ] ), 'snooze: expired entry was pruned from the store' );
assert_true( ! isset( $meta['forged-id'] ), 'snooze: rejected request writes nothing' );

// Bad nonce + missing capability short-circuits with a permission error.
$GLOBALS['insightistic_test_nonce_ok']         = false;
$GLOBALS['insightistic_test_current_user_can'] = false;
$_POST['item_id'] = 'seo-' . md5( 'snoozable query' );
$sent = null;
try {
	Insightistic_Do_Next::ajax_snooze();
} catch ( RuntimeException $e ) {
	$sent = $e->getMessage();
}
assert_same( 0, strpos( $sent, 'wp_send_json_error: Insufficient permissions.' ), 'snooze: missing capability is rejected' );

/**
 * Switch the stubbed current user.
 *
 * @param int $id User id.
 */
function dtn_user_switch( $id ) {
	$GLOBALS['insightistic_test_user_id'] = $id;
}

/* ------------------------------------------------------------------ */
/* Cache lifecycle: config fingerprint keying + refresh invalidation.  */
/* ------------------------------------------------------------------ */

dtn_reset();
dtn_site();
dtn_seed_gsc(
	array(
		array( 'query' => 'before refresh', 'impressions' => 500, 'ctr' => 1.0, 'position' => 5.0 ),
	)
);
$pool = dtn_call( 'get_pool' );
assert_same( 1, count(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'before refresh' === $item['title'];
		}
	)
), 'cache: pool built from the seeded source data' );
$cached = $GLOBALS['insightistic_test_transients'];
assert_same( 1, count(
	array_filter(
		array_keys( $cached ),
		static function ( $key ) {
			return 0 === strpos( $key, 'insightistic_do_next_' );
		}
	)
), 'cache: assembled pool stored under the insightistic_do_next_ transient' );

// New source data alone does NOT rebuild (15-minute queue cache holds).
dtn_seed_gsc(
	array(
		array( 'query' => 'after refresh', 'impressions' => 500, 'ctr' => 1.0, 'position' => 5.0 ),
	)
);
$pool = dtn_call( 'get_pool' );
assert_same( 0, count(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'after refresh' === $item['title'];
		}
	)
), 'cache: pool stays cached until invalidated' );

// The data-refresh action invalidates; the rebuild sees the new data.
$GLOBALS['insightistic_test_nonce_ok']         = true;
$GLOBALS['insightistic_test_current_user_can'] = true;
$_POST['nonce'] = 'ok';
Insightistic_Do_Next::maybe_invalidate_cache();
$pool = dtn_call( 'get_pool' );
assert_same( 1, count(
	array_filter(
		$pool,
		static function ( $item ) {
			return 'after refresh' === $item['title'];
		}
	)
), 'cache: invalidation on the refresh action rebuilds from new data' );

// A failed nonce/capability check must not delete the cache.
$GLOBALS['insightistic_test_nonce_ok']         = false;
$GLOBALS['insightistic_test_current_user_can'] = false;
Insightistic_Do_Next::maybe_invalidate_cache();
$cached = $GLOBALS['insightistic_test_transients'];
assert_same( 1, count(
	array_filter(
		array_keys( $cached ),
		static function ( $key ) {
			return 0 === strpos( $key, 'insightistic_do_next_' );
		}
	)
), 'cache: unauthenticated invalidation request is a no-op' );

// Changing the configuration fingerprint starts a fresh cache key.
dtn_reset();
dtn_site();
dtn_seed_gsc(
	array(
		array( 'query' => 'property A', 'impressions' => 500, 'ctr' => 1.0, 'position' => 5.0 ),
	)
);
dtn_call( 'get_pool' );
$key_a = dtn_call( 'cache_key' );
update_option( 'insightistic_gsc_property_url', 'https://other.test/' );
$key_b = dtn_call( 'cache_key' );
assert_true( $key_a !== $key_b, 'cache: settings change rotates the cache key' );

test_summary( 'do-next' );
