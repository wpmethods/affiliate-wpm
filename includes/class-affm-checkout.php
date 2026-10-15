<?php
 defined( 'ABSPATH' ) || exit;

/** Click Shop's AJAX checkout bypasses WC_Checkout; capture the final saved order. */
final class AFFM_Checkout {
	private static $orders = array();
	public static function init() {
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'collect' ), 20 );
		add_action( 'shutdown', array( __CLASS__, 'finish' ), 5 );
	}
	private static function quick_request() {
		if ( ! wp_doing_ajax() || 'POST' !== sanitize_text_field( wp_unslash( AFFM_Security::value( $_SERVER, 'REQUEST_METHOD' ) ) ) || ! function_exists( 'handle_quick_order_submit_cs' ) ) { return false; }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This scalar is the nonce being verified on the next line; no order mutation occurs before verification.
		$nonce = sanitize_text_field( wp_unslash( AFFM_Security::value( $_POST, 'security' ) ) );
		if ( ! wp_verify_nonce( $nonce, 'quick_order_nonce' ) ) { return false; }
		return 'quick_order_submit' === sanitize_key( wp_unslash( AFFM_Security::value( $_POST, 'action' ) ) );
	}
	public static function collect( $order ) {
		if ( ! self::quick_request() || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || ! $order->get_id() || ! $order->get_items( 'line_item' ) ) { return; }
		self::$orders[ $order->get_id() ] = true;
	}
	public static function finish() {
		$ids = array_keys( self::$orders ); self::$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || $order instanceof WC_Order_Refund || AFFM_Commissions::incomplete_order( $order ) ) { continue; }
			if ( ! $order->get_meta( '_affm_affiliate' ) ) {
				AFFM_Tracking::attach( $order );
				if ( $order->get_meta( '_affm_affiliate' ) ) { $order->save_meta_data(); }
			}
			if ( $order->get_meta( '_affm_affiliate' ) ) { AFFM_Commissions::sync( $id ); }
		}
	}
}
