<?php
/**
 * Pushes WooCommerce + site-health data to the Insightistic SaaS so a site
 * running only this plugin never needs the separate insightistic-connector
 * plugin. Ported from insightistic-connector's Insightistic_Sync (v0.1.0),
 * adapted to reuse this plugin's existing HMAC-signed transport
 * (Insightistic_Saas_Client::request()) and the connector credentials
 * already stored by Insightistic_License_Manager::activate() — no separate
 * "paste a connector token" step needed.
 *
 * Linear chain (each step schedules the next, so a store with thousands of
 * orders never runs one huge request and never needs cross-job coordination):
 *   site-health -> orders(page 1..N) -> products(page 1..N) -> customers(page 1..N) -> sync-complete
 *
 * Chunk sizes match the SaaS contract returned by /api/connector/v1/handshake
 * (spec: 50 orders, 100 products, 100 customers per request).
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Sync
 */
class Insightistic_Sync {

	const ORDERS_PER    = 50;
	const PRODUCTS_PER  = 100;
	const CUSTOMERS_PER = 100;

	/** WP-Cron hook that kicks off a full sync (mirrors the license-validate cron). */
	const RUN_HOOK = 'insightistic_run_sync';

	/**
	 * Register hooks. The chain hooks (orders/products/customers) are plain
	 * WP actions so Action Scheduler (bundled with WooCommerce) or the
	 * inline fallback can both invoke them the same way.
	 */
	public function init() {
		add_action( self::RUN_HOOK, array( $this, 'start_full_sync' ) );
		add_action( 'insightistic_sync_orders', array( $this, 'sync_orders' ), 10, 1 );
		add_action( 'insightistic_sync_products', array( $this, 'sync_products' ), 10, 1 );
		add_action( 'insightistic_sync_customers', array( $this, 'sync_customers' ), 10, 1 );
		add_action( 'insightistic_sync_expanded', array( $this, 'sync_expanded' ) );
	}

	/**
	 * Sync toggles (all default on). Kept intentionally simple — a single
	 * small option, unlike the connector's full settings screen, since this
	 * plugin already has a License page to host a compact version of this.
	 *
	 * @return array
	 */
	public static function settings() {
		return wp_parse_args(
			get_option( 'insightistic_sync_settings', array() ),
			array(
				'sync_orders'      => 1,
				'sync_products'    => 1,
				'sync_customers'   => 1,
				'sync_site_health' => 1,
			)
		);
	}

	/**
	 * Timestamp (site timezone, MySQL format) of the last fully-completed sync,
	 * or '' if none yet.
	 *
	 * @return string
	 */
	public static function last_sync() {
		return (string) get_option( 'insightistic_last_sync', '' );
	}

	/**
	 * Small rolling log (last 20 entries) surfaced on the License page for
	 * troubleshooting, same purpose as the connector's Logs tab.
	 *
	 * @param string $message Log line.
	 * @param string $level   info|error.
	 */
	private function log( $message, $level = 'info' ) {
		$logs = get_option( 'insightistic_sync_log', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		array_unshift(
			$logs,
			array(
				'time'    => current_time( 'mysql' ),
				'level'   => $level,
				'message' => $message,
			)
		);
		update_option( 'insightistic_sync_log', array_slice( $logs, 0, 20 ), false );
	}

	/**
	 * Recent sync log entries, newest first.
	 *
	 * @return array
	 */
	public static function logs() {
		$logs = get_option( 'insightistic_sync_log', array() );
		return is_array( $logs ) ? $logs : array();
	}

	private function woo_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Queue the next chain step. Prefers Action Scheduler (bundled with
	 * WooCommerce, so available whenever there's anything to sync) for
	 * reliable background execution; falls back to running the step inline
	 * on stores without it.
	 *
	 * @param string $hook Action hook name.
	 * @param array  $args Action args.
	 */
	private function enqueue( $hook, $args = array() ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, 'insightistic' );
		} else {
			do_action( $hook, ...array_values( $args ) );
		}
	}

