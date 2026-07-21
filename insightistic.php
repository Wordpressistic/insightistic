<?php
/**
 * Plugin Name: Insightistic - GA4 Analytics & AI Insights
 * Plugin URI:  https://wordpressistic.com/insightistic
 * Description: Connect Google Analytics 4, Search Console, PageSpeed and WooCommerce to your WordPress dashboard — fully free. Create a free Insightistic account to unlock AI Insights and email automation delivery.
 * Version:     4.4.0
 * Author:      WordPressistic
 * Author URI:  https://wordpressistic.com
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: insightistic
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 8.0
 * Tested up to: 6.8
 *
 * @package Insightistic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'INSIGHTISTIC_VERSION',  '4.4.0' );
define( 'INSIGHTISTIC_FILE',     __FILE__ );
define( 'INSIGHTISTIC_PATH',     plugin_dir_path( __FILE__ ) );
define( 'INSIGHTISTIC_URL',      plugin_dir_url( __FILE__ ) );
define( 'INSIGHTISTIC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load plugin classes after all plugins are loaded.
 */
function insightistic_load() {
	insightistic_migrate_legacy_keys();
	insightistic_migrate_encryption();
	insightistic_migrate_400();

	$files = array(
		INSIGHTISTIC_PATH . 'includes/class-insightistic-encryption.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-auth.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-saas-client.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-sync.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-license-manager.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-feature-gate.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-ga.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-gsc.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-pagespeed.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-cloudflare.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-engagement.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-ai.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-email-automations.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-addons.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-system-status.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-woocommerce.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-admin.php',
	);

	foreach ( $files as $file ) {
		if ( ! file_exists( $file ) ) {
			add_action(
				'admin_notices',
				function () use ( $file ) {
					echo '<div class="notice notice-error"><p>';
					printf(
						/* translators: %s: file path */
						esc_html__( 'Insightistic: Missing file %s. Please reinstall the plugin.', 'insightistic' ),
						esc_html( str_replace( INSIGHTISTIC_PATH, '', $file ) )
					);
					echo '</p></div>';
				}
			);
			return;
		}
		require_once $file;
	}

	load_plugin_textdomain( 'insightistic', false, dirname( INSIGHTISTIC_BASENAME ) . '/languages' );

	( new Insightistic_Admin() )->init();
	( new Insightistic_Sync() )->init();
	( new Insightistic_License_Manager() )->init();
	( new Insightistic_GA() )->init();
	( new Insightistic_GSC() )->init();
	( new Insightistic_PageSpeed() )->init();
	( new Insightistic_Cloudflare() )->init();
	( new Insightistic_Engagement() )->init();
	( new Insightistic_AI() )->init();
	( new Insightistic_Email_Automations() )->init();
	( new Insightistic_Woocommerce() )->init();
}
add_action( 'plugins_loaded', 'insightistic_load' );

/**
 * Activation hook.
 */
