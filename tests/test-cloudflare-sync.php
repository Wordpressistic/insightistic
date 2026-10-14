<?php
/**
 * Tests for the connector push payload (Insightistic_Cloudflare::get_sync_payload)
 * — the BYO Zone ID + API token path that forwards daily edge analytics to
 * the SaaS so the app dashboard fills without any app-side Cloudflare connect.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require __DIR__ . '/wp-stubs.php';

/* The encryption fallback path derives its key from wp_salt('auth'). */
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-fixed-0123456789abcdef0123456789abcdef';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-insightistic-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-insightistic-cloudflare.php';

require __DIR__ . '/lib.php';

$cf = new Insightistic_Cloudflare();

/* ------------------------------------------------------------------ */
/* Not configured: quiet null, never an error.                          */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_options']     = array();
$GLOBALS['insightistic_test_http_queue']  = array();
$GLOBALS['insightistic_test_http_calls']  = array();

assert_same( null, $cf->get_sync_payload(), 'no BYO credentials returns null (quiet skip)' );

/* ------------------------------------------------------------------ */
/* Happy path: traffic + firewall + bots all answer.                    */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_options']['insightistic_cloudflare_zone_id'] = 'zone123abc';
$GLOBALS['insightistic_test_options']['insightistic_cloudflare_api_token_enc'] = Insightistic_Encryption::encrypt( 'cf-token-value' );

$day1 = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
$day2 = gmdate( 'Y-m-d', strtotime( '-2 day' ) );

queue_http( 200, wp_json_encode( array(
	'data' => array( 'viewer' => array( 'zones' => array( array(
		'httpRequests1dGroups' => array(
			array(
				'dimensions' => array( 'date' => $day2 ),
				'sum'        => array(
					'requests'        => 100,
					'bytes'           => 1000,
					'cachedRequests'  => 60,
					'cachedBytes'     => 600,
					'encryptedRequests' => 99,
					'pageViews'       => 20,
					'threats'         => 2,
					'countryMap'      => array(
						array( 'clientCountryName' => 'US', 'requests' => 70 ),
						array( 'clientCountryName' => 'DE', 'requests' => 30 ),
					),
					'responseStatusMap' => array(
						array( 'edgeResponseStatus' => 200, 'requests' => 95 ),
						array( 'edgeResponseStatus' => 404, 'requests' => 5 ),
					),
				),
			),
			array(
				'dimensions' => array( 'date' => $day1 ),
				'sum'        => array(
					'requests'        => 50,
					'bytes'           => 500,
					'cachedRequests'  => 25,
					'cachedBytes'     => 250,
					'encryptedRequests' => 50,
					'pageViews'       => 10,
					'threats'         => 0,
					'countryMap'      => array( array( 'clientCountryName' => 'US', 'requests' => 50 ) ),
					'responseStatusMap' => array( array( 'edgeResponseStatus' => 200, 'requests' => 50 ) ),
				),
			),
		),
	) ) ) ),
) ) );

queue_http( 200, wp_json_encode( array(
	'data' => array( 'viewer' => array( 'zones' => array( array(
		'firewallEventsAdaptiveGroups' => array(
			array( 'dimensions' => array( 'date' => $day2, 'action' => 'block' ), 'count' => 4 ),
			array( 'dimensions' => array( 'date' => $day2, 'action' => 'block' ), 'count' => 1 ),
			array( 'dimensions' => array( 'date' => $day1, 'action' => 'challenge' ), 'count' => 2 ),
		),
	) ) ) ),
) ) );

queue_http( 200, wp_json_encode( array(
	'data' => array( 'viewer' => array( 'zones' => array( array(
		'httpRequestsAdaptiveGroups' => array(
			array( 'dimensions' => array( 'date' => $day1, 'clientRequestUserAgent' => 'Mozilla/5.0 (compatible; GPTBot/1.0)' ), 'count' => 12 ),
			array( 'dimensions' => array( 'date' => $day1, 'clientRequestUserAgent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0' ), 'count' => 900 ),
			array( 'dimensions' => array( 'date' => $day1, 'clientRequestUserAgent' => '' ), 'count' => 55 ),
		),
	) ) ) ),
) ) );

