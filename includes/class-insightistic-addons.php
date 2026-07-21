<?php
/**
 * Addon intelligence reports for Insightistic.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds lightweight reports for free and paid addon modules.
 */
class Insightistic_Addons {

	/**
	 * Get an addon report payload.
	 *
	 * @param string $slug Addon slug.
	 * @return array|WP_Error
	 */
	public static function get_report( $slug ) {
		$slug = sanitize_key( $slug );

		switch ( $slug ) {
			case 'seo_opportunities':
				return self::seo_opportunities();
			case 'anomaly_alerts':
				return self::anomaly_alerts();
			case 'content_lab':
				return self::content_lab();
			case 'woocommerce_pro':
				return self::woocommerce_pro();
			default:
				return new WP_Error( 'insightistic_invalid_addon_report', __( 'Invalid addon report.', 'insightistic' ) );
		}
	}

	/**
	 * SEO opportunity finder from cached GSC data.
	 *
	 * @return array
	 */
	private static function seo_opportunities() {
		$gsc   = self::latest_gsc_cache();
		$items = array();

		foreach ( $gsc['top_queries'] ?? array() as $row ) {
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$ctr         = (float) ( $row['ctr'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );

			if ( $impressions >= 100 && $ctr < 3 && $position <= 20 ) {
				$items[] = array(
					'title'    => $row['query'] ?? '',
					'metric'   => sprintf( '%s impressions / %s%% CTR / #%s', number_format_i18n( $impressions ), $ctr, $position ),
					'action'   => __( 'Rewrite title/meta angle and add this query to the matching landing page.', 'insightistic' ),
					'priority' => $position <= 10 ? __( 'High', 'insightistic' ) : __( 'Medium', 'insightistic' ),
				);
			}
		}

		if ( empty( $items ) ) {
			$items[] = array(
				'title'    => __( 'Load Search Console data', 'insightistic' ),
				'metric'   => __( 'No cached GSC opportunity data yet', 'insightistic' ),
				'action'   => __( 'Open the Search Console tab, load data, then return here for opportunity scoring.', 'insightistic' ),
				'priority' => __( 'Setup', 'insightistic' ),
			);
		}

		return array(
			'title' => __( 'SEO Opportunity Finder', 'insightistic' ),
			'intro' => __( 'Find queries with enough impressions but weak click-through performance.', 'insightistic' ),
			'items' => array_slice( $items, 0, 10 ),
		);
	}

	/**
	 * Anomaly report from GA4 period-over-period changes.
	 *
	 * @return array
	 */
	private static function anomaly_alerts() {
		$ga       = self::latest_ga_cache();
		$overview = $ga['overview'] ?? array();
		$items    = array();

		$watch = array(
			'sessions'     => __( 'Sessions', 'insightistic' ),
			'unique_users' => __( 'Users', 'insightistic' ),
			'pageviews'    => __( 'Pageviews', 'insightistic' ),
			'revenue'      => __( 'Revenue', 'insightistic' ),
			'bounce_rate'  => __( 'Bounce Rate', 'insightistic' ),
		);

		foreach ( $watch as $key => $label ) {
			if ( ! isset( $overview[ $key ]['change'] ) ) {
				continue;
			}
			$change  = (float) $overview[ $key ]['change'];
			$is_bad  = 'bounce_rate' === $key ? $change >= 15 : $change <= -15;
			$is_good = 'bounce_rate' === $key ? $change <= -15 : $change >= 20;

			if ( $is_bad || $is_good ) {
				$items[] = array(
					'title'    => $label,
					'metric'   => ( $change > 0 ? '+' : '' ) . $change . '% ' . __( 'vs previous period', 'insightistic' ),
					'action'   => $is_bad ? __( 'Investigate this metric today and compare top channels/pages.', 'insightistic' ) : __( 'Positive movement detected. Capture what changed and repeat it.', 'insightistic' ),
					'priority' => $is_bad ? __( 'High', 'insightistic' ) : __( 'Win', 'insightistic' ),
				);
			}
		}

		if ( empty( $items ) ) {
			$items[] = array(
				'title'    => __( 'No major anomaly detected', 'insightistic' ),
				'metric'   => __( 'Current cached period looks stable', 'insightistic' ),
				'action'   => __( 'Keep monitoring; Insightistic will flag large movements when dashboard data is refreshed.', 'insightistic' ),
				'priority' => __( 'Stable', 'insightistic' ),
			);
		}

		return array(
			'title' => __( 'Anomaly Alerts', 'insightistic' ),
			'intro' => __( 'Catch sharp traffic, revenue, and engagement movements from your latest cached dashboard data.', 'insightistic' ),
			'items' => $items,
		);
	}

	/**
	 * Content performance lab from GA4 pages and posts.
	 *
	 * @return array
	 */
	private static function content_lab() {
		$ga    = self::latest_ga_cache();
		$items = array();

		foreach ( $ga['top_posts'] ?? array() as $row ) {
			$items[] = array(
				'title'    => $row['title'] ?? $row['path'] ?? '',
				'metric'   => sprintf( '%s views / %s avg time', number_format_i18n( (int) ( $row['views'] ?? 0 ) ), $row['avg_time'] ?? '0:00' ),
				'action'   => __( 'Add internal links, update the intro, and place one stronger conversion action.', 'insightistic' ),
				'priority' => __( 'Content Win', 'insightistic' ),
			);
		}

		foreach ( $ga['pages'] ?? array() as $row ) {
			if ( isset( $row['bounce'] ) && (float) $row['bounce'] >= 65 ) {
				$items[] = array(
					'title'    => $row['title'] ?? $row['path'] ?? '',
					'metric'   => sprintf( '%s%% bounce / %s views', $row['bounce'], number_format_i18n( (int) ( $row['views'] ?? 0 ) ) ),
					'action'   => __( 'Improve above-the-fold promise and add a clearer next step.', 'insightistic' ),
					'priority' => __( 'Fix', 'insightistic' ),
				);
			}
		}

		if ( empty( $items ) ) {
			$items[] = array(
				'title'    => __( 'Load GA4 data', 'insightistic' ),
				'metric'   => __( 'No cached content data yet', 'insightistic' ),
				'action'   => __( 'Open the Overview tab, refresh data, then return here for content recommendations.', 'insightistic' ),
				'priority' => __( 'Setup', 'insightistic' ),
			);
		}

		return array(
			'title' => __( 'Content Performance Lab', 'insightistic' ),
			'intro' => __( 'Turn top pages and posts into conversion and internal-linking opportunities.', 'insightistic' ),
			'items' => array_slice( $items, 0, 10 ),
		);
	}

	/**
	 * Free WooCommerce intelligence module.
	 *
	 * @return array
	 */
	private static function woocommerce_pro() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'title' => __( 'WooCommerce Intelligence', 'insightistic' ),
				'intro' => __( 'Commerce intelligence activates when WooCommerce is installed.', 'insightistic' ),
				'items' => array(
					array(
						'title'    => __( 'WooCommerce not detected', 'insightistic' ),
						'metric'   => __( 'Addon ready, store module inactive', 'insightistic' ),
						'action'   => __( 'Install WooCommerce to unlock revenue, product, and checkout intelligence.', 'insightistic' ),
						'priority' => __( 'Setup', 'insightistic' ),
					),
				),
			);
		}

		$orders = wc_get_orders(
			array(
				'limit'   => 50,
				'status'  => array( 'wc-processing', 'wc-completed' ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			)
		);

		$revenue        = 0;
		$product_totals = array();
		foreach ( $orders as $order ) {
			$revenue += (float) $order->get_total();
			foreach ( $order->get_items() as $item ) {
				$name = $item->get_name();
				if ( ! isset( $product_totals[ $name ] ) ) {
					$product_totals[ $name ] = 0;
				}
				$product_totals[ $name ] += (float) $item->get_total();
			}
		}
		arsort( $product_totals );

		$items = array(
			array(
				'title'    => __( 'Recent Store Revenue', 'insightistic' ),
				'metric'   => '$' . number_format_i18n( $revenue, 2 ) . ' / ' . count( $orders ) . ' ' . __( 'orders', 'insightistic' ),
				'action'   => __( 'Compare store revenue with GA4 channels to find the traffic source producing real buyers.', 'insightistic' ),
				'priority' => __( 'Revenue', 'insightistic' ),
			),
		);

		foreach ( array_slice( $product_totals, 0, 5, true ) as $product => $total ) {
			$items[] = array(
				'title'    => $product,
				'metric'   => '$' . number_format_i18n( $total, 2 ),
				'action'   => __( 'Promote this product through your strongest channel and add it to high-traffic content.', 'insightistic' ),
				'priority' => __( 'Product', 'insightistic' ),
			);
		}

		return array(
			'title' => __( 'WooCommerce Intelligence', 'insightistic' ),
			'intro' => __( 'Free commerce intelligence for revenue, orders, and product performance.', 'insightistic' ),
			'items' => $items,
		);
	}

	/**
	 * Render a report into addon card HTML.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function render_report( $report ) {
		if ( is_wp_error( $report ) ) {
			return '<div class="isp-notice isp-notice-error">' . esc_html( $report->get_error_message() ) . '</div>';
		}

		ob_start();
		?>
		<div class="isp-addon-report">
			<h4><?php echo esc_html( $report['title'] ?? '' ); ?></h4>
			<p><?php echo esc_html( $report['intro'] ?? '' ); ?></p>
			<div class="isp-addon-report-list">
				<?php foreach ( $report['items'] ?? array() as $item ) : ?>
					<div class="isp-addon-report-item">
						<span class="isp-addon-report-priority"><?php echo esc_html( $item['priority'] ?? '' ); ?></span>
						<strong><?php echo esc_html( $item['title'] ?? '' ); ?></strong>
						<em><?php echo esc_html( $item['metric'] ?? '' ); ?></em>
						<span><?php echo esc_html( $item['action'] ?? '' ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get newest GA4 cache for current property.
	 *
	 * @return array
	 */
	private static function latest_ga_cache() {
		$property_id = get_option( 'insightistic_property_id', '' );
		foreach ( array( 28, 30, 7, 90, 180 ) as $days ) {
			$data = get_transient( 'insightistic_data_' . $days . '_' . md5( (string) $property_id ) );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * Get newest GSC cache for current property.
	 *
	 * @return array
	 */
	private static function latest_gsc_cache() {
		$site_url = get_option( 'insightistic_gsc_property_url', '' );
		foreach ( array( 28, 30, 7, 90, 180 ) as $days ) {
			$data = get_transient( 'insightistic_gsc_' . $days . '_' . md5( (string) $site_url ) );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}
}
