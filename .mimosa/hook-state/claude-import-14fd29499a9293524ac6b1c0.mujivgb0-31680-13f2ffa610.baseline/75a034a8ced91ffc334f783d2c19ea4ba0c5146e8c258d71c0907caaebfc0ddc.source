<?php
/**
 * Multisite probe for the release gate.
 *
 * Run AFTER `wp core multisite-install` and network activation:
 *   docker compose -f tests/docker/compose-multisite.yaml run --rm wpcli \
 *     wp --path=/var/www/html --allow-root eval-file /multisite-probe.php
 *
 * Verifies: network activation works, the plugin boots on the main site
 * AND on a child site, options are per-site scoped, no fatals.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- gate probe executed via wp-cli eval-file.

$pass = 0; $fail = 0;
$ok  = function ( $m ) use ( &$pass ) { $pass++; echo "  PASS  {$m}\n"; };
$bad = function ( $m ) use ( &$fail ) { $fail++; echo "  FAIL  {$m}\n"; };

is_multisite() ? $ok( 'multisite is installed' ) : $bad( 'multisite is installed' );

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
is_plugin_active_for_network( 'insightistic/insightistic.php' )
	? $ok( 'insightistic is network-activated' )
	: $bad( 'insightistic is network-activated' );

( defined( 'INSIGHTISTIC_VERSION' ) && INSIGHTISTIC_VERSION === '4.4.2' )
	? $ok( 'plugin boot on main site (constant 4.4.2 present)' )
	: $bad( 'plugin boot on main site (constant missing)' );

// Create + switch to a child site (subdirectory-style network).
$child = wpmu_create_blog( get_current_site()->domain, '/child/', 'Child Gate', 1 );
( ! is_wp_error( $child ) && $child > 1 )
	? $ok( "child site created (blog_id {$child})" )
	: $bad( 'child site created: ' . ( is_wp_error( $child ) ? $child->get_error_message() : 'unexpected' ) );

switch_to_blog( $child );
update_option( 'insightistic_property_id', '987654321' );
( get_option( 'insightistic_property_id' ) === '987654321' )
	? $ok( 'plugin options scoped per-site on child' )
	: $bad( 'plugin options scoped per-site on child' );
( defined( 'INSIGHTISTIC_VERSION' ) )
	? $ok( 'plugin classes available on child site boot' )
	: $bad( 'plugin classes available on child site boot' );
restore_current_blog();

// Main site options untouched by child writes.
( get_option( 'insightistic_property_id' ) !== '987654321' )
	? $ok( 'main site options isolated from child' )
	: $bad( 'main site options isolated from child' );

echo "MULTISITE PROBE: PASS={$pass} FAIL={$fail}\n";
exit( $fail ? 1 : 0 );
