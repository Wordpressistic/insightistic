<?php
/**
 * Plugin Name: Insightistic
 * Plugin URI:  https://wordpressistic.com/insightistic
 * Description: Connect Google Analytics 4, Search Console, PageSpeed, Cloudflare, and WooCommerce to your WordPress dashboard. Core analytics features work without an account. An optional free Insightistic account adds Cloud AI, dashboard sync, and hosted traffic insights.
 * Version:     4.7.1
 * Author:      WordPressistic
 * Author URI:  https://wordpressistic.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: insightistic
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 8.0
 *
 * @package Insightistic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'INSIGHTISTIC_VERSION', '4.7.1' );
define( 'INSIGHTISTIC_FILE', __FILE__ );
define( 'INSIGHTISTIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'INSIGHTISTIC_URL', plugin_dir_url( __FILE__ ) );
define( 'INSIGHTISTIC_TERMS_URL', 'https://www.insightistic.com/terms/' );
define( 'INSIGHTISTIC_PRIVACY_URL', 'https://www.insightistic.com/privacy-policy/' );
define( 'INSIGHTISTIC_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load plugin classes after all plugins are loaded.
 */
function insightistic_load() {
	insightistic_migrate_legacy_keys();
	insightistic_migrate_encryption();
	insightistic_migrate_470();

	$files = array(
		INSIGHTISTIC_PATH . 'includes/class-insightistic-encryption.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-auth.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-saas-client.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-sync.php',
		INSIGHTISTIC_PATH . 'includes/class-insightistic-indexnow.php',
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
		INSIGHTISTIC_PATH . 'includes/class-insightistic-do-next.php',
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

	// Text translations are loaded automatically for WordPress.org-hosted
	// plugins since 4.6 (Plugin Check flags a manual load_plugin_textdomain()).

	( new Insightistic_Admin() )->init();
	( new Insightistic_Sync() )->init();
	( new Insightistic_License_Manager() )->init();
	( new Insightistic_IndexNow() )->init();
	( new Insightistic_GA() )->init();
	( new Insightistic_GSC() )->init();
	( new Insightistic_PageSpeed() )->init();
	( new Insightistic_Cloudflare() )->init();
	( new Insightistic_Engagement() )->init();
	( new Insightistic_AI() )->init();
	( new Insightistic_Email_Automations() )->init();
	( new Insightistic_Woocommerce() )->init();
	Insightistic_Do_Next::init();
}
add_action( 'plugins_loaded', 'insightistic_load' );

/**
 * Add the plugin's data-handling summary to the site's Privacy Policy guide.
 */
function insightistic_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content  = '<p>' . esc_html__( 'Insightistic stores locally configured analytics settings and, when enabled, a rolling log of 404 request paths and external referrer host names. The 404 log does not store IP addresses or user agents and can be cleared from the plugin dashboard.', 'insightistic' ) . '</p>';
	$content .= '<p>' . esc_html__( 'When you connect an Insightistic account, the plugin can send the site URL, plugin version, analytics summaries, WooCommerce summaries, configured Cloudflare summaries, IndexNow submission data, and 404/broken-link summaries to Insightistic for dashboard sync and Cloud AI. Disconnecting the account stops those calls.', 'insightistic' ) . '</p>';
	$content .= '<p>' . esc_html__( 'AI analysis is off by default. Only after an administrator ticks the Insightistic Cloud AI consent box and clicks "Get AI Insights" does the plugin send an analytics summary to api.insightistic.com. When you configure Google or Cloudflare, the plugin sends only the requests described in its External services documentation.', 'insightistic' ) . '</p>';

	wp_add_privacy_policy_content( 'Insightistic', $content );
}
add_action( 'admin_init', 'insightistic_add_privacy_policy_content' );

/**
 * Activation hook.
 */
