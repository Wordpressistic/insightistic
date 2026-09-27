<?php
/**
 * Central feature gate. insightistic is a fully free plugin — every module
 * (GA4 / GSC / PageSpeed dashboards, WooCommerce Intelligence, SEO Opportunity
 * Finder, Anomaly Alerts, Content Lab, the engagement tracker, settings) works
 * with zero account required.
 *
 * Exactly two flows ask this class before doing work, because they use the
 * Insightistic cloud (AI inference, outbound email delivery):
 *   ai_insights            — the "Get AI Insights" buttons.
 *   email_audit_automation — scheduled + manual email digest sending.
 *
 * Both simply require a free Insightistic account (Insightistic_License_Manager
 * ::is_connected()) — there is no paid tier, trial, or plan to fall out of.
 *
 * Legacy grace: installs upgrading from <= 3.3.0 that already used the old
 * gated add-ons keep them working for 14 days (insightistic_legacy_grace_until),
 * so existing users are never cut off overnight.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Feature_Gate
 */
class Insightistic_Feature_Gate {

	/**
	 * The only feature slugs that require a free connected account.
	 * Everything else in the plugin is unconditionally free.
	 *
	 * @var string[]
	 */
	const ACCOUNT_GATED_FEATURES = array( 'ai_insights', 'email_audit_automation' );

	/**
	 * Account statuses that block the two gated features even though
	 * connector credentials are still stored locally — e.g. a key that was
	 * revoked for abuse. NOT a paid-plan concept (there is no plan to expire
	 * or cancel out of); this only guards against a genuinely invalid account.
	 *
	 * @var string[]
	 */
	const BLOCKED_STATUSES = array( 'revoked', 'suspended' );

	/**
	 * Add-on slug -> required feature. Only add-ons that need the Insightistic
	 * cloud belong here; every other add-on resolves to '' (ungated).
	 *
	 * @var array<string,string>
	 */
	const ADDON_FEATURES = array(
		'email_automations' => 'email_audit_automation',
	);

	/**
	 * Whether a feature may run right now.
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public static function can( $feature ) {
		$feature = sanitize_key( $feature );

		if ( ! in_array( $feature, self::ACCOUNT_GATED_FEATURES, true ) ) {
			return true;
		}

		if ( self::in_legacy_grace() ) {
			return true;
		}

		if ( ! Insightistic_License_Manager::is_connected() ) {
			return false;
		}

		if ( in_array( Insightistic_License_Manager::status(), self::BLOCKED_STATUSES, true ) ) {
			return false;
		}

		return ! Insightistic_License_Manager::is_stale();
	}

	/**
	 * Why a feature is locked — drives which prompt the UI shows.
	 *
	 * @param string $feature Feature slug.
	 * @return string ok | no_license | blocked | stale
	 */
	public static function reason( $feature ) {
		if ( self::can( $feature ) ) {
			return 'ok';
		}
		if ( ! Insightistic_License_Manager::is_connected() ) {
			return 'no_license';
		}
		if ( in_array( Insightistic_License_Manager::status(), self::BLOCKED_STATUSES, true ) ) {
			return 'blocked';
		}

		return 'stale';
	}

	/**
	 * The feature an add-on toggle requires ('' = ungated).
	 *
	 * @param string $addon_slug Add-on slug.
	 * @return string
	 */
	public static function addon_feature( $addon_slug ) {
		return self::ADDON_FEATURES[ sanitize_key( $addon_slug ) ] ?? '';
	}

	/**
	 * Legacy 14-day grace for pre-4.0 installs (set once at upgrade).
	 *
	 * @return bool
	 */
	public static function in_legacy_grace() {
		$until = (int) get_option( 'insightistic_legacy_grace_until', 0 );

		return $until > time();
	}

	/**
	 * Locked-state upgrade card (spec §9). Also used inside AJAX error payloads.
	 *
	 * @param string $feature Feature slug.
	 * @param string $title   Card heading.
	 * @param string $message One-line value statement for this feature.
	 * @return string HTML (escaped).
	 */
	public static function locked_card( $feature, $title = '', $message = '' ) {
		$feature = sanitize_key( $feature );
		$reason  = self::reason( $feature );
		$title   = $title ? $title : __( 'Create a free account', 'insightistic' );

		if ( '' === $message ) {
			$message = __( 'AI Insights and email automations need a free Insightistic account.', 'insightistic' );
		}

		switch ( $reason ) {
			case 'stale':
				$hint = __( 'We have not been able to reach Insightistic for a while. Click Refresh License once your site is back online.', 'insightistic' );
				$cta  = __( 'Refresh license', 'insightistic' );
				$url  = admin_url( 'admin.php?page=insightistic-license' );
				break;
			case 'blocked':
				$hint = __( 'This account is no longer in good standing. Contact Insightistic support or reconnect a different account.', 'insightistic' );
				$cta  = __( 'Open license page', 'insightistic' );
				$url  = admin_url( 'admin.php?page=insightistic-license' );
				break;
			default: // no_license.
				$hint = __( 'Takes about a minute — no credit card, free forever.', 'insightistic' );
				$cta  = __( 'Create a free account', 'insightistic' );
				$url  = admin_url( 'admin.php?page=insightistic-license' );
				break;
		}

		return sprintf(
			'<div class="isp-locked-card" data-feature="%1$s">
				<div class="isp-locked-icon" aria-hidden="true">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				</div>
				<h3 class="isp-locked-title">%2$s</h3>
				<p class="isp-locked-msg">%3$s</p>
				<p class="isp-locked-hint">%4$s</p>
				<a class="isp-btn isp-btn-primary" href="%5$s"%6$s>%7$s</a>
			</div>',
			esc_attr( $feature ),
			esc_html( $title ),
			esc_html( $message ),
			esc_html( $hint ),
			esc_url( $url ),
			( 0 === strpos( $url, 'http' ) ? ' target="_blank" rel="noopener"' : '' ),
			esc_html( $cta )
		);
	}

	/**
	 * Compact state array for wp_localize_script (drives JS-side lock badges).
	 *
	 * @return array
	 */
	public static function js_state() {
		return array(
			'connected'   => Insightistic_License_Manager::is_connected(),
			'status'      => Insightistic_License_Manager::status(),
			'features'    => Insightistic_License_Manager::features(),
			'stale'       => Insightistic_License_Manager::is_stale(),
			'legacyGrace' => self::in_legacy_grace(),
		);
	}
}
