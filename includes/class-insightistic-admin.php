<?php
/**
 * Admin class for Insightistic.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Admin
 */
class Insightistic_Admin {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_insightistic_toggle_addon', array( $this, 'ajax_toggle_addon' ) );
		add_action( 'wp_ajax_insightistic_save_email_automation', array( $this, 'ajax_save_email_automation' ) );
		add_action( 'wp_ajax_insightistic_send_test_email_automation', array( $this, 'ajax_send_test_email_automation' ) );
		add_action( 'wp_ajax_insightistic_preview_email_digest', array( $this, 'ajax_preview_email_digest' ) );
		add_action( 'wp_ajax_insightistic_get_addon_report', array( $this, 'ajax_get_addon_report' ) );
		add_action( 'wp_ajax_insightistic_save_ai_snapshot', array( $this, 'ajax_save_ai_snapshot' ) );
		add_action( 'wp_ajax_insightistic_clear_ai_key', array( $this, 'ajax_clear_ai_key' ) );
		add_action( 'wp_ajax_insightistic_test_ai_provider', array( $this, 'ajax_test_ai_provider' ) );
		add_action( 'wp_ajax_insightistic_license_activate', array( $this, 'ajax_license_activate' ) );
		add_action( 'wp_ajax_insightistic_license_refresh', array( $this, 'ajax_license_refresh' ) );
		add_action( 'wp_ajax_insightistic_license_disconnect', array( $this, 'ajax_license_disconnect' ) );
		add_action( 'wp_ajax_insightistic_sync_now', array( $this, 'ajax_sync_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_connect_notice' ) );
	}

	/**
	 * Provider labels + per-run cost hints, surfaced to the dashboard so
	 * users see what they are about to spend before clicking "Get AI Insights".
	 *
	 * @return array<string, array{label:string,cost:string}>
	 */
	private function ai_provider_meta() {
		return array(
			'openai'             => array( 'label' => 'OpenAI', 'cost' => '~$0.01 / run' ),
			'gemini'             => array( 'label' => 'Google Gemini', 'cost' => '~$0.005 / run' ),
			'openrouter'         => array( 'label' => 'OpenRouter', 'cost' => __( 'Free models supported', 'insightistic' ) ),
			'claude'             => array( 'label' => 'Anthropic Claude', 'cost' => '~$0.015 / run' ),
			'groq'               => array( 'label' => 'Groq', 'cost' => __( 'Low cost / fast', 'insightistic' ) ),
			'insightistic_cloud' => array( 'label' => 'Insightistic Cloud AI', 'cost' => __( 'Free with your account (usage limits apply)', 'insightistic' ) ),
			'none'               => array( 'label' => __( 'None', 'insightistic' ), 'cost' => '' ),
		);
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Insightistic Analytics', 'insightistic' ),
			__( 'Insightistic', 'insightistic' ),
			'manage_options',
			'insightistic',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			30
		);

		add_submenu_page(
			'insightistic',
			__( 'Analytics Dashboard', 'insightistic' ),
			__( 'Dashboard', 'insightistic' ),
			'manage_options',
			'insightistic',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'insightistic',
			__( 'Insightistic Speed Test', 'insightistic' ),
			__( 'Speed Test', 'insightistic' ),
			'manage_options',
			'insightistic-speed-test',
			array( $this, 'render_speed_test' )
		);

		add_submenu_page(
			'insightistic',
			__( 'Insightistic Settings', 'insightistic' ),
			__( 'Settings', 'insightistic' ),
			'manage_options',
			'insightistic-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'insightistic',
			__( 'Insightistic Addons', 'insightistic' ),
			__( ' Addons', 'insightistic' ),
			'manage_options',
			'insightistic-addons',
			array( $this, 'render_addons' )
		);

		add_submenu_page(
			'insightistic',
			__( 'Insightistic License', 'insightistic' ),
			Insightistic_License_Manager::is_connected()
				? __( 'License', 'insightistic' )
				: __( 'License ✦', 'insightistic' ),
			'manage_options',
			'insightistic-license',
			array( $this, 'render_license' )
		);

		add_submenu_page(
			'insightistic',
			__( 'Insightistic System Status', 'insightistic' ),
			__( 'System Status', 'insightistic' ),
			'manage_options',
			'insightistic-system-status',
			array( $this, 'render_system_status' )
		);
	}

	/**
	 * Enqueue CSS and JS only on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'insightistic' ) ) {
			return;
		}

		// Chart.js is bundled locally  no external CDN dependency.
		wp_register_script(
			'insightistic-chartjs',
			INSIGHTISTIC_URL . 'assets/js/vendor/chart.umd.min.js',
			array(),
			'4.4.4',
			true
		);

		// Serve minified assets in production when current minified files are available.
		$use_min  = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
		$css_file = $use_min && file_exists( INSIGHTISTIC_PATH . 'assets/css/admin.min.css' ) && filemtime( INSIGHTISTIC_PATH . 'assets/css/admin.min.css' ) >= filemtime( INSIGHTISTIC_PATH . 'assets/css/admin.css' ) ? 'assets/css/admin.min.css' : 'assets/css/admin.css';
		$js_file  = $use_min && file_exists( INSIGHTISTIC_PATH . 'assets/js/admin.min.js' ) && filemtime( INSIGHTISTIC_PATH . 'assets/js/admin.min.js' ) >= filemtime( INSIGHTISTIC_PATH . 'assets/js/admin.js' ) ? 'assets/js/admin.min.js' : 'assets/js/admin.js';

		wp_enqueue_style(
			'insightistic-admin',
			INSIGHTISTIC_URL . $css_file,
			array(),
			INSIGHTISTIC_VERSION
		);

		wp_enqueue_script(
			'insightistic-admin',
			INSIGHTISTIC_URL . $js_file,
			array( 'jquery', 'insightistic-chartjs' ),
			INSIGHTISTIC_VERSION,
			true
		);

		$ai_provider = get_option( 'insightistic_ai_provider', 'none' );
		$ai_meta     = $this->ai_provider_meta();
		$ai_label    = $ai_meta[ $ai_provider ]['label'] ?? '';
		$ai_cost     = $ai_meta[ $ai_provider ]['cost'] ?? '';
		$ai_model    = '';
		if ( 'none' !== $ai_provider ) {
			$ai_model = get_option( 'insightistic_' . $ai_provider . '_model', '' );
		}

		wp_localize_script(
			'insightistic-admin',
			'insightisticPro',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'insightistic_nonce' ),
				'aiEnabled'       => (int) get_option( 'insightistic_ai_enabled', 0 ),
				'aiProvider'      => $ai_provider,
				'aiProviderLabel' => $ai_label,
				'aiModel'         => $ai_model,
				'aiCostHint'      => $ai_cost,
				'gscConfigured'   => (bool) get_option( 'insightistic_gsc_property_url' ),
				'psiConfigured'   => (bool) get_option( 'insightistic_pagespeed_api_key_enc' ),
				'cfAvailable'     => class_exists( 'Insightistic_Cloudflare' ) && Insightistic_Cloudflare::is_available(),
				'wooActive'       => class_exists( 'Insightistic_Woocommerce' ) ? ( new Insightistic_Woocommerce() )->is_active() : false,
				'addons'          => $this->get_addons_state(),
				'emailAutomation' => $this->get_email_automation_config(),
				'defaultUrl'      => esc_url( get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) ) ),
				'settingsUrl'     => admin_url( 'admin.php?page=insightistic-settings' ),
				'licenseUrl'      => admin_url( 'admin.php?page=insightistic-license' ),
				'adminEmail'      => get_option( 'admin_email' ),
				'license'         => Insightistic_Feature_Gate::js_state(),
				'homeUrl'         => esc_url( home_url( '/' ) ),
				'i18n'            => array(
					'loading'         => __( 'Loading data', 'insightistic' ),
					'loadingReport'   => __( 'Loading addon report', 'insightistic' ),
					'analyzing'       => __( 'AI is analysing your data', 'insightistic' ),
					'error'           => __( 'Something went wrong. Please try again.', 'insightistic' ),
					'noData'          => __( 'No data found for the selected period.', 'insightistic' ),
					'noMetric'        => __( 'No data for this metric', 'insightistic' ),
					'noRevenue'       => __( 'No revenue tracked', 'insightistic' ),
					'noTransactions'  => __( 'No transactions tracked', 'insightistic' ),
					'revenue'         => __( 'Revenue', 'insightistic' ),
					'sessions'        => __( 'Sessions', 'insightistic' ),
					'increase'        => __( 'increase', 'insightistic' ),
					'decrease'        => __( 'decrease', 'insightistic' ),
					'vsLastPeriod'    => __( 'vs last period', 'insightistic' ),
					'runTest'         => __( 'Run PageSpeed Test', 'insightistic' ),
					'testing'         => __( 'Running test', 'insightistic' ),
					'refreshData'     => __( 'Refresh Data', 'insightistic' ),
					'loadData'        => __( 'Load Data', 'insightistic' ),
					'loadCommerce'    => __( 'Load Commerce Data', 'insightistic' ),
					'retry'           => __( 'Retry', 'insightistic' ),
					'openSettings'    => __( 'Open Settings', 'insightistic' ),
					'updated'         => __( 'Updated', 'insightistic' ),
					'forceRefresh'    => __( 'Force refresh', 'insightistic' ),
					'justNow'         => __( 'just now', 'insightistic' ),
					'minuteAgo'       => __( '1 min ago', 'insightistic' ),
					'minutesAgo'      => __( 'min ago', 'insightistic' ),
					'hourAgo'         => __( '1 hour ago', 'insightistic' ),
					'hoursAgo'        => __( 'hours ago', 'insightistic' ),
					'daysAgo'         => __( 'days ago', 'insightistic' ),
					'aiCopy'          => __( 'Copy insights', 'insightistic' ),
					'aiRerun'         => __( 'Re-run', 'insightistic' ),
					'aiSave'          => __( 'Save snapshot', 'insightistic' ),
					'aiCopied'        => __( 'Copied', 'insightistic' ),
					'aiSaved'         => __( 'Snapshot saved', 'insightistic' ),
					'aiCostTitle'     => __( 'Approximate per-run cost (provider pricing varies)', 'insightistic' ),
					'enabled'         => __( 'Enabled', 'insightistic' ),
					'disabled'        => __( 'Disabled', 'insightistic' ),
					'saving'          => __( 'Saving', 'insightistic' ),
					'sending'         => __( 'Sending', 'insightistic' ),
					'settingsSaved'   => __( 'Settings saved.', 'insightistic' ),
					'testSent'        => __( 'Test digest sent.', 'insightistic' ),
					'confirmClearKey' => __( 'Clear this saved key? You will need to paste it again to use this provider.', 'insightistic' ),
					'confirmClear404' => __( 'Clear the 404 log? This cannot be undone.', 'insightistic' ),
					'emailPreview'    => __( 'Email Digest Preview', 'insightistic' ),
					'emailDirty'      => __( 'You have unsaved changes. The test digest will send to the previously saved address:', 'insightistic' ),
					'emailSaveFirst'  => __( 'Click Save Email Automation first to use the new values.', 'insightistic' ),
					'sessionExpired'  => __( 'Your session expired. Hard-refresh the page (Ctrl+Shift+R / Cmd+Shift+R) and try again.', 'insightistic' ),
					'serverError'     => __( 'Server returned an error', 'insightistic' ),
					'jsonOk'          => __( 'Credentials extracted! Please also fill in your GA4 Property ID, then click Save Settings.', 'insightistic' ),
					'jsonErr'         => __( 'Could not parse JSON. Please paste the entire contents of your service account key file.', 'insightistic' ),
				),
			)
		);
	}

	public function ajax_toggle_addon() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$slug    = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;
		$allowed = array( 'email_automations', 'seo_opportunities', 'anomaly_alerts', 'content_lab', 'woocommerce_pro' );
		if ( ! in_array( $slug, $allowed, true ) ) {
			wp_send_json_error( __( 'Invalid addon.', 'insightistic' ) );
		}

		// Account gate: only Email Automations needs a free Insightistic account
		// (disabling is always allowed). The response carries the signup card.
		$feature = Insightistic_Feature_Gate::addon_feature( $slug );
		if ( $enabled && $feature && ! Insightistic_Feature_Gate::can( $feature ) ) {
			wp_send_json_error(
				array(
					'code'    => 'locked',
					'feature' => $feature,
					'message' => __( 'Create a free account to enable this addon.', 'insightistic' ),
					'html'    => Insightistic_Feature_Gate::locked_card( $feature, '', __( 'Create a free account to enable this addon.', 'insightistic' ) ),
				)
			);
		}

		$addons          = $this->get_addons_state();
		$addons[ $slug ] = $enabled;
		update_option( 'insightistic_addons', $addons );

		$email = $this->get_email_automation_config();
		if ( 'email_automations' === $slug ) {
			$email['enabled'] = $enabled;
			update_option( 'insightistic_email_automations', $email );
			( new Insightistic_Email_Automations() )->maybe_schedule_event();
		}

		wp_send_json_success(
			array(
				'addons'          => $addons,
				'emailAutomation' => $email,
			)
		);
	}

	public function ajax_save_email_automation() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$addons = $this->get_addons_state();
		if ( empty( $addons['email_automations'] ) ) {
			wp_send_json_error( __( 'Enable Email Automations addon first.', 'insightistic' ) );
		}

		if ( ! Insightistic_Feature_Gate::can( 'email_audit_automation' ) ) {
			wp_send_json_error(
				array(
					'code' => 'locked',
					'html' => Insightistic_Feature_Gate::locked_card( 'email_audit_automation', '', __( 'Create a free account to enable email automations.', 'insightistic' ) ),
				)
			);
		}

		$recipients        = sanitize_text_field( wp_unslash( $_POST['recipients'] ?? '' ) );
		$frequency         = sanitize_key( wp_unslash( $_POST['frequency'] ?? 'weekly' ) );
		$day               = sanitize_key( wp_unslash( $_POST['day'] ?? 'monday' ) );
		$time              = sanitize_text_field( wp_unslash( $_POST['time'] ?? '09:00' ) );
		$allowed_frequency = array( 'daily', 'weekly', 'monthly' );
		if ( ! in_array( $frequency, $allowed_frequency, true ) ) {
			$frequency = 'weekly';
		}
		if ( ! in_array( $day, array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ), true ) ) {
			$day = 'monday';
		}
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = '09:00';
		}

		$config               = $this->get_email_automation_config();
		$config['enabled']    = 1;
		$config['recipients'] = $recipients ? $recipients : get_option( 'admin_email' );
		$config['frequency']  = $frequency;
		$config['day']        = $day;
		$config['time']       = $time;
		update_option( 'insightistic_email_automations', $config );
		wp_clear_scheduled_hook( Insightistic_Email_Automations::CRON_HOOK );
		( new Insightistic_Email_Automations() )->maybe_schedule_event();

		wp_send_json_success(
			array(
				'message' => __( 'Email automation settings saved.', 'insightistic' ),
				'config'  => $config,
			)
		);
	}

	public function ajax_send_test_email_automation() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		if ( ! Insightistic_Feature_Gate::can( 'email_audit_automation' ) ) {
			wp_send_json_error(
				array(
					'code' => 'locked',
					'html' => Insightistic_Feature_Gate::locked_card( 'email_audit_automation', '', __( 'Create a free account to enable email automations.', 'insightistic' ) ),
				)
			);
		}

		$result = ( new Insightistic_Email_Automations() )->send_now( true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		if ( ! $result ) {
			wp_send_json_error( __( 'WordPress could not send the email. Check your mail configuration.', 'insightistic' ) );
		}

		wp_send_json_success( __( 'Test digest sent successfully.', 'insightistic' ) );
	}

	public function ajax_get_addon_report() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$slug   = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$addons = $this->get_addons_state();
		if ( empty( $addons[ $slug ] ) ) {
			wp_send_json_error( __( 'Enable this addon first.', 'insightistic' ) );
		}

		// Server-side account gate (the toggle gate alone is not enough — the
		// addon may have been enabled during legacy grace). Only Email
		// Automations resolves to a non-empty feature here; every other
		// addon is unconditionally free.
		$feature = Insightistic_Feature_Gate::addon_feature( $slug );
		if ( $feature && ! Insightistic_Feature_Gate::can( $feature ) ) {
			wp_send_json_error(
				array(
					'code'    => 'locked',
					'feature' => $feature,
					'html'    => Insightistic_Feature_Gate::locked_card( $feature, '', __( 'Create a free account to enable this addon.', 'insightistic' ) ),
				)
			);
		}

		$report = Insightistic_Addons::get_report( $slug );
		wp_send_json_success(
			array(
				'html' => Insightistic_Addons::render_report( $report ),
			)
		);
	}

	/**
	 * Render an Email Automation preview HTML so users can verify the
	 * digest before sending. Reuses the same builder the cron job uses.
	 */
	public function ajax_preview_email_digest() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$automations = new Insightistic_Email_Automations();
		if ( method_exists( $automations, 'build_preview_html' ) ) {
			$html = $automations->build_preview_html();
		} elseif ( method_exists( $automations, 'build_html' ) ) {
			$html = $automations->build_html();
		} else {
			wp_send_json_error( __( 'Preview is not available in this version.', 'insightistic' ) );
		}

		if ( is_wp_error( $html ) ) {
			wp_send_json_error( $html->get_error_message() );
		}
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			wp_send_json_error( __( 'Preview is empty. Load GA4 data first so the digest has something to summarise.', 'insightistic' ) );
		}
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Append a small "saved AI snapshot" log so users can keep the last
	 * three runs as a paper trail (no PII, just the rendered HTML).
	 */
	public function ajax_save_ai_snapshot() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$html = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
		if ( '' === $html ) {
			wp_send_json_error( __( 'No insight content to save.', 'insightistic' ) );
		}

		$history = get_option( 'insightistic_ai_history', array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift(
			$history,
			array(
				'saved_at' => time(),
				'provider' => get_option( 'insightistic_ai_provider', 'none' ),
				'html'     => $html,
			)
		);
		$history = array_slice( $history, 0, 3 );
		update_option( 'insightistic_ai_history', $history, false );
		wp_send_json_success( __( 'Insight snapshot saved.', 'insightistic' ) );
	}

	/**
	 * Delete an AI provider key without going through the save form.
	 */
	public function ajax_clear_ai_key() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$map      = array(
			'openai'     => 'insightistic_openai_key',
			'gemini'     => 'insightistic_gemini_key',
			'openrouter' => 'insightistic_openrouter_key',
			'claude'     => 'insightistic_claude_key',
			'groq'       => 'insightistic_groq_key',
		);
		if ( ! isset( $map[ $provider ] ) ) {
			wp_send_json_error( __( 'Unknown provider.', 'insightistic' ) );
		}
		delete_option( $map[ $provider ] );
		delete_option( $provider . '_key_updated_at' );
		delete_option( 'insightistic_' . $provider . '_key_updated_at' );
		wp_send_json_success( __( 'Provider key cleared.', 'insightistic' ) );
	}

	/**
	 * Send a one-sentence prompt to the requested AI provider so users can
	 * verify the key, the model slug, and outbound network all in one click.
	 */
	public function ajax_test_ai_provider() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$allowed  = array( 'openai', 'gemini', 'openrouter', 'claude', 'groq', 'insightistic_cloud' );
		if ( ! in_array( $provider, $allowed, true ) ) {
			wp_send_json_error( __( 'Unknown provider.', 'insightistic' ) );
		}

		$result = ( new Insightistic_AI() )->test_provider( $provider );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( __( 'Provider responded successfully. Key and model are working.', 'insightistic' ) );
	}

	/* ------------------------------------------------------------------ */
	/* License (SaaS connection)                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Activate a pasted license key against the Insightistic SaaS.
	 */
	public function ajax_license_activate() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		// The raw key passes through this request once and is never stored.
		$raw_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		if ( '' === $raw_key ) {
			wp_send_json_error( __( 'Please paste your license key.', 'insightistic' ) );
		}

		$result = ( new Insightistic_License_Manager() )->activate( $raw_key );
		if ( is_wp_error( $result ) ) {
			$extra = $result->get_error_data();
			wp_send_json_error(
				array(
					'code'        => $result->get_error_code(),
					'message'     => $result->get_error_message(),
					'upgrade_url' => is_array( $extra ) && ! empty( $extra['upgrade_url'] ) ? esc_url_raw( $extra['upgrade_url'] ) : '',
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'License activated! Your site is now connected to Insightistic.', 'insightistic' ),
				'state'   => $result,
				'gate'    => Insightistic_Feature_Gate::js_state(),
			)
		);
	}

	/**
	 * Manual "Refresh License" — re-validate entitlements now.
	 */
	public function ajax_license_refresh() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$result = ( new Insightistic_License_Manager() )->refresh();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'License refreshed.', 'insightistic' ),
				'state'   => $result,
				'gate'    => Insightistic_Feature_Gate::js_state(),
			)
		);
	}

	/**
	 * Manually kick off a WooCommerce + site-health sync (same chain the
	 * daily cron runs). Site-health posts synchronously; orders/products/
	 * customers are handed to Action Scheduler (or the inline fallback) so
	 * this request returns quickly even on a large store.
	 */
	public function ajax_sync_now() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		if ( ! Insightistic_License_Manager::is_connected() ) {
			wp_send_json_error( __( 'Connect your Insightistic account first.', 'insightistic' ) );
		}

		( new Insightistic_Sync() )->start_full_sync();

		wp_send_json_success(
			array(
				'message' => __( 'Sync started in the background.', 'insightistic' ),
			)
		);
	}

	/**
	 * Disconnect this site (best-effort remote deactivation + local wipe).
	 */
	public function ajax_license_disconnect() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		( new Insightistic_License_Manager() )->disconnect();
		wp_send_json_success( __( 'Disconnected. Advanced features are paused; your analytics settings are untouched.', 'insightistic' ) );
	}

	/**
	 * One quiet notice on Insightistic pages while unconnected (never site-wide).
	 */
	public function maybe_show_connect_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'insightistic' ) ) {
			return;
		}
		if ( false !== strpos( (string) $screen->id, 'insightistic-license' ) ) {
			return; // The license page speaks for itself.
		}
		if ( Insightistic_License_Manager::is_connected() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$grace = Insightistic_Feature_Gate::in_legacy_grace();
		printf(
			'<div class="notice notice-info isp-connect-notice"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Create a free account to unlock AI Insights and email automations.', 'insightistic' ),
			$grace
				? esc_html__( 'Your existing add-ons keep working during the upgrade grace period — connect your free Insightistic account to keep them active afterwards.', 'insightistic' )
				: esc_html__( 'Every module in Insightistic is free. A free account is only needed for AI-generated insights and automated email delivery.', 'insightistic' ),
			esc_url( admin_url( 'admin.php?page=insightistic-license' ) ),
			esc_html__( 'Create a free account →', 'insightistic' )
		);
	}

	/**
	 * Render the license page.
	 */
	public function render_license() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'insightistic' ) );
		}
		require INSIGHTISTIC_PATH . 'templates/license.php';
	}

	/**
	 * Render the Speed Test page.
	 */
	public function render_speed_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'insightistic' ) );
		}
		require INSIGHTISTIC_PATH . 'templates/speed-test.php';
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'insightistic' ) );
		}
		require INSIGHTISTIC_PATH . 'templates/dashboard.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'insightistic' ) );
		}

		if ( isset( $_POST['insightistic_settings_nonce'] ) ) {
			$nonce_ok = (bool) wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['insightistic_settings_nonce'] ) ),
				'insightistic_save_settings'
			);
			if ( $nonce_ok ) {
				$this->save_settings();
			} else {
				// Make nonce expiry visible instead of a silent no-op.
				add_settings_error(
					'insightistic_messages',
					'nonce_expired',
					__( 'Your session expired before the form was submitted. Please try again.', 'insightistic' ),
					'error'
				);
			}
		}

		require INSIGHTISTIC_PATH . 'templates/settings.php';
	}

	/**
	 * Render the addons showcase page.
	 */
	public function render_addons() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'insightistic' ) );
		}
		require INSIGHTISTIC_PATH . 'templates/addons.php';
	}

	/**
	 * Render system status page.
	 */
	public function render_system_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'insightistic' ) );
		}

		if ( isset( $_POST['insightistic_export_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['insightistic_export_settings_nonce'] ) ), 'insightistic_export_settings' ) ) {
			$payload = wp_json_encode( Insightistic_System_Status::export_settings(), JSON_PRETTY_PRINT );
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename=insightistic-settings-' . gmdate( 'Ymd-His' ) . '.json' );
			echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( isset( $_POST['insightistic_import_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['insightistic_import_settings_nonce'] ) ), 'insightistic_import_settings' ) ) {
			// Raw JSON export blob; decoded + allowlisted in import_settings(), never echoed.
			$raw    = wp_unslash( $_POST['insightistic_settings_json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$result = Insightistic_System_Status::import_settings( json_decode( $raw, true ) );
			add_settings_error(
				'insightistic_system_messages',
				'insightistic_import',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'Settings imported. Secret keys were intentionally skipped.', 'insightistic' ),
				is_wp_error( $result ) ? 'error' : 'success'
			);
		}

		require INSIGHTISTIC_PATH . 'templates/system-status.php';
	}

	/**
	 * Save all settings from POST data.
	 */
	private function save_settings() {
		// Defense in depth: render_settings() already verifies this nonce before
		// calling, but re-check here so the gate is explicit and local.
		if ( ! isset( $_POST['insightistic_settings_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['insightistic_settings_nonce'] ) ), 'insightistic_save_settings' ) ) {
			return;
		}

		// GA4 credentials
		update_option( 'insightistic_property_id', sanitize_text_field( wp_unslash( $_POST['property_id'] ?? '' ) ) );
		update_option( 'insightistic_api_email', sanitize_email( wp_unslash( $_POST['api_email'] ?? '' ) ) );

		if ( ! empty( $_POST['api_private_key'] ) ) {
			// Multi-line PEM key  intentionally not run through sanitize_text_field
			// (which would strip the newlines); it is encrypted immediately below.
			$raw_key = wp_unslash( $_POST['api_private_key'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$enc     = Insightistic_Encryption::encrypt( $raw_key );
			if ( $enc ) {
				update_option( 'insightistic_api_private_key', $enc );
				// Invalidate both tokens on new key.
				delete_transient( 'insightistic_access_token_ga4' );
				delete_transient( 'insightistic_access_token_gsc' );
			}
		}

		// Search Console
		update_option( 'insightistic_gsc_property_url', esc_url_raw( wp_unslash( $_POST['gsc_property_url'] ?? '' ) ) );

		// PageSpeed Insights
		if ( ! empty( $_POST['pagespeed_api_key'] ) ) {
			$psi_raw = sanitize_text_field( wp_unslash( $_POST['pagespeed_api_key'] ) );
			if ( strpos( $psi_raw, '*' ) === false ) {
				$enc = Insightistic_Encryption::encrypt( $psi_raw );
				if ( $enc ) {
					// Store ONLY the encrypted version. Decrypted on use in class-insightistic-pagespeed.php.
					update_option( 'insightistic_pagespeed_api_key_enc', $enc );
					// Remove any legacy plaintext value that may exist from a previous version.
					delete_option( 'insightistic_pagespeed_api_key' );
				}
			}
		}
		update_option( 'insightistic_pagespeed_default_url', esc_url_raw( wp_unslash( $_POST['pagespeed_default_url'] ?? home_url( '/' ) ) ) );

		// Cloudflare Traffic Insights (Phase 0: credentials only).
		update_option( 'insightistic_cloudflare_zone_id', sanitize_text_field( wp_unslash( $_POST['cf_zone_id'] ?? '' ) ) );
		update_option( 'insightistic_cloudflare_account_id', sanitize_text_field( wp_unslash( $_POST['cf_account_id'] ?? '' ) ) );
		if ( ! empty( $_POST['cf_api_token'] ) ) {
			$cf_raw = sanitize_text_field( wp_unslash( $_POST['cf_api_token'] ) );
			if ( strpos( $cf_raw, '*' ) === false ) {
				$enc = Insightistic_Encryption::encrypt( $cf_raw );
				if ( $enc ) {
					update_option( 'insightistic_cloudflare_api_token_enc', $enc );
				}
			}
		}

		// Engagement Tracking
		update_option( 'insightistic_engagement_enabled', isset( $_POST['engagement_enabled'] ) ? 1 : 0 );
		update_option( 'insightistic_measurement_id', sanitize_text_field( wp_unslash( $_POST['measurement_id'] ?? '' ) ) );
		update_option( 'insightistic_404_monitor_enabled', isset( $_POST['monitor_404_enabled'] ) ? 1 : 0 );

		if ( ! empty( $_POST['measurement_secret'] ) ) {
			$sec = sanitize_text_field( wp_unslash( $_POST['measurement_secret'] ) );
			if ( strpos( $sec, '*' ) === false ) {
				$enc = Insightistic_Encryption::encrypt( $sec );
				if ( $enc ) {
					update_option( 'insightistic_measurement_secret', $enc );
				}
			}
		}

		// AI settings
		$ai_enabled = isset( $_POST['ai_enabled'] ) ? 1 : 0;
		update_option( 'insightistic_ai_enabled', $ai_enabled );

		$provider = sanitize_key( wp_unslash( $_POST['ai_provider'] ?? 'none' ) );
		$allowed  = array( 'none', 'openai', 'gemini', 'openrouter', 'claude', 'groq', 'insightistic_cloud' );
		if ( ! in_array( $provider, $allowed, true ) ) {
			$provider = 'none';
		}
		update_option( 'insightistic_ai_provider', $provider );
		$skill_profile = sanitize_key( wp_unslash( $_POST['ai_skill_profile'] ?? 'basic' ) );
		if ( ! in_array( $skill_profile, array( 'basic', 'seo_expert' ), true ) ) {
			$skill_profile = 'basic';
		}
		update_option( 'insightistic_ai_skill_profile', $skill_profile );

		// API keys.
		$key_fields = array(
			'openai_api_key'     => 'insightistic_openai_key',
			'gemini_api_key'     => 'insightistic_gemini_key',
			'openrouter_api_key' => 'insightistic_openrouter_key',
			'claude_api_key'     => 'insightistic_claude_key',
			'groq_api_key'       => 'insightistic_groq_key',
		);
		foreach ( $key_fields as $post_key => $option_name ) {
			if ( ! empty( $_POST[ $post_key ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				if ( strpos( $val, '*' ) === false ) {
					$enc = Insightistic_Encryption::encrypt( $val );
					if ( $enc ) {
						update_option( $option_name, $enc );
						// Track rotation so users can see "Rotated 2 days ago".
						$prov = str_replace( '_api_key', '', $post_key );
						update_option( 'insightistic_' . $prov . '_key_updated_at', time() );
					}
				}
			}
		}

		// AI models.
		$model_fields = array(
			'openai_model'             => 'insightistic_openai_model',
			'gemini_model'             => 'insightistic_gemini_model',
			'openrouter_model'         => 'insightistic_openrouter_model',
			'claude_model'             => 'insightistic_claude_model',
			'groq_model'               => 'insightistic_groq_model',
			'insightistic_cloud_model' => 'insightistic_insightistic_cloud_model',
		);
		foreach ( $model_fields as $post_key => $option_name ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_option( $option_name, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
			$custom_key = $post_key . '_custom';
			if ( ! empty( $_POST[ $custom_key ] ) ) {
				update_option( $option_name, sanitize_text_field( wp_unslash( $_POST[ $custom_key ] ) ) );
			}
		}

		// Guide links.
		update_option( 'insightistic_video_guide_url', esc_url_raw( wp_unslash( $_POST['video_guide_url'] ?? '' ) ) );
		update_option( 'insightistic_docs_url', esc_url_raw( wp_unslash( $_POST['docs_url'] ?? '' ) ) );

		add_settings_error(
			'insightistic_messages',
			'settings_saved',
			__( 'Settings saved successfully.', 'insightistic' ),
			'success'
		);
	}

	private function get_addons_state() {
		$saved    = get_option( 'insightistic_addons', array() );
		$defaults = array(
			'email_automations' => 0,
			'seo_opportunities' => 0,
			'anomaly_alerts'    => 0,
			'content_lab'       => 0,
			'woocommerce_pro'   => 0,
		);
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	private function get_email_automation_config() {
		$saved    = get_option( 'insightistic_email_automations', array() );
		$defaults = array(
			'enabled'    => 0,
			'recipients' => get_option( 'admin_email' ),
			'frequency'  => 'weekly',
			'day'        => 'monday',
			'time'       => '09:00',
		);
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}
}
