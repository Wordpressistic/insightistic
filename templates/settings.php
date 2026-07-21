<?php
/**
 * Settings template.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_id    = get_option( 'insightistic_property_id', '' );
$api_email      = get_option( 'insightistic_api_email', '' );
$api_key_stored = (bool) get_option( 'insightistic_api_private_key' );

// GSC.
$gsc_property_url = get_option( 'insightistic_gsc_property_url', '' );

// PageSpeed.
$psi_key_stored  = (bool) get_option( 'insightistic_pagespeed_api_key_enc' );
$psi_default_url = get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) );

// Engagement.
$engagement_on  = (bool) get_option( 'insightistic_engagement_enabled', 0 );
$measurement_id = get_option( 'insightistic_measurement_id', '' );
$secret_stored  = (bool) get_option( 'insightistic_measurement_secret' );
$monitor_404_on = (bool) get_option( 'insightistic_404_monitor_enabled', 1 );

// AI.
$ai_enabled        = (int) get_option( 'insightistic_ai_enabled', 0 );
$ai_provider       = get_option( 'insightistic_ai_provider', 'none' );
$openai_stored     = (bool) get_option( 'insightistic_openai_key' );
$gemini_stored     = (bool) get_option( 'insightistic_gemini_key' );
$openrouter_stored = (bool) get_option( 'insightistic_openrouter_key' );
$claude_stored     = (bool) get_option( 'insightistic_claude_key' );
$groq_stored       = (bool) get_option( 'insightistic_groq_key' );
$openai_model      = get_option( 'insightistic_openai_model', 'gpt-4o-mini' );
$gemini_model      = get_option( 'insightistic_gemini_model', 'gemini-1.5-flash' );
$openrouter_model  = get_option( 'insightistic_openrouter_model', 'meta-llama/llama-3.3-70b-instruct:free' );
$claude_model      = get_option( 'insightistic_claude_model', 'claude-haiku-4-5-20251001' );
$groq_model        = get_option( 'insightistic_groq_model', 'llama-3.1-8b-instant' );
$ai_skill_profile  = get_option( 'insightistic_ai_skill_profile', 'basic' );
$cloud_model       = get_option( 'insightistic_insightistic_cloud_model', 'ollama-balanced' );
$cloud_connected   = class_exists( 'Insightistic_License_Manager' ) && Insightistic_License_Manager::is_connected();

// AI key rotation timestamps (per-provider) for the "Last rotated" badge.
$key_updated_at = array(
	'openai'     => (int) get_option( 'insightistic_openai_key_updated_at', 0 ),
	'gemini'     => (int) get_option( 'insightistic_gemini_key_updated_at', 0 ),
	'openrouter' => (int) get_option( 'insightistic_openrouter_key_updated_at', 0 ),
	'groq'       => (int) get_option( 'insightistic_groq_key_updated_at', 0 ),
	'claude'     => (int) get_option( 'insightistic_claude_key_updated_at', 0 ),
);

// Cloudflare Traffic Insights (Phase 0: credentials only).
$cf_zone_id      = get_option( 'insightistic_cloudflare_zone_id', '' );
$cf_account_id   = get_option( 'insightistic_cloudflare_account_id', '' );
$cf_token_stored = (bool) get_option( 'insightistic_cloudflare_api_token_enc' );

// Docs.
$video_guide_url = get_option( 'insightistic_video_guide_url', '' );
$docs_url        = get_option( 'insightistic_docs_url', '' );

// Active tab persistence  preserved across Save Settings reload.
$active_tab = '';
// Read-only UI state (which tab to show); sanitised and allowlisted below,
// so no nonce is required to read it.
// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
if ( ! empty( $_GET['active_tab'] ) ) {
	$active_tab = sanitize_key( wp_unslash( $_GET['active_tab'] ) );
} elseif ( ! empty( $_POST['active_tab'] ) ) {
	$active_tab = sanitize_key( wp_unslash( $_POST['active_tab'] ) );
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
if ( ! in_array( $active_tab, array( 'ga4', 'gsc', 'pagespeed', 'engagement', 'ai', 'cloudflare', 'guides' ), true ) ) {
	$active_tab = '';
}

/**
 * Format a relative "X days ago" label for AI key rotation timestamps.
 *
 * @param int $ts UNIX timestamp.
 * @return string
 */
