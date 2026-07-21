<?php
/**
 * HTTP client for the Insightistic SaaS API (app.insightistic.com).
 *
 * Two auth modes:
 *  - activate(): authenticated BY the license key itself (sent once, never stored).
 *  - Everything after activation: HMAC-signed with the per-site connector
 *    credentials returned by activate(). The secret never travels again.
 *
 * Canonical signing string (MUST match the server's ConnectorAuth middleware):
 *   METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256_hex(body)
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Saas_Client
 */
class Insightistic_Saas_Client {

	/**
	 * SaaS API base URL. Override for staging with the
	 * INSIGHTISTIC_API_BASE constant or the `insightistic_api_base` filter.
	 *
	 * @return string
	 */
	public static function api_base() {
		$base = defined( 'INSIGHTISTIC_API_BASE' )
			? INSIGHTISTIC_API_BASE
			: 'https://api.insightistic.com';

		/**
		 * Filters the Insightistic SaaS API base URL.
		 *
		 * @param string $base API base URL, no trailing slash.
		 */
		return untrailingslashit( apply_filters( 'insightistic_api_base', $base ) );
	}

	/**
	 * Exchange a license key for entitlements + connector credentials.
	 *
	 * @param string $license_key Raw license key pasted by the user.
	 * @return array{ok:bool,status:int,data:mixed,error:string|null}
	 */
	public static function activate( $license_key ) {
		$body = array(
			'license_key'        => $license_key,
			'site_url'           => home_url( '/' ),
			'site_name'          => get_bloginfo( 'name' ),
			'wp_version'         => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'plugin_version'     => INSIGHTISTIC_VERSION,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
		);

		return self::request( 'POST', '/api/plugin/activate', $body, false );
	}

	/**
	 * Daily validation — refreshes entitlements so plan changes propagate.
	 *
	 * @return array{ok:bool,status:int,data:mixed,error:string|null}
	 */
	public static function validate() {
		$body = array(
			'wp_version'         => get_bloginfo( 'version' ),
			'php_version'        => PHP_VERSION,
			'plugin_version'     => INSIGHTISTIC_VERSION,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
		);

		return self::request( 'POST', '/api/plugin/validate', $body, true );
	}

	/**
	 * Release this site's activation slot (disconnect / uninstall).
	 *
	 * @return array{ok:bool,status:int,data:mixed,error:string|null}
	 */
	public static function deactivate() {
		return self::request( 'POST', '/api/plugin/deactivate', array(), true );
	}

	/**
	 * Run an AI analysis on the Insightistic Cloud AI backend (self-hosted
	 * Ollama models + the Hermes SEO skill agent). HMAC-signed like every
	 * other post-activation call — a connected free account IS the auth.
	 *
	 * Request body: { model: 'ollama-balanced'|'hermes-seo', skill_profile,
	 * system_prompt, prompt }. Expected 200 body: { content: "<json string>" }.
	 * The server enforces the free-tier usage limit and responds
	 * `{ code: 'quota_exceeded' }` (HTTP 429) once a site exceeds it — the
	 * plugin surfaces that as a friendly message rather than a raw error.
	 *
	 * @param array $body Analysis payload (see class docblock).
	 * @return array{ok:bool,status:int,data:mixed,error:string|null,network:bool}
	 */
	public static function ai_insights( $body ) {
		return self::request( 'POST', '/api/plugin/ai-insights', $body, true );
	}

	/**
	 * Fetch Cloudflare Traffic Insights via the connected Insightistic
	 * account  the zero-config default (see docs/APP-CONNECT-WORKFLOW.md
	 * §10). No Zone ID or API token is ever sent from this site; the SaaS
	 * maps the site's own `home_url()` (already sent on every activate/
	 * validate call) to a Cloudflare zone the account owner linked once on
	 * the Insightistic dashboard.
	 *
	 * Expected 200 body: `{ available: bool, data?: {...} }`.
	 * `available: false` means the account is connected but hasn't linked
	 * a Cloudflare zone yet  an expected, non-error state.
	 *
	 * @param array $body Analysis payload, e.g. `{ days: 7 }`.
	 * @return array{ok:bool,status:int,data:mixed,error:string|null,network:bool}
	 */
	public static function cloudflare_traffic( $body ) {
		return self::request( 'POST', '/api/plugin/cloudflare-traffic', $body, true );
	}

