<?php
/**
 * GA4 regression suite C — hostile / edge-case payloads.
 *
 * Zero previous values, empty results, missing metrics, malformed bodies,
 * API errors, current-only and previous-only responses, division-by-zero,
 * numeric strings, and unexpected extra dimensions. The parsers must
 * degrade gracefully: no PHP diagnostics, no crashes, sane values.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require __DIR__ . '/lib.php';

echo "== GA4 Test C: edge cases ==\n";

/**
 * Build a named-range overview response with arbitrary metric sets.
 */
function ov_named( $cur_metrics, $prev_metrics ) {
	return array(
		'rows' => array(
			array( 'dimensionValues' => array( array( 'value' => 'current' ) ), 'metricValues' => $cur_metrics ),
			array( 'dimensionValues' => array( array( 'value' => 'previous' ) ), 'metricValues' => $prev_metrics ),
		),
	);
}

/**
 * Metric helper: eight-value current/previous sets by default.
 */
function m( $v ) {
	return array( array( 'value' => (string) $v ) );
}

function full_metrics( $vals ) {
	$out = array();
	foreach ( $vals as $v ) {
		$out[] = array( 'value' => (string) $v );
	}
	return $out;
}

/* 1. Zero previous value -> +100 sentinel, never division by zero. */
echo "- zero previous period\n";
$ga = ga_instance();
$GLOBALS['insightistic_test_http_queue'] = array();
queue_http( 200, json_encode( ov_named(
	full_metrics( array( 500, 300, 0, 2, 900, 45.5, 0.30, 150 ) ),
	full_metrics( array( 0, 0, 0, 0, 0, 0, 0, 0 ) )
) ) );
$ov = call_private( $ga, 'get_overview', ga_dates() );
assert_epsilon( 100, $ov['sessions']['change'], 'zero previous + positive current -> +100' );
assert_same( 500, $ov['sessions']['value'], 'current value parsed with zero previous' );

/* 2. Zero current AND zero previous -> 0 change, not NaN/INF. */
echo "- zero previous and zero current\n";
$ga = ga_instance();
queue_http( 200, json_encode( ov_named(
	full_metrics( array( 0, 0, 0, 0, 0, 0, 0, 0 ) ),
	full_metrics( array( 0, 0, 0, 0, 0, 0, 0, 0 ) )
) ) );
$ov = call_private( $ga, 'get_overview', ga_dates() );
assert_epsilon( 0, $ov['sessions']['change'], '0/0 -> 0 change (no division-by-zero)' );
assert_epsilon( 0.0, $ov['bounce_rate']['value'], '0 bounce rate renders 0' );

/* 3. Empty result set. */
echo "- empty result (no rows/totals)\n";
$ga = ga_instance();
queue_http( 200, json_encode( array( 'rowCount' => 0 ) ) );
assert_same( null, call_private( $ga, 'get_overview', ga_dates() ), 'overview: empty payload -> null' );
$ga = ga_instance();
queue_http( 200, json_encode( array( 'rows' => array(), 'rowCount' => 0 ) ) );
assert_same( array(), call_private( $ga, 'get_traffic_channels', ga_dates() ), 'channels: empty rows -> []' );

/* 4. Missing metrics (fewer metricValues than requested). */
echo "- missing metric entries\n";
$ga = ga_instance();
queue_http( 200, json_encode( ov_named(
	full_metrics( array( 500, 300, 0, 2 ) ), // metrics 4..7 missing entirely
	full_metrics( array( 400, 250, 0, 1 ) )
) ) );
$ov = call_private( $ga, 'get_overview', ga_dates() );
assert_same( 500, $ov['sessions']['value'], 'present metrics parsed when later ones are missing' );
assert_same( 0, $ov['pageviews']['value'], 'missing pageviews degrades to 0' );
assert_same( '0:00', $ov['avg_duration']['value'], 'missing avg duration degrades to 0:00' );
assert_epsilon( 0.0, $ov['bounce_rate']['value'], 'missing bounce rate degrades to 0' );
assert_epsilon( 0, $ov['new_vs_return']['new_pct'], 'missing newUsers degrades to 0%' );

/* 5. Malformed responses. */
echo "- malformed response bodies\n";
$ga = ga_instance();
queue_http( 200, '<html>gateway error</html>' ); // non-JSON body
assert_same( null, call_private( $ga, 'get_overview', ga_dates() ), 'non-JSON body -> null, no crash' );

$ga = ga_instance();
queue_http( 200, json_encode( array( 'rows' => 'not-an-array' ) ) ); // rows wrong type
assert_same( array(), call_private( $ga, 'get_traffic_channels', ga_dates() ), 'rows of wrong type -> [] (cast), no diagnostics' );