	/**
	 * Entry point — kicks the chain. Safe to call from the daily cron or the
	 * License page's "Sync now" button.
	 */
	public function start_full_sync() {
		if ( ! Insightistic_License_Manager::is_connected() ) {
			$this->log( 'Sync skipped: site not connected.', 'error' );
			return;
		}

		$settings = self::settings();

		if ( ! empty( $settings['sync_site_health'] ) ) {
			Insightistic_Saas_Client::request( 'POST', '/api/connector/v1/site-health', $this->site_health_payload(), true );
		}

		if ( ! empty( $settings['sync_orders'] ) && $this->woo_active() ) {
			$this->enqueue( 'insightistic_sync_orders', array( 'page' => 1 ) );
		} elseif ( ! empty( $settings['sync_products'] ) && $this->woo_active() ) {
			$this->enqueue( 'insightistic_sync_products', array( 'page' => 1 ) );
		} elseif ( ! empty( $settings['sync_customers'] ) ) {
			$this->enqueue( 'insightistic_sync_customers', array( 'page' => 1 ) );
		} else {
			$this->finish();
		}

		// Independent of the commerce chain above (different data, different
		// size — each of these is at most ~90 rows, well under one request),
		// so it runs as its own single step rather than a paged chain.
		$this->enqueue( 'insightistic_sync_expanded' );
	}

	/**
	 * Connector v2 (Milestone 3): traffic (GA4), SEO (Search Console),
	 * performance (PageSpeed) and broken-links, forwarded in one pass. Each
	 * source is independently optional — a site with only GA4 configured
	 * still gets that pushed even though GSC/PageSpeed have nothing to send.
	 * A failure in one source is logged and skipped, never aborts the rest
	 * (same policy as api_request()'s own graceful-degradation rule).
	 */
	public function sync_expanded() {
		if ( ! Insightistic_License_Manager::is_connected() ) {
			return;
		}

		$batch    = Insightistic_Saas_Client::sync_start();
		$batch_id = ( $batch['ok'] && isset( $batch['data']['sync_batch_id'] ) ) ? $batch['data']['sync_batch_id'] : null;

		$this->sync_traffic( $batch_id );
		$this->sync_seo( $batch_id );
		$this->sync_performance( $batch_id );
		$this->sync_broken_links( $batch_id );
	}

