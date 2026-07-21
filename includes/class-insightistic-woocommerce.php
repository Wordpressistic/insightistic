<?php
/**
 * WooCommerce Intelligence data layer.
 *
 * Aggregates revenue, orders, products, customers, geography and refund
 * data into a single structured payload that the dashboard, the email
 * digest, and the AI insights engine can all consume.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Woocommerce
 *
 * All public AJAX handlers respect the same nonce + capability + cache
 * pattern used by the GA4 / GSC / PageSpeed classes so the Commerce panel
 * inherits the 3.1.x diagnostics (cached_at, force refresh, error UI).
 */
class Insightistic_Woocommerce {

	/** Transient TTL: 15 minutes, identical to GA4 / GSC. */
	const CACHE_TTL = 900;

	/** Maximum days of history a single call can request. */
	const MAX_DAYS = 365;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_insightistic_get_woo_data', array( $this, 'ajax_get_data' ) );
		add_action( 'wp_ajax_insightistic_woo_ai_analyze', array( $this, 'ajax_ai_analyze' ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX handlers                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Return the full Commerce dashboard payload.
	 */
	public function ajax_get_data() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		if ( ! $this->is_active() ) {
			wp_send_json_error( __( 'WooCommerce Intelligence is not active. Enable the addon and install WooCommerce.', 'insightistic' ) );
		}

		$days  = min( max( intval( $_POST['days'] ?? 28 ), 1 ), self::MAX_DAYS );
		$force = ! empty( $_POST['force'] );

		$data = $this->get_dashboard_data( $days, $force );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( $data->get_error_message() );
		}
		wp_send_json_success( $data );
	}

	/**
	 * Run an AI analysis over the Commerce payload only.
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
			wp_send_json_error( __( 'No commerce data to analyse. Load Commerce data first.', 'insightistic' ) );
		}

		$days   = intval( $_POST['days'] ?? 28 );
		$result = ( new Insightistic_AI() )->analyze_commerce( $data, $days );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/* ------------------------------------------------------------------ */
	/* Public helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * True only when the addon toggle is on and WooCommerce is loaded.
	 * WooCommerce Intelligence is free — no account or license required.
	 */
	public function is_active() {
		$addons = get_option( 'insightistic_addons', array() );
		if ( empty( $addons['woocommerce_pro'] ) ) {
			return false;
		}
		return function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order_statuses' );
	}

