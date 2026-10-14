<?php
/**
 * Uninstall script for Insightistic.
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * @package Insightistic
 */

// Only run when WordPress uninstaller calls this file directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// List of all options to remove.
$options = array(
	// GA4.
	'insightistic_property_id',
	'insightistic_api_email',
	'insightistic_api_private_key',
	// Search Console.
	'insightistic_gsc_property_url',
	// PageSpeed (key stored only encrypted; also delete any legacy plaintext entry).
	'insightistic_pagespeed_api_key_enc',
	'insightistic_pagespeed_api_key',
	'insightistic_pagespeed_default_url',
	// Cloudflare Traffic Insights (Phase 0).
	'insightistic_cloudflare_zone_id',
	'insightistic_cloudflare_account_id',
	'insightistic_cloudflare_api_token_enc',
	// Engagement.
	'insightistic_engagement_enabled',
	'insightistic_measurement_id',
	'insightistic_measurement_secret',
	// 404 & broken-link monitor.
	'insightistic_404_monitor_enabled',
	'insightistic_404_log',
	// AI.
	'insightistic_ai_enabled',
	'insightistic_ai_provider',
	'insightistic_ai_skill_profile',
	'insightistic_openai_key',
	'insightistic_openai_model',
	'insightistic_gemini_key',
	'insightistic_gemini_model',
	'insightistic_openrouter_key',
	'insightistic_openrouter_model',
	'insightistic_claude_key',
	'insightistic_claude_model',
	'insightistic_groq_key',
	'insightistic_groq_model',
	'insightistic_insightistic_cloud_model',
	// AI history + key rotation timestamps.
	'insightistic_ai_history',
	'insightistic_openai_key_updated_at',
	'insightistic_gemini_key_updated_at',
	'insightistic_openrouter_key_updated_at',
	'insightistic_claude_key_updated_at',
	'insightistic_groq_key_updated_at',
	// Encryption.
	'insightistic_crypto_secret',
	'insightistic_enc_migrated_330',
	// Misc.
	'insightistic_video_guide_url',
	'insightistic_docs_url',
	'insightistic_addons',
	'insightistic_email_automations',
	'insightistic_email_next_run',
	'insightistic_email_last_sent',
	'insightistic_migrated_from_pro',
	// License / SaaS connection (v4.0). The SaaS side also expires this
	// activation automatically once heartbeats stop.
	'insightistic_license_state',
	'insightistic_license_last4',
	'insightistic_connector_key_id',
	'insightistic_connector_secret',
	'insightistic_saas_site_id',
	'insightistic_saas_activation_id',
	'insightistic_legacy_grace_until',
	'insightistic_migrated_400',
	// WooCommerce/site-health sync to the SaaS (v4.1), replacing the need
	// for the separate insightistic-connector plugin.
	'insightistic_sync_settings',
	'insightistic_last_sync',
	'insightistic_sync_log',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear the daily license validation + sync crons.
wp_clear_scheduled_hook( 'insightistic_license_validate' );
wp_clear_scheduled_hook( 'insightistic_run_sync' );

// Sweep any remaining plugin options (e.g. dynamically-named rotation
// timestamps) plus all plugin transients.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		'insightistic\_%\_key_updated_at',
		'_transient_insightistic%',
		'_transient_timeout_insightistic%'
	)
);
