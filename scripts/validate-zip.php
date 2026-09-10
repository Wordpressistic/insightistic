<?php
/**
 * Release ZIP validator for Insightistic.
 *
 * Opens dist/insightistic.{VERSION}.zip and enforces the production package
 * contract. Exits non-zero on any violation.
 *
 * Checks:
 *   - exactly one root directory: insightistic/
 *   - required runtime files present (plugin main file, readme.txt, LICENSE,
 *     uninstall.php, includes/, templates/, assets/, languages/)
 *   - no development artifacts (.git, .github, node_modules, vendor, tests,
 *     build tooling, source maps, *.src.js, dotfiles, lock files, etc.)
 *   - packaged plugin header + runtime constant + readme stable tag all equal
 *     the version in the ZIP filename
 *   - packaged Chart.js registration still reports the bundled dependency
 *     version (not the plugin version)
 *
 * Usage: php scripts/validate-zip.php [zip-path]
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI script, WordPress is not loaded.

require_once __DIR__ . '/zip-utils.php';

$root = dirname( __DIR__ );

$zip_path = isset( $argv[1] ) ? $argv[1] : null;
if ( ! $zip_path ) {
	if ( ! preg_match( "/define\(\s*'INSIGHTISTIC_VERSION',\s*'([^']+)'/", file_get_contents( $root . '/insightistic.php' ), $m ) ) {
		fwrite( STDERR, "Cannot read INSIGHTISTIC_VERSION.\n" );
		exit( 1 );
	}
	$zip_path = $root . '/dist/insightistic.' . $m[1] . '.zip';
}
if ( ! file_exists( $zip_path ) ) {
	fwrite( STDERR, "ZIP not found: {$zip_path}\n" );
	exit( 1 );
}

$version = preg_match( '/insightistic\.([0-9][0-9A-Za-z.\-]*)\.zip$/', $zip_path, $vm )
	? $vm[1]
	: null;

$zip = false;
try {
	$zip = new Insightistic_Zip_Reader( $zip_path );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, "Cannot open ZIP: {$e->getMessage()}\n" );
	exit( 1 );
}

$failures  = array();
$roots     = array();
$entries   = $zip->names();

$zip_count = $zip->count();
echo "Validating " . basename( $zip_path ) . " ({$zip_count} entries)\n";

/* 1. Single root directory. */
foreach ( $entries as $e ) {
	$parts = explode( '/', trim( $e, '/' ) );
	if ( count( $parts ) > 1 ) {
		$roots[ $parts[0] ] = true;
	}
}
if ( array( 'insightistic' ) !== array_keys( $roots ) ) {
	$failures[] = 'Archive must contain exactly one root directory "insightistic/", found: ' . implode( ', ', array_keys( $roots ) );
} else {
	echo "OK   single root directory: insightistic/\n";
}

/* 2. Required files. */
$required = array(
	'insightistic/insightistic.php',
	'insightistic/uninstall.php',
	'insightistic/readme.txt',
	'insightistic/LICENSE',
	'insightistic/languages/insightistic.pot',
	'insightistic/assets/js/tracking.min.js',
	'insightistic/assets/js/admin.min.js',
	'insightistic/assets/css/admin.min.css',
	'insightistic/assets/js/vendor/chart.umd.min.js',
);
foreach ( $required as $req ) {
	if ( ! in_array( $req, $entries, true ) ) {
		$failures[] = "Required file missing from ZIP: {$req}";
	}
}
$has_includes = false;
$has_templates = false;
foreach ( $entries as $e ) {
	if ( 0 === strpos( $e, 'insightistic/includes/' ) ) { $has_includes = true; }
	if ( 0 === strpos( $e, 'insightistic/templates/' ) ) { $has_templates = true; }
}
if ( ! $has_includes ) { $failures[] = 'includes/ directory missing from ZIP'; }
if ( ! $has_templates ) { $failures[] = 'templates/ directory missing from ZIP'; }
if ( ! $failures ) {
	echo "OK   required runtime files present\n";
}