	private function sync_traffic( $batch_id ) {
		if ( ! class_exists( 'Insightistic_GA' ) ) {
			return;
		}
		$payload = ( new Insightistic_GA() )->get_sync_payload( 90 );
		if ( ! $payload || is_wp_error( $payload ) ) {
			return;
		}

		if ( ! empty( $payload['daily'] ) ) {
			$res = Insightistic_Saas_Client::sync_traffic_daily(
				array(
					'source'        => 'ga4',
					'days'          => $payload['daily'],
					'sync_batch_id' => $batch_id,
				)
			);
			$this->log( 'Traffic daily: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
		}

		if ( ! empty( $payload['channels'] ) ) {
			$end   = gmdate( 'Y-m-d' );
			$start = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
			$res   = Insightistic_Saas_Client::sync_traffic_dimensions(
				array(
					'source'         => 'ga4',
					'period_start'   => $start,
					'period_end'     => $end,
					'dimension_type' => 'channel',
					'rows'           => $payload['channels'],
					'sync_batch_id'  => $batch_id,
				)
			);
			$this->log( 'Traffic channels: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
		}
	}

	private function sync_seo( $batch_id ) {
		if ( ! class_exists( 'Insightistic_GSC' ) ) {
			return;
		}
		$payload = ( new Insightistic_GSC() )->get_sync_payload( 90 );
		if ( ! $payload || is_wp_error( $payload ) ) {
			return;
		}

		if ( ! empty( $payload['daily'] ) ) {
			$res = Insightistic_Saas_Client::sync_seo_daily( array( 'days' => $payload['daily'], 'sync_batch_id' => $batch_id ) );
			$this->log( 'SEO daily: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
		}

		$end   = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start = gmdate( 'Y-m-d', strtotime( '-93 days' ) );

		if ( ! empty( $payload['queries'] ) ) {
			$res = Insightistic_Saas_Client::sync_seo_queries(
				array(
					'period_start'  => $start,
					'period_end'    => $end,
					'rows'          => $payload['queries'],
					'sync_batch_id' => $batch_id,
				)
			);
			$this->log( 'SEO queries: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
		}

		if ( ! empty( $payload['pages'] ) ) {
			$res = Insightistic_Saas_Client::sync_seo_pages(
				array(
					'period_start'  => $start,
					'period_end'    => $end,
					'rows'          => $payload['pages'],
					'sync_batch_id' => $batch_id,
				)
			);
			$this->log( 'SEO pages: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
		}
	}

	private function sync_performance( $batch_id ) {
		if ( ! class_exists( 'Insightistic_PageSpeed' ) ) {
			return;
		}
		$payload = ( new Insightistic_PageSpeed() )->get_sync_payload();
		if ( ! $payload ) { // null (not configured) or false (fetch failed) both skip quietly.
			return;
		}

		$payload['sync_batch_id'] = $batch_id;
		$res                      = Insightistic_Saas_Client::sync_performance_run( $payload );
		$this->log( 'Performance run: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
	}

	private function sync_broken_links( $batch_id ) {
		if ( ! class_exists( 'Insightistic_Engagement' ) ) {
			return;
		}
		$links = ( new Insightistic_Engagement() )->get_broken_links_payload();
		if ( ! $links ) {
			return;
		}

		$res = Insightistic_Saas_Client::sync_broken_links( array( 'links' => $links, 'sync_batch_id' => $batch_id ) );
		$this->log( 'Broken links: ' . ( $res['ok'] ? 'ok' : 'failed: ' . $res['error'] ), $res['ok'] ? 'info' : 'error' );
	}

	/**
	 * Push one page of WooCommerce orders, then queue the next page or advance.
	 *
	 * @param int $page Page number (1-based).
	 */
	public function sync_orders( $page = 1 ) {
		$page = max( 1, (int) $page );

		if ( ! $this->woo_active() ) {
			$this->advance_after_orders();
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'   => self::ORDERS_PER,
				'page'    => $page,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( empty( $orders ) ) {
			$this->advance_after_orders();
			return;
		}

		$payload = array( 'orders' => array_map( array( $this, 'map_order' ), $orders ) );
		$res     = Insightistic_Saas_Client::request( 'POST', '/api/connector/v1/orders/bulk', $payload, true );
		$this->log(
			sprintf( 'Orders page %d: %s (%d sent)', $page, $res['ok'] ? 'ok' : 'failed: ' . $res['error'], count( $orders ) ),
			$res['ok'] ? 'info' : 'error'
		);

		if ( count( $orders ) < self::ORDERS_PER ) {
			$this->advance_after_orders();
		} else {
			$this->enqueue( 'insightistic_sync_orders', array( 'page' => $page + 1 ) );
		}
	}

	/**
	 * Push one page of WooCommerce products, then queue the next page or advance.
	 *
	 * @param int $page Page number (1-based).
	 */
	public function sync_products( $page = 1 ) {
		$page = max( 1, (int) $page );

		if ( ! $this->woo_active() || ! function_exists( 'wc_get_products' ) ) {
			$this->advance_after_products();
			return;
		}

		$products = wc_get_products(
			array(
				'limit'   => self::PRODUCTS_PER,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( empty( $products ) ) {
			$this->advance_after_products();
			return;
		}

		$payload = array( 'products' => array_map( array( $this, 'map_product' ), $products ) );
		$res     = Insightistic_Saas_Client::request( 'POST', '/api/connector/v1/products/bulk', $payload, true );
		$this->log(
			sprintf( 'Products page %d: %s (%d sent)', $page, $res['ok'] ? 'ok' : 'failed: ' . $res['error'], count( $products ) ),
			$res['ok'] ? 'info' : 'error'
		);

		if ( count( $products ) < self::PRODUCTS_PER ) {
			$this->advance_after_products();
		} else {
			$this->enqueue( 'insightistic_sync_products', array( 'page' => $page + 1 ) );
		}
	}

	/**
	 * Push one page of WooCommerce customers, then queue the next page or finish.
	 *
	 * @param int $page Page number (1-based).
	 */
	public function sync_customers( $page = 1 ) {
		$page = max( 1, (int) $page );

		$users = get_users(
			array(
				'role'    => 'customer',
				'number'  => self::CUSTOMERS_PER,
				'paged'   => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( empty( $users ) ) {
			$this->finish();
			return;
		}

		$payload = array( 'customers' => array_map( array( $this, 'map_customer' ), $users ) );
		$res     = Insightistic_Saas_Client::request( 'POST', '/api/connector/v1/customers/bulk', $payload, true );
		$this->log(
			sprintf( 'Customers page %d: %s (%d sent)', $page, $res['ok'] ? 'ok' : 'failed: ' . $res['error'], count( $users ) ),
			$res['ok'] ? 'info' : 'error'
		);

		if ( count( $users ) < self::CUSTOMERS_PER ) {
			$this->finish();
		} else {
			$this->enqueue( 'insightistic_sync_customers', array( 'page' => $page + 1 ) );
		}
	}

	private function advance_after_orders() {
		$settings = self::settings();
		if ( ! empty( $settings['sync_products'] ) && $this->woo_active() ) {
			$this->enqueue( 'insightistic_sync_products', array( 'page' => 1 ) );
		} else {
			$this->advance_after_products();
		}
	}

	private function advance_after_products() {
		$settings = self::settings();
		if ( ! empty( $settings['sync_customers'] ) ) {
			$this->enqueue( 'insightistic_sync_customers', array( 'page' => 1 ) );
		} else {
			$this->finish();
		}
	}

	private function finish() {
		$res = Insightistic_Saas_Client::request( 'POST', '/api/connector/v1/sync-complete', array(), true );
		if ( $res['ok'] ) {
			update_option( 'insightistic_last_sync', current_time( 'mysql' ), false );
			$this->log( 'Full sync complete.', 'info' );
		} else {
			$this->log( 'sync-complete failed: ' . $res['error'], 'error' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Mappers — business data only, never card/payment secrets.            */
	/* ------------------------------------------------------------------ */

	/**
	 * Map a WooCommerce order to the SaaS orders/bulk payload shape.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public function map_order( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'external_product_id' => $item->get_product_id(),
				'product_name'        => $item->get_name(),
				'sku'                 => $product ? $product->get_sku() : null,
				'quantity'            => (int) $item->get_quantity(),
				'subtotal'            => (float) $item->get_subtotal(),
				'total'               => (float) $item->get_total(),
			);
		}

		return array(
			'external_order_id'  => $order->get_id(),
			'order_number'       => $order->get_order_number(),
			'customer_id'        => $order->get_customer_id(),
			'status'              => $order->get_status(),
			'currency'            => $order->get_currency(),
			'total'               => (float) $order->get_total(),
			'subtotal'            => (float) $order->get_subtotal(),
			'tax_total'           => (float) $order->get_total_tax(),
			'shipping_total'      => (float) $order->get_shipping_total(),
			'discount_total'      => (float) $order->get_total_discount(),
			'refund_total'        => (float) $order->get_total_refunded(),
			'payment_method'      => $order->get_payment_method_title(),
			'created_at_store'    => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'completed_at_store'  => $order->get_date_completed() ? $order->get_date_completed()->date( 'c' ) : null,
			'items'               => $items,
		);
	}

	/**
	 * Map a WooCommerce product to the SaaS products/bulk payload shape.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	public function map_product( $product ) {
		$sale = $product->get_sale_price();
		return array(
			'external_product_id' => $product->get_id(),
			'name'                => $product->get_name(),
			'sku'                 => $product->get_sku(),
			'price'               => '' !== $product->get_price() ? (float) $product->get_price() : 0,
			'regular_price'       => '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : 0,
			'sale_price'          => ( '' !== $sale && null !== $sale ) ? (float) $sale : null,
			'stock_quantity'      => $product->get_stock_quantity(),
			'stock_status'        => $product->get_stock_status(),
			'total_sales'         => (int) $product->get_total_sales(),
			'status'              => $product->get_status(),
		);
	}

	/**
	 * Map a WooCommerce customer to the SaaS customers/bulk payload shape.
	 *
	 * @param WP_User $user Customer user object.
	 * @return array
	 */
	public function map_customer( $user ) {
		$total_spent = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $user->ID ) : 0;
		$order_count = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user->ID ) : 0;

		return array(
			'external_customer_id' => $user->ID,
			// Privacy: hash the email; the raw address never leaves the store.
			'email_hash'            => hash( 'sha256', strtolower( trim( $user->user_email ) ) ),
			'first_name'            => get_user_meta( $user->ID, 'first_name', true ) ?: null,
			'last_name'             => get_user_meta( $user->ID, 'last_name', true ) ?: null,
			'city'                  => get_user_meta( $user->ID, 'billing_city', true ) ?: null,
			'country'               => get_user_meta( $user->ID, 'billing_country', true ) ?: null,
			'total_spent'           => $total_spent,
			'order_count'           => $order_count,
		);
	}

	/**
	 * Environment payload sent to the SaaS site-health endpoint.
	 *
	 * @return array
	 */
	private function site_health_payload() {
		return array(
			'wp_version'     => get_bloginfo( 'version' ),
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'plugin_version' => INSIGHTISTIC_VERSION,
			'timezone'       => wp_timezone_string(),
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
		);
	}
}
