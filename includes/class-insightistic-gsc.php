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
		add_action( 'wp_ajax_insightistic_get_gsc_data',      array( $this, 'ajax_get_data' ) );
		add_action( 'wp_ajax_insightistic_test_gsc',          array( $this, 'ajax_test_connection' ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX Handlers                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Main GSC data AJAX handler.
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$days         = min( max( intval( $_POST['days'] ?? 28 ), 1 ), 90 );
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

		$site_url    = rawurlencode( trailingslashit( $property_url ) );
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

		$site_url = rawurlencode( trailingslashit( $property_url ) );
		$url      = self::API_BASE . $site_url . '/searchAnalytics/query';
		$body     = array(
			'startDate' => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
			'endDate'   => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
			'rowLimit'  => 1,
		);

		$response = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		wp_send_json_success( __( 'Search Console connection successful!', 'insightistic' ) );
	}

	/* ------------------------------------------------------------------ */
	/* GSC Report Methods                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Get overview totals: clicks, impressions, CTR, avg position.
	 */
	private function get_overview( $site_url, $token, $start, $end, $prev_start, $prev_end ) {
		$url = self::API_BASE . $site_url . '/searchAnalytics/query';

		// Current period.
		$cur_data  = $this->api_request( $url, array( 'startDate' => $start, 'endDate' => $end, 'rowLimit' => 1 ), $token );
		$prev_data = $this->api_request( $url, array( 'startDate' => $prev_start, 'endDate' => $prev_end, 'rowLimit' => 1 ), $token );

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
		$site_url   = rawurlencode( trailingslashit( $property_url ) );

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
				$key_field   => $row['keys'][0],
				'clicks'      => intval( $row['clicks'] ),
				'impressions' => intval( $row['impressions'] ),
				'ctr'         => round( floatval( $row['ctr'] ), 4 ),
				'position'    => round( floatval( $row['position'] ), 2 ),
			);
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
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
			return new WP_Error( 'gsc_error', $decoded['error']['message'] ?? __( 'Search Console API error.', 'insightistic' ) );
		}

		return $decoded;
	}

	/**
	 * Calculate percent change.
	 */
	private function pct_change( $old, $new ) {
		if ( 0 == $old ) {
			return $new > 0 ? 100 : 0;
		}
		return round( ( ( $new - $old ) / abs( $old ) ) * 100, 1 );
	}
}
