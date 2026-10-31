<?php
/**
 * Central feature gate.
 *
 * Every feature that ships in this plugin and can run on the site itself is
 * free with no account: GA4, Search Console, PageSpeed, Cloudflare (bring
 * your own token), WooCommerce Intelligence, SEO, anomaly alerts, content
 * lab, engagement tracking, and email digests (sent with wp_mail()).
 *
 * The only remaining account check is Insightistic Cloud AI — an external
 * service. Bring-your-own-key providers (OpenAI, Gemini, OpenRouter, Groq,
 * Claude) are not gated. Nothing here is a paid plan, trial, or license lock.
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
	 * Feature that calls the Insightistic cloud. Bring-your-own-key providers
	 * skip this check. Email digests are local and are not listed.
	 *
	 * @var string[]
	 */
	const ACCOUNT_GATED_FEATURES = array( 'ai_insights' );

	/**
	 * Account statuses that stop Cloud AI even if a key is still stored —
	 * a revoked or suspended account. Not a paid-plan concept.
	 *
	 * @var string[]
	 */
	const BLOCKED_STATUSES = array( 'revoked', 'suspended' );

	/**
	 * No add-on is account-gated. Email digests send with wp_mail().
	 *
	 * @var array<string,string>
	 */
	const ADDON_FEATURES = array();

	/**
	 * Whether a feature may run right now.
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public static function can( $feature ) {
		$feature = sanitize_key( $feature );

		if ( 'ai_insights' !== $feature ) {
			return true;
		}

		// The site owner's own provider key never needs an Insightistic account.
		$provider = sanitize_key( (string) get_option( 'insightistic_ai_provider', 'insightistic_cloud' ) );
		if ( in_array( $provider, array( 'openai', 'gemini', 'openrouter', 'groq', 'claude' ), true ) ) {
			return true;
		}

		if ( self::in_legacy_grace() ) {
			return true;
		}

		if ( ! class_exists( 'Insightistic_License_Manager' ) || ! Insightistic_License_Manager::is_connected() ) {
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
			$message = __( 'Insightistic Cloud AI needs a free account. Or pick your own OpenAI, Gemini, Groq, Claude, or OpenRouter key in Settings — those work with no account.', 'insightistic' );
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
