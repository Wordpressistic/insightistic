<?php
/**
 * License / SaaS connection template.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$isp_connected = Insightistic_License_Manager::is_connected();
$isp_state     = Insightistic_License_Manager::state();
$isp_status    = Insightistic_License_Manager::status();
$isp_stale     = Insightistic_License_Manager::is_stale();
$isp_last4     = get_option( 'insightistic_license_last4', '' );
$isp_grace     = Insightistic_Feature_Gate::in_legacy_grace();
$isp_app_url   = 'https://app.insightistic.com';

// Only 'revoked' / 'suspended' actually change behaviour (see
// Insightistic_Feature_Gate::BLOCKED_STATUSES) — every other status, however
// the SaaS labels its own billing state internally, simply reads "Connected"
// here so the badge never contradicts a green dot with scary paid-plan text.
$isp_status_labels = array(
	'none'      => __( 'Not connected', 'insightistic' ),
	'revoked'   => __( 'Revoked', 'insightistic' ),
	'suspended' => __( 'Suspended', 'insightistic' ),
);
$isp_blocked       = in_array( $isp_status, Insightistic_Feature_Gate::BLOCKED_STATUSES, true );
$isp_status_class  = 'none' === $isp_status
	? 'isp-license-status-neutral'
	: ( $isp_blocked ? 'isp-license-status-bad' : 'isp-license-status-good' );

// Every module in Insightistic is free. A connected account only unlocks
// these two cloud-powered flows — nothing else in the plugin reads this list.
$isp_features_meta = array(
	'ai_insights'            => array( __( 'AI Insights', 'insightistic' ), __( 'AI-generated analysis of your data', 'insightistic' ) ),
	'email_audit_automation' => array( __( 'Email Automations', 'insightistic' ), __( 'Scheduled branded audit digests', 'insightistic' ) ),
);
?>
<div class="wrap isp-wrap isp-license-wrap">

	<div class="isp-header">
		<div class="isp-header-brand">
			<div>
				<h1 class="isp-header-title"><?php esc_html_e( 'License & Connection', 'insightistic' ); ?></h1>
				<p class="isp-header-sub"><?php esc_html_e( 'One free account connects this site for AI Insights, email automations, and cloud sync — everything else in Insightistic is free without it.', 'insightistic' ); ?></p>
			</div>
		</div>
		<div class="isp-header-actions">
			<span class="isp-license-status <?php echo esc_attr( $isp_status_class ); ?>">
				<span class="isp-license-status-dot"></span>
				<?php echo esc_html( $isp_status_labels[ $isp_status ] ?? __( 'Connected', 'insightistic' ) ); ?>
				<?php if ( $isp_stale ) : ?>
					· <?php esc_html_e( 'reconnecting…', 'insightistic' ); ?>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<?php if ( $isp_grace && ! $isp_connected ) : ?>
		<div class="isp-license-banner isp-license-banner-grace">
			<strong><?php esc_html_e( 'Upgrade grace period active.', 'insightistic' ); ?></strong>
			<?php
			printf(
				/* translators: %s: formatted date */
				esc_html__( 'AI Insights and email automations keep working until %s. Connect your free Insightistic account to keep them active after that — every other module stays free either way.', 'insightistic' ),
				esc_html( date_i18n( get_option( 'date_format' ), (int) get_option( 'insightistic_legacy_grace_until', 0 ) ) )
			);
			?>
		</div>
	<?php endif; ?>

	<div id="isp-license-msg" class="isp-license-msg" style="display:none;" role="status"></div>

	<?php if ( ! $isp_connected ) : ?>

		<div class="isp-license-grid">
			<div class="isp-license-card isp-license-card-main isp-animate-in">
				<h2 class="isp-license-card-title"><?php esc_html_e( 'Activate your license', 'insightistic' ); ?></h2>
				<p class="isp-license-card-sub">
					<?php esc_html_e( 'Every module in Insightistic is free. Paste the key from your free Insightistic account to unlock AI Insights and email automation delivery.', 'insightistic' ); ?>
				</p>

				<div class="isp-license-form">
					<label class="screen-reader-text" for="isp-license-key"><?php esc_html_e( 'License key', 'insightistic' ); ?></label>
					<input
						type="text"
						id="isp-license-key"
						class="isp-input isp-license-input"
						placeholder="insightistic_xxxxxxxxxxxxxxxxxxxxxxxxxx"
						autocomplete="off"
						spellcheck="false"
					/>
					<button type="button" class="isp-btn isp-btn-primary isp-license-activate-btn" id="isp-license-activate">
						<span class="isp-btn-text"><?php esc_html_e( 'Activate', 'insightistic' ); ?></span>
					</button>
				</div>
				<p class="isp-license-form-hint">
					<?php esc_html_e( 'The key is exchanged for secure site credentials and never stored on this server.', 'insightistic' ); ?>
				</p>

				<div class="isp-license-steps">
					<h3><?php esc_html_e( 'Don’t have a key yet?', 'insightistic' ); ?></h3>
					<ol>
						<li>
							<?php
							printf(
								/* translators: %s: link to app */
								esc_html__( 'Create a free account at %s — no credit card, free forever.', 'insightistic' ),
								'<a href="' . esc_url( $isp_app_url . '/register?ref=plugin' ) . '" target="_blank" rel="noopener">app.insightistic.com</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Copy the license key shown after signup (also in Dashboard → Licenses).', 'insightistic' ); ?></li>
						<li><?php esc_html_e( 'Paste it above and click Activate. This site appears in your dashboard automatically.', 'insightistic' ); ?></li>
					</ol>
					<a class="isp-btn isp-btn-ghost" href="<?php echo esc_url( $isp_app_url . '/register?ref=plugin' ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Create a free account →', 'insightistic' ); ?>
					</a>
				</div>
			</div>

			<div class="isp-license-card isp-animate-in isp-animate-delay-1">
				<h2 class="isp-license-card-title"><?php esc_html_e( 'What a free account unlocks', 'insightistic' ); ?></h2>
				<ul class="isp-license-features">
					<?php foreach ( $isp_features_meta as $isp_slug => $isp_meta ) : ?>
						<li class="isp-license-feature isp-license-feature-locked">
							<span class="isp-license-feature-icon" aria-hidden="true">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
							</span>
							<span>
								<strong><?php echo esc_html( $isp_meta[0] ); ?></strong>
								<em><?php echo esc_html( $isp_meta[1] ); ?></em>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

	<?php else : ?>

		<div class="isp-license-grid">
			<div class="isp-license-card isp-license-card-main isp-animate-in">
				<h2 class="isp-license-card-title"><?php esc_html_e( 'Connection', 'insightistic' ); ?></h2>

				<div class="isp-license-keyline">
					<code class="isp-license-masked">insightistic_ ••••••••••••••••••••<?php echo esc_html( $isp_last4 ); ?></code>
					<?php if ( ! empty( $isp_state['plan_name'] ) ) : ?>
						<span class="isp-license-plan-badge"><?php echo esc_html( $isp_state['plan_name'] ); ?></span>
					<?php endif; ?>
				</div>

				<dl class="isp-license-meta">
					<div>
						<dt><?php esc_html_e( 'Websites connected', 'insightistic' ); ?></dt>
						<dd><?php echo esc_html( (int) ( $isp_state['activations_used'] ?? 1 ) . ' / ' . (int) ( $isp_state['activation_limit'] ?? 1 ) ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Last verified', 'insightistic' ); ?></dt>
						<dd>
							<?php
							echo ! empty( $isp_state['validated_at'] )
								? esc_html( human_time_diff( (int) $isp_state['validated_at'] ) . ' ' . __( 'ago', 'insightistic' ) )
								: esc_html__( 'never', 'insightistic' );
							?>
						</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Last synced', 'insightistic' ); ?></dt>
						<dd id="isp-last-sync">
							<?php
							$isp_last_sync = Insightistic_Sync::last_sync();
							echo $isp_last_sync
								? esc_html( human_time_diff( strtotime( $isp_last_sync ) ) . ' ' . __( 'ago', 'insightistic' ) )
								: esc_html__( 'never', 'insightistic' );
							?>
						</dd>
					</div>
				</dl>

				<?php if ( $isp_blocked ) : ?>
					<div class="isp-license-banner isp-license-banner-warn">
						<?php esc_html_e( 'This account is no longer in good standing, so AI Insights and email automations are paused. Every other module keeps working. Contact Insightistic support or connect a different account.', 'insightistic' ); ?>
						<a href="<?php echo esc_url( $isp_app_url . '/dashboard?ref=plugin' ); ?>" target="_blank" rel="noopener" class="isp-btn isp-btn-primary">
							<?php esc_html_e( 'Open dashboard →', 'insightistic' ); ?>
						</a>
					</div>
				<?php elseif ( $isp_stale ) : ?>
					<div class="isp-license-banner isp-license-banner-warn">
						<?php esc_html_e( 'We have not been able to reach Insightistic for a while, so AI Insights and email automations are paused. Every other module keeps working — click Refresh License below once your site is back online.', 'insightistic' ); ?>
					</div>
				<?php endif; ?>

				<div class="isp-license-actions">
					<button type="button" class="isp-btn isp-btn-primary" id="isp-license-refresh">
						<span class="isp-btn-text"><?php esc_html_e( 'Refresh License', 'insightistic' ); ?></span>
					</button>
					<button type="button" class="isp-btn isp-btn-secondary" id="isp-sync-now">
						<span class="isp-btn-text"><?php esc_html_e( 'Sync now', 'insightistic' ); ?></span>
					</button>
					<a class="isp-btn isp-btn-ghost" href="<?php echo esc_url( $isp_app_url . '/dashboard/licenses?ref=plugin' ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Manage in dashboard', 'insightistic' ); ?>
					</a>
					<button type="button" class="isp-btn isp-btn-danger-ghost" id="isp-license-disconnect">
						<?php esc_html_e( 'Disconnect site', 'insightistic' ); ?>
					</button>
				</div>
			</div>

			<div class="isp-license-card isp-animate-in isp-animate-delay-1">
				<h2 class="isp-license-card-title"><?php esc_html_e( 'Your features', 'insightistic' ); ?></h2>
				<ul class="isp-license-features">
					<?php foreach ( $isp_features_meta as $isp_slug => $isp_meta ) : ?>
						<?php $isp_on = Insightistic_Feature_Gate::can( $isp_slug ); ?>
						<li class="isp-license-feature <?php echo $isp_on ? 'isp-license-feature-on' : 'isp-license-feature-locked'; ?>">
							<span class="isp-license-feature-icon" aria-hidden="true">
								<?php if ( $isp_on ) : ?>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
								<?php else : ?>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
								<?php endif; ?>
							</span>
							<span>
								<strong><?php echo esc_html( $isp_meta[0] ); ?></strong>
								<em><?php echo esc_html( $isp_meta[1] ); ?></em>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="isp-license-card-sub" style="margin-top:12px;">
					<?php esc_html_e( 'Every other module — WooCommerce Intelligence, SEO, Anomaly Alerts, Content Lab — is free with no account needed.', 'insightistic' ); ?>
				</p>
			</div>
		</div>

	<?php endif; ?>

	<p class="isp-footer">
		<?php esc_html_e( 'Every Insightistic module is free forever. Your Google credentials and settings never leave this site — only aggregated metrics sync to your Insightistic dashboard.', 'insightistic' ); ?>
	</p>
</div>