function insightistic_activate() {
	// Admin-only options are stored without autoloading so they never weigh
	// down front-end page loads. The 4th add_option() arg is $autoload.

	// GA4.
	add_option( 'insightistic_property_id', '', '', 'no' );
	// AI.
	add_option( 'insightistic_ai_provider', 'openrouter', '', 'no' );
	add_option( 'insightistic_ai_enabled', 0, '', 'no' );
	add_option( 'insightistic_ai_skill_profile', 'basic', '', 'no' );
	add_option( 'insightistic_openrouter_model', 'meta-llama/llama-3.3-70b-instruct:free', '', 'no' );
	add_option( 'insightistic_groq_model', 'llama-3.1-8b-instant', '', 'no' );
	add_option( 'insightistic_insightistic_cloud_model', 'ollama-balanced', '', 'no' );
	// GSC.
	add_option( 'insightistic_gsc_property_url', '', '', 'no' );
	// PageSpeed (key stored only in encrypted form as _enc suffix).
	add_option( 'insightistic_pagespeed_api_key_enc', '', '', 'no' );
	add_option( 'insightistic_pagespeed_default_url', '', '', 'no' );
	// Cloudflare Traffic Insights (Phase 0: credentials only  see class docblock).
	add_option( 'insightistic_cloudflare_zone_id', '', '', 'no' );
	add_option( 'insightistic_cloudflare_account_id', '', '', 'no' );
	add_option( 'insightistic_cloudflare_api_token_enc', '', '', 'no' );
	// Engagement (read on the front end, so these stay autoloaded).
	add_option( 'insightistic_engagement_enabled', 0 );
	add_option( 'insightistic_measurement_id', '' );
	add_option( 'insightistic_measurement_secret', '' );
	// 404 monitor is server-side only (template_redirect, no front-end script),
	// on by default, and admin-only so it does not need to autoload.
	add_option( 'insightistic_404_monitor_enabled', 1, '', 'no' );
	// Misc.
	add_option( 'insightistic_video_guide_url', '', '', 'no' );
	add_option( 'insightistic_docs_url', '', '', 'no' );
	add_option(
		'insightistic_addons',
		array(
			'email_automations' => 0,
			'seo_opportunities' => 0,
			'anomaly_alerts'    => 0,
			'content_lab'       => 0,
			'woocommerce_pro'   => 0,
		),
		'',
		'no'
	);
	add_option(
		'insightistic_email_automations',
		array(
			'enabled'   => 0,
			'recipients' => get_option( 'admin_email' ),
			'frequency' => 'weekly',
			'day'       => 'monday',
			'time'      => '09:00',
		),
		'',
		'no'
	);
}
register_activation_hook( INSIGHTISTIC_FILE, 'insightistic_activate' );

/**
 * Deactivation hook.
 */
function insightistic_deactivate() {
	delete_transient( 'insightistic_access_token_ga4' );
	delete_transient( 'insightistic_access_token_gsc' );
	wp_clear_scheduled_hook( 'insightistic_license_validate' );
	// Clear all analytics cache transients.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_insightistic_data_%',
			'_transient_timeout_insightistic_data_%'
		)
	);
}
register_deactivation_hook( INSIGHTISTIC_FILE, 'insightistic_deactivate' );

/**
 * One-time migration from old Insightistic_* keys to insightistic_* keys.
 */
function insightistic_migrate_legacy_keys() {
	$flag = get_option( 'insightistic_migrated_from_pro', 0 );
	if ( $flag ) {
		return;
	}

	$map = array(
		'Insightistic_property_id'             => 'insightistic_property_id',
		'Insightistic_api_email'               => 'insightistic_api_email',
		'Insightistic_api_private_key'         => 'insightistic_api_private_key',
		'Insightistic_gsc_property_url'        => 'insightistic_gsc_property_url',
		'Insightistic_pagespeed_api_key_enc'   => 'insightistic_pagespeed_api_key_enc',
		'Insightistic_pagespeed_default_url'   => 'insightistic_pagespeed_default_url',
		'Insightistic_engagement_enabled'      => 'insightistic_engagement_enabled',
		'Insightistic_measurement_id'          => 'insightistic_measurement_id',
		'Insightistic_measurement_secret'      => 'insightistic_measurement_secret',
		'Insightistic_ai_enabled'              => 'insightistic_ai_enabled',
		'Insightistic_ai_provider'             => 'insightistic_ai_provider',
		'Insightistic_ai_skill_profile'        => 'insightistic_ai_skill_profile',
		'Insightistic_openai_key'              => 'insightistic_openai_key',
		'Insightistic_openai_model'            => 'insightistic_openai_model',
		'Insightistic_gemini_key'              => 'insightistic_gemini_key',
		'Insightistic_gemini_model'            => 'insightistic_gemini_model',
		'Insightistic_openrouter_key'          => 'insightistic_openrouter_key',
		'Insightistic_openrouter_model'        => 'insightistic_openrouter_model',
		'Insightistic_claude_key'              => 'insightistic_claude_key',
		'Insightistic_claude_model'            => 'insightistic_claude_model',
		'Insightistic_groq_key'                => 'insightistic_groq_key',
		'Insightistic_groq_model'              => 'insightistic_groq_model',
		'Insightistic_video_guide_url'         => 'insightistic_video_guide_url',
		'Insightistic_docs_url'                => 'insightistic_docs_url',
		'Insightistic_addons'                  => 'insightistic_addons',
		'Insightistic_email_automations'       => 'insightistic_email_automations',
	);

	foreach ( $map as $old_key => $new_key ) {
		$old_value = get_option( $old_key, null );
		if ( null !== $old_value && false === get_option( $new_key, false ) ) {
			update_option( $new_key, $old_value );
		}
	}

	$transient_map = array(
		'Insightistic_access_token_ga4' => 'insightistic_access_token_ga4',
		'Insightistic_access_token_gsc' => 'insightistic_access_token_gsc',
	);
	foreach ( $transient_map as $old_key => $new_key ) {
		$old = get_transient( $old_key );
		if ( false !== $old && false === get_transient( $new_key ) ) {
			set_transient( $new_key, $old, HOUR_IN_SECONDS );
		}
	}

	update_option( 'insightistic_migrated_from_pro', 1 );
}

