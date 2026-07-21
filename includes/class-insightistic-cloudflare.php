<?php
/**
 * Cloudflare Traffic Insights.
 *
 * Two ways to get data, tried in this order by get_dashboard_data():
 *
 *  1. Hosted via the connected free Insightistic account (the default,
 *     zero-config path  see docs/APP-CONNECT-WORKFLOW.md §10). No Zone ID
 *     or API token ever touches this site; the SaaS maps this site's
 *     domain to a Cloudflare zone the account owner linked once on the
 *     Insightistic dashboard. Requires nothing here beyond the account
 *     connection every other AI/email flow already needs.
 *  2. Advanced/BYO: a Zone ID + API token pasted directly into Settings →
 *     Cloudflare, queried straight against Cloudflare's GraphQL Analytics
 *     API (`httpRequests1dGroups` + `firewallEventsAdaptiveGroups`, both
 *     free-plan datasets). For users who'd rather not connect an account.
 *
 * Both paths normalize to the identical payload shape (see
 * normalize_response()), so the dashboard tab, Traffic Gap callout,
 * security monitor, and AI narrative (Phases 2-5) work unchanged
 * regardless of which one is active. This is unconditionally optional:
 * the dashboard tab is fully hidden (see templates/dashboard.php's
 * `$cf_available`) when neither path is available, never a required or
 * blocking flow.
 *
 * Note on schema confidence: the GraphQL field names in the BYO path
 * follow Cloudflare's publicly documented Analytics schema, but this has
 * not been exercised against a live zone in this environment. A
 * field-name mismatch surfaces as a graceful "temporarily unavailable"
 * message (via graphql_request()'s error handling) rather than a fatal
 * see docs/APP-CONNECT-WORKFLOW.md §9 for the live-verification checklist.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Cloudflare
 */
class Insightistic_Cloudflare {

	/** GraphQL Analytics API endpoint  one endpoint for every zone/query. */
	const GRAPHQL_ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

	/**
	 * Register AJAX hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_insightistic_test_cloudflare', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_insightistic_get_cloudflare_data', array( $this, 'ajax_get_data' ) );
		add_action( 'wp_ajax_insightistic_cloudflare_ai_analyze', array( $this, 'ajax_ai_analyze' ) );
	}

	/**
	 * Whether a Zone ID and API token are both stored (the Advanced/BYO path).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return (bool) get_option( 'insightistic_cloudflare_zone_id' ) && (bool) get_option( 'insightistic_cloudflare_api_token_enc' );
	}

	/**
	 * Whether Traffic Insights can show *something* right now  either the
	 * BYO path is configured, or a free account is connected (hosted path).
	 * Drives whether the dashboard tab renders at all; when this is false
	 * the tab is fully omitted rather than shown with a setup prompt, per
	 * the "never critical" requirement.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return self::is_configured()
			|| ( class_exists( 'Insightistic_License_Manager' ) && Insightistic_License_Manager::is_connected() );
	}

	/**
	 * Decrypt and return the stored API token.
	 *
	 * @return string|WP_Error
	 */
	private function get_token() {
		$enc = get_option( 'insightistic_cloudflare_api_token_enc' );
		if ( ! $enc ) {
			return new WP_Error( 'no_token', __( 'Cloudflare API token not configured. Please add it in Settings → Cloudflare.', 'insightistic' ) );
		}
		$token = Insightistic_Encryption::decrypt( $enc );
		if ( ! $token ) {
			return new WP_Error( 'bad_token', __( 'Failed to read the Cloudflare API token. Please re-save it in Settings.', 'insightistic' ) );
		}
		return $token;
	}

