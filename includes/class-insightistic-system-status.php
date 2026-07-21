<?php
/**
 * System status and settings portability for Insightistic.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds release-readiness diagnostics.
 */
class Insightistic_System_Status {

	/**
	 * Collect status checks.
	 *
	 * @return array
	 */
	public static function collect() {
		$settings = admin_url( 'admin.php?page=insightistic-settings' );
		$addons   = admin_url( 'admin.php?page=insightistic-addons' );

		$checks = array(
			self::check( __( 'GA4 property configured', 'insightistic' ),       (bool) get_option( 'insightistic_property_id' ),                                                __( 'Required for dashboard, digests, anomaly alerts, and content lab.', 'insightistic' ),      false, $settings . '&active_tab=ga4#ga4',             __( 'Open GA4 settings', 'insightistic' ) ),
			self::check( __( 'Service account email configured', 'insightistic' ), (bool) get_option( 'insightistic_api_email' ),                                              __( 'Required for Google API authentication.', 'insightistic' ),                              false, $settings . '&active_tab=ga4#ga4',             __( 'Open GA4 settings', 'insightistic' ) ),
			self::check( __( 'Private key encrypted', 'insightistic' ),         (bool) get_option( 'insightistic_api_private_key' ),                                            __( 'Credentials should never be stored in plaintext.', 'insightistic' ),                     false, $settings . '&active_tab=ga4#ga4',             __( 'Import service-account key', 'insightistic' ) ),
			self::check( __( 'Search Console configured', 'insightistic' ),     (bool) get_option( 'insightistic_gsc_property_url' ),                                           __( 'Required for SEO Opportunity Finder.', 'insightistic' ),                                  false, $settings . '&active_tab=gsc#gsc',             __( 'Connect Search Console', 'insightistic' ) ),
			self::check( __( 'PageSpeed configured', 'insightistic' ),          (bool) get_option( 'insightistic_pagespeed_api_key_enc' ),                                      __( 'Required for Core Web Vitals reports.', 'insightistic' ),                                false, $settings . '&active_tab=pagespeed#pagespeed', __( 'Add PageSpeed API key', 'insightistic' ) ),
			self::check( __( 'Cloudflare Traffic Insights available', 'insightistic' ), class_exists( 'Insightistic_Cloudflare' ) && Insightistic_Cloudflare::is_available(), __( 'Optional  works automatically once a free account is connected; a Cloudflare API token is only needed as an alternative.', 'insightistic' ), true,  admin_url( 'admin.php?page=insightistic-license' ), __( 'Create a free account', 'insightistic' ) ),
			self::check( __( 'AI provider enabled', 'insightistic' ),           (bool) get_option( 'insightistic_ai_enabled' ),                                                 __( 'Optional, but important for AI insight positioning.', 'insightistic' ),                  false, $settings . '&active_tab=ai#ai',               __( 'Enable AI Insights', 'insightistic' ) ),
			self::check( __( 'Email automation scheduled', 'insightistic' ),    (bool) wp_next_scheduled( Insightistic_Email_Automations::CRON_HOOK ),                          __( 'Required for scheduled growth digests.', 'insightistic' ),                               false, $addons,                                       __( 'Configure Email Automations', 'insightistic' ) ),
			self::check( __( 'WordPress mail function available', 'insightistic' ), function_exists( 'wp_mail' ),                                                               __( 'SMTP plugin is recommended for reliable delivery.', 'insightistic' ),                    false, 'https://wordpress.org/plugins/wp-mail-smtp/', __( 'Install SMTP plugin', 'insightistic' ) ),
			self::check( __( 'Current minified admin JS', 'insightistic' ),     self::asset_is_current( 'assets/js/admin.js', 'assets/js/admin.min.js' ),                       __( 'Production should load current minified JavaScript.', 'insightistic' ),                  false, null, null ),
			self::check( __( 'Current minified admin CSS', 'insightistic' ),    self::asset_is_current( 'assets/css/admin.css', 'assets/css/admin.min.css' ),                   __( 'Production should load current minified CSS.', 'insightistic' ),                         false, null, null ),
			self::check( __( 'Frontend tracker under 3KB', 'insightistic' ),  self::tracker_under_limit(),                                                                    __( 'Keeps frontend tracking lightweight.', 'insightistic' ),                                 false, null, null ),
			self::check( __( 'WooCommerce detected', 'insightistic' ),          function_exists( 'wc_get_orders' ),                                                             __( 'Only required for WooCommerce Intelligence Pro.', 'insightistic' ),                      true,  'https://wordpress.org/plugins/woocommerce/',  __( 'Install WooCommerce', 'insightistic' ) ),
		);

		return $checks;
	}

