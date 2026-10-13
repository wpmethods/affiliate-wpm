<?php
 defined( 'ABSPATH' ) || exit;

/** Click Shop's AJAX checkout bypasses WC_Checkout; capture the final saved order. */
final class AWPM_Checkout {
	private static $orders = array();
	public static function init() {
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'collect' ), 20 );
		add_action( 'shutdown', array( __CLASS__, 'finish' ), 5 );
	}
	private static function quick_request() {
		return wp_doing_ajax() && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'quick_order_submit' === AWPM_Security::value( $_POST, 'action' ) && function_exists( 'handle_quick_order_submit_cs' ) && wp_verify_nonce( AWPM_Security::value( $_POST, 'security' ), 'quick_order_nonce' );
	}
	public static function collect( $order ) {
		if ( ! self::quick_request() || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! $order->get_id() || ! $order->get_items( 'line_item' ) ) { return; }
		self::$orders[ $order->get_id() ] = true;
	}
	public static function finish() {
		$ids = array_keys( self::$orders ); self::$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || $order instanceof WC_Order_Refund || AWPM_Commissions::incomplete_order( $order ) ) { continue; }
			if ( ! $order->get_meta( '_awpm_affiliate' ) ) {
				AWPM_Tracking::attach( $order );
				if ( $order->get_meta( '_awpm_affiliate' ) ) { $order->save_meta_data(); }
			}
			if ( $order->get_meta( '_awpm_affiliate' ) ) { AWPM_Commissions::sync( $id ); }
		}
	}
}
