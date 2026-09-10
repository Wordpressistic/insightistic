<?php
/**
 * Validate that minified assets exist, are non-empty, are UTF-8 without BOM,
 * and are at least as new as their sources (the enqueue logic serves .min
 * files only while filemtime(min) >= filemtime(src)).
 *
 * Byte-exact freshness is enforced by `npm run check:assets`
 * (scripts/build-assets.mjs --check), which re-minifies and compares.
 *
 * Usage: php scripts/validate-assets.php
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI script, WordPress is not loaded.

$root = dirname( __DIR__ );

$pairs = array(
	array( 'assets/css/admin.css', 'assets/css/admin.min.css' ),
	array( 'assets/js/admin.js', 'assets/js/admin.min.js' ),
	array( 'assets/js/tracking.js', 'assets/js/tracking.min.js' ),
);

$fail = false;

foreach ( $pairs as list( $src, $min ) ) {
	$src_path = $root . '/' . $src;
	$min_path = $root . '/' . $min;

	if ( ! file_exists( $min_path ) ) {
		echo "FAIL {$min}: missing\n";
		$fail = true;
		continue;
	}
	$size = filesize( $min_path );
	if ( $size < 10 ) {
		echo "FAIL {$min}: suspiciously small ({$size} bytes)\n";
		$fail = true;
		continue;
	}
	$head = file_get_contents( $min_path, false, null, 0, 3 );
	if ( 0 === strncmp( $head, "\xEF\xBB\xBF", 3 ) ) {
		echo "FAIL {$min}: starts with a UTF-8 BOM\n";
		$fail = true;
		continue;
	}
	if ( filesize( $src_path ) <= $size ) {
		echo "FAIL {$min}: not smaller than its source (src=" . filesize( $src_path ) . ", min={$size})\n";
		$fail = true;
		continue;
	}
	if ( filemtime( $min_path ) < filemtime( $src_path ) ) {
		echo "FAIL {$min}: older than {$src} — enqueue logic would fall back to the source file\n";
		$fail = true;
		continue;
	}
	echo "OK   {$min} ({$size} bytes)\n";
}

echo $fail ? "\nASSETS: FAIL\n" : "\nASSETS: PASS\n";
exit( $fail ? 1 : 0 );
