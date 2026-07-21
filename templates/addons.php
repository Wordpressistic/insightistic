<?php
/**
 * Addons showcase template.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$addons_state     = get_option( 'insightistic_addons', array() );
$email_config     = get_option( 'insightistic_email_automations', array() );
$email_enabled    = ! empty( $addons_state['email_automations'] );
$email_recipients = ! empty( $email_config['recipients'] ) ? $email_config['recipients'] : get_option( 'admin_email' );
$email_frequency  = ! empty( $email_config['frequency'] ) ? $email_config['frequency'] : 'weekly';
$email_day        = ! empty( $email_config['day'] ) ? $email_config['day'] : 'monday';
$email_time       = ! empty( $email_config['time'] ) ? $email_config['time'] : '09:00';
$email_next_run   = wp_next_scheduled( Insightistic_Email_Automations::CRON_HOOK );

if ( ! function_exists( 'insightistic_addon_icon_svg' ) ) {
	/**
	 * Small, self-contained line-icon set for the addon cards (stroke-based,
	 * matches the visual language already used by .isp-locked-icon).
	 *
	 * @param string $key Icon key.
	 * @return string Trusted static SVG markup (no user input).
	 */
	function insightistic_addon_icon_svg( $key ) {
		$paths = array(
			'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path>',
			'search' => '<circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path>',
			'bell'   => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path>',
			'layers' => '<rect x="4" y="4" width="16" height="4" rx="1"></rect><rect x="4" y="10" width="16" height="4" rx="1"></rect><rect x="4" y="16" width="10" height="4" rx="1"></rect>',
			'bag'    => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path>',
		);
		$path  = $paths[ $key ] ?? $paths['layers'];
		return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
	}
}

$addons = array(
	array(
		'slug'  => 'email_automations',
		'icon'  => 'mail',
		'color' => 'blue',
		'name'  => __( 'Email Automations', 'insightistic' ),
		'desc'  => __( 'Send branded growth digests with KPIs, top channels, top pages, content winners, and action recommendations.', 'insightistic' ),
	),
	array(
		'slug'  => 'seo_opportunities',
		'icon'  => 'search',
		'color' => 'purple',
		'name'  => __( 'SEO Opportunity Finder', 'insightistic' ),
		'desc'  => __( 'Find high-impression, low-CTR queries and turn them into prioritized optimization tasks.', 'insightistic' ),
	),
	array(
		'slug'  => 'anomaly_alerts',
		'icon'  => 'bell',
		'color' => 'amber',
		'name'  => __( 'Anomaly Alerts', 'insightistic' ),
		'desc'  => __( 'Detect unusual traffic, revenue, pageview, and bounce-rate movements from refreshed dashboard data.', 'insightistic' ),
	),
	array(
		'slug'  => 'content_lab',
		'icon'  => 'layers',
		'color' => 'green',
		'name'  => __( 'Content Performance Lab', 'insightistic' ),
		'desc'  => __( 'Turn top posts and pages into internal-link, refresh, and conversion opportunities.', 'insightistic' ),
	),
	array(
		'slug'  => 'woocommerce_pro',
		'icon'  => 'bag',
		'color' => 'indigo',
		'name'  => __( 'WooCommerce Intelligence', 'insightistic' ),
		'desc'  => __( 'Free commerce intelligence for revenue, orders, products, and conversion decisions.', 'insightistic' ),
	),
);