if ( ! function_exists( 'insightistic_format_rotated' ) ) {
	function insightistic_format_rotated( $ts ) {
		if ( ! $ts ) {
			return '';
		}
		$diff = max( 0, time() - $ts );
		if ( $diff < HOUR_IN_SECONDS ) {
			return __( 'Rotated less than an hour ago', 'insightistic' );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hours = max( 1, round( $diff / HOUR_IN_SECONDS ) );
			/* translators: %d: number of hours */
			return sprintf( _n( 'Rotated %d hour ago', 'Rotated %d hours ago', $hours, 'insightistic' ), $hours );
		}
		$days = max( 1, round( $diff / DAY_IN_SECONDS ) );
		/* translators: %d: number of days */
		return sprintf( _n( 'Rotated %d day ago', 'Rotated %d days ago', $days, 'insightistic' ), $days );
	}
}
?>
<div class="wrap isp-wrap isp-settings-wrap">

	<?php settings_errors( 'insightistic_messages' ); ?>

	<div class="isp-header">
		<div class="isp-header-brand">
			<img src="<?php echo esc_url( INSIGHTISTIC_URL . 'assets/images/wordpressistic-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'WordPressistic', 'insightistic' ); ?>"
				class="isp-logo">
			<div>
				<h1 class="isp-header-title"><?php esc_html_e( 'Insightistic Settings', 'insightistic' ); ?></h1>
				<p class="isp-header-sub"><?php esc_html_e( 'Configure connections, tracking, and AI insights', 'insightistic' ); ?></p>
			</div>
		</div>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic' ) ); ?>" class="isp-btn isp-btn-ghost">
			<?php esc_html_e( 'Dashboard', 'insightistic' ); ?>
		</a>
	</div>

	<!-- SETTINGS TABS -->
	<div class="isp-settings-nav" id="isp-settings-nav">
		<button class="isp-tab-btn isp-tab-active" data-tab="ga4">
			<?php esc_html_e( 'GA4', 'insightistic' ); ?>
		</button>
		<button class="isp-tab-btn" data-tab="gsc" id="isp-stab-gsc">
			<?php esc_html_e( 'Search Console', 'insightistic' ); ?>
		</button>
		<button class="isp-tab-btn" data-tab="pagespeed" id="isp-stab-pagespeed">
			<?php esc_html_e( 'PageSpeed', 'insightistic' ); ?>
		</button>
		<button class="isp-tab-btn" data-tab="engagement">
			<?php esc_html_e( 'Engagement', 'insightistic' ); ?>
		</button>
		<button class="isp-tab-btn" data-tab="ai">
			<?php esc_html_e( 'AI Insights', 'insightistic' ); ?>
		</button>
		<button class="isp-tab-btn" data-tab="cloudflare" id="isp-stab-cloudflare">
			<?php esc_html_e( 'Cloudflare', 'insightistic' ); ?>
		</button>
		<button class="isp-tab-btn" data-tab="guides">
			<?php esc_html_e( 'Docs', 'insightistic' ); ?>
		</button>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'insightistic_save_settings', 'insightistic_settings_nonce' ); ?>
		<input type="hidden" name="active_tab" id="isp-active-tab" value="<?php echo esc_attr( $active_tab ); ?>">

		<!-- ====================================================== TAB: GA4 -->
		<div class="isp-tab-panel" id="isp-tab-ga4">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'Google Analytics 4 Credentials', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Connect your GA4 property via a service account. No sampling, no OAuth pop-ups.', 'insightistic' ); ?></p>
				</div>

				<details class="isp-guide-box" <?php echo $property_id ? '' : 'open'; ?>>
					<summary><?php esc_html_e( '📘 Setup guide — connect GA4 in about 5 minutes', 'insightistic' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'Open Google Cloud Console and create (or pick) a project.', 'insightistic' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: API name */
								esc_html__( 'Enable the %s for that project (APIs & Services → Library).', 'insightistic' ),
								'<strong>Google Analytics Data API</strong>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Create a service account (IAM & Admin → Service Accounts) — no roles needed — then open it and add a JSON key (Keys → Add key → JSON). A key file downloads.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'In GA4: Admin → Property → Property Access Management → add the service account email with the Viewer role.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Back here: paste your numeric Property ID (GA4 → Admin → Property Settings), then click "Import from JSON key file" below and paste the whole key file — the email and private key fill in automatically.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Save Settings, then click "Test GA4 Connection". Green means done.', 'insightistic' ); ?></li>
					</ol>
					<div class="isp-guide-links">
						<a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener"><?php esc_html_e( 'Enable Analytics Data API ↗', 'insightistic' ); ?></a>
						<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener"><?php esc_html_e( 'Service Accounts ↗', 'insightistic' ); ?></a>
						<a href="https://analytics.google.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Google Analytics ↗', 'insightistic' ); ?></a>
					</div>
				</details>

				<div class="isp-field">
					<label class="isp-label" for="property_id">
						<?php esc_html_e( 'GA4 Property ID', 'insightistic' ); ?>
						<span class="isp-required">*</span>
					</label>
					<input type="text" id="property_id" name="property_id" class="isp-input"
						value="<?php echo esc_attr( $property_id ); ?>"
						placeholder="123456789" pattern="\d+" />
					<p class="isp-hint"><?php esc_html_e( 'Numeric only. Find it in GA4  Admin  Property Settings.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="api_email">
						<?php esc_html_e( 'Service Account Email', 'insightistic' ); ?>
						<span class="isp-required">*</span>
					</label>
					<input type="email" id="api_email" name="api_email" class="isp-input"
						value="<?php echo esc_attr( $api_email ); ?>"
						placeholder="analytics@your-project.iam.gserviceaccount.com" />
					<p class="isp-hint"><?php esc_html_e( 'Add this email to your GA4 property with Viewer role.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="api_private_key">
						<?php esc_html_e( 'Private Key', 'insightistic' ); ?>
						<span class="isp-required">*</span>
					</label>
					<?php if ( $api_key_stored ) : ?>
					<div class="isp-key-stored">
						<span class="isp-key-badge"> <?php esc_html_e( 'Key stored & encrypted', 'insightistic' ); ?></span>
						<button type="button" id="isp-change-key" class="isp-link-btn"><?php esc_html_e( 'Change key', 'insightistic' ); ?></button>
					</div>
					<textarea id="api_private_key" name="api_private_key" class="isp-textarea" rows="4"
						style="display:none;" placeholder="-----BEGIN PRIVATE KEY-----&#10;..."></textarea>
					<?php else : ?>
					<textarea id="api_private_key" name="api_private_key" class="isp-textarea" rows="4"
						placeholder="-----BEGIN PRIVATE KEY-----&#10;..."></textarea>
					<?php endif; ?>
					<p class="isp-hint isp-hint-security"> <?php esc_html_e( 'Encrypted with AES-256 before storage. Never exposed in plain text.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-json-import">
					<button type="button" id="isp-toggle-json" class="isp-link-btn"> <?php esc_html_e( 'Import from JSON key file', 'insightistic' ); ?></button>
					<div id="isp-json-wrap" style="display:none; margin-top:12px;">
						<textarea id="isp-json-input" class="isp-textarea" rows="6"
							placeholder="<?php esc_attr_e( 'Paste the entire contents of your service account JSON key file here', 'insightistic' ); ?>"></textarea>
						<button type="button" id="isp-extract-json" class="isp-btn isp-btn-secondary" style="margin-top:8px;">
							<?php esc_html_e( 'Extract Credentials', 'insightistic' ); ?>
						</button>
					</div>
					<!-- Inline notice replaces browser alert()  shown/hidden by admin.js -->
					<div id="isp-json-notice" class="isp-notice" style="display:none; margin-top:10px;" role="status" aria-live="polite"></div>
				</div>
			</div>

			<div class="isp-settings-card">
				<div class="isp-test-card">
					<h3><?php esc_html_e( 'Test GA4 Connection', 'insightistic' ); ?></h3>
					<p><?php esc_html_e( 'Save your settings first, then test the connection.', 'insightistic' ); ?></p>
					<button type="button" id="isp-test-connection" class="isp-btn isp-btn-secondary">
						<?php esc_html_e( 'Test Connection', 'insightistic' ); ?>
					</button>
					<div id="isp-test-result" style="margin-top:12px;"></div>
				</div>
			</div>

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h3><?php esc_html_e( 'How to Set Up', 'insightistic' ); ?></h3>
				</div>
				<div class="isp-instructions">
					<ol class="isp-steps">
						<li><strong><?php esc_html_e( 'Create a Service Account', 'insightistic' ); ?></strong><span><?php esc_html_e( 'Go to Google Cloud Console  IAM & Admin  Service Accounts  Create Service Account.', 'insightistic' ); ?></span></li>
						<li><strong><?php esc_html_e( 'Download the JSON key', 'insightistic' ); ?></strong><span><?php esc_html_e( 'Click the service account  Keys  Add Key  JSON. Use the "Import from JSON" button above.', 'insightistic' ); ?></span></li>
						<li><strong><?php esc_html_e( 'Add to GA4 property', 'insightistic' ); ?></strong><span><?php esc_html_e( 'In GA4  Admin  Property Access Management  Add the service account email as Viewer.', 'insightistic' ); ?></span></li>
						<li><strong><?php esc_html_e( 'Enter your Property ID', 'insightistic' ); ?></strong><span><?php esc_html_e( 'Find it in GA4  Admin  Property Settings (numeric only, e.g. 123456789).', 'insightistic' ); ?></span></li>
					</ol>
				</div>
			</div>

		</div><!-- /#isp-tab-ga4 -->

		<!-- ================================================ TAB: SEARCH CONSOLE -->
		<div class="isp-tab-panel" id="isp-tab-gsc" style="display:none;">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'Google Search Console', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Uses the same service account as GA4  just add it to your Search Console property and enter the URL below.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="gsc_property_url">
						<?php esc_html_e( 'Search Console Property URL', 'insightistic' ); ?>
					</label>
					<input type="url" id="gsc_property_url" name="gsc_property_url" class="isp-input"
						value="<?php echo esc_attr( $gsc_property_url ); ?>"
						placeholder="https://yoursite.com/" />
					<p class="isp-hint"><?php esc_html_e( 'Enter the exact property URL as registered in Search Console (e.g. https://yoursite.com/ with trailing slash). For domain properties, leave blank and use sc-domain:yoursite.com format.', 'insightistic' ); ?></p>
				</div>
			</div>

			<div class="isp-settings-card">
				<div class="isp-test-card">
					<h3><?php esc_html_e( 'Test Search Console Connection', 'insightistic' ); ?></h3>
					<p><?php esc_html_e( 'Save settings first, then verify the connection.', 'insightistic' ); ?></p>
					<button type="button" id="isp-test-gsc" class="isp-btn isp-btn-secondary">
						<?php esc_html_e( 'Test GSC Connection', 'insightistic' ); ?>
					</button>
					<div id="isp-test-gsc-result" style="margin-top:12px;"></div>
				</div>
			</div>

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h3><?php esc_html_e( 'How to Set Up', 'insightistic' ); ?></h3>
				</div>
				<div class="isp-instructions">
					<ol class="isp-steps">
						<li><strong><?php esc_html_e( 'Use the same service account', 'insightistic' ); ?></strong><span><?php esc_html_e( 'No new JSON key needed  same credentials as GA4.', 'insightistic' ); ?></span></li>
						<li><strong><?php esc_html_e( 'Add to Search Console', 'insightistic' ); ?></strong><span><?php esc_html_e( 'Go to Google Search Console  Settings  Users and permissions  Add user. Paste the service account email and set as Full permission.', 'insightistic' ); ?></span></li>
						<li><strong><?php esc_html_e( 'Enter property URL', 'insightistic' ); ?></strong><span><?php esc_html_e( 'Enter the URL exactly as it appears in Search Console, including protocol and trailing slash.', 'insightistic' ); ?></span></li>
					</ol>
				</div>
				<div class="isp-guide-links" style="margin:0 0 14px;">
					<a href="https://search.google.com/search-console" target="_blank" rel="noopener"><?php esc_html_e( 'Open Search Console ↗', 'insightistic' ); ?></a>
					<a href="https://support.google.com/webmasters/answer/9128669" target="_blank" rel="noopener"><?php esc_html_e( 'Getting started guide ↗', 'insightistic' ); ?></a>
					<a href="https://support.google.com/webmasters/answer/7687615" target="_blank" rel="noopener"><?php esc_html_e( 'Users & permissions ↗', 'insightistic' ); ?></a>
				</div>
				<div class="isp-field" style="padding-top:0;">
					<div class="isp-notice isp-notice-info">
						<?php esc_html_e( 'Search Console data has a 23 day delay and only shows data from the last 16 months.', 'insightistic' ); ?>
					</div>
				</div>
			</div>

		</div><!-- /#isp-tab-gsc -->

		<!-- ============================================== TAB: PAGESPEED -->
		<div class="isp-tab-panel" id="isp-tab-pagespeed" style="display:none;">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'PageSpeed Insights', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Enter your Google Cloud API key to enable PageSpeed testing and the Speed Test page. The free tier allows 25,000 queries/day.', 'insightistic' ); ?></p>
				</div>

				<details class="isp-guide-box" <?php echo $psi_key_stored ? '' : 'open'; ?>>
					<summary><?php esc_html_e( '📘 Setup guide — get a free API key in 2 minutes', 'insightistic' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'Open Google Cloud Console with any Google account (the same project you used for GA4 works fine).', 'insightistic' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: API name */
								esc_html__( 'Enable the %s (APIs & Services → Library → search “PageSpeed”).', 'insightistic' ),
								'<strong>PageSpeed Insights API</strong>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Go to APIs & Services → Credentials → Create credentials → API key. Copy the key that starts with “AIza”.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Recommended: click "Edit API key" and restrict it to the PageSpeed Insights API only.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Paste the key below and Save Settings — it is stored encrypted. Then open the Speed Test page and run your first full audit.', 'insightistic' ); ?></li>
					</ol>
					<div class="isp-guide-links">
						<a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener"><?php esc_html_e( 'Enable PageSpeed Insights API ↗', 'insightistic' ); ?></a>
						<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener"><?php esc_html_e( 'Create API key ↗', 'insightistic' ); ?></a>
						<a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener"><?php esc_html_e( 'Official docs ↗', 'insightistic' ); ?></a>
					</div>
				</details>

				<div class="isp-field">
					<label class="isp-label" for="pagespeed_api_key">
						<?php esc_html_e( 'Google Cloud API Key', 'insightistic' ); ?>
					</label>
					<?php if ( $psi_key_stored ) : ?>
					<div class="isp-key-stored">
						<span class="isp-key-badge"> <?php esc_html_e( 'API key stored', 'insightistic' ); ?></span>
						<button type="button" id="isp-change-psi-key" class="isp-link-btn"><?php esc_html_e( 'Change key', 'insightistic' ); ?></button>
					</div>
					<input type="text" id="pagespeed_api_key" name="pagespeed_api_key" class="isp-input"
						style="display:none;" placeholder="AIza..." autocomplete="off" />
					<?php else : ?>
					<input type="text" id="pagespeed_api_key" name="pagespeed_api_key" class="isp-input"
						placeholder="AIza..." autocomplete="off" />
					<?php endif; ?>
					<p class="isp-hint"><?php esc_html_e( 'Create an API key in Google Cloud Console  APIs & Services  Credentials. Enable the PageSpeed Insights API on the same project.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="pagespeed_default_url">
						<?php esc_html_e( 'Default URL to Test', 'insightistic' ); ?>
					</label>
					<input type="url" id="pagespeed_default_url" name="pagespeed_default_url" class="isp-input"
						value="<?php echo esc_attr( $psi_default_url ); ?>"
						placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
					<p class="isp-hint"><?php esc_html_e( 'This URL will be pre-filled in the PageSpeed tab. You can change it any time on the dashboard.', 'insightistic' ); ?></p>
				</div>
			</div>

		</div><!-- /#isp-tab-pagespeed -->

		<!-- ============================================= TAB: ENGAGEMENT -->
		<div class="isp-tab-panel" id="isp-tab-engagement" style="display:none;">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'Engagement Tracking', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'A lightweight script (&lt;3KB) that fires custom GA4 events: outbound links, scroll depth, file downloads, button clicks — plus form submits, site search, video plays and copy events.', 'insightistic' ); ?></p>
				</div>

				<details class="isp-guide-box" <?php echo $measurement_id ? '' : 'open'; ?>>
					<summary><?php esc_html_e( '📘 Setup guide — Measurement ID & API secret', 'insightistic' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'In GA4 go to Admin → Data Streams and open your web stream.', 'insightistic' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: example measurement id */
								esc_html__( 'Copy the Measurement ID at the top right (looks like %s) into the field below.', 'insightistic' ),
								'<code>G-XXXXXXXXXX</code>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'On the same stream page scroll to "Measurement Protocol API secrets" → Create. Name it "Insightistic" and copy the secret value.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Paste the secret below — it stays on your server, encrypted, and is never exposed to visitors. Events are proxied server-side.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Enable the toggle, Save Settings, then open your site in a new tab and watch events arrive in GA4 → Reports → Realtime.', 'insightistic' ); ?></li>
					</ol>
					<div class="isp-guide-links">
						<a href="https://analytics.google.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Open GA4 Admin ↗', 'insightistic' ); ?></a>
						<a href="https://support.google.com/analytics/answer/9539598" target="_blank" rel="noopener"><?php esc_html_e( 'Find your Measurement ID ↗', 'insightistic' ); ?></a>
						<a href="https://developers.google.com/analytics/devguides/collection/protocol/ga4" target="_blank" rel="noopener"><?php esc_html_e( 'Measurement Protocol docs ↗', 'insightistic' ); ?></a>
					</div>
				</details>

				<div class="isp-field isp-field-toggle">
					<label class="isp-label"><?php esc_html_e( 'Enable Tracking Script', 'insightistic' ); ?></label>
					<label class="isp-toggle">
						<input type="checkbox" name="engagement_enabled" value="1" <?php checked( $engagement_on ); ?>>
						<span class="isp-toggle-track"><span class="isp-toggle-thumb"></span></span>
						<span class="isp-toggle-label">
							<span class="isp-on"><?php esc_html_e( 'Enabled', 'insightistic' ); ?></span>
							<span class="isp-off"><?php esc_html_e( 'Disabled', 'insightistic' ); ?></span>
						</span>
					</label>
					<p class="isp-hint" style="margin-top:8px;"><?php esc_html_e( 'When enabled, a small script is loaded on the frontend of your site.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="measurement_id">
						<?php esc_html_e( 'GA4 Measurement ID', 'insightistic' ); ?>
					</label>
					<input type="text" id="measurement_id" name="measurement_id" class="isp-input"
						value="<?php echo esc_attr( $measurement_id ); ?>"
						placeholder="G-XXXXXXXXXX" />
					<p class="isp-hint"><?php esc_html_e( 'Find this in GA4  Admin  Data Streams  your stream  Measurement ID.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="measurement_secret">
						<?php esc_html_e( 'Measurement Protocol API Secret', 'insightistic' ); ?>
					</label>
					<?php if ( $secret_stored ) : ?>
					<div class="isp-key-stored">
						<span class="isp-key-badge"> <?php esc_html_e( 'Secret stored & encrypted', 'insightistic' ); ?></span>
						<button type="button" id="isp-change-secret" class="isp-link-btn"><?php esc_html_e( 'Change', 'insightistic' ); ?></button>
					</div>
					<input type="text" id="measurement_secret" name="measurement_secret" class="isp-input"
						style="display:none;" placeholder="secret" autocomplete="off" />
					<?php else : ?>
					<input type="text" id="measurement_secret" name="measurement_secret" class="isp-input"
						placeholder="secret" autocomplete="off" />
					<?php endif; ?>
					<p class="isp-hint"><?php esc_html_e( 'GA4  Admin  Data Streams  your stream  Measurement Protocol  Create. Stored encrypted.', 'insightistic' ); ?></p>
				</div>
			</div>

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h3><?php esc_html_e( 'What Gets Tracked', 'insightistic' ); ?></h3>
				</div>
				<div class="isp-field">
					<div class="isp-tracking-features">
						<div class="isp-tracking-item">
							<span class="isp-tracking-icon"></span>
							<div>
								<strong><?php esc_html_e( 'Outbound Link Clicks', 'insightistic' ); ?></strong>
								<p><?php esc_html_e( 'Fires an event when a visitor clicks a link to an external domain.', 'insightistic' ); ?></p>
							</div>
						</div>
						<div class="isp-tracking-item">
							<span class="isp-tracking-icon"></span>
							<div>
								<strong><?php esc_html_e( 'Scroll Depth', 'insightistic' ); ?></strong>
								<p><?php esc_html_e( 'Fires at 25%, 50%, 75%, and 100% scroll milestones.', 'insightistic' ); ?></p>
							</div>
						</div>
						<div class="isp-tracking-item">
							<span class="isp-tracking-icon"></span>
							<div>
								<strong><?php esc_html_e( 'File Downloads', 'insightistic' ); ?></strong>
								<p><?php esc_html_e( 'Fires when .pdf, .zip, .doc, .xls, .mp3 and similar files are clicked.', 'insightistic' ); ?></p>
							</div>
						</div>
						<div class="isp-tracking-item">
							<span class="isp-tracking-icon"></span>
							<div>
								<strong><?php esc_html_e( 'Element Clicks', 'insightistic' ); ?></strong>
								<p><?php esc_html_e( 'Tracks clicks on elements with the CSS class .isp-track.', 'insightistic' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( '404 & Broken Link Monitor', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Purely server-side  no script, no dependency on the tracker above, unaffected by ad-blockers. Records the request path, distinct referring domains, a hit count, and last-seen time for pages that 404. No IP address or user agent stored.', 'insightistic' ); ?></p>
				</div>
				<div class="isp-field isp-field-toggle">
					<label class="isp-label"><?php esc_html_e( 'Enable 404 Monitor', 'insightistic' ); ?></label>
					<label class="isp-toggle">
						<input type="checkbox" name="monitor_404_enabled" value="1" <?php checked( $monitor_404_on ); ?>>
						<span class="isp-toggle-track"><span class="isp-toggle-thumb"></span></span>
						<span class="isp-toggle-label">
							<span class="isp-on"><?php esc_html_e( 'Enabled', 'insightistic' ); ?></span>
							<span class="isp-off"><?php esc_html_e( 'Disabled', 'insightistic' ); ?></span>
						</span>
					</label>
					<p class="isp-hint" style="margin-top:8px;"><?php esc_html_e( 'On by default  view the results on the Dashboard Overview tab under "404 & Broken Link Monitor".', 'insightistic' ); ?></p>
				</div>
			</div>

		</div><!-- /#isp-tab-engagement -->

		<!-- ================================================= TAB: AI -->
		<div class="isp-tab-panel" id="isp-tab-ai" style="display:none;">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'AI-Powered Insights', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Enable manual AI analysis of your analytics data. Click "Get AI Insights" on the dashboard to run an analysis  never auto-triggered.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field isp-field-toggle">
					<label class="isp-label"><?php esc_html_e( 'Enable AI Insights', 'insightistic' ); ?></label>
					<label class="isp-toggle">
						<input type="checkbox" name="ai_enabled" value="1" <?php checked( $ai_enabled ); ?>>
						<span class="isp-toggle-track"><span class="isp-toggle-thumb"></span></span>
						<span class="isp-toggle-label">
							<span class="isp-on"><?php esc_html_e( 'Enabled', 'insightistic' ); ?></span>
							<span class="isp-off"><?php esc_html_e( 'Disabled', 'insightistic' ); ?></span>
						</span>
					</label>
				</div>

				<div class="isp-field">
					<label class="isp-label"><?php esc_html_e( 'AI Provider', 'insightistic' ); ?></label>
					<div class="isp-provider-grid">
						<?php
						$providers = array(
							'none'               => array( 'icon' => '', 'name' => __( 'None', 'insightistic' ), 'note' => __( 'Disabled', 'insightistic' ) ),
							'openai'             => array( 'icon' => '', 'name' => 'OpenAI', 'note' => 'GPT-4o / GPT-4' ),
							'gemini'             => array( 'icon' => '', 'name' => 'Google Gemini', 'note' => 'Gemini 1.5' ),
							'openrouter'         => array( 'icon' => '', 'name' => 'OpenRouter', 'note' => __( 'Free models available', 'insightistic' ) ),
							'groq'               => array( 'icon' => '', 'name' => 'Groq', 'note' => __( 'Fast and low-cost models', 'insightistic' ) ),
							'claude'             => array( 'icon' => '', 'name' => 'Anthropic Claude', 'note' => 'Claude 3' ),
							'insightistic_cloud' => array( 'icon' => '☁', 'name' => __( 'Insightistic Cloud AI', 'insightistic' ), 'note' => __( 'Free account • Ollama + Hermes SEO', 'insightistic' ) ),
						);
						foreach ( $providers as $key => $p ) :
							$selected = $ai_provider === $key ? 'isp-provider-selected' : '';
							?>
							<label class="isp-provider-card <?php echo esc_attr( $selected ); ?>">
								<input type="radio" name="ai_provider" value="<?php echo esc_attr( $key ); ?>" <?php checked( $ai_provider, $key ); ?>>
								<span class="isp-provider-icon"><?php echo esc_html( $p['icon'] ); ?></span>
								<span class="isp-provider-name"><?php echo esc_html( $p['name'] ); ?></span>
								<span class="isp-provider-note"><?php echo esc_html( $p['note'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="isp-field">
					<label class="isp-label"><?php esc_html_e( 'Default AI Skill Profile', 'insightistic' ); ?></label>
					<select name="ai_skill_profile" class="isp-select">
						<option value="basic" <?php selected( $ai_skill_profile, 'basic' ); ?>><?php esc_html_e( 'Basic Insights', 'insightistic' ); ?></option>
						<option value="seo_expert" <?php selected( $ai_skill_profile, 'seo_expert' ); ?>><?php esc_html_e( 'SEO Expert Insights', 'insightistic' ); ?></option>
					</select>
					<p class="isp-hint"><?php esc_html_e( 'Basic gives general analytics insights. SEO Expert prioritizes traffic, CTR, ranking, and content optimization tasks.', 'insightistic' ); ?></p>
				</div>
			</div>

			<?php
			foreach ( array( 'openai', 'gemini', 'openrouter', 'groq', 'claude' ) as $prov ) :
				$display    = array( 'openai' => 'OpenAI', 'gemini' => 'Google Gemini', 'openrouter' => 'OpenRouter', 'groq' => 'Groq', 'claude' => 'Anthropic Claude' );
				$key_stored = array( 'openai' => $openai_stored, 'gemini' => $gemini_stored, 'openrouter' => $openrouter_stored, 'groq' => $groq_stored, 'claude' => $claude_stored );
				$cur_model  = array( 'openai' => $openai_model, 'gemini' => $gemini_model, 'openrouter' => $openrouter_model, 'groq' => $groq_model, 'claude' => $claude_model );
				$model_opts = array(
					'openai'     => array( 'gpt-4o-mini' => 'GPT-4o Mini (recommended)', 'gpt-4o' => 'GPT-4o', 'gpt-4-turbo' => 'GPT-4 Turbo' ),
					'gemini'     => array( 'gemini-1.5-flash' => 'Gemini 1.5 Flash (recommended)', 'gemini-1.5-pro' => 'Gemini 1.5 Pro', 'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash' ),
					'openrouter' => array(
						// --- Free tier models (:free suffix = no API cost) ---
						// Current as of the 3.2.1 release. If a slug ever 404s on
						// OpenRouter, use the custom-model input below with any
						// slug from https://openrouter.ai/models?max_price=0
						'meta-llama/llama-3.3-70b-instruct:free'      => 'Meta Llama 3.3 70B (Free - recommended)',
						'deepseek/deepseek-chat-v3-0324:free'         => 'DeepSeek Chat V3 (Free)',
						'deepseek/deepseek-r1-0528:free'              => 'DeepSeek R1 (Free - reasoning)',
						'google/gemma-2-9b-it:free'                   => 'Google Gemma 2 9B (Free)',
						'mistralai/mistral-small-3.2-24b-instruct:free' => 'Mistral Small 3.2 24B (Free)',
						'mistralai/mistral-small-3.1-24b-instruct:free' => 'Mistral Small 3.1 24B (Free)',
						'qwen/qwen3-14b:free'                         => 'Qwen 3 14B (Free)',
						'qwen/qwen3-8b:free'                          => 'Qwen 3 8B (Free)',
						'meta-llama/llama-3.2-3b-instruct:free'       => 'Meta Llama 3.2 3B (Free - fast)',
						'nvidia/nemotron-nano-9b-v2:free'             => 'NVIDIA Nemotron Nano 9B v2 (Free)',
						// --- Reliable paid fallbacks ---
						'openai/gpt-4o-mini'                          => 'GPT-4o Mini (Paid)',
						'anthropic/claude-3.5-haiku'                  => 'Claude 3.5 Haiku (Paid)',
					),
					'groq'       => array(
						'llama-3.1-8b-instant' => 'Llama 3.1 8B Instant',
						'llama-3.3-70b-versatile' => 'Llama 3.3 70B Versatile',
						'mixtral-8x7b-32768' => 'Mixtral 8x7B',
						'gemma2-9b-it' => 'Gemma2 9B IT',
					),
					'claude'     => array( 'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (recommended)', 'claude-sonnet-4-6' => 'Claude Sonnet 4.6', 'claude-opus-4-6' => 'Claude Opus 4.6' ),
				);
				$key_fields = array( 'openai' => 'openai_api_key', 'gemini' => 'gemini_api_key', 'openrouter' => 'openrouter_api_key', 'groq' => 'groq_api_key', 'claude' => 'claude_api_key' );
				$vis        = $ai_provider === $prov ? '' : 'display:none;';
				?>
			<div class="isp-settings-card isp-provider-settings" id="isp-settings-<?php echo esc_attr( $prov ); ?>" style="<?php echo esc_attr( $vis ); ?>">
				<div class="isp-settings-card-header">
					<h3><?php echo esc_html( $display[ $prov ] ); ?> <?php esc_html_e( 'Settings', 'insightistic' ); ?></h3>
				</div>
				<div class="isp-field">
					<label class="isp-label" for="ai_key_<?php echo esc_attr( $prov ); ?>"><?php esc_html_e( 'API Key', 'insightistic' ); ?></label>
					<?php if ( $key_stored[ $prov ] ) : ?>
					<div class="isp-key-stored">
						<span class="isp-key-badge"><?php esc_html_e( 'Key stored & encrypted', 'insightistic' ); ?></span>
						<?php if ( ! empty( $key_updated_at[ $prov ] ) ) : ?>
						<span class="isp-key-rotated-label"><?php echo esc_html( insightistic_format_rotated( $key_updated_at[ $prov ] ) ); ?></span>
						<?php endif; ?>
						<button type="button" class="isp-link-btn isp-change-ai-key" data-provider="<?php echo esc_attr( $prov ); ?>"><?php esc_html_e( 'Change key', 'insightistic' ); ?></button>
						<button type="button" class="isp-link-btn isp-link-danger isp-clear-ai-key" data-provider="<?php echo esc_attr( $prov ); ?>"><?php esc_html_e( 'Clear key', 'insightistic' ); ?></button>
					</div>
					<input type="text" id="ai_key_<?php echo esc_attr( $prov ); ?>" name="<?php echo esc_attr( $key_fields[ $prov ] ); ?>" class="isp-input"
						style="display:none;" placeholder="<?php esc_attr_e( 'Enter new key to replace', 'insightistic' ); ?>" autocomplete="off" />
					<?php else : ?>
					<input type="text" id="ai_key_<?php echo esc_attr( $prov ); ?>" name="<?php echo esc_attr( $key_fields[ $prov ] ); ?>" class="isp-input"
						placeholder="<?php esc_attr_e( 'Enter API key', 'insightistic' ); ?>" autocomplete="off" />
					<?php endif; ?>
				</div>
				<div class="isp-field">
					<label class="isp-label" for="<?php echo esc_attr( $prov ); ?>_model"><?php esc_html_e( 'Model', 'insightistic' ); ?></label>
					<select id="<?php echo esc_attr( $prov ); ?>_model" name="<?php echo esc_attr( $prov ); ?>_model" class="isp-select">
						<?php foreach ( $model_opts[ $prov ] as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cur_model[ $prov ], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="<?php echo esc_attr( $prov ); ?>_model_custom" class="isp-input" style="margin-top:8px;" placeholder="<?php esc_attr_e( 'Optional: paste any custom model slug (overrides dropdown when saved)', 'insightistic' ); ?>" />
					<?php if ( 'openrouter' === $prov ) : ?>
					<div class="isp-notice isp-notice-info" style="margin-top:10px; flex-direction:column; align-items:flex-start;">
						<strong style="margin-bottom:4px;"><?php esc_html_e( 'Heads up - free models on OpenRouter require a one-time opt-in.', 'insightistic' ); ?></strong>
						<?php
						printf(
							/* translators: 1: privacy URL, 2: free models URL */
							wp_kses(
								/* translators: 1: privacy settings URL, 2: free models catalog URL */
								__( 'Open %1$s and enable <em>"Free model providers may train on my prompts"</em>. Without it every <code>:free</code> model rejects requests with a "no endpoints / data policy" error. Once enabled, any current slug from %2$s will work.', 'insightistic' ),
								array(
									'em'   => array(),
									'code' => array(),
								)
							),
							'<a href="https://openrouter.ai/settings/privacy" target="_blank" rel="noopener noreferrer">openrouter.ai/settings/privacy</a>',
							'<a href="https://openrouter.ai/models?max_price=0" target="_blank" rel="noopener noreferrer">openrouter.ai/models?max_price=0</a>'
						);
						?>
					</div>
					<?php endif; ?>
				</div>
				<?php if ( $key_stored[ $prov ] ) : ?>
				<div class="isp-field isp-ai-test-field">
					<button type="button" class="isp-btn isp-btn-secondary isp-test-ai-provider" data-provider="<?php echo esc_attr( $prov ); ?>">
						<?php esc_html_e( 'Test This Provider', 'insightistic' ); ?>
					</button>
					<p class="isp-hint"><?php esc_html_e( 'Sends a 1-sentence prompt to verify the key, the model, and the network path.', 'insightistic' ); ?></p>
					<div class="isp-ai-test-result" style="margin-top:10px;"></div>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>

			<!-- Insightistic Cloud AI  no key: uses the free account connection. -->
			<div class="isp-settings-card isp-provider-settings" id="isp-settings-insightistic_cloud" style="<?php echo esc_attr( 'insightistic_cloud' === $ai_provider ? '' : 'display:none;' ); ?>">
				<div class="isp-settings-card-header">
					<h3><?php esc_html_e( 'Insightistic Cloud AI Settings', 'insightistic' ); ?></h3>
					<p class="isp-header-desc"><?php esc_html_e( 'Runs on our self-hosted models  no API key to paste. Free with your Insightistic account, with a fair-use limit per period.', 'insightistic' ); ?></p>
				</div>

				<?php if ( $cloud_connected ) : ?>
				<div class="isp-notice isp-notice-success">
					<?php esc_html_e( 'Connected  Insightistic Cloud AI is ready to use.', 'insightistic' ); ?>
				</div>
				<?php else : ?>
				<div class="isp-notice isp-notice-info">
					<?php esc_html_e( 'Create a free Insightistic account to use this provider  every other provider on this page still needs its own key.', 'insightistic' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-license' ) ); ?>"><?php esc_html_e( 'Create a free account →', 'insightistic' ); ?></a>
				</div>
				<?php endif; ?>

				<div class="isp-field">
					<label class="isp-label" for="insightistic_cloud_model"><?php esc_html_e( 'Engine', 'insightistic' ); ?></label>
					<select id="insightistic_cloud_model" name="insightistic_cloud_model" class="isp-select">
						<option value="ollama-balanced" <?php selected( $cloud_model, 'ollama-balanced' ); ?>><?php esc_html_e( 'Balanced (Ollama)  general analytics insights', 'insightistic' ); ?></option>
						<option value="hermes-seo" <?php selected( $cloud_model, 'hermes-seo' ); ?>><?php esc_html_e( 'SEO Specialist (Hermes)  organic-growth-tuned insights', 'insightistic' ); ?></option>
					</select>
					<p class="isp-hint"><?php esc_html_e( 'Balanced covers general GA4/GSC/commerce analysis. SEO Specialist routes through our Hermes agent, tuned for content decay, keyword intent, and technical SEO recommendations.', 'insightistic' ); ?></p>
				</div>

				<?php if ( $cloud_connected ) : ?>
				<div class="isp-field isp-ai-test-field">
					<button type="button" class="isp-btn isp-btn-secondary isp-test-ai-provider" data-provider="insightistic_cloud">
						<?php esc_html_e( 'Test This Provider', 'insightistic' ); ?>
					</button>
					<p class="isp-hint"><?php esc_html_e( 'Sends a 1-sentence prompt through the Insightistic Cloud AI backend to verify connectivity.', 'insightistic' ); ?></p>
					<div class="isp-ai-test-result" style="margin-top:10px;"></div>
				</div>
				<?php endif; ?>
			</div>

		</div><!-- /#isp-tab-ai -->

		<!-- ============================================= TAB: CLOUDFLARE -->
		<div class="isp-tab-panel" id="isp-tab-cloudflare" style="display:none;">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'Cloudflare Traffic Insights', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Edge-level traffic data — cache ratio, threats blocked, country/status breakdowns — that Google Analytics never sees. Always free; never required for anything else in the plugin.', 'insightistic' ); ?></p>
				</div>

				<?php if ( $cloud_connected ) : ?>
				<div class="isp-notice isp-notice-success">
					<?php esc_html_e( 'Your free Insightistic account is connected — Traffic Insights already works with no setup here. The fields below are only for people who\'d rather use their own Cloudflare API token instead of their account.', 'insightistic' ); ?>
				</div>
				<?php else : ?>
				<div class="isp-notice isp-notice-info">
					<?php esc_html_e( 'Nothing to configure here by default: create a free Insightistic account and Traffic Insights turns on automatically, with no Zone ID or API token to manage.', 'insightistic' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-license' ) ); ?>"><?php esc_html_e( 'Create a free account →', 'insightistic' ); ?></a>
				</div>
				<?php endif; ?>
			</div>

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2><?php esc_html_e( 'Advanced: bring your own Cloudflare token', 'insightistic' ); ?></h2>
					<p class="isp-header-desc"><?php esc_html_e( 'Optional. If you\'d rather not connect a free account, paste a Cloudflare API token here instead — Traffic Insights will use it directly, with no account involved.', 'insightistic' ); ?></p>
				</div>

				<details class="isp-guide-box">
					<summary><?php esc_html_e( '📘 Setup guide — create a read-only Cloudflare token in 2 minutes', 'insightistic' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'Open the Cloudflare dashboard and select your site (zone).', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Copy the Zone ID from the site\'s Overview page (right-hand sidebar) into the field below.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Go to My Profile → API Tokens → Create Token → Custom Token. Grant Zone → Zone → Read and Zone → Analytics → Read, scoped to this zone only.', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Paste the generated token below and Save Settings  it is stored encrypted, the same way every other credential in this plugin is. Then click Test Connection.', 'insightistic' ); ?></li>
					</ol>
					<div class="isp-guide-links">
						<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener"><?php esc_html_e( 'Create API Token ↗', 'insightistic' ); ?></a>
						<a href="https://developers.cloudflare.com/fundamentals/api/get-started/create-token/" target="_blank" rel="noopener"><?php esc_html_e( 'Official docs ↗', 'insightistic' ); ?></a>
					</div>
				</details>

				<div class="isp-field">
					<label class="isp-label" for="cf_zone_id">
						<?php esc_html_e( 'Zone ID', 'insightistic' ); ?>
					</label>
					<input type="text" id="cf_zone_id" name="cf_zone_id" class="isp-input"
						value="<?php echo esc_attr( $cf_zone_id ); ?>"
						placeholder="a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4" />
					<p class="isp-hint"><?php esc_html_e( 'Cloudflare dashboard → your site → Overview → Zone ID (right-hand sidebar).', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="cf_account_id">
						<?php esc_html_e( 'Account ID (optional)', 'insightistic' ); ?>
					</label>
					<input type="text" id="cf_account_id" name="cf_account_id" class="isp-input"
						value="<?php echo esc_attr( $cf_account_id ); ?>"
						placeholder="a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4" />
					<p class="isp-hint"><?php esc_html_e( 'Only needed for account-level reporting added in a later release. Safe to leave blank.', 'insightistic' ); ?></p>
				</div>

				<div class="isp-field">
					<label class="isp-label" for="cf_api_token">
						<?php esc_html_e( 'API Token', 'insightistic' ); ?>
					</label>
					<?php if ( $cf_token_stored ) : ?>
					<div class="isp-key-stored">
						<span class="isp-key-badge"> <?php esc_html_e( 'Token stored & encrypted', 'insightistic' ); ?></span>
						<button type="button" id="isp-change-cf-token" class="isp-link-btn"><?php esc_html_e( 'Change token', 'insightistic' ); ?></button>
					</div>
					<input type="text" id="cf_api_token" name="cf_api_token" class="isp-input"
						style="display:none;" placeholder="<?php esc_attr_e( 'Enter new token to replace', 'insightistic' ); ?>" autocomplete="off" />
					<?php else : ?>
					<input type="text" id="cf_api_token" name="cf_api_token" class="isp-input"
						placeholder="<?php esc_attr_e( 'Paste your Cloudflare API token', 'insightistic' ); ?>" autocomplete="off" />
					<?php endif; ?>
					<p class="isp-hint isp-hint-security"> <?php esc_html_e( 'Encrypted with AES-256 before storage, exactly like every other credential in this plugin.', 'insightistic' ); ?></p>
				</div>
			</div>

			<?php if ( $cf_zone_id && $cf_token_stored ) : ?>
			<div class="isp-settings-card">
				<div class="isp-test-card">
					<h3><?php esc_html_e( 'Test Cloudflare Connection', 'insightistic' ); ?></h3>
					<p><?php esc_html_e( 'Save your settings first, then test the connection.', 'insightistic' ); ?></p>
					<button type="button" id="isp-test-cloudflare" class="isp-btn isp-btn-secondary">
						<?php esc_html_e( 'Test Connection', 'insightistic' ); ?>
					</button>
					<div id="isp-test-cloudflare-result" style="margin-top:12px;"></div>
				</div>
			</div>
			<?php endif; ?>

		</div><!-- /#isp-tab-cloudflare -->

		<!-- ================================================ TAB: DOCS -->
		<div class="isp-tab-panel" id="isp-tab-guides" style="display:none;">

			<div class="isp-settings-card">
				<div class="isp-settings-card-header">
					<h2>Documentation Links</h2>
					<p class="isp-header-desc"> Check our guide links. These will help you to setup the plugin with a few steps.</p>
				</div>
				<div class="isp-field">
					<label class="isp-label" > Video Tutorial URL </label>
					<a href="https://www.youtube.com/@wordpressistic" target="_blank" class="isp-guide-link isp-guide-video">
						Watch Tutorial
					</a>
				</div>
				<div class="isp-field">
					<label class="isp-label" > Documentation URL
</label>
					<a href="https://www.chatbotistic.com/insightistic-installation-documents" target="_blank" class="isp-guide-link isp-guide-docs">
						Documentation
					</a>
				</div>
			</div>

		</div><!-- /#isp-tab-guides -->

		<!-- ================================================ SAVE BUTTON -->
		<div class="isp-form-actions">
			<button type="submit" class="isp-btn isp-btn-primary">
				<?php esc_html_e( 'Save Settings', 'insightistic' ); ?>
			</button>
		</div>

	</form>

	<div class="isp-footer">
		<p>
			<?php esc_html_e( 'Insightistic', 'insightistic' ); ?> v<?php echo esc_html( INSIGHTISTIC_VERSION ); ?> &bull;
			<a href="https://wordpressistic.com" target="_blank" rel="noopener noreferrer">WordPressistic</a>
		</p>
	</div>

</div><!-- /.isp-wrap -->