function insightistic_activate() {
	// Admin-only options are stored without autoloading so they never weigh
	// down front-end page loads. The 4th add_option() arg is $autoload.

	// GA4.
	add_option( 'insightistic_property_id', '', '', 'no' );
	// AI.
	add_option( 'insightistic_ai_provider', 'insightistic_cloud', '', 'no' );
	add_option( 'insightistic_ai_enabled', 0, '', 'no' );
	add_option( 'insightistic_ai_skill_profile', 'basic', '', 'no' );
	add_option( 'insightistic_ai_consent', 0, '', 'no' );
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
			'enabled'    => 0,
			'recipients' => get_option( 'admin_email' ),
			'frequency'  => 'weekly',
			'day'        => 'monday',
			'time'       => '09:00',
		),
		'',
		'no'
	);
	// IndexNow URL submission pipeline (v4.4.3).
	add_option(
		'insightistic_indexnow',
		array(
			'key'                 => '',
			'key_location'        => '',
			'key_file_confirmed'  => false,
			'auto_submit_enabled' => 'yes',
			'last_submitted'      => '',
		),
		'',
		'no'
	);
	add_option( 'insightistic_indexnow_queue', array(), '', 'no' );
}
register_activation_hook( INSIGHTISTIC_FILE, 'insightistic_activate' );

/**
 * Deactivation hook.
 */
function insightistic_deactivate() {
	delete_transient( 'insightistic_access_token_ga4' );
	delete_transient( 'insightistic_access_token_gsc' );
	wp_clear_scheduled_hook( 'insightistic_license_validate' );
	wp_clear_scheduled_hook( 'insightistic_indexnow_flush' );
	// Clear all analytics cache transients.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bulk-deletes this plugin's own prefixed transients by LIKE; no WP API exists for wildcard transient deletion and the result must not be cached.
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
		'Insightistic_property_id'           => 'insightistic_property_id',
		'Insightistic_api_email'             => 'insightistic_api_email',
		'Insightistic_api_private_key'       => 'insightistic_api_private_key',
		'Insightistic_gsc_property_url'      => 'insightistic_gsc_property_url',
		'Insightistic_pagespeed_api_key_enc' => 'insightistic_pagespeed_api_key_enc',
		'Insightistic_pagespeed_default_url' => 'insightistic_pagespeed_default_url',
		'Insightistic_engagement_enabled'    => 'insightistic_engagement_enabled',
		'Insightistic_measurement_id'        => 'insightistic_measurement_id',
		'Insightistic_measurement_secret'    => 'insightistic_measurement_secret',
		'Insightistic_ai_enabled'            => 'insightistic_ai_enabled',
		'Insightistic_ai_provider'           => 'insightistic_ai_provider',
		'Insightistic_ai_skill_profile'      => 'insightistic_ai_skill_profile',
		'Insightistic_video_guide_url'       => 'insightistic_video_guide_url',
		'Insightistic_docs_url'              => 'insightistic_docs_url',
		'Insightistic_addons'                => 'insightistic_addons',
		'Insightistic_email_automations'     => 'insightistic_email_automations',
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
 * One-time 4.7.1 migration: the free plugin no longer connects to third-party
 * AI providers directly, so remove any stored bring-your-own provider keys and
 * models, and require a fresh explicit opt-in for Insightistic Cloud AI.
 */
function insightistic_migrate_470() {
	if ( get_option( 'insightistic_migrated_470', 0 ) ) {
		return;
	}

	// Retired: the pre-4.0 add-on grace window (every module is free now).
	delete_option( 'insightistic_legacy_grace_until' );

	foreach ( array( 'openai', 'gemini', 'openrouter', 'claude', 'groq' ) as $insightistic_provider ) {
		delete_option( 'insightistic_' . $insightistic_provider . '_key' );
		delete_option( 'insightistic_' . $insightistic_provider . '_model' );
		delete_option( 'insightistic_' . $insightistic_provider . '_key_updated_at' );
	}
	update_option( 'insightistic_ai_provider', 'insightistic_cloud', false );
	add_option( 'insightistic_ai_consent', 0, '', 'no' );

	update_option( 'insightistic_migrated_470', 1, false );
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