	/**
	 * AJAX: verify the Zone ID + API Token pair by requesting the zone
	 * itself (needs only Zone → Zone → Read). Confirms the credentials
	 * work before the GraphQL Analytics data layer is built on top of them.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$zone_id = get_option( 'insightistic_cloudflare_zone_id' );
		if ( ! $zone_id ) {
			wp_send_json_error( __( 'Enter your Cloudflare Zone ID first.', 'insightistic' ) );
		}

		$token = $this->get_token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( $token->get_error_message() );
		}

		$response = wp_remote_get(
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $body['success'] ) ) {
			$message = ! empty( $body['errors'][0]['message'] )
				? $body['errors'][0]['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Cloudflare rejected the request (HTTP %d). Check the Zone ID and that the token has Zone → Zone → Read permission.', 'insightistic' ),
					$status
				);
			wp_send_json_error( $message );
		}

		$zone_name = $body['result']['name'] ?? '';
		wp_send_json_success(
			$zone_name
				/* translators: %s: Cloudflare zone/domain name */
				? sprintf( __( 'Connected! Cloudflare confirms this token can read %s.', 'insightistic' ), $zone_name )
				: __( 'Connected.', 'insightistic' )
		);
	}

	/**
	 * AJAX: full Traffic Insights payload for the dashboard tab.
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$days   = min( max( intval( $_POST['days'] ?? 28 ), 1 ), 90 );
		$force  = ! empty( $_POST['force'] );
		$result = $this->get_dashboard_data( $days, $force );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: AI narrative over the already-fetched Traffic Insights payload.
	 * Gated by the same `ai_insights` account requirement as every other AI
	 * Insights flow  Insightistic_AI::analyze_cloudflare() enforces nothing
	 * extra itself, so the gate lives here, matching how
	 * Insightistic_Woocommerce::ajax_ai_analyze() gates commerce AI.
	 */
	public function ajax_ai_analyze() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		if ( ! get_option( 'insightistic_ai_enabled', 0 ) ) {
			wp_send_json_error( __( 'AI analysis is disabled. Enable it in Settings.', 'insightistic' ) );
		}

		if ( class_exists( 'Insightistic_Feature_Gate' ) && ! Insightistic_Feature_Gate::can( 'ai_insights' ) ) {
			wp_send_json_error(
				array(
					'code' => 'locked',
					'html' => Insightistic_Feature_Gate::locked_card( 'ai_insights', '', __( 'Create a free account to unlock AI Insights.', 'insightistic' ) ),
				)
			);
		}

		// Raw JSON payload from our own dashboard JS; decoded below, never echoed.
		$raw_data = wp_unslash( $_POST['data'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data     = json_decode( $raw_data, true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			wp_send_json_error( __( 'No traffic data to analyse. Load Traffic Insights data first.', 'insightistic' ) );
		}

		$days   = intval( $_POST['days'] ?? 28 );
		$result = ( new Insightistic_AI() )->analyze_cloudflare( $data, $days );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Dispatch to whichever data path is available. BYO takes priority when
	 * both are configured (an explicit opt-in the user set up on purpose);
	 * otherwise the hosted account path; otherwise a WP_Error the caller
	 * should never actually surface to a user, since the dashboard tab is
	 * hidden whenever is_available() is false.
	 *
	 * @param int  $days          Window size in days (max 90).
	 * @param bool $force_refresh Bypass the transient cache.
	 * @return array|WP_Error Array always includes `available` (bool).
	 */
	public function get_dashboard_data( $days = 28, $force_refresh = false ) {
		if ( self::is_configured() ) {
			return $this->get_direct_dashboard_data( $days, $force_refresh );
		}
		if ( class_exists( 'Insightistic_License_Manager' ) && Insightistic_License_Manager::is_connected() ) {
			return $this->get_hosted_dashboard_data( $days, $force_refresh );
		}
		return new WP_Error( 'insightistic_cf_unavailable', __( 'Cloudflare Traffic Insights is not set up on this site.', 'insightistic' ) );
	}

	/**
	 * Hosted path: ask the connected Insightistic account for this site's
	 * Cloudflare data. No Zone ID or token involved  see class docblock.
	 *
	 * @param int  $days          Window size in days (max 90).
	 * @param bool $force_refresh Bypass the transient cache.
	 * @return array|WP_Error `{ available: false }` when the account hasn't
	 *                        linked a Cloudflare zone yet  not an error.
	 */
	private function get_hosted_dashboard_data( $days = 28, $force_refresh = false ) {
		$days = min( max( intval( $days ), 1 ), 90 );

		$cache_key = 'insightistic_cf_hosted_data_' . $days;
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = Insightistic_Saas_Client::cloudflare_traffic( array( 'days' => $days ) );

		if ( ! $result['ok'] ) {
			if ( $result['network'] ) {
				return new WP_Error( 'insightistic_cf_network', __( 'Could not reach Insightistic. Please try again shortly.', 'insightistic' ) );
			}
			$code = is_array( $result['data'] ) && ! empty( $result['data']['code'] ) ? $result['data']['code'] : '';
			if ( 'no_license' === $code || in_array( $result['status'], array( 401, 403 ), true ) ) {
				return new WP_Error( 'insightistic_cf_unauthorized', __( 'Your Insightistic account connection could not be verified. Open Insightistic → License and reconnect.', 'insightistic' ) );
			}
			return new WP_Error(
				'insightistic_cf_error',
				$result['error'] ? $result['error'] : __( 'Insightistic Cloudflare Traffic Insights returned an error. Please try again.', 'insightistic' )
			);
		}

		$body = is_array( $result['data'] ) ? $result['data'] : array();

		if ( empty( $body['available'] ) ) {
			// Account connected but no Cloudflare zone linked to it yet
			// an expected state, not a failure. Still cached briefly so a
			// dashboard reload doesn't hammer the SaaS.
			$not_linked = array( 'available' => false );
			set_transient( $cache_key, $not_linked, 15 * MINUTE_IN_SECONDS );
			return $not_linked;
		}

		$payload              = is_array( $body['data'] ?? null ) ? $body['data'] : array();
		$payload['available'] = true;
		$payload['cached_at'] = time();

		set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
		return $payload;
	}

	/**
	 * Advanced/BYO path: query Cloudflare's GraphQL Analytics API directly
	 * with the Zone ID + API token pasted into Settings → Cloudflare.
	 * Daily timeline, country/status/TLS breakdowns, and top firewall
	 * actions  cached for 15 minutes like the GA4/GSC/Woo data layers.
	 *
	 * @param int  $days          Window size in days (max 90; Cloudflare's
	 *                            free-plan GraphQL retention).
	 * @param bool $force_refresh Bypass the transient cache.
	 * @return array|WP_Error
	 */
	private function get_direct_dashboard_data( $days = 28, $force_refresh = false ) {
		$days    = min( max( intval( $days ), 1 ), 90 );
		$zone_id = get_option( 'insightistic_cloudflare_zone_id' );

		if ( ! $zone_id ) {
			return new WP_Error( 'insightistic_cf_missing_zone', __( 'Cloudflare Zone ID is not configured. Please visit Settings → Cloudflare.', 'insightistic' ) );
		}

		$cache_key = 'insightistic_cf_data_' . $days . '_' . md5( $zone_id );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$token = $this->get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$until = gmdate( 'Y-m-d' );
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$query = 'query GetZoneAnalytics($zoneTag: string!, $since: Date!, $until: Date!, $sinceTime: Time!, $untilTime: Time!, $rows: Int!) {
			viewer {
				zones(filter: { zoneTag: $zoneTag }) {
					httpRequests1dGroups(limit: $rows, filter: { date_geq: $since, date_leq: $until }, orderBy: [date_ASC]) {
						dimensions { date }
						sum {
							requests
							cachedRequests
							cachedBytes
							bytes
							threats
							pageViews
							encryptedRequests
							countryMap { clientCountryName requests threats }
							responseStatusMap { edgeResponseStatus requests }
							clientSSLMap { clientSSLProtocol requests }
							browserMap { uaBrowserFamily pageViews }
						}
						uniq { uniques }
					}
					firewallEventsAdaptiveGroups(limit: 20, filter: { datetime_geq: $sinceTime, datetime_leq: $untilTime }, orderBy: [count_DESC]) {
						count
						dimensions { action clientCountryName }
					}
				}
			}
		}';

		$variables = array(
			'zoneTag'   => $zone_id,
			'since'     => $since,
			'until'     => $until,
			'sinceTime' => $since . 'T00:00:00Z',
			'untilTime' => $until . 'T23:59:59Z',
			'rows'      => $days + 1,
		);

		$raw = $this->graphql_request( $query, $variables, $token );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$result              = $this->normalize_response( $raw );
		$result['available'] = true;
		$result['cached_at'] = time();

		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Send a GraphQL request with exponential-backoff retry on transient
	 * transport/server failures  same policy as Insightistic_GA::api_request().
	 *
	 * @param string $query     GraphQL query document.
	 * @param array  $variables GraphQL variables.
	 * @param string $token     Bearer token.
	 * @param int    $attempts  Maximum attempts.
	 * @return array|WP_Error Decoded `data` object, or WP_Error on hard failure.
	 */
	private function graphql_request( $query, $variables, $token, $attempts = 3 ) {
		$delay = 1;

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$response = wp_remote_post(
				self::GRAPHQL_ENDPOINT,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'query'     => $query,
							'variables' => $variables,
						)
					),
					'timeout' => 20,
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $i < $attempts ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}
				return $response;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $i < $attempts ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			if ( ! empty( $decoded['errors'] ) ) {
				$message = is_array( $decoded['errors'][0] ) && ! empty( $decoded['errors'][0]['message'] )
					? $decoded['errors'][0]['message']
					: __( 'Cloudflare returned an error.', 'insightistic' );
				error_log( 'Insightistic Cloudflare GraphQL error: ' . wp_json_encode( $decoded['errors'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error( 'insightistic_cf_graphql', $message );
			}

			if ( 200 !== $code || empty( $decoded['data'] ) ) {
				return new WP_Error(
					'insightistic_cf_http',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Cloudflare Traffic Insights is temporarily unavailable (HTTP %d). Please try again shortly.', 'insightistic' ),
						$code
					)
				);
			}

			return $decoded['data'];
		}

		return new WP_Error(
			'insightistic_cf_request_failed',
			__( 'The Cloudflare request failed after multiple attempts. Please try again shortly.', 'insightistic' )
		);
	}

	/**
	 * Flatten the raw GraphQL `data` object into the structured payload the
	 * dashboard tab (Phase 2) and AI narrative (Phase 5) consume.
	 *
	 * @param array $data Decoded GraphQL `data` object.
	 * @return array
	 */
	private function normalize_response( $data ) {
		$zone   = $data['viewer']['zones'][0] ?? array();
		$groups = is_array( $zone['httpRequests1dGroups'] ?? null ) ? $zone['httpRequests1dGroups'] : array();
		$fw     = is_array( $zone['firewallEventsAdaptiveGroups'] ?? null ) ? $zone['firewallEventsAdaptiveGroups'] : array();

		$timeline     = array();
		$totals       = array(
			'requests'           => 0,
			'cached_requests'    => 0,
			'cached_bytes'       => 0,
			'bytes'              => 0,
			'threats'            => 0,
			'page_views'         => 0,
			'encrypted_requests' => 0,
			'uniques'            => 0,
		);
		$countries    = array();
		$status_codes = array();
		$tls_versions = array();
		$browsers     = array();

		foreach ( $groups as $row ) {
			$sum  = $row['sum'] ?? array();
			$date = $row['dimensions']['date'] ?? '';

			$timeline[] = array(
				'date'            => $date,
				'requests'        => (int) ( $sum['requests'] ?? 0 ),
				'cached_requests' => (int) ( $sum['cachedRequests'] ?? 0 ),
				'threats'         => (int) ( $sum['threats'] ?? 0 ),
			);

			$totals['requests']           += (int) ( $sum['requests'] ?? 0 );
			$totals['cached_requests']    += (int) ( $sum['cachedRequests'] ?? 0 );
			$totals['cached_bytes']       += (int) ( $sum['cachedBytes'] ?? 0 );
			$totals['bytes']              += (int) ( $sum['bytes'] ?? 0 );
			$totals['threats']            += (int) ( $sum['threats'] ?? 0 );
			$totals['page_views']         += (int) ( $sum['pageViews'] ?? 0 );
			$totals['encrypted_requests'] += (int) ( $sum['encryptedRequests'] ?? 0 );
			$totals['uniques']            += (int) ( $row['uniq']['uniques'] ?? 0 );

			foreach ( (array) ( $sum['countryMap'] ?? array() ) as $c ) {
				$name = $c['clientCountryName'] ?? '';
				if ( ! $name ) {
					continue;
				}
				$countries[ $name ]            ??= array( 'requests' => 0, 'threats' => 0 );
				$countries[ $name ]['requests'] += (int) ( $c['requests'] ?? 0 );
				$countries[ $name ]['threats']  += (int) ( $c['threats'] ?? 0 );
			}
			foreach ( (array) ( $sum['responseStatusMap'] ?? array() ) as $s ) {
				$code = (string) ( $s['edgeResponseStatus'] ?? '' );
				if ( '' === $code ) {
					continue;
				}
				$status_codes[ $code ] = ( $status_codes[ $code ] ?? 0 ) + (int) ( $s['requests'] ?? 0 );
			}
			foreach ( (array) ( $sum['clientSSLMap'] ?? array() ) as $t ) {
				$proto = $t['clientSSLProtocol'] ?? '';
				if ( ! $proto ) {
					continue;
				}
				$tls_versions[ $proto ] = ( $tls_versions[ $proto ] ?? 0 ) + (int) ( $t['requests'] ?? 0 );
			}
			foreach ( (array) ( $sum['browserMap'] ?? array() ) as $b ) {
				$family = $b['uaBrowserFamily'] ?? '';
				if ( ! $family ) {
					continue;
				}
				$browsers[ $family ] = ( $browsers[ $family ] ?? 0 ) + (int) ( $b['pageViews'] ?? 0 );
			}
		}

		arsort( $countries );
		arsort( $status_codes );
		arsort( $tls_versions );
		arsort( $browsers );

		$firewall_events = array();
		foreach ( $fw as $row ) {
			$firewall_events[] = array(
				'action'  => $row['dimensions']['action'] ?? '',
				'country' => $row['dimensions']['clientCountryName'] ?? '',
				'count'   => (int) ( $row['count'] ?? 0 ),
			);
		}

		return array(
			'totals'          => $totals,
			'cache_ratio'     => $totals['requests'] > 0 ? round( ( $totals['cached_requests'] / $totals['requests'] ) * 100, 1 ) : 0,
			'encrypted_ratio' => $totals['requests'] > 0 ? round( ( $totals['encrypted_requests'] / $totals['requests'] ) * 100, 1 ) : 0,
			'timeline'        => $timeline,
			'top_countries'   => array_slice( $countries, 0, 10, true ),
			'status_codes'    => array_slice( $status_codes, 0, 10, true ),
			'tls_versions'    => $tls_versions,
			'top_browsers'    => array_slice( $browsers, 0, 8, true ),
			'firewall_events' => array_slice( $firewall_events, 0, 20 ),
		);
	}
}
