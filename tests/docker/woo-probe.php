<?php
/**
 * WooCommerce compatibility probe for the release gate.
 * Creates representative data and exercises Insightistic_Woocommerce::
 * get_dashboard_data() end-to-end (WooCommerce active path).
 *
 * Usage: docker compose run --rm wpcli wp --path=/var/www/html --allow-root eval-file /woo-probe.php
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- gate probe executed via wp-cli eval-file.

$pass = 0; $fail = 0;
$ok  = function ( $m ) use ( &$pass ) { $pass++; echo "  PASS  {$m}\n"; };
$bad = function ( $m ) use ( &$fail ) { $fail++; echo "  FAIL  {$m}\n"; };

if ( ! class_exists( 'WooCommerce' ) ) {
	echo "  FAIL  WooCommerce is not active\n";
	exit( 1 );
}
$ok( 'WooCommerce active: ' . WC()->version );

// Representative product.
$product = new WC_Product_Simple();
$product->set_name( 'Gate Probe Product' );
$product->set_regular_price( '29.99' );
$product->set_status( 'publish' );
$product->save();
$ok( 'product created (#' . $product->get_id() . ')' );

// Representative customer + completed order.
$customer = new WC_Customer();
$customer->set_email( 'gate-probe@example.com' );
$customer->set_billing_email( 'gate-probe@example.com' );
$customer->set_first_name( 'Gate' );
$customer->set_last_name( 'Probe' );
$customer->save();

$order = wc_create_order( array( 'customer_id' => $customer->get_id() ) );
$order->add_product( $product, 3 );
$order->set_address( array( 'email' => 'gate-probe@example.com', 'first_name' => 'Gate', 'last_name' => 'Probe' ), 'billing' );
$order->set_status( 'completed' );
$order->set_total( '89.97' );
$order->calculate_totals();
$order->save();
$ok( 'order created (#' . $order->get_id() . ', total ' . $order->get_total() . ')' );

// Boot the plugin's WooCommerce dashboard aggregation.
$wc = new Insightistic_Woocommerce();
$data = $wc->get_dashboard_data( 28, true );

if ( is_wp_error( $data ) ) {
	$bad( 'get_dashboard_data(): ' . $data->get_error_message() );
} else {
	$ok( 'get_dashboard_data() returned a payload' );
	$orders = isset( $data['overview']['orders']['value'] ) ? $data['overview']['orders']['value'] : null;
	( $orders >= 1 ) ? $ok( "aggregated orders count >= 1 (got {$orders})" ) : $bad( "aggregated orders count >= 1 (got " . var_export( $orders, true ) . ')' );
	$revenue = isset( $data['overview']['revenue']['value'] ) ? $data['overview']['revenue']['value'] : null;
	( (float) $revenue >= 89.97 ) ? $ok( "aggregated revenue >= 89.97 (got {$revenue})" ) : $bad( "aggregated revenue >= 89.97 (got " . var_export( $revenue, true ) . ')' );
}

echo "WOOCOMMERCE PROBE: PASS={$pass} FAIL={$fail}\n";
exit( $fail ? 1 : 0 );
