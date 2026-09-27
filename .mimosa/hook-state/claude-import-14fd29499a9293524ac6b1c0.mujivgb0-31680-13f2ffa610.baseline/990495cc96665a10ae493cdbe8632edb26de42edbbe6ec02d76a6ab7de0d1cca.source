<?php
/**
 * Insightistic release version contract validator.
 *
 * Fails (exit 1) when any release metadata source disagrees with the others,
 * or with an explicitly expected version (e.g. derived from the git tag in CI).
 *
 * Usage:
 *   php scripts/validate-release.php [expected-version]
 *
 * Without an argument the expected version is taken from the plugin header.
 *
 * Also asserts that the bundled Chart.js dependency version (4.4.4) has NOT
 * been mistakenly rewritten to the plugin version — this was a hazard during
 * the 4.4.2 repair.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile WordPress.WP.EnqueuedResources -- CLI script, no WP loaded.

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$root = dirname( __DIR__ );

$failures = array();
$notes    = array();

/**
 * Read the "Version:" header from the main plugin file.
 */
function plugin_header_version( $root ) {
	$source = file_get_contents( $root . '/insightistic.php' );
	if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)\s*$/im', $source, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Read the INSIGHTISTIC_VERSION runtime constant.
 */
function runtime_constant_version( $root ) {
	$source = file_get_contents( $root . '/insightistic.php' );
	if ( ! preg_match( "/define\(\s*'INSIGHTISTIC_VERSION',\s*'([^']+)'/", $source, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Read the "Stable tag:" from readme.txt.
 */
function readme_stable_tag( $root ) {
	$source = file_get_contents( $root . '/readme.txt' );
	if ( ! preg_match( '/^Stable tag:\s*([0-9][0-9A-Za-z.\-]*)\s*$/im', $source, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Read the Project-Id-Version from the POT template.
 */
function pot_project_version( $root ) {
	$path = $root . '/languages/insightistic.pot';
	if ( ! file_exists( $path ) ) {
		return null;
	}
	$source = file_get_contents( $path );
	if ( ! preg_match( '/^"Project-Id-Version:\s*Insightistic\s+([0-9][0-9A-Za-z.\-]*)\\\\n"$/im', $source, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Read the version from package.json.
 */
function package_json_version( $root ) {
	$raw = file_get_contents( $root . '/package.json' );
	$json = json_decode( $raw, true );
	return is_array( $json ) && isset( $json['version'] ) ? $json['version'] : null;
}

/**
 * Read the Chart.js dependency version registered in the admin class.
 */
function chartjs_registered_version( $root ) {
	$source = file_get_contents( $root . '/includes/class-insightistic-admin.php' );
	if ( ! preg_match( "/wp_register_script\(\s*'insightistic-chartjs'.*?'(\d+\.\d+\.\d+)'\s*,\s*true/s", $source, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Verify the bundled Chart.js vendor file really is the claimed version.
 *
 * The jsdelivr UMD bundle opens with a banner comment:
 *   "Original file: /npm/chart.js@X.Y.Z/dist/chart.umd.js" and
 *   "Chart.js vX.Y.Z".
 */
function chartjs_vendor_version( $root ) {
	$source = file_get_contents( $root . '/assets/js/vendor/chart.umd.min.js' );
	if ( preg_match( '/Chart\.js v(\d+\.\d+\.\d+)/', $source, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '#chart\.js@(\d+\.\d+\.\d+)#', $source, $m ) ) {
		return $m[1];
	}
	return null;
}

$header  = plugin_header_version( $root );
$const   = runtime_constant_version( $root );
$stable  = readme_stable_tag( $root );
$pot     = pot_project_version( $root );
$pkg     = package_json_version( $root );

$expected = isset( $argv[1] ) ? $argv[1] : $header;

$sources = array(
	'insightistic.php plugin header' => $header,
	'INSIGHTISTIC_VERSION constant'  => $const,
	'readme.txt Stable tag'          => $stable,
	'POT Project-Id-Version'         => $pot,
	'package.json version'           => $pkg,
);

echo "== Insightistic release version contract ==\n";
foreach ( $sources as $label => $value ) {
	$status = ( $value === $expected ) ? 'PASS' : 'FAIL';
	if ( 'PASS' !== $status ) {
		$failures[] = sprintf( '%s: expected %s, found %s', $label, $expected, var_export( $value, true ) );
	}
	printf( "%-32s %-10s %s\n", $label, $status, null === $value ? '(missing)' : $value );
}

/*
 * Guard the Chart.js dependency version: the 4.4.4 string in
 * class-insightistic-admin.php is the bundled Chart.js release, NOT the
 * Insightistic plugin version. It must not be rewritten during releases.
 */
$chart_reg   = chartjs_registered_version( $root );
$chart_file  = chartjs_vendor_version( $root );
printf( "%-32s %-10s %s\n", 'Chart.js registered version', ( $chart_reg ? 'PASS' : 'FAIL' ), $chart_reg ?: '(missing)' );
printf( "%-32s %-10s %s\n", 'Chart.js bundled vendor file', ( $chart_file ? 'PASS' : 'FAIL' ), $chart_file ?: '(missing)' );
if ( ! $chart_reg || ! $chart_file ) {
	$failures[] = 'Chart.js version registration could not be verified.';
} elseif ( $chart_reg !== $chart_file ) {
	$failures[] = sprintf( 'Chart.js registration (%s) does not match bundled vendor file (%s).', $chart_reg, $chart_file );
} elseif ( $chart_reg === $expected && '4.4.4' !== $expected ) {
	$failures[] = sprintf( 'Chart.js registration was overwritten with the plugin version (%s). It must remain the dependency version.', $chart_reg );
}

/*
 * readme.txt must document the current version in its changelog, without
 * rewriting history: the changelog entry must exist for $expected.
 */
$readme = file_get_contents( $root . '/readme.txt' );
if ( ! preg_match( '/^= ' . preg_quote( $expected, '/' ) . ' \(/m', $readme ) ) {
	$failures[] = "readme.txt changelog is missing a '= {$expected} (date) =' entry.";
} else {
	$notes[] = "readme.txt changelog entry for {$expected} present.";
}

if ( ! empty( $failures ) ) {
	echo "\nRESULT: FAIL\n";
	foreach ( $failures as $f ) {
		echo " - {$f}\n";
	}
	exit( 1 );
}

echo "\nRESULT: PASS — all version sources agree on {$expected}.\n";
foreach ( $notes as $n ) {
	echo " note: {$n}\n";
}
