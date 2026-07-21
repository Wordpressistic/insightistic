<?php
/**
 * Google Analytics 4 Data API class.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_GA
 * Handles GA4 Data API requests with extended metrics.
 */
class Insightistic_GA {

	/**
	 * Register AJAX hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_insightistic_get_data',        array( $this, 'ajax_get_data' ) );
		add_action( 'wp_ajax_insightistic_test_connection',  array( $this, 'ajax_test_connection' ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX Handlers                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Main data AJAX handler.
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$days   = min( max( intval( $_POST['days'] ?? 28 ), 1 ), 365 );
		$force  = ! empty( $_POST['force'] );
		$result = $this->get_dashboard_data( $days, $force );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Build the full dashboard data payload for UI, AI, and email digests.
	 *
	 * @param int  $days Number of days to include.
	 * @param bool $force_refresh Whether to bypass the transient cache.
	 * @return array|WP_Error
	 */
	public function get_dashboard_data( $days = 28, $force_refresh = false ) {
		$days        = min( max( intval( $days ), 1 ), 365 );
		$property_id = get_option( 'insightistic_property_id' );

		if ( ! $property_id ) {
			return new WP_Error( 'insightistic_ga4_missing_property', __( 'GA4 Property ID is not configured. Please visit Settings.', 'insightistic' ) );
		}

		// Check transient cache (15-minute TTL).
		$cache_key = 'insightistic_data_' . $days . '_' . md5( $property_id );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$token = Insightistic_Auth::get_token( 'https://www.googleapis.com/auth/analytics.readonly', 'ga4' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$prev_end   = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 1 ) . ' days' ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( '-' . ( $days * 2 ) . ' days' ) );

		// Run all reports. A failure in any single report degrades that panel
		// gracefully instead of aborting the entire dashboard load.
		$attribution       = $this->get_attribution_report( $property_id, $token, $start_date, $end_date );
		$attribution_error = is_wp_error( $attribution ) ? $attribution : null;
		if ( $attribution_error ) {
			$attribution = array();
		}

		$time_series = $this->get_time_series( $property_id, $token, $start_date, $end_date );
		$overview    = $this->get_overview( $property_id, $token, $start_date, $end_date, $prev_start, $prev_end );
		$countries   = $this->get_countries( $property_id, $token, $start_date, $end_date, $prev_start, $prev_end );
		$pages       = $this->get_top_pages( $property_id, $token, $start_date, $end_date, $prev_start, $prev_end );
		$channels    = $this->get_traffic_channels( $property_id, $token, $start_date, $end_date, $prev_start, $prev_end );
		$top_posts   = $this->get_top_posts( $property_id, $token, $start_date, $end_date );

		$structured = $this->build_structured_data( $attribution );

		$result = array(
			'html'            => $attribution_error
				? $this->render_unavailable( $attribution_error->get_error_message() )
				: $this->render_attribution_table( $attribution ),
			'chartData'       => $time_series,
			'overview'        => $overview,
			'countries'       => $countries,
			'pages'           => $pages,
			'channels'        => $channels,
			'top_posts'       => $top_posts,
			'structured_data' => $structured,
			'partial'         => (bool) $attribution_error,
			'cached_at'       => time(),
		);