	/* ------------------------------------------------------------------ */
	/* Connector v2 — expanded ingestion (Milestone 3, spec §7.2/§8).        */
	/* Each wraps one sync/* endpoint 1:1; all are HMAC-signed like every    */
	/* connector call. See Insightistic_Sync::sync_expanded() for the        */
	/* orchestration that calls these.                                      */
	/* ------------------------------------------------------------------ */

	/** Begin a sync batch; response carries `sync_batch_id` for the calls below. */
	public static function sync_start() {
		return self::request( 'POST', '/api/connector/v1/sync/start', array(), true );
	}

	public static function sync_traffic_daily( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/traffic/daily', $body, true );
	}

	public static function sync_traffic_dimensions( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/traffic/dimensions', $body, true );
	}

	public static function sync_seo_daily( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/seo/daily', $body, true );
	}

	public static function sync_seo_queries( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/seo/queries', $body, true );
	}

	public static function sync_seo_pages( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/seo/pages', $body, true );
	}

	public static function sync_performance_run( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/performance/runs', $body, true );
	}

	public static function sync_engagement_daily( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/engagement/daily', $body, true );
	}

	public static function sync_broken_links( $body ) {
		return self::request( 'POST', '/api/connector/v1/sync/broken-links', $body, true );
	}

	/**
	 * Perform a (optionally HMAC-signed) JSON request against the SaaS API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path starting with /api/.
	 * @param array|null $body   JSON body.
	 * @param bool       $signed Sign with connector credentials.
	 * @return array{ok:bool,status:int,data:mixed,error:string|null,network:bool}
	 */
	public static function request( $method, $path, $body = null, $signed = true ) {
		$url  = self::api_base() . $path;
		$json = ( null === $body ) ? '' : wp_json_encode( $body );

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'Insightistic/' . INSIGHTISTIC_VERSION . '; ' . home_url( '/' ),
		);

		if ( $signed ) {
			$key_id     = get_option( 'insightistic_connector_key_id', '' );
			$secret_enc = get_option( 'insightistic_connector_secret', '' );
			$secret     = $secret_enc ? Insightistic_Encryption::decrypt( $secret_enc ) : false;

			if ( ! $key_id || ! $secret ) {
				return array(
					'ok'      => false,
					'status'  => 0,
					'data'    => null,
					'error'   => __( 'Not connected to Insightistic. Activate your license first.', 'insightistic' ),
					'network' => false,
				);
			}

			$sign_path = wp_parse_url( $url, PHP_URL_PATH ); // exactly what the server sees.
			$timestamp = (string) time();
			$nonce     = wp_generate_uuid4();
			$canonical = implode(
				"\n",
				array(
					strtoupper( $method ),
					$sign_path,
					$timestamp,
					$nonce,
					hash( 'sha256', $json ),
				)
			);

			$headers['X-INS-Key-Id']    = $key_id;
			$headers['X-INS-Timestamp'] = $timestamp;
			$headers['X-INS-Nonce']     = $nonce;
			$headers['X-INS-Signature'] = hash_hmac( 'sha256', $canonical, $secret );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 15,
			'headers' => $headers,
		);
		if ( '' !== $json ) {
			$args['body'] = $json;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'data'    => null,
				'error'   => $response->get_error_message(),
				'network' => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$ok   = $code >= 200 && $code < 300;

		return array(
			'ok'      => $ok,
			'status'  => $code,
			'data'    => $data,
			'error'   => $ok ? null : ( is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code ),
			'network' => false,
		);
	}
}