$ga = ga_instance();
queue_http( 200, json_encode( array( 'rows' => array( array( 'dimensionValues' => 'oops' ) ) ) ) ); // malformed row
assert_same( null, call_private( $ga, 'get_overview', ga_dates() ), 'malformed row -> null, no crash' );

/* 6. API error paths. */
echo "- API error responses\n";
$ga = ga_instance();
queue_http_error();
queue_http_error();
queue_http_error();
assert_same( null, call_private( $ga, 'get_overview', ga_dates() ), 'transport failure -> null (WP_Error upstream)' );

$ga = ga_instance();
for ( $i = 0; $i < 3; $i++ ) {
	queue_http( 429, json_encode( array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded' ) ) ) );
}
assert_same( null, call_private( $ga, 'get_overview', ga_dates() ), '429 quota after retries -> null' );

/* 7. Current-only response (previous row absent). */
echo "- current-only response\n";
$ga = ga_instance();
queue_http( 200, json_encode( array(
	'rows' => array(
		array( 'dimensionValues' => array( array( 'value' => 'current' ) ), 'metricValues' => full_metrics( array( 700, 500, 0, 3, 2100, 100.0, 0.35, 300 ) ) ),
	),
) ) );
$ov = call_private( $ga, 'get_overview', ga_dates() );
assert_same( 700, $ov['sessions']['value'], 'current-only: value parsed' );
assert_epsilon( 0.0, $ov['sessions']['change'], 'current-only: previous falls back to current -> 0% change (no N/A)' );

/* 8. Previous-only response (no current row at all). */
echo "- previous-only response\n";
$ga = ga_instance();
queue_http( 200, json_encode( array(
	'rows' => array(
		array( 'dimensionValues' => array( array( 'value' => 'previous' ) ), 'metricValues' => full_metrics( array( 700, 500, 0, 3, 2100, 100.0, 0.35, 300 ) ) ),
	),
) ) );
assert_same( null, call_private( $ga, 'get_overview', ga_dates() ), 'previous-only: overview -> null (nothing to show)' );

/* 9. Numeric strings are coerced exactly like the API sends them. */
echo "- numeric string coercion\n";
$ga = ga_instance();
queue_http( 200, json_encode( ov_named(
	array(
		array( 'value' => '0130' ), array( 'value' => '012' ), array( 'value' => '99.999' ),
		array( 'value' => '1' ), array( 'value' => '0500' ), array( 'value' => '65.5' ),
		array( 'value' => '0.10' ), array( 'value' => '007' ),
	),
	array(
		array( 'value' => '100' ), array( 'value' => '10' ), array( 'value' => '50' ),
		array( 'value' => '2' ), array( 'value' => '400' ), array( 'value' => '60' ),
		array( 'value' => '0.20' ), array( 'value' => '005' ),
	)
) ) );
$ov = call_private( $ga, 'get_overview', ga_dates() );
assert_same( 130, $ov['sessions']['value'], "'0130' coerced to 130" );
assert_epsilon( 30.0, $ov['sessions']['change'], 'change computed from numeric strings' );
assert_epsilon( 100.0, $ov['revenue']['value'], 'revenue float string rounded to 2dp (99.999 -> 100.0)' );
assert_same( 7, $ov['new_vs_return']['new_count'], "'007' coerced to 7" );
assert_epsilon( 58.3, $ov['new_vs_return']['new_pct'], 'new_pct from coerced counts (7/12)' );

/* 10. Unexpected extra dimensions after the range dimension. */
echo "- unexpected extra dimensions\n";
$ga = ga_instance();
queue_http( 200, json_encode( array(
	'rows' => array(
		array(
			'dimensionValues' => array( array( 'value' => 'current' ), array( 'value' => 'sessionSource' ), array( 'value' => 'unplanned' ) ),
			'metricValues'    => full_metrics( array( 900, 600, 0, 4, 2500, 120.0, 0.30, 350 ) ),
		),
		array(
			'dimensionValues' => array( array( 'value' => 'previous' ), array( 'value' => 'sessionSource' ), array( 'value' => 'unplanned' ) ),
			'metricValues'    => full_metrics( array( 800, 550, 0, 4, 2300, 110.0, 0.31, 320 ) ),
		),
	),
) ) );
$ov = call_private( $ga, 'get_overview', ga_dates() );
assert_same( 900, $ov['sessions']['value'], 'extra dimensions ignored, range dim still [0]' );
assert_epsilon( 12.5, $ov['sessions']['change'], 'change correct with extra dims present' );

test_summary( 'GA4 EDGE-CASE TESTS' );