		// Never cache a partial/error payload  retry on the next load.
		if ( ! $attribution_error ) {
			set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * Build the payload connector v2's sync/traffic/* endpoints expect —
	 * raw day-by-day totals and a channel breakdown, not the display-
	 * formatted/rounded shapes get_dashboard_data() builds for the chart
	 * widgets. Returns null (not WP_Error) when GA4 isn't configured, so
	 * callers can skip this source silently rather than surfacing an error
	 * for an integration the site simply hasn't set up.
	 *
	 * @param int $days How many trailing days to include (max 90, matching
	 *                  the handshake-advertised traffic_days_per_request).
	 * @return array{daily:array,channels:array}|WP_Error|null
	 */
	public function get_sync_payload( $days = 28 ) {
		$property_id = get_option( 'insightistic_property_id' );
		if ( ! $property_id ) {
			return null;
		}

		$days = min( max( intval( $days ), 1 ), 90 );
		$token = Insightistic_Auth::get_token( 'https://www.googleapis.com/auth/analytics.readonly', 'ga4' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		return array(
			'daily'    => $this->get_sync_daily( $property_id, $token, $start_date, $end_date ),
			'channels' => $this->get_sync_channel_breakdown( $property_id, $token, $start_date, $end_date ),
		);
	}

	/**
	 * Raw per-day totals — every metric traffic_daily has a column for,
	 * unrounded, with an ISO date per row (GA4 returns dates as YYYYMMDD).
	 */
	private function get_sync_daily( $property_id, $token, $start, $end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
			'dimensions' => array( array( 'name' => 'date' ) ),
			'metrics'    => array(
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'newUsers' ),
				array( 'name' => 'sessions' ),
				array( 'name' => 'engagedSessions' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'engagementRate' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
				array( 'name' => 'conversions' ),
				array( 'name' => 'transactions' ),
				array( 'name' => 'totalRevenue' ),
			),
			'orderBys'   => array( array( 'dimension' => array( 'dimensionName' => 'date' ) ) ),
			'limit'      => 100,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$raw = $row['dimensionValues'][0]['value']; // YYYYMMDD
			$m   = $row['metricValues'];
			$rows[] = array(
				'date'                             => substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 ),
				'users'                            => intval( $m[0]['value'] ),
				'new_users'                        => intval( $m[1]['value'] ),
				'sessions'                         => intval( $m[2]['value'] ),
				'engaged_sessions'                 => intval( $m[3]['value'] ),
				'page_views'                       => intval( $m[4]['value'] ),
				'engagement_rate'                  => round( floatval( $m[5]['value'] ) * 100, 2 ),
				'bounce_rate'                      => round( floatval( $m[6]['value'] ) * 100, 2 ),
				'avg_engagement_duration_seconds'  => intval( round( floatval( $m[7]['value'] ) ) ),
				'conversions'                      => intval( $m[8]['value'] ),
				'transactions'                     => intval( $m[9]['value'] ),
				'revenue'                          => round( floatval( $m[10]['value'] ), 2 ),
			);
		}
		return $rows;
	}

