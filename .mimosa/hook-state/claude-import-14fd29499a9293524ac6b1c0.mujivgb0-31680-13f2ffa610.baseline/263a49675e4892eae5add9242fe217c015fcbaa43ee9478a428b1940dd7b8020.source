<?php
/**
 * Build installable ZIPs of previous releases from git tags for the upgrade
 * gate: 4.4.0 -> 4.4.2 and 4.4.1 -> 4.4.2.
 *
 * Usage: php tests/docker/make-older-zips.php
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI tooling, WordPress is not loaded.

require_once dirname( __DIR__, 2 ) . '/scripts/zip-utils.php';

$repo   = dirname( __DIR__, 2 );
$stage  = __DIR__ . '/olders';
@mkdir( $stage, 0755, true );

$allow = array( 'insightistic.php', 'uninstall.php', 'readme.txt', 'includes', 'templates', 'assets', 'languages' );

/**
 * Recursively delete a directory (portable, no shell).
 */
function rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}

foreach ( array( 'insightistic-4.4.0', 'insightistic-4.4.1' ) as $tag ) {
	$ver = substr( $tag, strlen( 'insightistic-' ) );
	$tmp = $stage . '/tmp-' . $ver . '-' . substr( (string) microtime( true ), -6 );
	rrmdir( $stage . '/tmp-' . $ver ); // legacy leftovers from earlier runs.
	mkdir( $tmp, 0755, true );

	$tar = $stage . '/' . $tag . '.tar';
	if ( file_exists( $tar ) ) {
		unlink( $tar );
	}
	exec( 'git -C ' . escapeshellarg( $repo ) . ' archive ' . escapeshellarg( $tag ) . ' -o ' . escapeshellarg( $tar ), $out, $code );
	if ( 0 !== $code || ! file_exists( $tar ) ) {
		fwrite( STDERR, "git archive failed for {$tag}\n" );
		exit( 1 );
	}

	// Extract with PharData (handles tar transparently).
	$phar = new PharData( $tar );
	$phar->extractTo( $tmp, null, true );

	$inner = $tmp . '/insightistic';
	mkdir( $inner, 0755, true );
	foreach ( $allow as $item ) {
		$src = $tmp . '/' . $item;
		if ( ! file_exists( $src ) ) {
			continue;
		}
		// Recursive copy (separator-safe on Windows).
		$dst = $inner . '/' . $item;
		if ( is_dir( $src ) ) {
			$norm = str_replace( '\\', '/', $src );
			$norm_len = strlen( $norm );
			mkdir( $dst, 0755, true );
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $f ) {
				$rel = substr( str_replace( '\\', '/', $f->getPathname() ), $norm_len + 1 );
				$target = $dst . '/' . $rel;
				$f->isDir() ? @mkdir( $target, 0755, true ) : @copy( $f->getPathname(), $target );
			}
		} else {
			copy( $src, $dst );
		}
	}

	$zip = new Insightistic_Zip_Writer();
	$count = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $inner, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY ) as $f ) {
		$name = 'insightistic/' . str_replace( '\\', '/', substr( $f->getPathname(), strlen( $inner ) + 1 ) );
		$zip->add_file( $name, file_get_contents( $f->getPathname() ) );
		$count++;
	}
	$zip_path = $stage . '/insightistic.' . $ver . '.zip';
	file_put_contents( $zip_path, $zip->finish() );
	echo "built {$zip_path} ({$count} files, " . filesize( $zip_path ) . " bytes)\n";

	// Cleanup temp + tar.
	unset( $phar );
	PharData::unlinkArchive( $tar );
	rrmdir( $tmp );
}
