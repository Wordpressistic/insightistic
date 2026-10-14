<?php
/**
 * PHP syntax lint across all production PHP files.
 *
 * Usage: php scripts/lint-php.php
 * Exits non-zero when any file fails `php -l`.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI script, WordPress is not loaded.

$root = dirname( __DIR__ );

$dirs  = array( $root, $root . '/includes', $root . '/templates' );
$files = array();
foreach ( $dirs as $dir ) {
	foreach ( glob( $dir . '/*.php' ) ?: array() as $f ) {
		$files[] = $f;
	}
}
// Scripts and tests lint too (excluding vendored tooling).
foreach ( glob( $root . '/scripts/*.php' ) ?: array() as $f ) {
	$files[] = $f;
}
foreach ( glob( $root . '/tests/*.php' ) ?: array() as $f ) {
	$files[] = $f;
}

$failed = 0;
foreach ( $files as $file ) {
	$rel   = str_replace( $root . DIRECTORY_SEPARATOR, '', $file );
	$out   = array();
	$code  = 0;
	exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		$failed++;
		echo "FAIL {$rel}\n" . implode( "\n", $out ) . "\n";
	} else {
		echo "OK   {$rel}\n";
	}
}

echo $failed ? "\nPHP LINT: FAIL ({$failed} files)\n" : "\nPHP LINT: PASS (" . count( $files ) . " files)\n";
exit( $failed ? 1 : 0 );
