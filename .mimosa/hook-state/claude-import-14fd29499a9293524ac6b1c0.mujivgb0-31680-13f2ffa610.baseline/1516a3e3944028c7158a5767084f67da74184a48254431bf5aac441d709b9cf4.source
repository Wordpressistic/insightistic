<?php
/**
 * Shared assertion + harness helpers for Insightistic standalone tests.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- test harness, WordPress is not loaded.

if ( ! defined( 'INSIGHTISTIC_TESTS' ) ) {
	define( 'INSIGHTISTIC_TESTS', true );
}

$GLOBALS['__test_pass'] = 0;
$GLOBALS['__test_fail'] = array();

/**
 * Record a passing assertion.
 */
function pass( $label ) {
	$GLOBALS['__test_pass']++;
	echo "  PASS  {$label}\n";
}

/**
 * Record a failing assertion.
 */
function fail_test( $label, $detail = '' ) {
	$GLOBALS['__test_fail'][] = $label;
	echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

/**
 * Boolean assertion.
 */
function assert_true( $cond, $label ) {
	if ( $cond ) {
		pass( $label );
	} else {
		fail_test( $label );
	}
}

/**
 * Strict equality assertion with a readable diff.
 */
function assert_same( $expected, $actual, $label ) {
	if ( $expected === $actual ) {
		pass( $label );
	} else {
		fail_test( $label, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

/**
 * Float equality within epsilon.
 */
function assert_epsilon( $expected, $actual, $label, $eps = 0.0001 ) {
	if ( is_numeric( $actual ) && abs( (float) $expected - (float) $actual ) <= $eps ) {
		pass( $label );
	} else {
		fail_test( $label, 'expected ~' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

/**
 * Invoke a private method on an object.
 *
 * @param object $obj  Instance.
 * @param string $name Method name.
 * @param array  $args Positional arguments.
 * @return mixed
 */
function call_private( $obj, $name, array $args = array() ) {
	$ref = new ReflectionMethod( $obj, $name );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $obj, $args );
}

/**
 * Queue one canned HTTP response consumed by the wp_remote_post stub.
 *
 * @param int             $status HTTP status code.
 * @param string|array    $body   Raw body (usually JSON).
 */
function queue_http( $status, $body ) {
	$GLOBALS['insightistic_test_http_queue'][] = array( 'status' => $status, 'body' => $body );
}

/**
 * Queue an HTTP transport failure (returned as WP_Error by the stub).
 */
function queue_http_error() {
	$GLOBALS['insightistic_test_http_queue'][] = array( 'wp_error' => 'http_request_failed', 'message' => 'cURL error 7' );
}

/**
 * Load a JSON fixture as a decoded array.
 */
function fixture( $name ) {
	$raw = file_get_contents( __DIR__ . '/fixtures/ga4/' . $name . '.json' );
	$dec = json_decode( $raw, true );
	if ( ! is_array( $dec ) ) {
		fwrite( STDERR, "Fixture {$name} is not valid JSON.\n" );
		exit( 1 );
	}
	return $dec;
}

/**
 * Queue a fixture as a successful HTTP response.
 */
function queue_fixture( $name ) {
	queue_http( 200, json_encode( fixture( $name ) ) );
}

/**
 * Instantiate the GA class without running init().
 */
function ga_instance() {
	require_once __DIR__ . '/wp-stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-insightistic-ga.php';
	return new Insightistic_GA();
}

/**
 * Standard date arguments used by the report methods.
 */
function ga_dates() {
	return array( 'properties/123', 'tok', '2026-08-13', '2026-09-09', '2026-07-16', '2026-08-12' );
}

/**
 * Print a summary and exit with the right code.
 */
function test_summary( $suite ) {
	$failed = count( $GLOBALS['__test_fail'] );
	$total  = $GLOBALS['__test_pass'] + $failed;
	if ( $failed ) {
		echo "\n{$suite}: FAIL ({$GLOBALS['__test_pass']}/{$total} passed)\n";
		exit( 1 );
	}
	echo "\n{$suite}: PASS ({$total}/{$total})\n";
	exit( 0 );
}

/*
 * Convert PHP notices/warnings to test failures — the parsers must degrade
 * gracefully on malformed input rather than emit diagnostics.
 */
set_error_handler(
	static function ( $errno, $errstr, $errfile, $errline ) {
		fail_test( 'no PHP diagnostic expected', "{$errstr} in {$errfile}:{$errline}" );
		return true;
	},
	E_ALL & ~E_DEPRECATED
);