$payload = $cf->get_sync_payload();

assert_same( 2, count( $payload['days'] ), 'two daily rows pushed' );
assert_same( true, $payload['bots_available'], 'bots dataset reported available' );
assert_same( 2, count( $payload['bots'] ), 'empty user agents skipped, raw UAs forwarded (classification is platform-side)' );

$first = $payload['days'][0];
assert_same( $day2, $first['date'], 'rows ordered date_ASC like the GraphQL orderBy' );
assert_same( 100, $first['requests'], 'requests forwarded' );
assert_same( 5, $first['client_4xx'], 'client_4xx derived from responseStatusMap' );
assert_same( array( 'US' => 70, 'DE' => 30 ), $first['countries'], 'country map normalized' );
assert_same( array( '200' => 95, '404' => 5 ), $first['status_breakdown'], 'status breakdown normalized' );
assert_same( array( 'block' => 5 ), $first['firewall'], 'firewall counts merged per (date, action)' );
assert_same( array( 'challenge' => 2 ), $payload['days'][1]['firewall'], 'second day firewall separate' );

assert_same( 3, count( $GLOBALS['insightistic_test_http_calls'] ), 'one request per dataset (traffic, firewall, bots)' );
assert_same( 'https://api.cloudflare.com/client/v4/graphql', $GLOBALS['insightistic_test_http_calls'][0]['url'], 'fixed Cloudflare GraphQL endpoint' );

/* The Zone ID must ride along as the zoneTag variable, never in the URL. */
$traffic_vars = wp_json_encode( $GLOBALS['insightistic_test_http_calls'][0]['args']['body'] );
assert_same( true, false !== strpos( $traffic_vars, 'zone123abc' ), 'zone id sent as GraphQL variable' );

/* ------------------------------------------------------------------ */
/* Plan-gated degradation: firewall + bots fail, push still succeeds.   */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_http_queue'] = array();
$GLOBALS['insightistic_test_http_calls'] = array();

queue_http( 200, wp_json_encode( array(
	'data' => array( 'viewer' => array( 'zones' => array( array(
		'httpRequests1dGroups' => array(
			array( 'dimensions' => array( 'date' => $day1 ), 'sum' => array( 'requests' => 7, 'pageViews' => 2 ) ),
		),
	) ) ) ),
) ) );
queue_http( 400, wp_json_encode( array( 'errors' => array( array( 'message' => 'failed to recognize query field firewallEventsAdaptiveGroups' ) ) ) ) );
queue_http( 400, wp_json_encode( array( 'errors' => array( array( 'message' => 'failed to recognize query field httpRequestsAdaptiveGroups' ) ) ) ) );

$payload = $cf->get_sync_payload();

assert_same( 1, count( $payload['days'] ), 'primary dataset still pushes' );
assert_same( array(), $payload['days'][0]['firewall'], 'degraded firewall becomes empty map' );
assert_same( false, $payload['bots_available'], 'bot dataset failure degrades, never fails the push' );
assert_same( array(), $payload['bots'], 'no bot rows when degraded' );

/* ------------------------------------------------------------------ */
/* Primary failure / empty zone: false and null respectively.           */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_http_queue'] = array();
$GLOBALS['insightistic_test_http_calls'] = array();

queue_http( 403, wp_json_encode( array( 'success' => false ) ) );
assert_same( false, $cf->get_sync_payload(), 'primary dataset failure returns false' );

$GLOBALS['insightistic_test_http_queue'] = array();
$GLOBALS['insightistic_test_http_calls'] = array();

queue_http( 200, wp_json_encode( array( 'data' => array( 'viewer' => array( 'zones' => array( array( 'httpRequests1dGroups' => array() ) ) ) ) ) ) );
queue_http( 200, '{}' );
queue_http( 200, '{}' );
assert_same( null, $cf->get_sync_payload(), 'zone with no rows in window returns null (nothing to push)' );

test_summary( 'cloudflare-sync' );