	/** Session-channel-group breakdown for sync/traffic/dimensions (dimension_type=channel). */
	private function get_sync_channel_breakdown( $property_id, $token, $start, $end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
			'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'conversions' ),
				array( 'name' => 'totalRevenue' ),
				array( 'name' => 'engagementRate' ),
			),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 15,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $data['rows'] as $row ) {
			$m      = $row['metricValues'];
			$rows[] = array(
				'dimension_value' => $row['dimensionValues'][0]['value'],
				'sessions'        => intval( $m[0]['value'] ),
				'users'           => intval( $m[1]['value'] ),
				'views'           => intval( $m[2]['value'] ),
				'conversions'     => intval( $m[3]['value'] ),
				'revenue'         => round( floatval( $m[4]['value'] ), 2 ),
				'engagement_rate' => round( floatval( $m[5]['value'] ) * 100, 2 ),
			);
		}
		return $rows;
	}

	/**
	 * Test GA4 connection AJAX handler.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		delete_transient( 'insightistic_access_token_ga4' );

		$token = Insightistic_Auth::get_token( 'https://www.googleapis.com/auth/analytics.readonly', 'ga4' );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( $token->get_error_message() );
		}

		$property_id = get_option( 'insightistic_property_id' );
		$url         = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body        = array(
			'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'today' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
			'limit'      => 1,
		);

		$response = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		wp_send_json_success( __( 'Connection successful! Your GA4 property is accessible.', 'insightistic' ) );
	}

	/* ------------------------------------------------------------------ */
	/* GA4 Report Methods                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Get source/medium attribution report.
	 */
	private function get_attribution_report( $property_id, $token, $start, $end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
			'dimensions' => array(
				array( 'name' => 'sessionSource' ),
				array( 'name' => 'sessionMedium' ),
			),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalRevenue' ),
				array( 'name' => 'transactions' ),
				array( 'name' => 'purchasers' ),
				array( 'name' => 'averagePurchaseRevenue' ),
			),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ),
			),
			'limit'      => 50,
		);

		$data = $this->api_request( $url, $body, $token );

		// Fallback without ecommerce metrics.
		if ( is_wp_error( $data ) || isset( $data['error'] ) ) {
			$body['metrics'] = array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalRevenue' ),
				array( 'name' => 'transactions' ),
			);
			$data            = $this->api_request( $url, $body, $token );
			if ( ! is_wp_error( $data ) && ! isset( $data['error'] ) ) {
				$data['basic_metrics'] = true;
			}
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'ga_error', $data['error']['message'] );
		}
		return $data;
	}

	/**
	 * Get daily time series data.
	 */
	private function get_time_series( $property_id, $token, $start, $end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
			'dimensions' => array( array( 'name' => 'date' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalRevenue' ),
				array( 'name' => 'activeUsers' ),
			),
			'orderBys'   => array(
				array( 'dimension' => array( 'dimensionName' => 'date' ) ),
			),
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return null;
		}

		$labels   = array();
		$sessions = array();
		$revenue  = array();
		$users    = array();

		foreach ( $data['rows'] as $row ) {
			$date_raw   = $row['dimensionValues'][0]['value'];
			$labels[]   = gmdate( 'M j', mktime( 0, 0, 0, substr( $date_raw, 4, 2 ), substr( $date_raw, 6, 2 ), substr( $date_raw, 0, 4 ) ) );
			$sessions[] = intval( $row['metricValues'][0]['value'] );
			$revenue[]  = round( floatval( $row['metricValues'][1]['value'] ), 2 );
			$users[]    = intval( $row['metricValues'][2]['value'] );
		}

		return compact( 'labels', 'sessions', 'revenue', 'users' );
	}

	/**
	 * Get extended overview stats with period comparison.
	 */
	private function get_overview( $property_id, $token, $start, $end, $prev_start, $prev_end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array(
				array( 'startDate' => $start, 'endDate' => $end, 'name' => 'current' ),
				array( 'startDate' => $prev_start, 'endDate' => $prev_end, 'name' => 'previous' ),
			),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'totalRevenue' ),
				array( 'name' => 'transactions' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'averageSessionDuration' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'newUsers' ),
			),
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['totals'] ) ) {
			return null;
		}

		$cur  = $data['totals'][0]['metricValues'];
		$prev = $data['totals'][1]['metricValues'];

		$cur_new      = floatval( $cur[7]['value'] );
		$cur_total    = floatval( $cur[1]['value'] );
		$return_ratio = $cur_total > 0 ? round( ( ( $cur_total - $cur_new ) / $cur_total ) * 100, 1 ) : 0;
		$new_ratio    = $cur_total > 0 ? round( ( $cur_new / $cur_total ) * 100, 1 ) : 0;

		// Format avg session duration as m:ss.
		$avg_dur_sec = floatval( $cur[5]['value'] );
		$dur_min     = floor( $avg_dur_sec / 60 );
		$dur_sec     = str_pad( (int) ( $avg_dur_sec % 60 ), 2, '0', STR_PAD_LEFT );

		$prev_dur_sec = floatval( $prev[5]['value'] );

		return array(
			'sessions'     => array(
				'value'  => intval( $cur[0]['value'] ),
				'change' => $this->percent_change( floatval( $prev[0]['value'] ), floatval( $cur[0]['value'] ) ),
			),
			'unique_users' => array(
				'value'  => intval( $cur[1]['value'] ),
				'change' => $this->percent_change( floatval( $prev[1]['value'] ), floatval( $cur[1]['value'] ) ),
			),
			'revenue'      => array(
				'value'  => round( floatval( $cur[2]['value'] ), 2 ),
				'change' => $this->percent_change( floatval( $prev[2]['value'] ), floatval( $cur[2]['value'] ) ),
			),
			'transactions' => array(
				'value'  => intval( $cur[3]['value'] ),
				'change' => $this->percent_change( floatval( $prev[3]['value'] ), floatval( $cur[3]['value'] ) ),
			),
			'pageviews'    => array(
				'value'  => intval( $cur[4]['value'] ),
				'change' => $this->percent_change( floatval( $prev[4]['value'] ), floatval( $cur[4]['value'] ) ),
			),
			'avg_duration' => array(
				'value'       => $dur_min . ':' . $dur_sec,
				'value_raw'   => $avg_dur_sec,
				'change'      => $this->percent_change( $prev_dur_sec, $avg_dur_sec ),
			),
			'bounce_rate'  => array(
				'value'  => round( floatval( $cur[6]['value'] ) * 100, 1 ),
				'change' => $this->percent_change( floatval( $prev[6]['value'] ), floatval( $cur[6]['value'] ) ),
			),
			'new_vs_return' => array(
				'new_pct'     => $new_ratio,
				'return_pct'  => $return_ratio,
				'new_count'   => intval( $cur_new ),
				'total_count' => intval( $cur_total ),
			),
		);
	}

	/**
	 * Get top 5 countries with comparison.
	 */
	private function get_countries( $property_id, $token, $start, $end, $prev_start, $prev_end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array(
				array( 'startDate' => $start, 'endDate' => $end, 'name' => 'current' ),
				array( 'startDate' => $prev_start, 'endDate' => $prev_end, 'name' => 'previous' ),
			),
			'dimensions' => array( array( 'name' => 'country' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ),
			),
			'limit'      => 5,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}

		$total_cur = 0;
		$rows_raw  = array();
		foreach ( $data['rows'] as $row ) {
			$cur_val    = intval( $row['metricValues'][0]['value'] );
			$prev_val   = intval( $row['metricValues'][3]['value'] ?? 0 );
			$total_cur += $cur_val;
			$rows_raw[] = array(
				'country' => $row['dimensionValues'][0]['value'],
				'current' => $cur_val,
				'prev'    => $prev_val,
			);
		}

		$result = array();
		foreach ( $rows_raw as $r ) {
			$result[] = array(
				'country'  => $r['country'],
				'sessions' => $r['current'],
				'share'    => $total_cur > 0 ? round( ( $r['current'] / $total_cur ) * 100, 1 ) : 0,
				'change'   => $this->percent_change( $r['prev'], $r['current'] ),
			);
		}
		return $result;
	}

	/**
	 * Get top 5 pages with comparison.
	 */
	private function get_top_pages( $property_id, $token, $start, $end, $prev_start, $prev_end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array(
				array( 'startDate' => $start, 'endDate' => $end, 'name' => 'current' ),
				array( 'startDate' => $prev_start, 'endDate' => $prev_end, 'name' => 'previous' ),
			),
			'dimensions' => array(
				array( 'name' => 'pageTitle' ),
				array( 'name' => 'pagePath' ),
			),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'bounceRate' ),
				array( 'name' => 'averageSessionDuration' ),
			),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ),
			),
			'limit'      => 5,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}

		$total_cur = 0;
		$rows_raw  = array();
		foreach ( $data['rows'] as $row ) {
			$cur_val    = intval( $row['metricValues'][0]['value'] );
			$prev_val   = intval( $row['metricValues'][3]['value'] ?? 0 );
			$total_cur += $cur_val;
			$rows_raw[] = array(
				'title'       => $row['dimensionValues'][0]['value'],
				'path'        => $row['dimensionValues'][1]['value'],
				'current'     => $cur_val,
				'prev'        => $prev_val,
				'bounce_rate' => round( floatval( $row['metricValues'][1]['value'] ) * 100, 1 ),
				'avg_time'    => floatval( $row['metricValues'][2]['value'] ),
			);
		}

		$result = array();
		foreach ( $rows_raw as $r ) {
			$avg_sec  = $r['avg_time'];
			$result[] = array(
				'title'      => $r['title'],
				'path'       => $r['path'],
				'views'      => $r['current'],
				'share'      => $total_cur > 0 ? round( ( $r['current'] / $total_cur ) * 100, 1 ) : 0,
				'change'     => $this->percent_change( $r['prev'], $r['current'] ),
				'bounce'     => $r['bounce_rate'],
				'avg_time'   => floor( $avg_sec / 60 ) . ':' . str_pad( (int) ( $avg_sec % 60 ), 2, '0', STR_PAD_LEFT ),
			);
		}
		return $result;
	}

	/**
	 * Get traffic by channel group.
	 */
	private function get_traffic_channels( $property_id, $token, $start, $end, $prev_start, $prev_end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array(
				array( 'startDate' => $start, 'endDate' => $end, 'name' => 'current' ),
				array( 'startDate' => $prev_start, 'endDate' => $prev_end, 'name' => 'previous' ),
			),
			'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'bounceRate' ),
			),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ),
			),
			'limit'      => 10,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}

		$total_cur = 0;
		$rows_raw  = array();
		foreach ( $data['rows'] as $row ) {
			$cur_val    = intval( $row['metricValues'][0]['value'] );
			$prev_val   = intval( $row['metricValues'][3]['value'] ?? 0 );
			$total_cur += $cur_val;
			$rows_raw[] = array(
				'channel' => $row['dimensionValues'][0]['value'],
				'current' => $cur_val,
				'users'   => intval( $row['metricValues'][1]['value'] ),
				'bounce'  => round( floatval( $row['metricValues'][2]['value'] ) * 100, 1 ),
				'prev'    => $prev_val,
			);
		}

		$result = array();
		foreach ( $rows_raw as $r ) {
			$result[] = array(
				'channel' => $r['channel'],
				'sessions' => $r['current'],
				'users'   => $r['users'],
				'bounce'  => $r['bounce'],
				'share'   => $total_cur > 0 ? round( ( $r['current'] / $total_cur ) * 100, 1 ) : 0,
				'change'  => $this->percent_change( $r['prev'], $r['current'] ),
			);
		}
		return $result;
	}

	/**
	 * Get top blog posts (filters by common blog URL patterns).
	 */
	private function get_top_posts( $property_id, $token, $start, $end ) {
		$url  = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
		$body = array(
			'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
			'dimensions' => array(
				array( 'name' => 'pageTitle' ),
				array( 'name' => 'pagePath' ),
			),
			'metrics'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'averageSessionDuration' ),
			),
			'dimensionFilter' => array(
				'orGroup' => array(
					'expressions' => array(
						array(
							'filter' => array(
								'fieldName'    => 'pagePath',
								'stringFilter' => array( 'matchType' => 'CONTAINS', 'value' => '/blog/' ),
							),
						),
						array(
							'filter' => array(
								'fieldName'    => 'pagePath',
								'stringFilter' => array( 'matchType' => 'CONTAINS', 'value' => '/post/' ),
							),
						),
						array(
							'filter' => array(
								'fieldName'    => 'pagePath',
								'stringFilter' => array( 'matchType' => 'CONTAINS', 'value' => '/article/' ),
							),
						),
						array(
							'filter' => array(
								'fieldName'    => 'pagePath',
								'stringFilter' => array( 'matchType' => 'CONTAINS', 'value' => '/news/' ),
							),
						),
					),
				),
			),
			'orderBys'   => array(
				array( 'metric' => array( 'metricName' => 'screenPageViews' ), 'desc' => true ),
			),
			'limit'      => 5,
		);

		$data = $this->api_request( $url, $body, $token );
		if ( is_wp_error( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}

		$result = array();
		foreach ( $data['rows'] as $row ) {
			$avg_sec  = floatval( $row['metricValues'][1]['value'] );
			$result[] = array(
				'title'    => $row['dimensionValues'][0]['value'],
				'path'     => $row['dimensionValues'][1]['value'],
				'views'    => intval( $row['metricValues'][0]['value'] ),
				'avg_time' => floor( $avg_sec / 60 ) . ':' . str_pad( (int) ( $avg_sec % 60 ), 2, '0', STR_PAD_LEFT ),
			);
		}
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Table Renderer                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Build structured data for AI consumption.
	 */
	private function build_structured_data( $data ) {
		$structured = array(
			'channels' => array(),
			'totals'   => array( 'visitors' => 0, 'revenue' => 0, 'transactions' => 0 ),
		);
		if ( empty( $data['rows'] ) ) {
			return $structured;
		}
		foreach ( $data['rows'] as $row ) {
			$v                                     = intval( $row['metricValues'][0]['value'] );
			$r                                     = floatval( $row['metricValues'][1]['value'] );
			$tx                                    = intval( $row['metricValues'][2]['value'] );
			$ch                                    = array(
				'source'          => $row['dimensionValues'][0]['value'],
				'medium'          => $row['dimensionValues'][1]['value'],
				'visitors'        => $v,
				'revenue'         => $r,
				'transactions'    => $tx,
				'conversion_rate' => $v > 0 ? round( ( $tx / $v ) * 100, 2 ) : 0,
			);
			$structured['channels'][]              = $ch;
			$structured['totals']['visitors']     += $v;
			$structured['totals']['revenue']      += $r;
			$structured['totals']['transactions'] += $tx;
		}
		return $structured;
	}

	/**
	 * Render the attribution data table HTML.
	 */
	private function render_attribution_table( $data ) {
		$basic       = ! empty( $data['basic_metrics'] );
		$source_data = array();

		if ( ! empty( $data['rows'] ) ) {
			foreach ( $data['rows'] as $row ) {
				$v             = intval( $row['metricValues'][0]['value'] );
				$rev           = floatval( $row['metricValues'][1]['value'] );
				$tx            = intval( $row['metricValues'][2]['value'] );
				$buyers        = $basic ? $tx : intval( $row['metricValues'][3]['value'] ?? $tx );
				$aov           = $basic ? ( $tx > 0 ? $rev / $tx : 0 ) : floatval( $row['metricValues'][4]['value'] ?? 0 );
				$source_data[] = array(
					'source'  => $row['dimensionValues'][0]['value'],
					'medium'  => $row['dimensionValues'][1]['value'],
					'sessions' => $v,
					'revenue' => $rev,
					'tx'      => $tx,
					'buyers'  => $buyers,
					'rpv'     => $v > 0 ? $rev / $v : 0,
					'conv'    => $buyers > 0 && $v > 0 ? ( $buyers / $v ) * 100 : 0,
					'aov'     => $aov,
				);
			}
		}

		$total_rev = array_sum( array_column( $source_data, 'revenue' ) );
		$has_rev   = $total_rev > 0;
		$avg_rpv   = count( $source_data ) ? array_sum( array_column( $source_data, 'rpv' ) ) / count( $source_data ) : 0;
		$avg_conv  = count( $source_data ) ? array_sum( array_column( $source_data, 'conv' ) ) / count( $source_data ) : 0;
		$sym       = $this->currency_symbol();

		ob_start();
		?>
		<div class="isp-table-wrap">
			<table class="isp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source / Medium', 'insightistic' ); ?></th>
						<th><?php esc_html_e( 'Sessions', 'insightistic' ); ?></th>
						<?php if ( $has_rev ) : ?>
						<th><?php esc_html_e( 'Revenue', 'insightistic' ); ?></th>
						<th><?php esc_html_e( 'Revenue %', 'insightistic' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Transactions', 'insightistic' ); ?></th>
						<?php if ( $has_rev ) : ?>
						<th><?php esc_html_e( 'Rev/Session', 'insightistic' ); ?></th>
						<th><?php esc_html_e( 'Conv. Rate', 'insightistic' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $source_data ) ) : ?>
					<tr><td colspan="<?php echo $has_rev ? 7 : 3; ?>" class="isp-no-data"><?php esc_html_e( 'No data found for this period.', 'insightistic' ); ?></td></tr>
					<?php
				else :
					$totals = array( 'sessions' => 0, 'revenue' => 0, 'tx' => 0, 'buyers' => 0 );
					foreach ( $source_data as $row ) :
						$rev_pct             = $has_rev && $total_rev > 0 ? ( $row['revenue'] / $total_rev ) * 100 : 0;
						$rpv_class           = $this->perf_class( $row['rpv'], $avg_rpv );
						$conv_class          = $this->perf_class( $row['conv'], $avg_conv );
						$totals['sessions'] += $row['sessions'];
						$totals['revenue']  += $row['revenue'];
						$totals['tx']       += $row['tx'];
						$totals['buyers']   += $row['buyers'];
						?>
						<tr>
							<td>
								<span class="isp-src-icon dashicons <?php echo esc_attr( $this->source_icon( $row['source'], $row['medium'] ) ); ?>"></span>
								<span class="isp-src-label"><?php echo esc_html( $row['source'] . ' / ' . $row['medium'] ); ?></span>
							</td>
							<td><?php echo esc_html( number_format( $row['sessions'] ) ); ?></td>
							<?php if ( $has_rev ) : ?>
							<td><?php echo esc_html( $sym . number_format( $row['revenue'], 2 ) ); ?></td>
							<td>
								<div class="isp-rev-bar">
									<div class="isp-rev-bar-fill" style="width:<?php echo esc_attr( min( $rev_pct, 100 ) ); ?>%"></div>
									<span><?php echo esc_html( number_format( $rev_pct, 1 ) ); ?>%</span>
								</div>
							</td>
							<?php endif; ?>
							<td><?php echo esc_html( number_format( $row['tx'] ) ); ?></td>
							<?php if ( $has_rev ) : ?>
							<td>
								<span class="isp-perf <?php echo esc_attr( $rpv_class ); ?>"></span>
								<?php echo esc_html( $sym . number_format( $row['rpv'], 2 ) ); ?>
							</td>
							<td>
								<span class="isp-perf <?php echo esc_attr( $conv_class ); ?>"></span>
								<?php echo esc_html( number_format( $row['conv'], 2 ) ); ?>%
							</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					<tr class="isp-totals-row">
						<td><strong><?php esc_html_e( 'Total', 'insightistic' ); ?></strong></td>
						<td><strong><?php echo esc_html( number_format( $totals['sessions'] ) ); ?></strong></td>
						<?php if ( $has_rev ) : ?>
						<td><strong><?php echo esc_html( $sym . number_format( $totals['revenue'], 2 ) ); ?></strong></td>
						<td><strong>100%</strong></td>
						<?php endif; ?>
						<td><strong><?php echo esc_html( number_format( $totals['tx'] ) ); ?></strong></td>
						<?php if ( $has_rev ) : ?>
						<td><strong><?php echo esc_html( $sym . number_format( $totals['sessions'] > 0 ? $totals['revenue'] / $totals['sessions'] : 0, 2 ) ); ?></strong></td>
						<td><strong><?php echo esc_html( number_format( $totals['sessions'] > 0 ? ( $totals['buyers'] / $totals['sessions'] ) * 100 : 0, 2 ) ); ?>%</strong></td>
						<?php endif; ?>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Make an authenticated POST request to the GA4 Data API.
	 *
	 * Retries transient transport/server failures with exponential backoff and
	 * converts Google quota errors into a single graceful, user-facing message
	 * (logged silently to the server error log, per the plugin error policy).
	 *
	 * @param string $url      Endpoint URL.
	 * @param array  $body     Request body.
	 * @param string $token    OAuth access token.
	 * @param int    $attempts Maximum attempts.
	 * @return array|WP_Error  Decoded body, or WP_Error on hard failure / quota.
	 */
	private function api_request( $url, $body, $token, $attempts = 3 ) {
		$delay = 1;

		for ( $i = 1; $i <= $attempts; $i++ ) {
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
				if ( $i < $attempts ) {
					sleep( $delay );
					$delay *= 2;
					continue;
				}
				return $response;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			// Retry transient rate-limit / server errors with backoff.
			if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $i < $attempts ) {
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			// Graceful quota handling.
			$status = isset( $decoded['error']['status'] ) ? $decoded['error']['status'] : '';
			if ( 429 === $code || 'RESOURCE_EXHAUSTED' === $status ) {
				$this->log_quota( $decoded );
				return new WP_Error(
					'insightistic_quota',
					__( 'Analytics data is temporarily unavailable  the Google Analytics API rate limit was reached. Please try again in a few minutes.', 'insightistic' )
				);
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		return new WP_Error(
			'insightistic_request_failed',
			__( 'The Google Analytics request failed after multiple attempts. Please try again shortly.', 'insightistic' )
		);
	}

	/**
	 * Silently log a quota event to the server error log.
	 *
	 * @param mixed $decoded Decoded API response.
	 */
	private function log_quota( $decoded ) {
		$detail = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'GA4 quota exceeded';
		error_log( '[Insightistic] GA4 API quota: ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Render a graceful "data temporarily unavailable" notice for a panel.
	 *
	 * @param string $message Reason to display.
	 * @return string HTML.
	 */
	private function render_unavailable( $message ) {
		return '<div class="isp-table-wrap"><p class="isp-no-data">'
			. esc_html( $message )
			. '</p></div>';
	}

	/**
	 * Calculate percent change between two values.
	 */
	private function percent_change( $old, $new ) {
		if ( 0 === $old || 0.0 === $old ) {
			return $new > 0 ? 100 : 0;
		}
		return round( ( ( $new - $old ) / $old ) * 100, 1 );
	}

	/**
	 * Return CSS class for performance indicator.
	 */
	private function perf_class( $value, $average ) {
		if ( 0 == $average ) {
			return 'isp-perf-neutral';
		}
		if ( $value >= $average * 1.2 ) {
			return 'isp-perf-high';
		}
		if ( $value <= $average * 0.8 ) {
			return 'isp-perf-low';
		}
		return 'isp-perf-medium';
	}

	/**
	 * Return a Dashicons class for a traffic source.
	 *
	 * Dashicons are used instead of emoji glyphs so the icon never depends on
	 * file-encoding survival (emoji bytes were silently lost in a prior pass).
	 *
	 * @param string $source Traffic source.
	 * @param string $medium Traffic medium.
	 * @return string Dashicons class slug.
	 */
	private function source_icon( $source, $medium ) {
		$s = strtolower( $source );
		$m = strtolower( $medium );

		if ( strpos( $m, 'cpc' ) !== false || strpos( $m, 'paid' ) !== false || strpos( $m, 'ppc' ) !== false ) {
			return 'dashicons-megaphone';
		}
		if ( strpos( $m, 'email' ) !== false || strpos( $m, 'newsletter' ) !== false ) {
			return 'dashicons-email';
		}
		if ( strpos( $m, 'social' ) !== false || in_array( $s, array( 'facebook', 'instagram', 'twitter', 'x', 'tiktok', 'linkedin', 'pinterest', 'youtube' ), true ) ) {
			return 'dashicons-share';
		}
		if ( 'organic' === $m || in_array( $s, array( 'google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'ecosia' ), true ) ) {
			return 'dashicons-search';
		}
		if ( 'referral' === $m ) {
			return 'dashicons-admin-links';
		}
		if ( '(direct)' === $s || 'direct' === $s ) {
			return 'dashicons-admin-home';
		}
		return 'dashicons-chart-bar';
	}

	/**
	 * Resolve a currency symbol for revenue display.
	 *
	 * Uses the live WooCommerce store currency when available; otherwise a
	 * filterable default so non-USD sites can localise it.
	 *
	 * @return string
	 */
	private function currency_symbol() {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			if ( $symbol ) {
				return $symbol;
			}
		}
		/**
		 * Filter the currency symbol used in the GA4 attribution table.
		 *
		 * @param string $symbol Default currency symbol.
		 */
		return (string) apply_filters( 'insightistic_currency_symbol', '$' );
	}
}




