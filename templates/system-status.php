<?php
/**
 * System status template.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$insightistic_checks = Insightistic_System_Status::collect();
$insightistic_passed = count(
	array_filter(
		$insightistic_checks,
		static function ( $insightistic_check ) {
			return 'pass' === $insightistic_check['status'] || 'optional' === $insightistic_check['status'];
		}
	)
);
$insightistic_total  = count( $insightistic_checks );
?>
<div class="wrap isp-wrap isp-system-wrap">
	<div class="isp-header">
		<div class="isp-header-brand">
			<img src="<?php echo esc_url( INSIGHTISTIC_URL . 'assets/images/wordpressistic-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'WordPressistic', 'insightistic' ); ?>"
				class="isp-logo">
			<div>
				<h1 class="isp-header-title"><?php esc_html_e( 'Insightistic System Status', 'insightistic' ); ?></h1>
				<p class="isp-header-sub"><?php esc_html_e( 'Release readiness, diagnostics, and settings portability', 'insightistic' ); ?></p>
			</div>
		</div>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-settings' ) ); ?>" class="isp-btn isp-btn-ghost">
			<?php esc_html_e( 'Settings', 'insightistic' ); ?>
		</a>
	</div>

	<?php settings_errors( 'insightistic_system_messages' ); ?>

	<div class="isp-system-score">
		<strong><?php echo esc_html( $insightistic_passed . '/' . $insightistic_total ); ?></strong>
		<span><?php esc_html_e( 'checks ready or optional', 'insightistic' ); ?></span>
	</div>

	<div class="isp-system-actions">
		<a class="isp-btn isp-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic-system-status' ) ); ?>">
			<?php esc_html_e( 'Recompute Checks', 'insightistic' ); ?>
		</a>
	</div>

	<div class="isp-system-grid">
		<?php foreach ( $insightistic_checks as $insightistic_check ) : ?>
			<div class="isp-system-check isp-system-<?php echo esc_attr( $insightistic_check['status'] ); ?>">
				<span class="isp-system-status"><?php echo esc_html( strtoupper( $insightistic_check['status'] ) ); ?></span>
				<strong><?php echo esc_html( $insightistic_check['label'] ); ?></strong>
				<p><?php echo esc_html( $insightistic_check['detail'] ); ?></p>
				<?php if ( 'pass' !== $insightistic_check['status'] && ! empty( $insightistic_check['fix_url'] ) ) : ?>
					<a class="isp-system-fix" href="<?php echo esc_url( $insightistic_check['fix_url'] ); ?>">
						<?php echo esc_html( $insightistic_check['fix_label'] ?? __( 'Fix this', 'insightistic' ) ); ?> &rarr;
					</a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="isp-system-panels">
		<div class="isp-system-panel">
			<h2><?php esc_html_e( 'Release QA Checklist', 'insightistic' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Install the release ZIP on a clean WordPress site.', 'insightistic' ); ?></li>
				<li><?php esc_html_e( 'Connect GA4 and load the Overview tab.', 'insightistic' ); ?></li>
				<li><?php esc_html_e( 'Connect Search Console and run SEO Opportunity Finder.', 'insightistic' ); ?></li>
				<li><?php esc_html_e( 'Run PageSpeed for homepage and one revenue page.', 'insightistic' ); ?></li>
				<li><?php esc_html_e( 'Send a test Email Automation digest through SMTP.', 'insightistic' ); ?></li>
				<li><?php esc_html_e( 'Enable each free addon and verify report output.', 'insightistic' ); ?></li>
				<li><?php esc_html_e( 'If WooCommerce is active, verify WooCommerce Intelligence sees recent orders.', 'insightistic' ); ?></li>
			</ol>
		</div>

		<div class="isp-system-panel">
			<h2><?php esc_html_e( 'Email Deliverability', 'insightistic' ); ?></h2>
			<p><?php esc_html_e( 'For launch, connect a real SMTP provider and verify SPF, DKIM, and DMARC for your sender domain. WordPress default mail can work, but it is not reliable enough for serious customer reporting.', 'insightistic' ); ?></p>
		</div>

		<div class="isp-system-panel">
			<h2><?php esc_html_e( 'Export Settings', 'insightistic' ); ?></h2>
			<p><?php esc_html_e( 'Exports non-secret configuration only. API keys and private keys are intentionally excluded.', 'insightistic' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'insightistic_export_settings', 'insightistic_export_settings_nonce' ); ?>
				<button class="isp-btn isp-btn-primary" type="submit"><?php esc_html_e( 'Download Settings JSON', 'insightistic' ); ?></button>
			</form>
		</div>

		<div class="isp-system-panel">
			<h2><?php esc_html_e( 'Import Settings', 'insightistic' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'insightistic_import_settings', 'insightistic_import_settings_nonce' ); ?>
				<textarea class="isp-textarea" name="insightistic_settings_json" rows="8" placeholder="<?php esc_attr_e( 'Paste Insightistic settings JSON here', 'insightistic' ); ?>"></textarea>
				<p><button class="isp-btn isp-btn-primary" type="submit"><?php esc_html_e( 'Import Non-Secret Settings', 'insightistic' ); ?></button></p>
			</form>
		</div>
	</div>
</div>
