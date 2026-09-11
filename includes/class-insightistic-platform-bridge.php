<?php
/**
 * Compatibility bridge between Insightistic's existing account/licensing flow
 * and the shared WPistic platform entitlement SDK.
 *
 * This class intentionally has no side effects. Existing Insightistic installs
 * continue to use Insightistic_License_Manager until the shared SDK is bundled
 * and a WPistic license has actually been activated. New marketplace/LTD flows
 * can then read the central entitlement contract without breaking legacy users.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Platform_Bridge
 */
class Insightistic_Platform_Bridge {

	/**
	 * WPistic SDK licensing class.
	 *
	 * @var string
	 */
	const SDK_CLASS = '\\WPistic\\Sdk\\Licensing';

	/**
	 * Whether the shared WPistic SDK is available in this request.
	 *
	 * @return bool
	 */
	public static function sdk_available() {
		return class_exists( self::SDK_CLASS ) && method_exists( self::SDK_CLASS, 'instance' );
	}

	/**
	 * Return the shared licensing singleton when available.
	 *
	 * @return object|null
	 */
	public static function licensing() {
		if ( ! self::sdk_available() ) {
			return null;
		}

		$licensing = call_user_func( array( self::SDK_CLASS, 'instance' ) );

		return is_object( $licensing ) ? $licensing : null;
	}

	/**
	 * Whether a WPistic-issued Insightistic license is active locally.
	 *
	 * Grace-period state is considered connected because the central SDK is
	 * designed to keep paid capabilities alive during temporary API outages.
	 *
	 * @return bool
	 */
	public static function wpistic_connected() {
		$licensing = self::licensing();
		if ( ! $licensing || ! method_exists( $licensing, 'state' ) ) {
			return false;
		}

		$state = $licensing->state();
		if ( ! is_array( $state ) || empty( $state['activation_token'] ) ) {
			return false;
		}

		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : '';

		return in_array( $status, array( 'active', 'grace_period' ), true );
	}

	/**
	 * Whether the existing Insightistic account connector is active.
	 *
	 * @return bool
	 */
	public static function legacy_connected() {
		return class_exists( 'Insightistic_License_Manager' )
			&& Insightistic_License_Manager::is_connected();
	}

	/**
	 * Current commercial source.
	 *
	 * WPistic wins when both are present because it is the shared entitlement
	 * authority for marketplace/LTD and future ecosystem purchases. The legacy
	 * connection remains usable for the existing Insightistic cloud connector.
	 *
	 * @return string wpistic|legacy|none
	 */
	public static function source() {
		if ( self::wpistic_connected() ) {
			return 'wpistic';
		}

		if ( self::legacy_connected() ) {
			return 'legacy';
		}

		return 'none';
	}

	/**
	 * Check a central WPistic boolean entitlement.
	 *
	 * This method never converts a missing SDK into a denial of existing free
	 * Insightistic functionality. Callers should use it only for capabilities
	 * explicitly introduced as paid/LTD features.
	 *
	 * @param string $entitlement Entitlement key, e.g. insightistic.white_label.
	 * @param bool   $default     Value when WPistic licensing is unavailable.
	 * @return bool
	 */
	public static function allows( $entitlement, $default = false ) {
		$entitlement = sanitize_text_field( (string) $entitlement );
		$licensing   = self::licensing();

		if ( ! self::wpistic_connected() || ! $licensing || ! method_exists( $licensing, 'entitlements' ) ) {
			return (bool) $default;
		}

		$entitlements = $licensing->entitlements();
		if ( ! is_object( $entitlements ) || ! method_exists( $entitlements, 'allows' ) ) {
			return (bool) $default;
		}

		return (bool) $entitlements->allows( $entitlement );
	}

	/**
	 * Read a numeric central WPistic entitlement limit.
	 *
	 * @param string $entitlement Entitlement key.
	 * @param int    $default     Value when unavailable.
	 * @return int
	 */
	public static function max( $entitlement, $default = 0 ) {
		$entitlement = sanitize_text_field( (string) $entitlement );
		$licensing   = self::licensing();

		if ( ! self::wpistic_connected() || ! $licensing || ! method_exists( $licensing, 'entitlements' ) ) {
			return max( 0, (int) $default );
		}

		$entitlements = $licensing->entitlements();
		if ( ! is_object( $entitlements ) || ! method_exists( $entitlements, 'getMax' ) ) {
			return max( 0, (int) $default );
		}

		return max( 0, (int) $entitlements->getMax( $entitlement ) );
	}

	/**
	 * Compact state for diagnostics/UI without leaking tokens or credentials.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot() {
		$licensing = self::licensing();
		$status    = 'none';

		if ( $licensing && method_exists( $licensing, 'state' ) ) {
			$state = $licensing->state();
			if ( is_array( $state ) && ! empty( $state['status'] ) ) {
				$status = sanitize_key( (string) $state['status'] );
			}
		}

		return array(
			'source'             => self::source(),
			'wpisticSdkLoaded'   => self::sdk_available(),
			'wpisticConnected'   => self::wpistic_connected(),
			'wpisticStatus'      => $status,
			'legacyConnected'    => self::legacy_connected(),
			'sitesMax'           => self::max( 'insightistic.sites.max', 0 ),
			'aiMonthlyCredits'   => self::max( 'insightistic.ai.monthly_credits', 0 ),
			'clientWorkspacesMax'=> self::max( 'insightistic.client_workspaces.max', 0 ),
			'whiteLabel'         => self::allows( 'insightistic.white_label', false ),
			'agencyDashboard'    => self::allows( 'insightistic.agency.dashboard', false ),
		);
	}
}
