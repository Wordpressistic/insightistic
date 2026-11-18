<?php
/**
 * Google Search Console API class.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_GSC
 * Handles Search Console API requests via shared service account auth.
 */
class Insightistic_GSC {

	/** GSC API base URL. */
	const API_BASE = 'https://www.googleapis.com/webmasters/v3/sites/';

	/**
	 * Register AJAX hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_insightistic_get_gsc_data', array( $this, 'ajax_get_data' ) );
		add_action( 'wp_ajax_insightistic_test_gsc', array( $this, 'ajax_test_connection' ) );
	}

	/*
	------------------------------------------------------------------ */
	/*
	AJAX Handlers                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Main GSC data AJAX handler.
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$days         = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 28;
		$days         = min( max( $days, 1 ), 90 );
		$force        = ! empty( $_POST['force'] );
		$property_url = get_option( 'insightistic_gsc_property_url' );

		if ( ! $property_url ) {
			wp_send_json_error( __( 'Search Console property URL is not configured. Please visit Settings.', 'insightistic' ) );
		}

		// 15-minute cache (bypassed when the user clicks Force refresh).
		$cache_key = 'insightistic_gsc_' . $days . '_' . md5( $property_url );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_send_json_success( $cached );
			}
		}

		$token = Insightistic_Auth::get_token( 'https://www.googleapis.com/auth/webmasters.readonly', 'gsc' );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( $token->get_error_message() );
		}

		// GSC has a 2-3 day data delay, so end 3 days ago.
		$end_date   = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 3 ) . ' days' ) );
		$prev_end   = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 4 ) . ' days' ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( '-' . ( ( $days * 2 ) + 3 ) . ' days' ) );

		$resolved_property = $this->resolve_accessible_property( $property_url, $token );
		if ( is_wp_error( $resolved_property ) ) {
			wp_send_json_error( $this->permission_error_message( $resolved_property, $property_url ) );
		}
		if ( ! empty( $resolved_property['auto_detected'] ) ) {
			update_option( 'insightistic_gsc_property_url', $resolved_property['property'] );
			$property_url = $resolved_property['property'];
		}

		$site_url    = $resolved_property['encoded'];
		$overview    = $this->get_overview( $site_url, $token, $start_date, $end_date, $prev_start, $prev_end );
		$top_queries = $this->get_top_queries( $site_url, $token, $start_date, $end_date );
		$top_pages   = $this->get_top_pages( $site_url, $token, $start_date, $end_date );
		$devices     = $this->get_devices( $site_url, $token, $start_date, $end_date );

		$result = array(
			'overview'    => $overview,
			'top_queries' => $top_queries,
			'top_pages'   => $top_pages,
			'devices'     => $devices,
			'date_range'  => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			'cached_at'   => time(),
		);

		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * Test GSC connection AJAX handler.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		delete_transient( 'insightistic_access_token_gsc' );

		$token = Insightistic_Auth::get_token( 'https://www.googleapis.com/auth/webmasters.readonly', 'gsc' );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( $token->get_error_message() );
		}

		$property_url = get_option( 'insightistic_gsc_property_url' );
		if ( ! $property_url ) {
			wp_send_json_error( __( 'Search Console property URL is not configured.', 'insightistic' ) );
		}

		$resolved_property = $this->resolve_accessible_property( $property_url, $token );
		if ( is_wp_error( $resolved_property ) ) {
			wp_send_json_error( $this->permission_error_message( $resolved_property, $property_url ) );
		}

		if ( ! empty( $resolved_property['auto_detected'] ) ) {
			update_option( 'insightistic_gsc_property_url', $resolved_property['property'] );
		}

		if ( ! empty( $resolved_property['auto_detected'] ) ) {
			wp_send_json_success(
				sprintf(
					/* translators: %s: detected Search Console domain property. */
					__( 'Search Console connection successful. Insightistic detected and saved the domain property %s.', 'insightistic' ),
					$resolved_property['property']
				)
			);
		}

		wp_send_json_success( __( 'Search Console connection successful!', 'insightistic' ) );
	}

	/**
	 * Normalize a URL-prefix or domain property before storing it.
	 *
	 * @param string $property_url Raw setting value.
	 * @return string
	 */
	public static function normalize_property_input( $property_url ) {
		$property_url = trim( sanitize_text_field( (string) $property_url ) );
		if ( preg_match( '/^sc-domain\s*:\s*(.+)$/i', $property_url, $matches ) ) {
			$domain = strtolower( trim( $matches[1], " /\t\r\n" ) );
			$domain = preg_replace( '/^www\./', '', $domain );
			return $domain ? 'sc-domain:' . $domain : '';
		}

		return esc_url_raw( $property_url );
	}

	/**
	 * Resolve the property that the configured service account can actually
	 * query. This handles the common case where Search Console shows a domain
	 * property (sc-domain:example.com) but the admin entered a URL-prefix URL.
	 *
	 * @param string $property_url Saved setting value.
	 * @param string $token        Google access token.
	 * @return array|WP_Error
	 */
	private function resolve_accessible_property( $property_url, $token ) {
		$property_url = self::normalize_property_input( $property_url );
		$candidates   = array( $property_url );
		$domain       = $this->domain_property_candidate( $property_url );
		if ( $domain && $domain !== $property_url ) {
			$candidates[] = $domain;
		}

		$body = array(
			'startDate' => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
			'endDate'   => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
			'rowLimit'  => 1,
		);

		$last_error = null;
		foreach ( $candidates as $candidate ) {
			$url      = self::API_BASE . $this->encode_property_url( $candidate ) . '/searchAnalytics/query';
			$response = $this->api_request( $url, $body, $token );
			if ( ! is_wp_error( $response ) ) {
				return array(
					'property'      => $candidate,
					'encoded'       => $this->encode_property_url( $candidate ),
					'auto_detected' => $candidate !== $property_url,
				);
			}
			$last_error = $response;
			if ( 'gsc_permission_denied' !== $response->get_error_code() ) {
				break;
			}
		}

		return $last_error instanceof WP_Error
			? $last_error
			: new WP_Error( 'gsc_error', __( 'Search Console property could not be verified.', 'insightistic' ) );
	}

	/**
	 * Build a domain-property candidate from a URL-prefix property.
	 *
	 * @param string $property_url Saved property value.
	 * @return string
	 */
	private function domain_property_candidate( $property_url ) {
		if ( 0 === stripos( $property_url, 'sc-domain:' ) ) {
			return '';
		}

		$host = wp_parse_url( $property_url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}

		$host = strtolower( preg_replace( '/^www\./', '', $host ) );
		return $host ? 'sc-domain:' . $host : '';
	}

	/**
	 * Turn a Search Console API error into actionable admin copy.
	 *
	 * @param WP_Error $error        API error.
	 * @param string   $property_url Tested property.
	 * @return string
	 */
	private function permission_error_message( $error, $property_url ) {
		if ( 'gsc_permission_denied' !== $error->get_error_code() ) {
			return $error->get_error_message();
		}

		$email  = sanitize_email( get_option( 'insightistic_api_email', '' ) );
		$detail = $email
			? sprintf(
				/* translators: %1$s: property; %2$s: service-account email. */
				__( 'Search Console denied access to %1$s for %2$s. In Search Console → Settings → Users and permissions, add this exact service-account email to the selected property with Full permission.', 'insightistic' ),
				$property_url,
				$email
			)
			: sprintf(
				/* translators: %s: property. */
				__( 'Search Console denied access to %s. Add the service-account email from Insightistic → Settings → GA4 to this exact property with Full permission.', 'insightistic' ),
				$property_url
			);

		$domain = $this->domain_property_candidate( $property_url );
		if ( $domain ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: domain property syntax. */
				__( 'If Search Console shows a domain property without https://, enter %s and test again.', 'insightistic' ),
				$domain
			);
		}

		return $detail;
	}

	/*
	------------------------------------------------------------------ */
	/*
	GSC Report Methods                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Get overview totals: clicks, impressions, CTR, avg position.
	 */
	private function get_overview( $site_url, $token, $start, $end, $prev_start, $prev_end ) {
		$url = self::API_BASE . $site_url . '/searchAnalytics/query';

		// Current period.
		$cur_data  = $this->api_request(
			$url,
			array(
				'startDate' => $start,
				'endDate'   => $end,
				'rowLimit'  => 1,
			),
			$token
		);
		$prev_data = $this->api_request(
			$url,
			array(
				'startDate' => $prev_start,
				'endDate'   => $prev_end,
				'rowLimit'  => 1,
			),
			$token
		);

		$cur  = ! is_wp_error( $cur_data ) && isset( $cur_data['rows'][0] ) ? $cur_data['rows'][0] : null;
		$prev = ! is_wp_error( $prev_data ) && isset( $prev_data['rows'][0] ) ? $prev_data['rows'][0] : null;

		return array(
			'clicks'      => array(
				'value'  => $cur ? intval( $cur['clicks'] ) : 0,
				'change' => $this->pct_change( $prev ? $prev['clicks'] : 0, $cur ? $cur['clicks'] : 0 ),
			),
			'impressions' => array(
				'value'  => $cur ? intval( $cur['impressions'] ) : 0,
				'change' => $this->pct_change( $prev ? $prev['impressions'] : 0, $cur ? $cur['impressions'] : 0 ),
			),
			'ctr'         => array(
				'value'  => $cur ? round( $cur['ctr'] * 100, 2 ) : 0,
				'change' => $this->pct_change( $prev ? $prev['ctr'] : 0, $cur ? $cur['ctr'] : 0 ),
			),
			'position'    => array(
				'value'  => $cur ? round( $cur['position'], 1 ) : 0,
				// Position change is inverted: lower is better.
				'change' => -1 * $this->pct_change( $prev ? $prev['position'] : 0, $cur ? $cur['position'] : 0 ),
			),
		);
	}

	/**
	 * Get top queries by clicks.
	 */
	private function get_top_queries( $site_url, $token, $start, $end ) {
		$url  = self::API_BASE . $site_url . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'query' ),
			'rowLimit'   => 10,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		$result = array();
		foreach ( $data['rows'] as $row ) {
			$result[] = array(
				'query'       => $row['keys'][0],
				'clicks'      => intval( $row['clicks'] ),
				'impressions' => intval( $row['impressions'] ),
				'ctr'         => round( $row['ctr'] * 100, 2 ),
				'position'    => round( $row['position'], 1 ),
			);
		}
		return $result;
	}

	/**
	 * Get top pages by clicks.
	 */
	private function get_top_pages( $site_url, $token, $start, $end ) {
		$url  = self::API_BASE . $site_url . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'page' ),
			'rowLimit'   => 10,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		$result = array();
		foreach ( $data['rows'] as $row ) {
			$result[] = array(
				'page'        => $row['keys'][0],
				'clicks'      => intval( $row['clicks'] ),
				'impressions' => intval( $row['impressions'] ),
				'ctr'         => round( $row['ctr'] * 100, 2 ),
				'position'    => round( $row['position'], 1 ),
			);
		}
		return $result;
	}

	/**
	 * Get device breakdown.
	 */
	private function get_devices( $site_url, $token, $start, $end ) {
		$url  = self::API_BASE . $site_url . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'device' ),
			'rowLimit'   => 3,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		$total_clicks = 0;
		$rows_raw     = array();
		foreach ( $data['rows'] as $row ) {
			$total_clicks += intval( $row['clicks'] );
			$rows_raw[]    = array(
				'device' => $row['keys'][0],
				'clicks' => intval( $row['clicks'] ),
				'impr'   => intval( $row['impressions'] ),
			);
		}

		$result = array();
		foreach ( $rows_raw as $r ) {
			$result[] = array(
				'device' => $r['device'],
				'clicks' => $r['clicks'],
				'impr'   => $r['impr'],
				'share'  => $total_clicks > 0 ? round( ( $r['clicks'] / $total_clicks ) * 100, 1 ) : 0,
			);
		}
		return $result;
	}

	/**
	 * Build the payload connector v2's sync/seo/* endpoints expect: raw
	 * day-by-day totals plus top queries/pages, unrounded (ctr as GSC's
	 * native 0-1 fraction). Returns null when GSC isn't configured.
	 *
	 * @param int $days How many trailing days to include (GSC's own 2-3 day
	 *                  data delay is applied on top, same as ajax_get_data()).
	 * @return array{daily:array,queries:array,pages:array}|WP_Error|null
	 */
	public function get_sync_payload( $days = 28 ) {
		$property_url = get_option( 'insightistic_gsc_property_url' );
		if ( ! $property_url ) {
			return null;
		}

		$days  = min( max( intval( $days ), 1 ), 90 );
		$token = Insightistic_Auth::get_token( 'https://www.googleapis.com/auth/webmasters.readonly', 'gsc' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$end_date   = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 3 ) . ' days' ) );
		$resolved_property = $this->resolve_accessible_property( $property_url, $token );
		if ( is_wp_error( $resolved_property ) ) {
			return $resolved_property;
		}
		if ( ! empty( $resolved_property['auto_detected'] ) ) {
			update_option( 'insightistic_gsc_property_url', $resolved_property['property'] );
		}
		$site_url   = $resolved_property['encoded'];

		return array(
			'daily'   => $this->get_sync_daily( $site_url, $token, $start_date, $end_date ),
			'queries' => $this->get_sync_rows( $site_url, $token, $start_date, $end_date, 'query', 'query' ),
			'pages'   => $this->get_sync_rows( $site_url, $token, $start_date, $end_date, 'page', 'page_path' ),
		);
	}

	/** Raw per-day clicks/impressions/ctr/position — ctr left as GSC's native 0-1 fraction. */
	private function get_sync_daily( $site_url, $token, $start, $end ) {
		$url  = self::API_BASE . $site_url . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'date' ),
			'rowLimit'   => 100,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$rows[] = array(
				'date'         => $row['keys'][0],
				'clicks'       => intval( $row['clicks'] ),
				'impressions'  => intval( $row['impressions'] ),
				'ctr'          => round( floatval( $row['ctr'] ), 4 ),
				'avg_position' => round( floatval( $row['position'] ), 2 ),
			);
		}
		return $rows;
	}

	/**
	 * Raw top-N rows for a single dimension ('query' or 'page'), unrounded,
	 * for sync/seo/queries and sync/seo/pages.
	 *
	 * @param string $site_url  Rawurlencoded, trailing-slashed GSC property URL.
	 * @param string $token     OAuth access token.
	 * @param string $start     Start date (Y-m-d).
	 * @param string $end       End date (Y-m-d).
	 * @param string $dimension GSC dimension name ('query'|'page').
	 * @param string $key_field Output field name for the dimension value
	 *                          ('query'|'page_path' — matches the SaaS schema).
	 */
	private function get_sync_rows( $site_url, $token, $start, $end, $dimension, $key_field ) {
		$url  = self::API_BASE . $site_url . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( $dimension ),
			'rowLimit'   => 250,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$rows[] = array(
				$key_field    => $row['keys'][0],
				'clicks'      => intval( $row['clicks'] ),
				'impressions' => intval( $row['impressions'] ),
				'ctr'         => round( floatval( $row['ctr'] ), 4 ),
				'position'    => round( floatval( $row['position'] ), 2 ),
			);
		}
		return $rows;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Make an authenticated POST request to the Search Console API.
	 */
	private function api_request( $url, $body, $token ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $decoded['error'] ) ) {
			$error   = is_array( $decoded['error'] ) ? $decoded['error'] : array();
			$message = isset( $error['message'] ) && is_string( $error['message'] )
				? $error['message']
				: __( 'Search Console API error.', 'insightistic' );
			$status  = absint( $error['code'] ?? wp_remote_retrieve_response_code( $response ) );
			$reason  = '';
			if ( ! empty( $error['errors'][0]['reason'] ) ) {
				$reason = sanitize_key( $error['errors'][0]['reason'] );
			}
			$code = 403 === $status || in_array( $reason, array( 'forbidden', 'insufficientpermissions' ), true )
				? 'gsc_permission_denied'
				: 'gsc_error';
			return new WP_Error( $code, $message );
		}

		return $decoded;
	}

	/**
	 * Encode a URL property or a domain property for the Search Console API.
	 * Domain properties use the literal `sc-domain:` prefix and must not gain
	 * a trailing slash.
	 *
	 * @param string $property_url Saved property value.
	 * @return string
	 */
	private function encode_property_url( $property_url ) {
		$property_url = trim( (string) $property_url );
		if ( 0 === strpos( $property_url, 'sc-domain:' ) ) {
			return rawurlencode( rtrim( $property_url, '/' ) );
		}
		return rawurlencode( trailingslashit( $property_url ) );
	}

	/**
	 * Calculate percent change.
	 */
	private function pct_change( $old, $new ) {
		if ( 0 === $old ) {
			return $new > 0 ? 100 : 0;
		}
		return round( ( ( $new - $old ) / abs( $old ) ) * 100, 1 );
	}
}