// Only Email Automations resolves to a gated feature today (email delivery
// needs a free connected account); everything else is unconditionally free.
foreach ( $addons as &$addon ) {
	$feature         = Insightistic_Feature_Gate::addon_feature( $addon['slug'] );
	$addon['locked'] = $feature && ! Insightistic_Feature_Gate::can( $feature );
	$addon['status'] = $addon['locked'] ? 'locked' : 'available';
	$addon['type']   = $addon['locked'] ? __( 'Account required', 'insightistic' ) : __( 'Free', 'insightistic' );
}
unset( $addon );
?>
<div class="wrap isp-wrap isp-addons-wrap">

	<div class="isp-header">
		<div class="isp-header-brand">
			<img src="<?php echo esc_url( INSIGHTISTIC_URL . 'assets/images/wordpressistic-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'WordPressistic', 'insightistic' ); ?>"
				class="isp-logo">
			<div>
				<h1 class="isp-header-title"><?php esc_html_e( 'Insightistic Addons', 'insightistic' ); ?></h1>
				<p class="isp-header-sub"><?php esc_html_e( 'Five free growth modules — every one of them', 'insightistic' ); ?></p>
			</div>
		</div>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-system-status' ) ); ?>" class="isp-btn isp-btn-ghost">
			<?php esc_html_e( 'System Status', 'insightistic' ); ?>
		</a>
	</div>

	<div class="isp-addons-hero">
		<div class="isp-addons-hero-text">
			<h2><?php esc_html_e( 'Every module. No paywall.', 'insightistic' ); ?></h2>
			<p><?php esc_html_e( 'WooCommerce, SEO, anomaly, and content intelligence work the moment you flip them on — no account needed. Email Automations sends through your free Insightistic account.', 'insightistic' ); ?></p>
		</div>
		<div class="isp-addons-hero-stats">
			<div class="isp-addon-stat">
				<span class="isp-addon-stat-num">5</span>
				<span class="isp-addon-stat-lbl"><?php esc_html_e( 'Launch addons', 'insightistic' ); ?></span>
			</div>
			<div class="isp-addon-stat">
				<span class="isp-addon-stat-num">5</span>
				<span class="isp-addon-stat-lbl"><?php esc_html_e( 'Free addons', 'insightistic' ); ?></span>
			</div>
		</div>
	</div>

	<div class="isp-addons-filter">
		<button class="isp-addon-filter-btn isp-addon-filter-active" data-filter="all"><?php esc_html_e( 'All', 'insightistic' ); ?></button>
		<button class="isp-addon-filter-btn" data-filter="available"><?php esc_html_e( 'Ready to use', 'insightistic' ); ?></button>
		<button class="isp-addon-filter-btn" data-filter="locked"><?php esc_html_e( 'Needs free account', 'insightistic' ); ?></button>
	</div>

	<div class="isp-addons-grid" id="isp-addons-grid">
		<?php
		foreach ( $addons as $addon ) :
			$type_class    = $addon['locked'] ? 'isp-addon-type-locked' : 'isp-addon-type-free';
			$addon_enabled = ! empty( $addons_state[ $addon['slug'] ] );
			?>
			<div class="isp-addon-card" data-type="<?php echo esc_attr( $addon['type'] ); ?>" data-status="<?php echo esc_attr( $addon['status'] ); ?>" data-addon-slug="<?php echo esc_attr( $addon['slug'] ); ?>">
				<div class="isp-addon-card-top">
					<div class="isp-addon-icon isp-addon-icon-<?php echo esc_attr( $addon['color'] ); ?>"><?php echo insightistic_addon_icon_svg( $addon['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static trusted SVG, no user input. ?></div>
					<span class="isp-addon-type-badge <?php echo esc_attr( $type_class ); ?>"><?php echo esc_html( $addon['type'] ); ?></span>
				</div>
				<h3 class="isp-addon-name"><?php echo esc_html( $addon['name'] ); ?></h3>
				<p class="isp-addon-desc"><?php echo esc_html( $addon['desc'] ); ?></p>

				<?php if ( $addon['locked'] ) : ?>
					<?php echo Insightistic_Feature_Gate::locked_card( 'email_audit_automation', __( 'Create a free account to enable this addon', 'insightistic' ), __( 'Email Automations sends through your Insightistic account.', 'insightistic' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- locked_card() escapes internally. ?>
				<?php endif; ?>

				<div class="isp-addon-footer">
					<label class="isp-addon-toggle">
						<input type="checkbox" class="isp-addon-toggle-input" data-addon-toggle="<?php echo esc_attr( $addon['slug'] ); ?>" <?php checked( $addon_enabled ); ?> />
						<span class="isp-addon-toggle-track"></span>
						<span class="isp-addon-toggle-label"><?php echo $addon_enabled ? esc_html__( 'Enabled', 'insightistic' ) : esc_html__( 'Disabled', 'insightistic' ); ?></span>
					</label>
				</div>

				<?php if ( 'email_automations' === $addon['slug'] ) : ?>
					<div class="isp-addon-config <?php echo $email_enabled ? '' : 'is-hidden'; ?>" id="isp-email-automation-config">
						<label class="isp-addon-field-label" for="isp-email-recipients"><?php esc_html_e( 'Recipients (comma-separated emails)', 'insightistic' ); ?></label>
						<input id="isp-email-recipients" class="isp-input" type="text" value="<?php echo esc_attr( $email_recipients ); ?>" />
						<label class="isp-addon-field-label" for="isp-email-frequency"><?php esc_html_e( 'Send Frequency', 'insightistic' ); ?></label>
						<select id="isp-email-frequency" class="isp-select">
							<option value="daily" <?php selected( $email_frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'insightistic' ); ?></option>
							<option value="weekly" <?php selected( $email_frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'insightistic' ); ?></option>
							<option value="monthly" <?php selected( $email_frequency, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'insightistic' ); ?></option>
						</select>
						<label class="isp-addon-field-label" for="isp-email-day"><?php esc_html_e( 'Weekly Send Day', 'insightistic' ); ?></label>
						<select id="isp-email-day" class="isp-select">
							<?php foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) : ?>
								<option value="<?php echo esc_attr( $day ); ?>" <?php selected( $email_day, $day ); ?>><?php echo esc_html( ucfirst( $day ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<label class="isp-addon-field-label" for="isp-email-time"><?php esc_html_e( 'Send Time', 'insightistic' ); ?></label>
						<input id="isp-email-time" class="isp-input" type="time" value="<?php echo esc_attr( $email_time ); ?>" />
						<?php if ( $email_next_run ) : ?>
							<p class="isp-addon-field-help">
								<?php
								printf(
									/* translators: %s: next scheduled send time */
									esc_html__( 'Next scheduled digest: %s', 'insightistic' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $email_next_run ) )
								);
								?>
							</p>
						<?php endif; ?>
						<div class="isp-addon-actions">
							<button type="button" class="isp-btn isp-btn-primary" id="isp-save-email-automation"><?php esc_html_e( 'Save Email Automation', 'insightistic' ); ?></button>
							<button type="button" class="isp-btn isp-btn-secondary" id="isp-preview-email-automation"><?php esc_html_e( 'Preview Digest', 'insightistic' ); ?></button>
							<button type="button" class="isp-btn isp-btn-ghost" id="isp-send-test-email-automation"><?php esc_html_e( 'Send Test Digest', 'insightistic' ); ?></button>
						</div>
						<div id="isp-email-automation-notice"></div>
					</div>
				<?php else : ?>
					<div class="isp-addon-config <?php echo $addon_enabled ? '' : 'is-hidden'; ?>" id="isp-addon-config-<?php echo esc_attr( $addon['slug'] ); ?>">
						<button type="button" class="isp-btn isp-btn-primary isp-load-addon-report" data-addon-report="<?php echo esc_attr( $addon['slug'] ); ?>">
							<?php esc_html_e( 'Load Addon Report', 'insightistic' ); ?>
						</button>
						<div class="isp-addon-report-result" id="isp-addon-report-<?php echo esc_attr( $addon['slug'] ); ?>"></div>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="isp-footer">
		<p>
			<?php esc_html_e( 'Insightistic', 'insightistic' ); ?> v<?php echo esc_html( INSIGHTISTIC_VERSION ); ?> &bull;
			<a href="https://wordpressistic.com" target="_blank" rel="noopener noreferrer">WordPressistic</a>
		</p>
	</div>
</div>