/* 3. Forbidden development artifacts. */
$forbidden_res = array(
	'#(^|/)\.git(/|$)#'                 => '.git metadata',
	'#(^|/)\.github(/|$)#'              => 'CI configuration',
	'#(^|/)node_modules(/|$)#'          => 'node_modules',
	'#(^|/)vendor(/|$)#'                => 'Composer vendor',
	'#(^|/)tests(/|$)#'                 => 'tests',
	'#(^|/)scripts(/|$)#'               => 'build scripts',
	'#\.map$#'                          => 'source maps',
	'#\.src\.js$#'                      => 'debug source files (*.src.js)',
	'#(^|/)\.env(\.|$)#'                => '.env files',
	'#composer\.(json|lock)$#'          => 'composer files',
	'#package(-lock)?\.json$#'          => 'npm files',
	'#phpcs\.xml(\.dist)?$#'            => 'PHPCS config',
	'#(^|/)README\.md$#'                => 'developer README (readme.txt ships instead)',
	'#\.vscode(/|$)|\.idea(/|$)#'       => 'IDE configuration',
);
$found_forbidden = array();
foreach ( $entries as $e ) {
	foreach ( $forbidden_res as $re => $label ) {
		if ( preg_match( $re, trim( $e, '/' ) ) ) {
			$found_forbidden[ $label ][] = $e;
		}
	}
}
if ( $found_forbidden ) {
	foreach ( $found_forbidden as $label => $list ) {
		$failures[] = "Forbidden content ({$label}): " . implode( ', ', array_slice( $list, 0, 5 ) );
	}
} else {
	echo "OK   no development artifacts\n";
}

/* 4. Version agreement inside the packaged plugin. */
$plugin_src = $zip->contents( 'insightistic/insightistic.php' );
$readme_src = $zip->contents( 'insightistic/readme.txt' );
if ( false === $plugin_src || false === $readme_src ) {
	$failures[] = 'Cannot read packaged insightistic.php/readme.txt for version inspection.';
} else {
	if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)\s*$/im', $plugin_src, $hm ) ) {
		$failures[] = 'Packaged plugin header has no Version.';
	}
	if ( ! preg_match( "/define\(\s*'INSIGHTISTIC_VERSION',\s*'([^']+)'/", $plugin_src, $cm ) ) {
		$failures[] = 'Packaged plugin has no INSIGHTISTIC_VERSION.';
	}
	if ( ! preg_match( '/^Stable tag:\s*([0-9][0-9A-Za-z.\-]*)\s*$/im', $readme_src, $sm ) ) {
		$failures[] = 'Packaged readme.txt has no Stable tag.';
	}
	if ( isset( $hm[1], $cm[1], $sm[1] ) ) {
		$vals = array( 'header' => $hm[1], 'constant' => $cm[1], 'stable' => $sm[1] );
		foreach ( $vals as $label => $v ) {
			if ( $version && $v !== $version ) {
				$failures[] = "Packaged {$label} version ({$v}) != ZIP filename version ({$version})";
			}
		}
		if ( 1 !== count( array_unique( $vals ) ) ) {
			$failures[] = 'Packaged versions disagree: ' . json_encode( $vals );
		} else {
			echo "OK   packaged versions agree ({$hm[1]})\n";
		}
	}

	/* 5. Chart.js dependency version guard inside the package. */
	$admin_src = $zip->contents( 'insightistic/includes/class-insightistic-admin.php' );
	if ( false === $admin_src || ! preg_match( "/wp_register_script\(\s*'insightistic-chartjs'.*?'(\d+\.\d+\.\d+)'\s*,\s*true/s", $admin_src, $gm ) ) {
		$failures[] = 'Cannot verify Chart.js registration inside package.';
	} elseif ( $version && $gm[1] === $version && '4.4.4' !== $version ) {
		$failures[] = 'Chart.js dependency version appears overwritten with plugin version.';
	} else {
		echo "OK   Chart.js dependency version retained ({$gm[1]})\n";
	}
}

/* 6. Checksum file consistency. */
$sha_file = $zip_path . '.sha256';
if ( file_exists( $sha_file ) ) {
	$expected = hash_file( 'sha256', $zip_path );
	$recorded = trim( (string) file_get_contents( $sha_file ) );
	if ( 0 !== strpos( $recorded, $expected ) ) {
		$failures[] = "Checksum file does not match the ZIP (expected {$expected}).";
	} else {
		echo "OK   checksum file matches\n";
	}
} else {
	echo "note: no checksum file next to ZIP (fine in CI; the release job writes one)\n";
}

if ( $failures ) {
	echo "\nZIP VALIDATION: FAIL\n";
	foreach ( $failures as $f ) {
		echo " - {$f}\n";
	}
	exit( 1 );
}
echo "\nZIP VALIDATION: PASS\n";
