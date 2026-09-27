<?php
/**
 * GA4 regression suite A — named dateRange responses (the 4.4.1 fix).
 *
 * GA4 returns rows keyed by a dateRange dimension ("current"/"previous")
 * instead of a totals[] array whenever the request names its date ranges.
 * 4.4.0 rendered every KPI as N/A and duplicated channel/page/country rows;
 * these tests pin the repaired behaviour so it cannot regress.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require __DIR__ . '/lib.php';

echo "== GA4 Test A: named dateRange rows ==\n";

/* ------------------------------------------------------------------ */
/* Overview KPIs                                                       */
/* ------------------------------------------------------------------ */

echo "- get_overview() named ranges\n";
$ga  = ga_instance();
$GLOBALS['insightistic_test_http_queue'] = array();
queue_fixture( 'overview-named-ranges' );
$ov = call_private( $ga, 'get_overview', ga_dates() );

assert_same( 1386, $ov['sessions']['value'], 'sessions value (current row)' );
assert_epsilon( 40.3, $ov['sessions']['change'], 'sessions change % vs previous row' );
assert_same( 921, $ov['unique_users']['value'], 'users value' );
assert_epsilon( 31.6, $ov['unique_users']['change'], 'users change %' );
assert_epsilon( 15420.55, $ov['revenue']['value'], 'revenue value' );
assert_epsilon( 28.5, $ov['revenue']['change'], 'revenue change %' );
assert_same( 37, $ov['transactions']['value'], 'transactions value' );
assert_epsilon( 23.3, $ov['transactions']['change'], 'transactions change %' );
assert_same( 4455, $ov['pageviews']['value'], 'pageviews value' );
assert_epsilon( 14.5, $ov['pageviews']['change'], 'pageviews change %' );
assert_same( '3:34', $ov['avg_duration']['value'], 'avg session duration formatted m:ss' );
assert_epsilon( 214.6, $ov['avg_duration']['value_raw'], 'avg session duration raw seconds' );
assert_epsilon( 9.9, $ov['avg_duration']['change'], 'avg duration change %' );
assert_epsilon( 42.3, $ov['bounce_rate']['value'], 'bounce rate 0.4231 -> 42.3%' );
assert_epsilon( -6.2, $ov['bounce_rate']['change'], 'bounce rate change % (negative)' );
assert_same( 512, $ov['new_vs_return']['new_count'], 'new users count' );
assert_epsilon( 55.6, $ov['new_vs_return']['new_pct'], 'new user share %' );
assert_epsilon( 44.4, $ov['new_vs_return']['return_pct'], 'returning user share %' );

/* ------------------------------------------------------------------ */
/* Traffic channels                                                    */
/* ------------------------------------------------------------------ */

echo "- get_traffic_channels() named ranges\n";
$ga = ga_instance();
queue_fixture( 'channels-named-ranges' );
$ch = call_private( $ga, 'get_traffic_channels', ga_dates() );

assert_same( 3, count( $ch ), 'exactly one row per channel (previous rows filtered, no duplicates)' );
assert_same( array( 'Organic Search', 'Direct', 'Referral' ), array_column( $ch, 'channel' ), 'channel order preserved, no dup rows' );

$organic = $ch[0];
assert_same( 620, $organic['sessions'], 'organic sessions (current only)' );
assert_epsilon( 55.9, $organic['share'], 'organic share % of current total' );
assert_epsilon( 24.0, $organic['change'], 'organic change % mapped from previous row (not 100/0)' );
assert_same( 410, $organic['users'], 'organic users' );
assert_epsilon( 39.1, $organic['bounce'], 'organic bounce %' );

$direct = $ch[1];
assert_same( 310, $direct['sessions'], 'direct sessions' );
assert_epsilon( 27.9, $direct['share'], 'direct share %' );
assert_epsilon( 40.9, $direct['change'], 'direct change %' );

$referral = $ch[2];
assert_epsilon( -5.3, $referral['change'], 'referral negative change %' );
assert_epsilon( 16.2, $referral['share'], 'referral share %' );

/* ------------------------------------------------------------------ */
/* Top pages                                                           */
/* ------------------------------------------------------------------ */

echo "- get_top_pages() named ranges\n";
$ga = ga_instance();
queue_fixture( 'pages-named-ranges' );
$pg = call_private( $ga, 'get_top_pages', ga_dates() );

assert_same( 3, count( $pg ), 'exactly one row per page (previous rows filtered)' );
assert_same( array( '/', '/pricing/', '/blog/ga4-guide/' ), array_column( $pg, 'path' ), 'page paths, no duplicates' );

$home = $pg[0];
assert_same( 1420, $home['views'], 'home views (current)' );
assert_epsilon( 57.5, $home['share'], 'home share %' );
assert_epsilon( 17.4, $home['change'], 'home change % mapped from previous row' );
assert_epsilon( 38.9, $home['bounce'], 'home bounce %' );
assert_same( '2:22', $home['avg_time'], 'home avg time formatted' );

assert_epsilon( 33.3, $pg[1]['change'], 'pricing change %' );
assert_epsilon( 100.0, $pg[2]['change'], 'blog post doubled -> +100%' );

/* ------------------------------------------------------------------ */
/* Countries (prev-period mapping fixed in 4.4.2)                      */
/* ------------------------------------------------------------------ */

echo "- get_countries() named ranges\n";
$ga = ga_instance();
queue_fixture( 'countries-named-ranges' );
$co = call_private( $ga, 'get_countries', ga_dates() );

assert_same( 3, count( $co ), 'exactly one row per country' );
$us = $co[0];
assert_same( 'United States', $us['country'], 'US first' );
assert_same( 500, $us['sessions'], 'US current sessions' );
assert_epsilon( 50.0, $us['share'], 'US share %' );
assert_epsilon( 25.0, $us['change'], 'US change % from previous row (was always 100/0 before 4.4.2)' );
assert_epsilon( -14.3, $co[1]['change'], 'UK declining change %' );
assert_epsilon( 66.7, $co[2]['change'], 'Germany growth change %' );

/* No current row may be polluted by previous-period rows. */
$total = array_sum( array_column( $co, 'sessions' ) );
assert_same( 1000, $total, 'country sessions total = current period only' );

test_summary( 'GA4 NAMED-RANGE TESTS' );
