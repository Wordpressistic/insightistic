<?php
/**
 * Deterministic release builder for Insightistic.
 *
 * Stages an allowlisted copy of the plugin into build/insightistic/ and zips
 * it into dist/insightistic.{VERSION}.zip (+ .sha256 checksum file).
 *
 * The ZIP contains exactly one root directory: insightistic/.
 *
 * Usage: php scripts/build-release.php
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI script, WordPress is not loaded.

require_once __DIR__ . '/zip-utils.php';

$root = dirname( __DIR__ );

if ( ! preg_match( "/define\(\s*'INSIGHTISTIC_VERSION',\s*'([^']+)'/", file_get_contents( $root . '/insightistic.php' ), $m ) ) {
	fwrite( STDERR, "Cannot read INSIGHTISTIC_VERSION.\n" );
	exit( 1 );
}
$version = $m[1];

$build_dir = $root . '/build/insightistic';
$dist_dir  = $root . '/dist';
$zip_path  = $dist_dir . '/insightistic.' . $version . '.zip';

/*
 * Production allowlist: everything the plugin needs at runtime and nothing
 * else. Directories are copied recursively.
 */
$allowlist = array(
	'insightistic.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'includes/',
	'templates/',
	'assets/',
	'languages/',
);

/* Development-only files that must never leak into the package. */
$deny_patterns = array(
	'#(^|/)\.git(/|$)#',
	'#(^|/)\.github(/|$)#',
	'#(^|/)node_modules(/|$)#',
	'#(^|/)vendor(/|$)#',
	'#(^|/)tests(/|$)#',
	'#(^|/)scripts(/|$)#',
	'#(^|/)build(/|$)#',
	'#(^|/)dist(/|$)#',
	'#\.map$#',
	'#\.src\.js$#',
	'#(^|/)\.env(\.|$)#',
	'#(^|/)\.(?!gitattributes|gitignore)[A-Za-z0-9_-]+($|/)#',
	'#composer\.(json|lock)$#',
	'#package(-lock)?\.json$#',
	'#phpcs\.xml(\.dist)?$#',
	'#\.vscode(/|$)#',
	'#\.idea(/|$)#',
	'#(^|/)composer\.phar$#',
);

/**
 * Recursive copy with deny-pattern filtering.
 *
 * @param string $src           Absolute source directory.
 * @param string $dst           Absolute destination directory.
 * @param array  $deny_patterns Regexes tested against paths relative to the
 *                              staged plugin root (e.g. "assets/js/x.map").
 */
function copy_filtered( $src, $dst, $deny_patterns ) {
	$items = scandir( $src );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$src_path = $src . '/' . $item;
		$dst_path = $dst . '/' . $item;

		// Deny patterns are checked against the path relative to the repo root.
		$rel = str_replace( '\\', '/', str_replace( dirname( __DIR__ ) . DIRECTORY_SEPARATOR, '', $src_path ) );
		foreach ( $deny_patterns as $re ) {
			if ( preg_match( $re, $rel ) ) {
				continue 2;
			}
		}

		if ( is_dir( $src_path ) ) {
			if ( ! is_dir( $dst_path ) ) {
				mkdir( $dst_path, 0755, true );
			}
			copy_filtered( $src_path, $dst_path, $deny_patterns );
		} else {
			copy( $src_path, $dst_path );
		}
	}
}

// Clean previous build output.
if ( is_dir( $root . '/build' ) ) {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/build', FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
} else {
	mkdir( $root . '/build', 0755, true );
}
mkdir( $build_dir, 0755, true );
if ( ! is_dir( $dist_dir ) ) {
	mkdir( $dist_dir, 0755, true );
}

$missing = array();
foreach ( $allowlist as $entry ) {
	$src = $root . '/' . rtrim( $entry, '/' );
	if ( ! file_exists( $src ) ) {
		$missing[] = $entry;
		continue;
	}
	if ( is_dir( $src ) ) {
		mkdir( $build_dir . '/' . basename( $src ), 0755, true );
		copy_filtered( $src, $build_dir . '/' . basename( $src ), $deny_patterns );
	} else {
		copy( $src, $build_dir . '/' . $entry );
	}
}

if ( $missing ) {
	fwrite( STDERR, "Allowlisted source(s) missing: " . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}

if ( ! class_exists( 'Insightistic_Zip_Writer' ) ) {
	fwrite( STDERR, "ZIP writer unavailable.\n" );
	exit( 1 );
}

$zip = new Insightistic_Zip_Writer();

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $build_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

$count = 0;
foreach ( $files as $file ) {
	$archive = 'insightistic/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $build_dir ) + 1 ) );
	$zip->add_file( $archive, file_get_contents( $file->getPathname() ) );
	$count++;
}

file_put_contents( $zip_path, $zip->finish() );

$sha256 = hash_file( 'sha256', $zip_path );
file_put_contents( $zip_path . '.sha256', $sha256 . '  ' . basename( $zip_path ) . "\n" );

printf(
	"Built %s (%d bytes, %d entries)\nSHA-256: %s\n",
	$zip_path,
	filesize( $zip_path ),
	$count,
	$sha256
);
