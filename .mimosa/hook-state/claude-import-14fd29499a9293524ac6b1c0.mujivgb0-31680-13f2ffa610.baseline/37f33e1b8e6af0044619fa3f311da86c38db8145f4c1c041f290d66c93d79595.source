<?php
/**
 * Speed Test template — full Lighthouse categories, Core Web Vitals,
 * opportunities/diagnostics and the AI Agent Readiness score.
 *
 * All result markup is rendered client-side by renderSpeedTest() in admin.js
 * from the insightistic_speed_test AJAX payload.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isp_psi_configured = (bool) get_option( 'insightistic_pagespeed_api_key_enc' );
$isp_default_url    = get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) );
?>
<div class="wrap isp-wrap isp-speedtest-wrap">

	<div class="isp-header">
		<div class="isp-header-brand">
			<div>
				<h1 class="isp-header-title"><?php esc_html_e( 'Speed Test', 'insightistic' ); ?></h1>
				<p class="isp-header-sub"><?php esc_html_e( 'Full Lighthouse audit — performance, SEO, accessibility, best practices, Core Web Vitals and your AI Agent Readiness score.', 'insightistic' ); ?></p>
			</div>
		</div>
	</div>

	<?php if ( ! $isp_psi_configured ) : ?>

		<div class="isp-license-card isp-animate-in isp-speedtest-setup">
			<h2 class="isp-license-card-title"><?php esc_html_e( 'One quick step first', 'insightistic' ); ?></h2>
			<p><?php esc_html_e( 'The Speed Test uses Google’s free PageSpeed Insights API. Add your API key in Settings — it takes about two minutes and the settings page walks you through it.', 'insightistic' ); ?></p>
			<a class="isp-btn isp-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings&active_tab=pagespeed' ) ); ?>">
				<?php esc_html_e( 'Open PageSpeed settings →', 'insightistic' ); ?>
			</a>
		</div>

	<?php else : ?>

		<div class="isp-speedtest-bar isp-animate-in">
			<label class="screen-reader-text" for="isp-speedtest-url"><?php esc_html_e( 'URL to test', 'insightistic' ); ?></label>
			<input
				type="url"
				id="isp-speedtest-url"
				class="isp-input isp-url-input"
				value="<?php echo esc_url( $isp_default_url ); ?>"
				placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
				spellcheck="false"
			/>
			<button type="button" class="isp-btn isp-btn-primary" id="isp-speedtest-run">
				<span class="isp-btn-text"><?php esc_html_e( 'Run Speed Test', 'insightistic' ); ?></span>
			</button>
			<button type="button" class="isp-btn isp-btn-ghost" id="isp-speedtest-force" title="<?php esc_attr_e( 'Ignore the 1-hour cache and fetch fresh results', 'insightistic' ); ?>">
				<?php esc_html_e( 'Force refresh', 'insightistic' ); ?>
			</button>
		</div>

		<div id="isp-speedtest-status" class="isp-speedtest-status" style="display:none;" role="status"></div>

		<div id="isp-speedtest-results" class="isp-speedtest-results" style="display:none;">
			<!-- Filled by renderSpeedTest() in admin.js -->
		</div>

		<div id="isp-speedtest-empty" class="isp-speedtest-empty isp-animate-in isp-animate-delay-1">
			<div class="isp-speedtest-empty-art" aria-hidden="true">
				<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 1 10-10"/><path d="M12 6v6l4 2"/><path d="M19 16l2 2 4-4" transform="translate(-3 3) scale(0.9)"/></svg>
			</div>
			<h2><?php esc_html_e( 'Test any page in seconds', 'insightistic' ); ?></h2>
			<p><?php esc_html_e( 'Run a full audit for mobile and desktop: category scores, Core Web Vitals, the biggest speed opportunities, and how ready your page is for AI assistants and answer engines.', 'insightistic' ); ?></p>
		</div>

	<?php endif; ?>
</div>
