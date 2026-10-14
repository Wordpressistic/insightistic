<?php
/**
 * GA4 regression suite B — legacy totals[] responses.
 *
 * Before 4.4.1 the parser consumed a totals[]-shaped overview payload.
 * The 4.4.1 fix must not regress that path: a legacy-format response must
 * still yield correct KPI values and period-over-period changes.
 *
 * Also covers the legacy channel shape (rows without a dateRange dimension).
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require __DIR__ . '/lib.php';

echo "== GA4 Test B: legacy totals[] responses ==\n";

/* ------------------------------------------------------------------ */
/* Overview via totals[]                                               */
/* ------------------------------------------------------------------ */

echo "- get_overview() legacy totals\n";
$ga  = ga_instance();
$GLOBALS['insightistic_test_http_queue'] = array();
queue_fixture( 'overview-legacy-totals' );
$ov = call_private( $ga, 'get_overview', ga_dates() );

assert_same( 1200, $ov['sessions']['value'], 'legacy sessions value (totals[0])' );
assert_epsilon( 20.0, $ov['sessions']['change'], 'legacy sessions change % (totals[1] baseline)' );
assert_same( 800, $ov['unique_users']['value'], 'legacy users value' );
assert_epsilon( 6.7, $ov['unique_users']['change'], 'legacy users change %' );
assert_epsilon( 9000.99, $ov['revenue']['value'], 'legacy revenue value' );
assert_epsilon( 12.5, $ov['revenue']['change'], 'legacy revenue change %' );
assert_same( 18, $ov['transactions']['value'], 'legacy transactions value' );
assert_epsilon( -10.0, $ov['transactions']['change'], 'legacy transactions change % (negative)' );
assert_same( 3100, $ov['pageviews']['value'], 'legacy pageviews value' );
assert_epsilon( 6.9, $ov['pageviews']['change'], 'legacy pageviews change %' );
assert_same( '2:58', $ov['avg_duration']['value'], 'legacy avg duration m:ss' );
assert_epsilon( 8.2, $ov['avg_duration']['change'], 'legacy avg duration change %' );
assert_epsilon( 51.2, $ov['bounce_rate']['value'], 'legacy bounce rate %' );
assert_epsilon( 4.9, $ov['bounce_rate']['change'], 'legacy bounce rate change %' );
assert_same( 430, $ov['new_vs_return']['new_count'], 'legacy new users count' );
assert_epsilon( 53.8, $ov['new_vs_return']['new_pct'], 'legacy new user share %' );

/* ------------------------------------------------------------------ */
/* Channels without a dateRange dimension (legacy shape)               */
/* ------------------------------------------------------------------ */

echo "- get_traffic_channels() legacy rows (no range dimension)\n";
$ga = ga_instance();
queue_fixture( 'channels-legacy-no-range' );
$ch = call_private( $ga, 'get_traffic_channels', ga_dates() );

assert_same( 3, count( $ch ), 'all rows kept when no range dimension exists' );
assert_same( 'Organic Search', $ch[0]['channel'], 'legacy channel name' );
assert_same( 620, $ch[0]['sessions'], 'legacy sessions value' );
assert_same( 410, $ch[0]['users'], 'legacy users value' );
assert_epsilon( 39.1, $ch[0]['bounce'], 'legacy bounce %' );
assert_epsilon( 55.9, $ch[0]['share'], 'legacy share %' );
// No previous-period data in this shape: prev defaults to 0 -> +100 flag.
assert_epsilon( 100.0, $ch[0]['change'], 'legacy change defaults to +100 sentinel (prev unknown)' );

test_summary( 'GA4 LEGACY-FORMAT TESTS' );
