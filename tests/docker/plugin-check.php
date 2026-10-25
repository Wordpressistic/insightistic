<?php
/**
 * Plugin Check runner for the release gate.
 *
 * Usage (inside the wpcli service):
 *   wp eval-file /plugin-check.php <plugin-slug> [--json=/tmp/report.json]
 *
 * Delegates to the official plugin-check WP-CLI command so results match the
 * WordPress.org Plugin Check exactly, then prints a compact summary.
 *
 * @package Insightistic
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "This script must run via wp-cli (wp eval-file /plugin-check.php <slug>).\n";
	exit( 1 );
}

$slug  = isset( $args[0] ) ? $args[0] : 'insightistic';
$json_path = '/tmp/insightistic-plugin-check.json';

// The official command already prints results; --format=json keeps it parseable.
$exit = 0;
ob_start();
WP_CLI::runcommand(
	"plugin check {$slug} --format=json",
	array(
		'exit_error' => false,
		'return'     => 'stdout',
	)
);
$raw = ob_get_clean();

file_put_contents( $json_path, $raw );

$report = json_decode( $raw, true );
if ( ! is_array( $report ) ) {
	WP_CLI::error( "Could not parse plugin check output (saved to {$json_path})." );
}

$errors   = array();
$warnings = array();
foreach ( $report as $file => $items ) {
	foreach ( (array) $items as $item ) {
		$type = isset( $item['type'] ) ? $item['type'] : 'WARNING';
		$line = sprintf(
			'%s:%s %s [%s]',
			$file,
			isset( $item['line'] ) ? $item['line'] : '?',
			isset( $item['message'] ) ? $item['message'] : '',
			isset( $item['code'] ) ? $item['code'] : ''
		);
		if ( 'ERROR' === $type ) {
			$errors[] = $line;
		} else {
			$warnings[] = $line;
		}
	}
}

WP_CLI::log( sprintf( 'Plugin Check: %d errors, %d warnings (full JSON: %s)', count( $errors ), count( $warnings ), $json_path ) );
foreach ( array_merge( $errors, $warnings ) as $line ) {
	WP_CLI::log( '  ' . $line );
}
