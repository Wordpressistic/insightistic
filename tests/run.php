<?php
/**
 * Insightistic standalone test runner.
 *
 * Runs every tests/test-*.php suite in a fresh PHP process and reports a
 * combined result. Exits non-zero when any suite fails.
 *
 * Usage: php tests/run.php
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- test runner, WordPress is not loaded.

$suites = array(
	'test-encryption.php',
	'test-ga4-named-ranges.php',
	'test-ga4-legacy.php',
	'test-ga4-edge-cases.php',
	'test-platform-bridge.php',
);

$php    = PHP_BINARY;
$dir    = __DIR__;
$failed = array();

echo "Insightistic test suite\n=======================\n\n";

foreach ( $suites as $suite ) {
	echo ">>> {$suite}\n";
	passthru( '"' . $php . '" "' . $dir . DIRECTORY_SEPARATOR . $suite . '" 2>&1', $code );
	if ( 0 !== $code ) {
		$failed[] = $suite;
	}
	echo "\n";
}

if ( $failed ) {
	echo "TESTS: FAIL (" . count( $failed ) . '/' . count( $suites ) . " suites failed: " . implode( ', ', $failed ) . ")\n";
	exit( 1 );
}
echo "TESTS: PASS (" . count( $suites ) . " suites)\n";
