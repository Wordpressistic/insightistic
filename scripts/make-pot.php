<?php
/**
 * Regenerate languages/insightistic.pot from current plugin source.
 *
 * Scans insightistic.php, includes/*.php and templates/*.php for gettext
 * calls restricted to the 'insightistic' text domain and writes a
 * deterministic POT template (UTF-8, no BOM).
 *
 * This is a lightweight stand-in for `wp i18n make-pot` that requires no
 * WordPress runtime, so it can run in CI and locally.
 *
 * Usage: php scripts/make-pot.php
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- CLI script, WordPress is not loaded.

$root    = dirname( __DIR__ );
$domain  = 'insightistic';
$version = '';

$main = file_get_contents( $root . '/insightistic.php' );
if ( preg_match( "/define\(\s*'INSIGHTISTIC_VERSION',\s*'([^']+)'/", $main, $m ) ) {
	$version = $m[1];
}
if ( '' === $version ) {
	fwrite( STDERR, "Could not determine INSIGHTISTIC_VERSION.\n" );
	exit( 1 );
}

$targets = array_merge(
	array( $root . '/insightistic.php' ),
	glob( $root . '/includes/*.php' ) ?: array(),
	glob( $root . '/templates/*.php' ) ?: array()
);

/*
 * Gettext function shapes we extract:
 *   __( 's', 'd' )            _e( 's', 'd' )
 *   _x( 's', 'c', 'd' )       _ex( 's', 'c', 'd' )
 *   _n( 's', 'p', n, 'd' )
 *   esc_html__ / esc_attr__   esc_html_e / esc_attr_e
 *   esc_html_x / esc_attr_x
 *   _n_noop( 's', 'p', 'd' )
 */
$simple   = '__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e';
$context  = '_x|_ex|esc_html_x|esc_attr_x';
$plural   = '_n';
$noop     = '_n_noop';

$entries = array(); // key: msgctx||msgid -> [refs[], plural or null]

foreach ( $targets as $file ) {
	$rel  = str_replace( $root . DIRECTORY_SEPARATOR, '', $file );
	$rel  = str_replace( '\\', '/', $rel );
	$lines = file( $file );
	if ( false === $lines ) {
		continue;
	}

	foreach ( $lines as $lineno => $line ) {
		$patterns = array(
			'simple'  => "/\b(?:{$simple})\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'{$domain}'\s*\)/",
			'context' => "/\b(?:{$context})\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'{$domain}'\s*\)/",
			'plural'  => "/\b{$plural}\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*[^,]+,\s*'{$domain}'\s*\)/",
			'noop'    => "/\b{$noop}\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'{$domain}'\s*\)/",
		);

		foreach ( $patterns as $kind => $re ) {
			if ( preg_match( $re, $line, $m ) ) {
				$msgid  = stripcslashes( $m[1] );
				$ctx    = ( 'context' === $kind ) ? stripcslashes( $m[2] ) : '';
				$plural = ( 'plural' === $kind || 'noop' === $kind ) ? stripcslashes( $m[2] ) : null;
				$key    = $ctx . "\x04" . $msgid;
				if ( ! isset( $entries[ $key ] ) ) {
					$entries[ $key ] = array( 'ctx' => $ctx, 'msgid' => $msgid, 'plural' => $plural, 'refs' => array() );
				}
				if ( null !== $plural ) {
					$entries[ $key ]['plural'] = $plural;
				}
				$entries[ $key ]['refs'][] = $rel . ':' . ( $lineno + 1 );
			}
		}
	}
}

ksort( $entries, SORT_STRING );

/**
 * Escape a string for PO format output.
 */
function po_escape( $s ) {
	$s = str_replace( array( '\\', '"', "\t" ), array( '\\\\', '\\"', '\\t' ), $s );
	$s = str_replace( array( "\r", "\n" ), array( '', '\n' ), $s );
	return $s;
}

$out  = "# Copyright (C) 2026 WordPressistic\n";
$out .= "# This file is distributed under the same license as the Insightistic plugin.\n";
$out .= 'msgid ""' . "\n";
$out .= 'msgstr ""' . "\n";
$out .= "\"Project-Id-Version: Insightistic {$version}\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://wordpressistic.com\\n\"\n";
$out .= '"Last-Translator: FULL NAME <EMAIL@ADDRESS>' . "\\" . "n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d\TH:i:s+00:00' ) . "\\" . "n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"X-Generator: insightistic/make-pot 1.0\\n\"\n";
$out .= "\"X-Domain: {$domain}\\n\"\n";

foreach ( $entries as $entry ) {
	$out .= "\n";
	foreach ( array_unique( $entry['refs'] ) as $ref ) {
		$out .= "#: {$ref}\n";
	}
	if ( '' !== $entry['ctx'] ) {
		$out .= 'msgctxt "' . po_escape( $entry['ctx'] ) . '"' . "\n";
	}
	$out .= 'msgid "' . po_escape( $entry['msgid'] ) . '"' . "\n";
	if ( null !== $entry['plural'] ) {
		$out .= 'msgid_plural "' . po_escape( $entry['plural'] ) . '"' . "\n";
		$out .= "msgstr[0] \"\"\n";
		$out .= "msgstr[1] \"\"\n";
	} else {
		$out .= "msgstr \"\"\n";
	}
}

$pot_path = $root . '/languages/insightistic.pot';
$bytes    = file_put_contents( $pot_path, $out ); // UTF-8, no BOM by default.
if ( false === $bytes || 0 === $bytes ) {
	fwrite( STDERR, "Failed to write {$pot_path}\n" );
	exit( 1 );
}

echo "Wrote {$pot_path} ({$bytes} bytes, " . count( $entries ) . " strings, Project-Id-Version: Insightistic {$version}).\n";