/**
 * One-time 4.0.0 migration. Installs upgrading from <= 3.3.0 that already
 * enabled add-ons which are now plan-gated get a 14-day legacy grace window
 * so nothing switches off overnight; a notice on the plugin pages explains
 * the change and links to license activation.
 */
function insightistic_migrate_400() {
	if ( get_option( 'insightistic_migrated_400', 0 ) ) {
		return;
	}

	$addons        = get_option( 'insightistic_addons', array() );
	$was_installed = false !== get_option( 'insightistic_property_id', false );
	$used_gated    = is_array( $addons ) && array_sum( array_map( 'intval', $addons ) ) > 0;

	if ( $was_installed && $used_gated && ! get_option( 'insightistic_connector_key_id', '' ) ) {
		update_option( 'insightistic_legacy_grace_until', time() + 14 * DAY_IN_SECONDS, false );
	}

	update_option( 'insightistic_migrated_400', 1, false );
}

/**
 * One-time re-encryption of credentials from the legacy (< 3.3.0) format to
 * the authenticated, salt-rotation-resilient format. Runs once; afterwards
 * stored secrets survive WordPress salt rotation.
 */
function insightistic_migrate_encryption() {
	if ( get_option( 'insightistic_enc_migrated_330', 0 ) ) {
		return;
	}

	if ( ! class_exists( 'Insightistic_Encryption' ) ) {
		$enc_file = INSIGHTISTIC_PATH . 'includes/class-insightistic-encryption.php';
		if ( ! file_exists( $enc_file ) ) {
			return;
		}
		require_once $enc_file;
	}

	$secret_options = array(
		'insightistic_api_private_key',
		'insightistic_pagespeed_api_key_enc',
		'insightistic_measurement_secret',
		'insightistic_openai_key',
		'insightistic_gemini_key',
		'insightistic_openrouter_key',
		'insightistic_claude_key',
		'insightistic_groq_key',
	);

	foreach ( $secret_options as $option ) {
		$value = get_option( $option );
		if ( ! $value || ! Insightistic_Encryption::is_legacy( $value ) ) {
			continue;
		}
		$plain = Insightistic_Encryption::decrypt( $value );
		if ( false === $plain || '' === $plain ) {
			continue;
		}
		$reencrypted = Insightistic_Encryption::encrypt( $plain );
		if ( $reencrypted ) {
			update_option( $option, $reencrypted );
		}
	}

	update_option( 'insightistic_enc_migrated_330', 1, false );
}