	/**
	 * Export non-secret settings.
	 *
	 * @return array
	 */
	public static function export_settings() {
		$keys = array(
			'insightistic_property_id',
			'insightistic_gsc_property_url',
			'insightistic_pagespeed_default_url',
			'insightistic_cloudflare_zone_id',
			'insightistic_cloudflare_account_id',
			'insightistic_engagement_enabled',
			'insightistic_measurement_id',
			'insightistic_404_monitor_enabled',
			'insightistic_ai_enabled',
			'insightistic_ai_provider',
			'insightistic_ai_skill_profile',
			'insightistic_openai_model',
			'insightistic_gemini_model',
			'insightistic_openrouter_model',
			'insightistic_claude_model',
			'insightistic_groq_model',
			'insightistic_insightistic_cloud_model',
			'insightistic_video_guide_url',
			'insightistic_docs_url',
			'insightistic_addons',
			'insightistic_email_automations',
		);

		$out = array(
			'plugin'  => 'insightistic',
			'version' => INSIGHTISTIC_VERSION,
			'site'    => home_url( '/' ),
			'created' => gmdate( 'c' ),
			'settings' => array(),
		);

		foreach ( $keys as $key ) {
			$out['settings'][ $key ] = get_option( $key );
		}

		return $out;
	}

	/**
	 * Import allowed non-secret settings.
	 *
	 * @param array $payload Export payload.
	 * @return true|WP_Error
	 */
	public static function import_settings( $payload ) {
		if ( empty( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
			return new WP_Error( 'insightistic_bad_import', __( 'Invalid settings import file.', 'insightistic' ) );
		}

		foreach ( $payload['settings'] as $key => $value ) {
			if ( 0 !== strpos( $key, 'insightistic_' ) ) {
				continue;
			}
			if ( false !== strpos( $key, '_key' ) || false !== strpos( $key, '_secret' ) || false !== strpos( $key, 'private_key' ) ) {
				continue;
			}
			update_option( sanitize_key( $key ), $value );
		}

		return true;
	}

	/**
	 * Build a single check row.
	 *
	 * @param string $label Label.
	 * @param bool   $pass Passed.
	 * @param string $detail Detail.
	 * @param bool   $optional Optional.
	 * @return array
	 */
	private static function check( $label, $pass, $detail, $optional = false, $fix_url = null, $fix_label = null ) {
		return array(
			'label'     => $label,
			'status'    => $pass ? 'pass' : ( $optional ? 'optional' : 'fail' ),
			'detail'    => $detail,
			'optional'  => $optional,
			'fix_url'   => $fix_url,
			'fix_label' => $fix_label,
		);
	}

	/**
	 * Check asset freshness.
	 *
	 * @param string $source Source.
	 * @param string $min Minified.
	 * @return bool
	 */
	private static function asset_is_current( $source, $min ) {
		$source_path = INSIGHTISTIC_PATH . $source;
		$min_path    = INSIGHTISTIC_PATH . $min;
		return file_exists( $source_path ) && file_exists( $min_path ) && filemtime( $min_path ) >= filemtime( $source_path );
	}

	/**
	 * Check tracker size.
	 *
	 * @return bool
	 */
	private static function tracker_under_limit() {
		$file = INSIGHTISTIC_PATH . 'assets/js/tracking.min.js';
		return file_exists( $file ) && filesize( $file ) < 3072;
	}
}