	/**
	 * Build the cached dashboard payload (15-minute TTL).
	 *
	 * @param int  $days  Window size in days.
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	public function get_dashboard_data( $days = 28, $force = false ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'insightistic_woo_missing', __( 'WooCommerce is not installed.', 'insightistic' ) );
		}

		$days      = min( max( intval( $days ), 1 ), self::MAX_DAYS );
		$cache_key = 'insightistic_woo_' . $days;

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$now        = current_time( 'timestamp' );
		$start      = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days", $now ) );
		$end        = gmdate( 'Y-m-d 23:59:59', $now );
		$prev_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $days * 2 ) . ' days', $now ) );
		$prev_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-' . ( $days + 1 ) . ' days', $now ) );

		$current  = $this->collect_window( $start, $end );
		$previous = $this->collect_window( $prev_start, $prev_end );

		$result = array(
			'period'          => array(
				'days'    => $days,
				'start'   => $start,
				'end'     => $end,
				'currency' => get_woocommerce_currency(),
				'symbol'  => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			),
			'overview'        => $this->build_overview( $current, $previous ),
			'timeline'        => $this->build_timeline( $current['orders'], $days ),
			'order_status'    => $this->build_order_status_breakdown( $current['orders'] ),
			'top_products'    => $this->build_top_products( $current['line_items'] ),
			'top_categories'  => $this->build_top_categories( $current['line_items'] ),
			'top_customers'   => $this->build_top_customers( $current['orders'] ),
			'recent_orders'   => $this->build_recent_orders( $current['orders'] ),
			'geography'       => $this->build_geography( $current['orders'] ),
			'payment_methods' => $this->build_payment_methods( $current['orders'] ),
			'coupons'         => $this->build_coupons( $current['orders'] ),
			'refunds'         => $this->build_refunds( $current['orders'], $current['refunds'] ),
			'low_stock'       => $this->build_low_stock(),
			'structured_data' => $this->build_structured_data( $current, $previous ),
			'cached_at'       => time(),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Data collection                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Pull all order objects + line items + refunds for a date window.
	 *
	 * @param string $start MySQL datetime.
	 * @param string $end   MySQL datetime.
	 * @return array
	 */
	private function collect_window( $start, $end ) {
		$paid_statuses = array_filter(
			array_keys( wc_get_order_statuses() ),
			static function ( $status ) {
				// Default countable statuses for revenue reporting.
				return in_array(
					$status,
					array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					true
				);
			}
		);

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => $paid_statuses,
				'date_created' => $start . '...' . $end,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'objects',
				'type'         => 'shop_order',
			)
		);

		$refunds_query = wc_get_orders(
			array(
				'limit'        => -1,
				'type'         => 'shop_order_refund',
				'date_created' => $start . '...' . $end,
				'return'       => 'objects',
			)
		);

		$gross      = 0.0;
		$discount   = 0.0;
		$tax        = 0.0;
		$shipping   = 0.0;
		$line_items = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$gross    += (float) $order->get_total();
			$discount += (float) $order->get_total_discount();
			$tax      += (float) $order->get_total_tax();
			$shipping += (float) $order->get_shipping_total();

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$pid = (int) $item->get_product_id();
				if ( ! isset( $line_items[ $pid ] ) ) {
					$product            = $item->get_product();
					$line_items[ $pid ] = array(
						'id'         => $pid,
						'name'       => $item->get_name(),
						'sku'        => $product ? $product->get_sku() : '',
						'category'   => $product ? $this->primary_category( $product ) : '',
						'units'      => 0,
						'revenue'    => 0.0,
						'orders'     => 0,
						'edit_url'   => $pid ? get_edit_post_link( $pid, '' ) : '',
					);
				}
				$line_items[ $pid ]['units']   += (float) $item->get_quantity();
				$line_items[ $pid ]['revenue'] += (float) $item->get_total();
				$line_items[ $pid ]['orders']  += 1;
			}
		}

		$refund_total = 0.0;
		foreach ( $refunds_query as $refund ) {
			$refund_total += (float) $refund->get_total();
		}

		return array(
			'orders'        => $orders,
			'refunds'       => $refunds_query,
			'gross'         => $gross,
			'discount'      => $discount,
			'tax'           => $tax,
			'shipping'      => $shipping,
			'refund_total'  => abs( $refund_total ),
			'order_count'   => count( $orders ),
			'line_items'    => $line_items,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Builders                                                             */
	/* ------------------------------------------------------------------ */

	private function build_overview( $current, $previous ) {
		$customer_stats = $this->customer_stats( $current['orders'] );
		$prev_customers = $this->customer_stats( $previous['orders'] );

		$current_aov  = $current['order_count'] ? $current['gross'] / $current['order_count'] : 0;
		$previous_aov = $previous['order_count'] ? $previous['gross'] / $previous['order_count'] : 0;

		$current_refund_rate  = $current['gross'] ? ( $current['refund_total'] / $current['gross'] ) * 100 : 0;
		$previous_refund_rate = $previous['gross'] ? ( $previous['refund_total'] / $previous['gross'] ) * 100 : 0;

		$current_net  = $current['gross'] - $current['refund_total'];
		$previous_net = $previous['gross'] - $previous['refund_total'];

		return array(
			'revenue'         => $this->kpi( $current['gross'],          $previous['gross'],          true ),
			'net_revenue'     => $this->kpi( $current_net,                $previous_net,               true ),
			'orders'          => $this->kpi( $current['order_count'],    $previous['order_count'],    false ),
			'aov'             => $this->kpi( $current_aov,                $previous_aov,               true ),
			'refund_rate'     => $this->kpi( round( $current_refund_rate, 2 ), round( $previous_refund_rate, 2 ), false, '%' ),
			'new_customers'   => $this->kpi( $customer_stats['new'],     $prev_customers['new'],     false ),
			'repeat_rate'     => $this->kpi( $customer_stats['repeat_rate'], $prev_customers['repeat_rate'], false, '%' ),
			'units_sold'      => $this->kpi( $customer_stats['units'],    $prev_customers['units'],   false ),
		);
	}

	/**
	 * Build a single KPI struct with current / previous / change percentage.
	 */
	private function kpi( $current, $previous, $is_money = false, $suffix = '' ) {
		$change = 0;
		if ( $previous > 0 ) {
			$change = round( ( ( $current - $previous ) / $previous ) * 100, 1 );
		} elseif ( $current > 0 ) {
			$change = 100.0;
		}
		return array(
			'value'    => $is_money ? round( (float) $current, 2 ) : (float) $current,
			'previous' => $is_money ? round( (float) $previous, 2 ) : (float) $previous,
			'change'   => $change,
			'is_money' => (bool) $is_money,
			'suffix'   => $suffix,
		);
	}

	private function customer_stats( $orders ) {
		$units     = 0;
		$customers = array();
		$repeat    = 0;
		foreach ( $orders as $order ) {
			$cid   = (int) $order->get_customer_id();
			$email = strtolower( (string) $order->get_billing_email() );
			$key   = $cid ? 'u_' . $cid : 'e_' . $email;
			if ( ! isset( $customers[ $key ] ) ) {
				$customers[ $key ] = 0;
			}
			$customers[ $key ] += 1;
			foreach ( $order->get_items() as $item ) {
				$units += (int) $item->get_quantity();
			}
		}
		$total_customers = count( $customers );
		foreach ( $customers as $count ) {
			if ( $count > 1 ) {
				++$repeat;
			}
		}
		return array(
			'units'       => $units,
			'new'         => $total_customers - $repeat,
			'repeat'      => $repeat,
			'repeat_rate' => $total_customers ? round( ( $repeat / $total_customers ) * 100, 1 ) : 0,
		);
	}

	private function build_timeline( $orders, $days ) {
		$labels      = array();
		$revenue     = array();
		$order_count = array();
		$buckets     = array();
		// Pre-fill all days so the chart has zero entries for empty days.
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$key             = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$buckets[ $key ] = array( 'rev' => 0.0, 'orders' => 0 );
			$labels[]        = $key;
		}
		foreach ( $orders as $order ) {
			$date_created = $order->get_date_created();
			if ( ! $date_created ) {
				continue;
			}
			$key = $date_created->date( 'Y-m-d' );
			if ( ! isset( $buckets[ $key ] ) ) {
				continue;
			}
			$buckets[ $key ]['rev']    += (float) $order->get_total();
			$buckets[ $key ]['orders'] += 1;
		}
		foreach ( $labels as $key ) {
			$revenue[]     = round( $buckets[ $key ]['rev'], 2 );
			$order_count[] = (int) $buckets[ $key ]['orders'];
		}
		return array(
			'labels'  => $labels,
			'revenue' => $revenue,
			'orders'  => $order_count,
		);
	}

	private function build_order_status_breakdown( $orders ) {
		$statuses = array();
		foreach ( $orders as $order ) {
			$status = $order->get_status();
			if ( ! isset( $statuses[ $status ] ) ) {
				$statuses[ $status ] = 0;
			}
			$statuses[ $status ] += 1;
		}
		arsort( $statuses );
		$out = array();
		foreach ( $statuses as $status => $count ) {
			$out[] = array(
				'status' => $status,
				'label'  => wc_get_order_status_name( $status ),
				'count'  => $count,
			);
		}
		return $out;
	}

	private function build_top_products( $line_items, $limit = 10 ) {
		usort(
			$line_items,
			static function ( $a, $b ) {
				return ( $b['revenue'] <=> $a['revenue'] );
			}
		);
		$out = array();
		foreach ( array_slice( $line_items, 0, $limit ) as $item ) {
			$out[] = array(
				'id'       => $item['id'],
				'name'     => $item['name'],
				'sku'      => $item['sku'],
				'category' => $item['category'],
				'units'    => (int) $item['units'],
				'revenue'  => round( $item['revenue'], 2 ),
				'orders'   => (int) $item['orders'],
				'edit_url' => $item['edit_url'],
			);
		}
		return $out;
	}

	private function build_top_categories( $line_items, $limit = 8 ) {
		$cats = array();
		foreach ( $line_items as $item ) {
			$cat = $item['category'] ?: __( 'Uncategorised', 'insightistic' );
			if ( ! isset( $cats[ $cat ] ) ) {
				$cats[ $cat ] = array( 'revenue' => 0.0, 'units' => 0 );
			}
			$cats[ $cat ]['revenue'] += $item['revenue'];
			$cats[ $cat ]['units']   += $item['units'];
		}
		uasort(
			$cats,
			static function ( $a, $b ) {
				return ( $b['revenue'] <=> $a['revenue'] );
			}
		);
		$out = array();
		$i   = 0;
		foreach ( $cats as $name => $row ) {
			if ( $i++ >= $limit ) {
				break;
			}
			$out[] = array(
				'name'    => $name,
				'revenue' => round( $row['revenue'], 2 ),
				'units'   => (int) $row['units'],
			);
		}
		return $out;
	}

	private function build_top_customers( $orders, $limit = 8 ) {
		$people = array();
		foreach ( $orders as $order ) {
			$cid   = (int) $order->get_customer_id();
			$email = strtolower( (string) $order->get_billing_email() );
			$key   = $cid ? 'u_' . $cid : 'e_' . $email;
			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = array(
					'id'       => $cid,
					'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $email,
					'email'    => $email,
					'orders'   => 0,
					'revenue'  => 0.0,
				);
			}
			$people[ $key ]['orders']  += 1;
			$people[ $key ]['revenue'] += (float) $order->get_total();
		}
		uasort(
			$people,
			static function ( $a, $b ) {
				return ( $b['revenue'] <=> $a['revenue'] );
			}
		);
		$out = array();
		$i   = 0;
		foreach ( $people as $row ) {
			if ( $i++ >= $limit ) {
				break;
			}
			$row['revenue'] = round( $row['revenue'], 2 );
			$out[]          = $row;
		}
		return $out;
	}

	private function build_recent_orders( $orders, $limit = 8 ) {
		$out = array();
		$i   = 0;
		foreach ( $orders as $order ) {
			if ( $i++ >= $limit ) {
				break;
			}
			$date  = $order->get_date_created();
			$out[] = array(
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'status'       => $order->get_status(),
				'status_label' => wc_get_order_status_name( $order->get_status() ),
				'total'        => round( (float) $order->get_total(), 2 ),
				'customer'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_email(),
				'created'      => $date ? $date->date( 'Y-m-d H:i' ) : '',
				'item_count'   => count( $order->get_items() ),
				'edit_url'     => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			);
		}
		return $out;
	}

	private function build_geography( $orders, $limit = 8 ) {
		$by_country = array();
		$total      = 0.0;
		foreach ( $orders as $order ) {
			$country = $order->get_billing_country();
			if ( ! $country ) {
				continue;
			}
			if ( ! isset( $by_country[ $country ] ) ) {
				$by_country[ $country ] = array( 'orders' => 0, 'revenue' => 0.0 );
			}
			$by_country[ $country ]['orders']  += 1;
			$by_country[ $country ]['revenue'] += (float) $order->get_total();
			$total                             += (float) $order->get_total();
		}
		uasort(
			$by_country,
			static function ( $a, $b ) {
				return ( $b['revenue'] <=> $a['revenue'] );
			}
		);
		$out = array();
		$i   = 0;
		foreach ( $by_country as $code => $row ) {
			if ( $i++ >= $limit ) {
				break;
			}
			$countries = function_exists( 'WC' ) ? WC()->countries->get_countries() : array();
			$out[]     = array(
				'code'    => $code,
				'name'    => $countries[ $code ] ?? $code,
				'orders'  => (int) $row['orders'],
				'revenue' => round( $row['revenue'], 2 ),
				'share'   => $total ? round( ( $row['revenue'] / $total ) * 100, 1 ) : 0,
			);
		}
		return $out;
	}

	private function build_payment_methods( $orders ) {
		$methods = array();
		foreach ( $orders as $order ) {
			$key  = $order->get_payment_method() ?: 'unknown';
			$name = $order->get_payment_method_title() ?: $key;
			if ( ! isset( $methods[ $key ] ) ) {
				$methods[ $key ] = array( 'label' => $name, 'orders' => 0, 'revenue' => 0.0 );
			}
			$methods[ $key ]['orders']  += 1;
			$methods[ $key ]['revenue'] += (float) $order->get_total();
		}
		uasort(
			$methods,
			static function ( $a, $b ) {
				return ( $b['revenue'] <=> $a['revenue'] );
			}
		);
		$out = array();
		foreach ( $methods as $row ) {
			$out[] = array(
				'label'   => $row['label'],
				'orders'  => (int) $row['orders'],
				'revenue' => round( $row['revenue'], 2 ),
			);
		}
		return $out;
	}

	private function build_coupons( $orders ) {
		$coupons = array();
		foreach ( $orders as $order ) {
			$used = $order->get_coupon_codes();
			if ( empty( $used ) ) {
				continue;
			}
			foreach ( $used as $code ) {
				if ( ! isset( $coupons[ $code ] ) ) {
					$coupons[ $code ] = array( 'uses' => 0, 'discount' => 0.0 );
				}
				$coupons[ $code ]['uses']     += 1;
				$coupons[ $code ]['discount'] += (float) $order->get_total_discount();
			}
		}
		uasort(
			$coupons,
			static function ( $a, $b ) {
				return ( $b['uses'] <=> $a['uses'] );
			}
		);
		$out = array();
		foreach ( $coupons as $code => $row ) {
			$out[] = array(
				'code'     => $code,
				'uses'     => (int) $row['uses'],
				'discount' => round( $row['discount'], 2 ),
			);
		}
		return array_slice( $out, 0, 8 );
	}

	private function build_refunds( $orders, $refunds ) {
		$count   = count( $refunds );
		$amount  = 0.0;
		$reasons = array();
		foreach ( $refunds as $refund ) {
			$amount += abs( (float) $refund->get_total() );
			$reason  = $refund->get_reason();
			if ( $reason ) {
				$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
			}
		}
		arsort( $reasons );
		$reason_rows = array();
		foreach ( $reasons as $reason => $n ) {
			$reason_rows[] = array( 'reason' => $reason, 'count' => $n );
		}
		return array(
			'count'   => $count,
			'amount'  => round( $amount, 2 ),
			'reasons' => array_slice( $reason_rows, 0, 5 ),
		);
	}

	private function build_low_stock( $limit = 8 ) {
		if ( ! function_exists( 'wc_get_low_stock_amount' ) ) {
			return array();
		}
		$query = new WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_manage_stock',
						'value'   => 'yes',
						'compare' => '=',
					),
					array(
						'key'     => '_stock',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'fields'         => 'ids',
			)
		);
		$rows  = array();
		foreach ( $query->posts as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}
			$threshold = wc_get_low_stock_amount( $product );
			$stock     = (int) $product->get_stock_quantity();
			if ( $stock > 0 && $stock <= $threshold ) {
				$rows[] = array(
					'id'        => $pid,
					'name'      => $product->get_name(),
					'sku'       => $product->get_sku(),
					'stock'     => $stock,
					'threshold' => $threshold,
					'edit_url'  => get_edit_post_link( $pid, '' ),
				);
			}
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Best-effort primary category name for a product.
	 */
	private function primary_category( $product ) {
		$id  = $product->get_id();
		$ids = wc_get_product_term_ids( $id, 'product_cat' );
		if ( empty( $ids ) ) {
			return '';
		}
		$term = get_term( (int) $ids[0], 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * Compact payload sent to the AI provider so the prompt stays small.
	 */
	private function build_structured_data( $current, $previous ) {
		return array(
			'currency'      => get_woocommerce_currency(),
			'gross_revenue' => round( $current['gross'], 2 ),
			'net_revenue'   => round( $current['gross'] - $current['refund_total'], 2 ),
			'orders'        => $current['order_count'],
			'aov'           => $current['order_count'] ? round( $current['gross'] / $current['order_count'], 2 ) : 0,
			'refund_amount' => round( $current['refund_total'], 2 ),
			'refund_rate'   => $current['gross'] ? round( ( $current['refund_total'] / $current['gross'] ) * 100, 2 ) : 0,
			'previous'      => array(
				'gross_revenue' => round( $previous['gross'], 2 ),
				'orders'        => $previous['order_count'],
				'refund_amount' => round( $previous['refund_total'], 2 ),
			),
			'top_products'  => array_slice( $this->build_top_products( $current['line_items'], 5 ), 0, 5 ),
			'top_categories' => array_slice( $this->build_top_categories( $current['line_items'], 5 ), 0, 5 ),
		);
	}
}
