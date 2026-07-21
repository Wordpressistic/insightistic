<?php
/**
 * Dashboard template  tabbed layout.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_id = get_option( 'insightistic_property_id' );
$gsc_url     = get_option( 'insightistic_gsc_property_url' );
$psi_key     = get_option( 'insightistic_pagespeed_api_key_enc' );
$ai_enabled  = (int) get_option( 'insightistic_ai_enabled', 0 );
$ai_provider = get_option( 'insightistic_ai_provider', 'none' );
$default_url = get_option( 'insightistic_pagespeed_default_url', home_url( '/' ) );
$woo_active  = class_exists( 'Insightistic_Woocommerce' ) ? ( new Insightistic_Woocommerce() )->is_active() : false;
// Traffic Insights only ever renders when it can show *something* right
// now (BYO token configured, or a free account is connected)  never a
// setup nag. See Insightistic_Cloudflare::is_available().
$cf_available = class_exists( 'Insightistic_Cloudflare' ) && Insightistic_Cloudflare::is_available();
?>
<div class="wrap isp-wrap">

	<!-- ============================================================ HEADER -->
	<div class="isp-header">
		<div class="isp-header-brand">
			<img src="<?php echo esc_url( INSIGHTISTIC_URL . 'assets/images/wordpressistic-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'WordPressistic', 'insightistic' ); ?>"
				class="isp-logo">
			<div>
				<h1 class="isp-header-title"><?php esc_html_e( 'Insightistic Analytics', 'insightistic' ); ?></h1>
				<p class="isp-header-sub"><?php esc_html_e( 'GA4  Search Console  PageSpeed  AI Insights', 'insightistic' ); ?></p>
			</div>
		</div>
		<div class="isp-header-actions">
			<?php if ( ! $property_id ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings' ) ); ?>" class="isp-btn isp-btn-primary">
				<?php esc_html_e( 'Connect GA4', 'insightistic' ); ?>
			</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings' ) ); ?>" class="isp-btn isp-btn-ghost">
				<?php esc_html_e( 'Settings', 'insightistic' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-addons' ) ); ?>" class="isp-btn isp-btn-ghost">
				<?php esc_html_e( 'Addons', 'insightistic' ); ?>
			</a>
		</div>
	</div>

	<?php if ( ! $property_id ) : ?>
	<!-- ====================================================== SETUP NOTICE -->
	<div class="isp-setup-notice">
		<div class="isp-setup-icon"></div>
		<div>
			<h2><?php esc_html_e( 'Connect your Google Analytics 4 property', 'insightistic' ); ?></h2>
			<p><?php esc_html_e( 'Go to Settings and enter your GA4 Property ID, Service Account Email, and Private Key to start seeing your analytics data.', 'insightistic' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings' ) ); ?>" class="isp-btn isp-btn-primary">
				<?php esc_html_e( 'Open Settings', 'insightistic' ); ?>
			</a>
		</div>
	</div>
	<?php else : ?>

	<!-- ======================================================= DASHBOARD TABS -->
	<div class="isp-dash-tabs">
		<button class="isp-dash-tab isp-dash-tab-active" data-tab="overview">
			<?php esc_html_e( 'Overview', 'insightistic' ); ?>
		</button>
		<button class="isp-dash-tab" data-tab="search-console" <?php echo $gsc_url ? '' : 'title="' . esc_attr__( 'Configure Search Console in Settings', 'insightistic' ) . '"'; ?>>
			<?php esc_html_e( 'Search Console', 'insightistic' ); ?>
			<?php
			if ( ! $gsc_url ) :
				?>
				<span class="isp-tab-badge"><?php esc_html_e( 'Setup', 'insightistic' ); ?></span><?php endif; ?>
		</button>
		<button class="isp-dash-tab" data-tab="pagespeed" <?php echo $psi_key ? '' : 'title="' . esc_attr__( 'Configure PageSpeed in Settings', 'insightistic' ) . '"'; ?>>
			<?php esc_html_e( 'PageSpeed', 'insightistic' ); ?>
			<?php
			if ( ! $psi_key ) :
				?>
				<span class="isp-tab-badge"><?php esc_html_e( 'Setup', 'insightistic' ); ?></span><?php endif; ?>
		</button>
		<?php if ( $cf_available ) : ?>
		<button class="isp-dash-tab" data-tab="cloudflare">
			<?php esc_html_e( 'Traffic Insights', 'insightistic' ); ?>
		</button>
		<?php endif; ?>
		<?php if ( $woo_active ) : ?>
		<button class="isp-dash-tab" data-tab="commerce">
			<?php esc_html_e( 'Commerce', 'insightistic' ); ?>
		</button>
		<?php endif; ?>
	</div>

	<!-- ========================================= TAB: OVERVIEW (GA4) -->
	<div class="isp-tab-content" id="isp-dash-overview">

		<!-- TOOLBAR -->
		<div class="isp-toolbar">
			<div class="isp-toolbar-left">
				<label for="isp-date-range" class="screen-reader-text"><?php esc_html_e( 'Date range', 'insightistic' ); ?></label>
				<select id="isp-date-range" class="isp-select">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'insightistic' ); ?></option>
					<option value="28" selected><?php esc_html_e( 'Last 28 days', 'insightistic' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 days', 'insightistic' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'insightistic' ); ?></option>
					<option value="180"><?php esc_html_e( 'Last 6 months', 'insightistic' ); ?></option>
				</select>
				<button id="isp-load-data" class="isp-btn isp-btn-primary" aria-live="polite">
					<span class="isp-btn-icon"></span>
					<span class="isp-btn-text"><?php esc_html_e( 'Refresh Data', 'insightistic' ); ?></span>
				</button>
				<div id="isp-overview-cache" class="isp-cache-badge" aria-live="polite" style="display:none;"></div>
			</div>
			<?php if ( $ai_enabled && 'none' !== $ai_provider ) : ?>
			<button id="isp-ai-analyze" class="isp-btn isp-btn-ai" style="display:none;" title="<?php esc_attr_e( 'AI runs on demand only  never auto-triggered', 'insightistic' ); ?>">
				<?php esc_html_e( 'Get AI Insights', 'insightistic' ); ?>
			</button>
			<?php endif; ?>
		</div>

		<!-- ROW 1 STAT CARDS: Sessions, Visitors, Pageviews, Avg Duration -->
		<div id="isp-overview-cards" class="isp-overview-cards isp-overview-cards-8">
			<div class="isp-stat-card" id="isp-card-sessions">
				<div class="isp-stat-icon isp-icon-blue"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Sessions', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-sessions"></div>
					<div class="isp-stat-change" id="isp-chg-sessions"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-users">
				<div class="isp-stat-icon isp-icon-purple"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Unique Visitors', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-users"></div>
					<div class="isp-stat-change" id="isp-chg-users"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-pageviews">
				<div class="isp-stat-icon isp-icon-cyan"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Pageviews', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-pageviews"></div>
					<div class="isp-stat-change" id="isp-chg-pageviews"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-duration">
				<div class="isp-stat-icon isp-icon-indigo"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Avg. Session', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-duration"></div>
					<div class="isp-stat-change" id="isp-chg-duration"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-bounce">
				<div class="isp-stat-icon isp-icon-orange"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Bounce Rate', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-bounce"></div>
					<div class="isp-stat-change" id="isp-chg-bounce"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-newreturn">
				<div class="isp-stat-icon isp-icon-teal"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'New vs Return', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-newreturn"></div>
					<div id="isp-newreturn-bar" class="isp-newreturn-bar"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-revenue">
				<div class="isp-stat-icon isp-icon-green"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Revenue', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-revenue"></div>
					<div class="isp-stat-change" id="isp-chg-revenue"></div>
				</div>
			</div>
			<div class="isp-stat-card" id="isp-card-tx">
				<div class="isp-stat-icon isp-icon-amber"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Transactions', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-val-tx"></div>
					<div class="isp-stat-change" id="isp-chg-tx"></div>
				</div>
			</div>
		</div>

		<!-- CHARTS -->
		<div id="isp-charts" class="isp-charts" style="display:none;">
			<div class="isp-chart-card isp-chart-wide">
				<div class="isp-chart-header">
					<span class="isp-chart-title"><?php esc_html_e( 'Traffic &amp; Revenue Over Time', 'insightistic' ); ?></span>
					<div class="isp-chart-legend">
						<span class="isp-legend-dot isp-dot-blue"></span><?php esc_html_e( 'Sessions', 'insightistic' ); ?>
						<span class="isp-legend-dot isp-dot-green"></span><?php esc_html_e( 'Revenue', 'insightistic' ); ?>
					</div>
				</div>
				<div class="isp-chart-body">
					<canvas id="isp-chart-timeline" aria-label="<?php esc_attr_e( 'Traffic and revenue chart', 'insightistic' ); ?>" role="img"></canvas>
				</div>
			</div>
			<div class="isp-chart-card">
				<div class="isp-chart-header">
					<span class="isp-chart-title"><?php esc_html_e( 'Traffic by Source', 'insightistic' ); ?></span>
				</div>
				<div class="isp-chart-body">
					<canvas id="isp-chart-sources" aria-label="<?php esc_attr_e( 'Traffic sources donut chart', 'insightistic' ); ?>" role="img"></canvas>
				</div>
			</div>
		</div>

		<!-- DETAIL CARDS: Countries + Pages -->
		<div id="isp-detail-cards" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Countries', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by sessions', 'insightistic' ); ?></span>
				</div>
				<ul class="isp-rank-list" id="isp-countries-list">
					<li class="isp-rank-loading"><?php esc_html_e( 'Loading', 'insightistic' ); ?></li>
				</ul>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Pages', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by pageviews', 'insightistic' ); ?></span>
				</div>
				<ul class="isp-rank-list" id="isp-pages-list">
					<li class="isp-rank-loading"><?php esc_html_e( 'Loading', 'insightistic' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- TRAFFIC CHANNELS + TOP POSTS -->
		<div id="isp-content-cards" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Traffic Channels', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by sessions', 'insightistic' ); ?></span>
				</div>
				<div id="isp-channels-table" class="isp-channels-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Posts', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'blog content', 'insightistic' ); ?></span>
				</div>
				<ul class="isp-rank-list" id="isp-posts-list">
					<li class="isp-rank-loading"><?php esc_html_e( 'Loading', 'insightistic' ); ?></li>
				</ul>
			</div>
		</div>

		<!-- ATTRIBUTION TABLE -->
		<div class="isp-section-header" id="isp-table-header" style="display:none;">
			<h2 class="isp-section-title"> <?php esc_html_e( 'Source / Medium Attribution', 'insightistic' ); ?></h2>
		</div>
		<div id="isp-data-container" class="isp-data-container">
			<div class="isp-skeleton-wrap" role="status" aria-live="polite">
				<span class="screen-reader-text"><?php esc_html_e( 'Loading your analytics data', 'insightistic' ); ?></span>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row isp-skeleton-row-sm"></div>
			</div>
		</div>

		<!-- 404 & BROKEN LINK MONITOR -->
		<div class="isp-section-header">
			<h2 class="isp-section-title"><?php esc_html_e( '404 & Broken Link Monitor', 'insightistic' ); ?></h2>
		</div>
		<div class="isp-detail-card" id="isp-404-card" style="margin-bottom:20px;">
			<div class="isp-detail-card-header">
				<span class="isp-detail-card-title"><?php esc_html_e( 'Most-hit broken URLs', 'insightistic' ); ?></span>
				<span>
					<button type="button" id="isp-404-refresh" class="isp-link-btn"><?php esc_html_e( 'Refresh', 'insightistic' ); ?></button>
					<button type="button" id="isp-404-clear" class="isp-link-btn isp-link-danger"><?php esc_html_e( 'Clear log', 'insightistic' ); ?></button>
				</span>
			</div>
			<div id="isp-404-table"><p class="isp-no-data"><?php esc_html_e( 'Loading', 'insightistic' ); ?></p></div>
		</div>

		<!-- AI INSIGHTS -->
		<div id="isp-ai-insights" class="isp-ai-container" style="display:none;"></div>

	</div><!-- /#isp-dash-overview -->

	<!-- ========================================= TAB: SEARCH CONSOLE -->
	<div class="isp-tab-content" id="isp-dash-search-console" style="display:none;">

		<?php if ( ! $gsc_url ) : ?>
		<div class="isp-setup-notice">
			<div class="isp-setup-icon"></div>
			<div>
				<h2><?php esc_html_e( 'Connect Google Search Console', 'insightistic' ); ?></h2>
				<p><?php esc_html_e( 'Add your Search Console property URL and grant access to your service account to see keyword and ranking data.', 'insightistic' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings#gsc' ) ); ?>" class="isp-btn isp-btn-primary">
					<?php esc_html_e( 'Configure Search Console', 'insightistic' ); ?>
				</a>
			</div>
		</div>
		<?php else : ?>

		<div class="isp-toolbar">
			<div class="isp-toolbar-left">
				<label for="isp-gsc-date-range" class="screen-reader-text"><?php esc_html_e( 'Date range', 'insightistic' ); ?></label>
				<select id="isp-gsc-date-range" class="isp-select">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'insightistic' ); ?></option>
					<option value="28" selected><?php esc_html_e( 'Last 28 days', 'insightistic' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'insightistic' ); ?></option>
				</select>
				<button id="isp-load-gsc" class="isp-btn isp-btn-primary" aria-live="polite">
					<span class="isp-btn-icon"></span>
					<span class="isp-btn-text"><?php esc_html_e( 'Load Data', 'insightistic' ); ?></span>
				</button>
				<div id="isp-gsc-cache" class="isp-cache-badge" aria-live="polite" style="display:none;"></div>
			</div>
			<div class="isp-gsc-delay-note">
				<span></span>
				<?php esc_html_e( 'Search Console data has a 23 day delay and shows up to 16 months.', 'insightistic' ); ?>
			</div>
		</div>

		<!-- GSC STAT CARDS -->
		<div id="isp-gsc-cards" class="isp-overview-cards isp-overview-cards-4" style="display:none;">
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-blue"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Total Clicks', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-gsc-clicks"></div>
					<div class="isp-stat-change" id="isp-gsc-clicks-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-purple"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Impressions', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-gsc-impr"></div>
					<div class="isp-stat-change" id="isp-gsc-impr-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-green"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Avg. CTR', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-gsc-ctr"></div>
					<div class="isp-stat-change" id="isp-gsc-ctr-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-amber"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Avg. Position', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-gsc-pos"></div>
					<div class="isp-stat-change" id="isp-gsc-pos-chg"></div>
				</div>
			</div>
		</div>

		<!-- GSC TABLES -->
		<div id="isp-gsc-tables" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Queries', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by clicks', 'insightistic' ); ?></span>
				</div>
				<div id="isp-gsc-queries" class="isp-table-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Pages', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by clicks', 'insightistic' ); ?></span>
				</div>
				<div id="isp-gsc-pages" class="isp-table-wrap"></div>
			</div>
		</div>

		<!-- DEVICE BREAKDOWN -->
		<div id="isp-gsc-devices" class="isp-detail-card isp-device-card" style="display:none;">
			<div class="isp-detail-card-header">
				<span class="isp-detail-card-title"> <?php esc_html_e( 'Device Breakdown', 'insightistic' ); ?></span>
				<span class="isp-detail-card-sub"><?php esc_html_e( 'by clicks', 'insightistic' ); ?></span>
			</div>
			<div id="isp-gsc-device-bars" class="isp-device-bars"></div>
		</div>

		<!-- GSC LOADING STATE -->
		<div id="isp-gsc-loading" class="isp-data-container">
			<div class="isp-skeleton-wrap" role="status" aria-live="polite">
				<span class="screen-reader-text"><?php esc_html_e( 'Loading Search Console data', 'insightistic' ); ?></span>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row isp-skeleton-row-sm"></div>
			</div>
		</div>

		<?php endif; ?>
	</div><!-- /#isp-dash-search-console -->

	<!-- ========================================= TAB: PAGESPEED -->
	<div class="isp-tab-content" id="isp-dash-pagespeed" style="display:none;">

		<?php if ( ! $psi_key ) : ?>
		<div class="isp-setup-notice">
			<div class="isp-setup-icon"></div>
			<div>
				<h2><?php esc_html_e( 'Set Up PageSpeed Insights', 'insightistic' ); ?></h2>
				<p><?php esc_html_e( 'Add your Google Cloud API key to start testing your page performance scores and Core Web Vitals.', 'insightistic' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings#pagespeed' ) ); ?>" class="isp-btn isp-btn-primary">
					<?php esc_html_e( 'Configure PageSpeed', 'insightistic' ); ?>
				</a>
			</div>
		</div>
		<?php else : ?>

		<div class="isp-toolbar">
			<div class="isp-toolbar-left">
				<label for="isp-psi-url" class="screen-reader-text"><?php esc_html_e( 'URL to test', 'insightistic' ); ?></label>
				<input type="url" id="isp-psi-url" class="isp-input isp-url-input"
					placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
					value="<?php echo esc_attr( $default_url ); ?>">
				<button id="isp-run-pagespeed" class="isp-btn isp-btn-primary" aria-live="polite">
					<span class="isp-btn-icon"></span>
					<span class="isp-btn-text"><?php esc_html_e( 'Run Test', 'insightistic' ); ?></span>
				</button>
				<div id="isp-psi-cache" class="isp-cache-badge" aria-live="polite" style="display:none;"></div>
			</div>
		</div>

		<!-- SPEED SCORE RINGS -->
		<div id="isp-psi-results" style="display:none;">

			<div class="isp-speed-scores">
				<div class="isp-speed-score-card">
					<div class="isp-speed-label"> <?php esc_html_e( 'Mobile', 'insightistic' ); ?></div>
					<div class="isp-ring-container">
						<svg class="isp-score-ring" viewBox="0 0 120 120" aria-hidden="true">
							<circle class="isp-ring-track" cx="60" cy="60" r="54"/>
							<circle class="isp-ring-progress" id="isp-mobile-ring" cx="60" cy="60" r="54"/>
						</svg>
						<div class="isp-ring-score" id="isp-mobile-score"></div>
					</div>
					<div class="isp-speed-score-label" id="isp-mobile-label"></div>
				</div>
				<div class="isp-speed-score-card">
					<div class="isp-speed-label"> <?php esc_html_e( 'Desktop', 'insightistic' ); ?></div>
					<div class="isp-ring-container">
						<svg class="isp-score-ring" viewBox="0 0 120 120" aria-hidden="true">
							<circle class="isp-ring-track" cx="60" cy="60" r="54"/>
							<circle class="isp-ring-progress" id="isp-desktop-ring" cx="60" cy="60" r="54"/>
						</svg>
						<div class="isp-ring-score" id="isp-desktop-score"></div>
					</div>
					<div class="isp-speed-score-label" id="isp-desktop-label"></div>
				</div>
				<div class="isp-speed-legend">
					<div class="isp-speed-legend-item"><span class="isp-speed-dot isp-speed-good"></span><?php esc_html_e( '90100: Good', 'insightistic' ); ?></div>
					<div class="isp-speed-legend-item"><span class="isp-speed-dot isp-speed-moderate"></span><?php esc_html_e( '5089: Needs Improvement', 'insightistic' ); ?></div>
					<div class="isp-speed-legend-item"><span class="isp-speed-dot isp-speed-poor"></span><?php esc_html_e( '049: Poor', 'insightistic' ); ?></div>
				</div>
			</div>

			<!-- CORE WEB VITALS -->
			<div class="isp-cwv-section">
				<div class="isp-section-header">
					<h2 class="isp-section-title"> <?php esc_html_e( 'Core Web Vitals', 'insightistic' ); ?></h2>
				</div>
				<div class="isp-cwv-tabs">
					<button class="isp-cwv-tab isp-cwv-tab-active" data-cwv-tab="mobile"><?php esc_html_e( 'Mobile', 'insightistic' ); ?></button>
					<button class="isp-cwv-tab" data-cwv-tab="desktop"><?php esc_html_e( 'Desktop', 'insightistic' ); ?></button>
				</div>
				<div id="isp-cwv-mobile" class="isp-cwv-grid"></div>
				<div id="isp-cwv-desktop" class="isp-cwv-grid" style="display:none;"></div>
			</div>

		</div><!-- /#isp-psi-results -->

		<div id="isp-psi-loading" class="isp-data-container">
			<div class="isp-skeleton-wrap" role="status" aria-live="polite">
				<span class="screen-reader-text"><?php esc_html_e( 'Loading PageSpeed report', 'insightistic' ); ?></span>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row isp-skeleton-row-sm"></div>
			</div>
		</div>

		<?php endif; ?>
	</div><!-- /#isp-dash-pagespeed -->

	<!-- ========================================= TAB: CLOUDFLARE TRAFFIC INSIGHTS -->
		<?php if ( $cf_available ) : ?>
	<div class="isp-tab-content" id="isp-dash-cloudflare" style="display:none;">

		<!-- Shown instead of the dashboard when the connected account has no
			Cloudflare zone linked yet -- an expected state, not an error, so
			this stays calm rather than a hard error banner. -->
		<div id="isp-cf-not-linked" class="isp-notice isp-notice-info" style="display:none;">
			<?php
			printf(
				wp_kses(
					/* translators: %s: Insightistic account URL */
					__( 'Connect Cloudflare in your <a href="%s" target="_blank" rel="noopener">Insightistic account</a> to see Traffic Insights here — no Zone ID or API token needed.', 'insightistic' ),
					array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
				),
				esc_url( 'https://app.insightistic.com/' )
			);
			?>
		</div>

		<div class="isp-toolbar">
			<div class="isp-toolbar-left">
				<label for="isp-cf-date-range" class="screen-reader-text"><?php esc_html_e( 'Date range', 'insightistic' ); ?></label>
				<select id="isp-cf-date-range" class="isp-select">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'insightistic' ); ?></option>
					<option value="28" selected><?php esc_html_e( 'Last 28 days', 'insightistic' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'insightistic' ); ?></option>
				</select>
				<button id="isp-load-cf" class="isp-btn isp-btn-primary" aria-live="polite">
					<span class="isp-btn-icon"></span>
					<span class="isp-btn-text"><?php esc_html_e( 'Load Data', 'insightistic' ); ?></span>
				</button>
				<div id="isp-cf-cache" class="isp-cache-badge" aria-live="polite" style="display:none;"></div>
			</div>
			<?php if ( $ai_enabled && 'none' !== $ai_provider ) : ?>
			<button id="isp-cf-ai-analyze" class="isp-btn isp-btn-ai" style="display:none;">
				<?php esc_html_e( 'Get Traffic AI Insights', 'insightistic' ); ?>
			</button>
			<?php endif; ?>
		</div>

		<div id="isp-cf-loading"></div>

		<!-- STAT CARDS -->
		<div id="isp-cf-cards" class="isp-overview-cards isp-overview-cards-4" style="display:none;">
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-blue"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Edge Requests', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-cf-requests"></div>
				</div>
			</div>
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-green"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Cache Hit Rate', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-cf-cache-ratio"></div>
				</div>
			</div>
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-amber"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Threats Blocked', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-cf-threats"></div>
				</div>
			</div>
			<div class="isp-stat-card">
				<div class="isp-stat-icon isp-icon-purple"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Encrypted Traffic', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-cf-encrypted"></div>
				</div>
			</div>
		</div>

		<!-- TRAFFIC GAP CALLOUT (Phase 3) -->
		<div id="isp-cf-gap-callout" style="display:none;"></div>

		<!-- TIMELINE CHART -->
		<div id="isp-cf-charts" class="isp-charts" style="display:none;">
			<div class="isp-chart-card">
				<div class="isp-chart-header">
					<span class="isp-chart-title"><?php esc_html_e( 'Edge Requests Over Time', 'insightistic' ); ?></span>
				</div>
				<div class="isp-chart-body">
					<canvas id="isp-cf-chart-timeline" aria-label="<?php esc_attr_e( 'Cloudflare requests over time', 'insightistic' ); ?>" role="img"></canvas>
				</div>
			</div>
			<div class="isp-chart-card">
				<div class="isp-chart-header">
					<span class="isp-chart-title"><?php esc_html_e( 'Status Codes', 'insightistic' ); ?></span>
				</div>
				<div class="isp-chart-body">
					<canvas id="isp-cf-chart-status" aria-label="<?php esc_attr_e( 'Response status code breakdown', 'insightistic' ); ?>" role="img"></canvas>
				</div>
			</div>
		</div>

		<!-- DETAIL TABLES -->
		<div id="isp-cf-detail-cards" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"><?php esc_html_e( 'Top Countries', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by edge requests', 'insightistic' ); ?></span>
				</div>
				<ul class="isp-rank-list" id="isp-cf-countries-list"></ul>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"><?php esc_html_e( 'TLS Version Mix', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by requests', 'insightistic' ); ?></span>
				</div>
				<ul class="isp-rank-list" id="isp-cf-tls-list"></ul>
			</div>
		</div>

		<!-- SECURITY MONITOR (Phase 4) -->
		<div id="isp-cf-security" style="display:none;"></div>

		<!-- AI INSIGHTS (Phase 5) -->
		<div id="isp-cf-ai-insights" class="isp-ai-container" style="display:none;"></div>

	</div><!-- /#isp-dash-cloudflare -->
	<?php endif; ?>


		<?php if ( $woo_active ) : ?>
	<!-- ========================================= TAB: COMMERCE (Woo) -->
	<div class="isp-tab-content" id="isp-dash-commerce" style="display:none;">

		<!-- TOOLBAR -->
		<div class="isp-toolbar">
			<div class="isp-toolbar-left">
				<label for="isp-woo-date-range" class="screen-reader-text"><?php esc_html_e( 'Date range', 'insightistic' ); ?></label>
				<select id="isp-woo-date-range" class="isp-select">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'insightistic' ); ?></option>
					<option value="28" selected><?php esc_html_e( 'Last 28 days', 'insightistic' ); ?></option>
					<option value="30"><?php esc_html_e( 'Last 30 days', 'insightistic' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'insightistic' ); ?></option>
					<option value="180"><?php esc_html_e( 'Last 6 months', 'insightistic' ); ?></option>
				</select>
				<button id="isp-load-woo" class="isp-btn isp-btn-primary" aria-live="polite">
					<span class="isp-btn-icon"></span>
					<span class="isp-btn-text"><?php esc_html_e( 'Load Commerce Data', 'insightistic' ); ?></span>
				</button>
				<div id="isp-woo-cache" class="isp-cache-badge" aria-live="polite" style="display:none;"></div>
			</div>
			<?php if ( $ai_enabled && 'none' !== $ai_provider ) : ?>
			<button id="isp-woo-ai-analyze" class="isp-btn isp-btn-ai" style="display:none;">
				<?php esc_html_e( 'Get Commerce AI Insights', 'insightistic' ); ?>
			</button>
			<?php endif; ?>
		</div>

		<!-- COMMERCE KPI CARDS -->
		<div id="isp-woo-cards" class="isp-overview-cards isp-overview-cards-8" style="display:none;">
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-green"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Gross Revenue', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-revenue"></div>
					<div class="isp-stat-change" id="isp-woo-revenue-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-teal"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Net Revenue', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-net"></div>
					<div class="isp-stat-change" id="isp-woo-net-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-blue"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Orders', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-orders"></div>
					<div class="isp-stat-change" id="isp-woo-orders-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-indigo"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Average Order Value', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-aov"></div>
					<div class="isp-stat-change" id="isp-woo-aov-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-orange"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Refund Rate', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-refundrate"></div>
					<div class="isp-stat-change" id="isp-woo-refundrate-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-purple"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'New Customers', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-newcust"></div>
					<div class="isp-stat-change" id="isp-woo-newcust-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-cyan"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Repeat Rate', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-repeat"></div>
					<div class="isp-stat-change" id="isp-woo-repeat-chg"></div>
				</div>
			</div>
			<div class="isp-stat-card"><div class="isp-stat-icon isp-icon-amber"></div>
				<div class="isp-stat-body">
					<div class="isp-stat-label"><?php esc_html_e( 'Units Sold', 'insightistic' ); ?></div>
					<div class="isp-stat-value" id="isp-woo-units"></div>
					<div class="isp-stat-change" id="isp-woo-units-chg"></div>
				</div>
			</div>
		</div>

		<!-- CHARTS -->
		<div id="isp-woo-charts" class="isp-charts" style="display:none;">
			<div class="isp-chart-card isp-chart-wide">
				<div class="isp-chart-header">
					<span class="isp-chart-title"><?php esc_html_e( 'Revenue & Orders Over Time', 'insightistic' ); ?></span>
				</div>
				<div class="isp-chart-body">
					<canvas id="isp-woo-chart-timeline" aria-label="<?php esc_attr_e( 'Commerce revenue and orders chart', 'insightistic' ); ?>" role="img"></canvas>
				</div>
			</div>
			<div class="isp-chart-card">
				<div class="isp-chart-header">
					<span class="isp-chart-title"><?php esc_html_e( 'Order Status', 'insightistic' ); ?></span>
				</div>
				<div class="isp-chart-body">
					<canvas id="isp-woo-chart-status" aria-label="<?php esc_attr_e( 'Order status breakdown', 'insightistic' ); ?>" role="img"></canvas>
				</div>
			</div>
		</div>

		<!-- TOP PRODUCTS + CATEGORIES -->
		<div id="isp-woo-prod-cards" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Products', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by revenue', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-products" class="isp-table-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Categories', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by revenue', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-categories" class="isp-table-wrap"></div>
			</div>
		</div>

		<!-- TOP CUSTOMERS + RECENT ORDERS -->
		<div id="isp-woo-cust-cards" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Customers', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by lifetime spend in period', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-customers" class="isp-table-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Recent Orders', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'latest paid orders', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-recent" class="isp-table-wrap"></div>
			</div>
		</div>

		<!-- GEO + PAYMENTS -->
		<div id="isp-woo-geo-cards" class="isp-detail-cards" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Top Countries', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by revenue', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-geo" class="isp-table-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Payment Methods', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'by revenue', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-payments" class="isp-table-wrap"></div>
			</div>
		</div>

		<!-- COUPONS + REFUNDS + LOW STOCK -->
		<div id="isp-woo-ops-cards" class="isp-detail-cards isp-detail-cards-3" style="display:none;">
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Coupons', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'in period', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-coupons" class="isp-table-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Refunds', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'reasons & totals', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-refunds" class="isp-table-wrap"></div>
			</div>
			<div class="isp-detail-card">
				<div class="isp-detail-card-header">
					<span class="isp-detail-card-title"> <?php esc_html_e( 'Low Stock', 'insightistic' ); ?></span>
					<span class="isp-detail-card-sub"><?php esc_html_e( 'at or under threshold', 'insightistic' ); ?></span>
				</div>
				<div id="isp-woo-lowstock" class="isp-table-wrap"></div>
			</div>
		</div>

		<!-- AI INSIGHTS -->
		<div id="isp-woo-ai-insights" class="isp-ai-container" style="display:none;"></div>

		<!-- LOADING STATE -->
		<div id="isp-woo-loading" class="isp-data-container">
			<div class="isp-skeleton-wrap" role="status" aria-live="polite">
				<span class="screen-reader-text"><?php esc_html_e( 'Loading commerce data', 'insightistic' ); ?></span>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row"></div>
				<div class="isp-skeleton-block isp-skeleton-row isp-skeleton-row-sm"></div>
			</div>
		</div>

	</div><!-- /#isp-dash-commerce -->
	<?php endif; ?>

	<?php endif; ?>

	<!-- ============================================================ FOOTER -->
	<div class="isp-footer">
		<p>
			<?php esc_html_e( 'Insightistic', 'insightistic' ); ?> v<?php echo esc_html( INSIGHTISTIC_VERSION ); ?> &bull;
			<a href="https://wordpressistic.com" target="_blank" rel="noopener noreferrer">WordPressistic</a>
		</p>
	</div>

</div><!-- /.isp-wrap -->




