<?php
/**
 * License manager: activation, cached entitlements, daily validation and the
 * offline grace period.
 *
 * Storage (all non-autoloaded; the connector secret is encrypted at rest):
 *  - insightistic_license_state      cached entitlement payload + validated_at
 *  - insightistic_license_last4      display-only masked key suffix
 *  - insightistic_connector_key_id   public HMAC key id
 *  - insightistic_connector_secret   ENCRYPTED HMAC secret
 *  - insightistic_saas_site_id / insightistic_saas_activation_id
 *
 * The raw license key is NEVER stored. It is exchanged once, in activate(),
 * for entitlements + HMAC connector credentials.
 *
 * Fail-open design: if the SaaS API is unreachable the cached entitlements
 * stay honored for `grace_hours` (server-provided, default 72). After that
 * only the advanced modules soft-lock — the plugin never fatals and never
 * blocks wp-admin.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_License_Manager
 */
class Insightistic_License_Manager {

	const CRON_HOOK = 'insightistic_license_validate';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'cron_validate' ) );
		add_action( 'upgrader_process_complete', array( $this, 'after_plugin_update' ), 10, 2 );

		if ( self::is_connected() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		// Daily WooCommerce/site-health sync, same pattern as the validate cron
		// above — keeps a connected site's data fresh in the SaaS dashboard
		// without needing the separate connector plugin.
		if ( self::is_connected() && ! wp_next_scheduled( Insightistic_Sync::RUN_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', Insightistic_Sync::RUN_HOOK );
		}
	}

	/* ------------------------------------------------------------------ */
	/* State accessors                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Cached entitlement state (empty array when never activated).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( 'insightistic_license_state', array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Current license status string, or 'none' when not activated.
	 *
	 * @return string
	 */
	public static function status() {
		$state = self::state();

		return ! empty( $state['status'] ) ? (string) $state['status'] : 'none';
	}

	/**
	 * Whether connector credentials exist (site has activated at least once).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return (bool) get_option( 'insightistic_connector_key_id', '' );
	}

	/**
	 * Cached state is stale when the last successful validation is older than
	 * the validate interval plus the grace window.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$state = self::state();
		if ( empty( $state['validated_at'] ) ) {
			return false; // Never validated -> not connected; gate handles that.
		}

		$interval = ! empty( $state['validate_interval'] ) ? (int) $state['validate_interval'] : DAY_IN_SECONDS;
		$grace    = ( ! empty( $state['grace_hours'] ) ? (int) $state['grace_hours'] : 72 ) * HOUR_IN_SECONDS;

		return ( time() - (int) $state['validated_at'] ) > ( $interval + $grace );
	}

	/**
	 * Features granted by the cached entitlements.
	 *
	 * @return string[]
	 */
	public static function features() {
		$state = self::state();

		return ! empty( $state['features'] ) && is_array( $state['features'] )
			? array_map( 'sanitize_key', $state['features'] )
			: array( 'basic_analytics' );
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Activate a license key against the SaaS.
	 *
	 * @param string $raw_key Raw key pasted by the user.
	 * @return array|WP_Error Entitlement state on success.
	 */
	public function activate( $raw_key ) {
		$raw_key = trim( (string) $raw_key );

		if ( ! preg_match( '/^insightistic_[A-Za-z0-9]{20,40}$/', $raw_key ) ) {
			return new WP_Error(
				'invalid_format',
				__( 'That does not look like an Insightistic license key. Keys start with "insightistic_".', 'insightistic' )
			);
		}

		$result = Insightistic_Saas_Client::activate( $raw_key );

		if ( ! $result['ok'] ) {
			$code = is_array( $result['data'] ) && ! empty( $result['data']['code'] ) ? $result['data']['code'] : 'activation_failed';

			return new WP_Error( $code, $this->human_error( $code, $result['error'] ), $result['data'] );
		}

		$data = $result['data'];
		if ( empty( $data['license'] ) || empty( $data['connector']['key_id'] ) || empty( $data['connector']['secret'] ) ) {
			return new WP_Error( 'bad_response', __( 'Unexpected response from Insightistic. Please try again.', 'insightistic' ) );
		}

		// Store connector credentials (secret encrypted at rest, never re-sent).
		$secret_enc = Insightistic_Encryption::encrypt( (string) $data['connector']['secret'] );
		if ( ! $secret_enc ) {
			return new WP_Error( 'encrypt_failed', __( 'Could not securely store the connection secret.', 'insightistic' ) );
		}

		update_option( 'insightistic_connector_key_id', sanitize_text_field( $data['connector']['key_id'] ), false );
		update_option( 'insightistic_connector_secret', $secret_enc, false );
		update_option( 'insightistic_saas_site_id', absint( $data['site_id'] ?? 0 ), false );
		update_option( 'insightistic_saas_activation_id', absint( $data['activation_id'] ?? 0 ), false );
		update_option( 'insightistic_license_last4', substr( $raw_key, -4 ), false );

		$state = $this->store_state( $data['license'] );

		// Fresh daily validation schedule.
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );

		// Fresh daily sync schedule, first run ~1 minute out so the SaaS
		// dashboard starts filling in shortly after activation without
		// blocking this request on a full order/product/customer sync.
		wp_clear_scheduled_hook( Insightistic_Sync::RUN_HOOK );
		wp_schedule_event( time() + 60, 'daily', Insightistic_Sync::RUN_HOOK );

		// The one-time key leaves memory here; only last4 remains.
		return $state;
	}

	/**
	 * Refresh entitlements from the SaaS (cron, manual button, post-update).
	 *
	 * @return array|WP_Error Fresh (or grace-period cached) state.
	 */
	public function refresh() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'not_connected', __( 'No license is active on this site yet.', 'insightistic' ) );
		}

		$result = Insightistic_Saas_Client::validate();

		if ( $result['ok'] && ! empty( $result['data']['license'] ) ) {
			return $this->store_state( $result['data']['license'] );
		}

		// Denied by the server (revoked key, deactivated site, ...): downgrade
		// the cached status so the gate reacts, but keep credentials so a
		// reactivation on the SaaS side heals on the next check-in.
		if ( ! $result['network'] && in_array( $result['status'], array( 401, 402, 403 ), true ) ) {
			$state                 = self::state();
			$code                  = is_array( $result['data'] ) && ! empty( $result['data']['code'] ) ? $result['data']['code'] : 'denied';
			$state['status']       = ( 'license_expired' === $code ) ? 'expired' : 'revoked';
			$state['validated_at'] = time();
			update_option( 'insightistic_license_state', $state, false );

			return new WP_Error( $code, $this->human_error( $code, $result['error'] ) );
		}

		// Network failure: keep the cached state, note the miss. The gate honors
		// the cache until grace runs out (is_stale()).
		return new WP_Error( 'network', __( 'Could not reach Insightistic — using your cached license for now.', 'insightistic' ) );
	}

	/**
	 * Disconnect: best-effort remote deactivation, then local wipe.
	 * Never blocks on the network.
	 */
	public function disconnect() {
		if ( self::is_connected() ) {
			Insightistic_Saas_Client::deactivate(); // best effort, result ignored.
		}

		delete_option( 'insightistic_license_state' );
		delete_option( 'insightistic_license_last4' );
		delete_option( 'insightistic_connector_key_id' );
		delete_option( 'insightistic_connector_secret' );
		delete_option( 'insightistic_saas_site_id' );
		delete_option( 'insightistic_saas_activation_id' );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Insightistic_Sync::RUN_HOOK );
	}

	/**
	 * Daily WP-Cron callback.
	 */
	public function cron_validate() {
		$this->refresh();
	}

	/**
	 * Re-validate right after this plugin is updated.
	 *
	 * @param object $upgrader Upgrader instance (unused).
	 * @param array  $extra    Update info.
	 */
	public function after_plugin_update( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) || 'plugin' !== $extra['type'] || ! self::is_connected() ) {
			return;
		}
		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : array();
		if ( in_array( INSIGHTISTIC_BASENAME, $plugins, true ) ) {
			// Defer to a single cron tick so the update request itself stays fast.
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Persist the entitlement payload returned by the SaaS.
	 *
	 * @param array $license License payload.
	 * @return array Stored state.
	 */
	private function store_state( $license ) {
		$state = array(
			'status'            => sanitize_key( $license['status'] ?? 'none' ),
			'plan'              => sanitize_key( $license['plan'] ?? '' ),
			'plan_name'         => sanitize_text_field( $license['plan_name'] ?? '' ),
			'features'          => array_map( 'sanitize_key', (array) ( $license['features'] ?? array() ) ),
			'activation_limit'  => absint( $license['activation_limit'] ?? 1 ),
			'activations_used'  => absint( $license['activations_used'] ?? 0 ),
			'trial_ends_at'     => sanitize_text_field( (string) ( $license['trial_ends_at'] ?? '' ) ),
			'expires_at'        => sanitize_text_field( (string) ( $license['expires_at'] ?? '' ) ),
			'validate_interval' => absint( $license['validate_interval'] ?? DAY_IN_SECONDS ),
			'grace_hours'       => absint( $license['grace_hours'] ?? 72 ),
			'validated_at'      => time(),
		);

		update_option( 'insightistic_license_state', $state, false );

		return $state;
	}

	/**
	 * Map server error codes to friendly, actionable copy.
	 *
	 * @param string      $code     Error code from the API.
	 * @param string|null $fallback Fallback message.
	 * @return string
	 */
	private function human_error( $code, $fallback ) {
		switch ( $code ) {
			case 'invalid_key':
				return __( 'This license key was not recognised. Double-check for typos, or copy it fresh from your Insightistic dashboard.', 'insightistic' );
			case 'key_revoked':
				return __( 'This license key has been revoked. Generate a new key from your Insightistic dashboard.', 'insightistic' );
			case 'license_expired':
				return __( 'This key is no longer active. Open your Insightistic dashboard to reconnect this site.', 'insightistic' );
			case 'activation_limit_reached':
				return __( 'This key is already connected to the maximum number of sites. Deactivate another site first, or open your Insightistic dashboard for options.', 'insightistic' );
			case 'payment_required':
				return __( 'There is an issue with your Insightistic account. Please check your account dashboard.', 'insightistic' );
			default:
				return $fallback ? $fallback : __( 'Activation failed. Please try again in a moment.', 'insightistic' );
		}
	}
}
