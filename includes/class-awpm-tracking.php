<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Tracking {
	const COOKIE = 'awpm_referral';
	const CLICK_COOKIE = 'awpm_click';
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'attach' ), 20 );
		add_action( 'user_register', array( __CLASS__, 'register_customer' ), 20 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'register_customer' ), 20 );
		add_action( 'deleted_user', array( __CLASS__, 'delete_customer' ) );
	}
	public static function active( $id ) {
		return $id && get_userdata( $id ) && 'approved' === AWPM_Affiliates::status( $id ) && 'yes' !== get_user_meta( $id, '_awpm_blocked', true );
	}
	public static function token( $id ) {
		$token = get_user_meta( $id, '_awpm_token', true );
		if ( ! $token ) {
			$token = strtolower( wp_generate_password( 32, false, false ) );
			add_user_meta( $id, '_awpm_token', $token, true );
			$token = get_user_meta( $id, '_awpm_token', true );
		}
		return $token;
	}
	public static function payload( $id, $expires ) {
		$data = absint( $id ) . '.' . absint( $expires );
		return $data . '.' . hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}
	public static function referral() {
		return self::cookie_id( self::COOKIE );
	}
	private static function cookie_id( $name ) {
		$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		$parts = explode( '.', $value );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < time() ) { return 0; }
		if ( ! hash_equals( self::payload( $parts[0], $parts[1] ), $value ) ) { return 0; }
		return self::active( (int) $parts[0] ) ? (int) $parts[0] : 0;
	}
	public static function capture() {
		if ( 'yes' !== AWPM_Settings::get( 'enabled' ) || ! isset( $_GET['awpm_ref'] ) || ! is_scalar( $_GET['awpm_ref'] ) || is_admin() || headers_sent() ) { return; }
		$token = sanitize_text_field( wp_unslash( $_GET['awpm_ref'] ) );
		if ( ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) { return; }
		$users = get_users( array( 'meta_key' => '_awpm_token', 'meta_value' => $token, 'number' => 1, 'fields' => 'ID' ) );
		$id = $users ? (int) $users[0] : 0;
		if ( ! self::active( $id ) || $id === get_current_user_id() ) { return; }
		// Consent integrations can veto tracking before a cookie is created.
		if ( ! apply_filters( 'awpm_tracking_allowed', true, $id ) ) { return; }
		self::record_click( $id );
		if ( 'first' === AWPM_Settings::get( 'attribution' ) && self::referral() ) { return; }
		$expires = time() + DAY_IN_SECONDS * AWPM_Settings::get( 'cookie_days' );
		$value = self::payload( $id, $expires );
		setcookie( self::COOKIE, $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::COOKIE ] = $value;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
	}
	public static function record_click( $id ) {
		global $wpdb;
		if ( 'yes' !== AWPM_Settings::get( 'enabled' ) || ! self::active( $id ) || $id === get_current_user_id() || ! apply_filters( 'awpm_tracking_allowed', true, $id ) || self::cookie_id( self::CLICK_COOKIE ) === $id ) { return false; }
		$table = AWPM_Store::table( 'clicks' );
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (affiliate_id,click_date,clicks) VALUES (%d,%s,1) ON DUPLICATE KEY UPDATE clicks=clicks+1", $id, current_time( 'Y-m-d' ) ) );
		if ( false === $result ) { return false; }
		$expires = time() + 30 * MINUTE_IN_SECONDS;
		$value = self::payload( $id, $expires );
		if ( ! headers_sent() ) {
			setcookie( self::CLICK_COOKIE, $value, array( 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			nocache_headers();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		$_COOKIE[ self::CLICK_COOKIE ] = $value;
		return true;
	}
	public static function register_customer( $customer_id ) {
		global $wpdb;
		$id = self::referral();
		$customer = get_userdata( $customer_id );
		$affiliate = $id ? get_userdata( $id ) : false;
		if ( 'yes' !== AWPM_Settings::get( 'enabled' ) || ! $customer || ! $affiliate || $id === (int) $customer_id || ! array_intersect( array( 'customer', 'subscriber' ), $customer->roles ) || strtolower( $customer->user_email ) === strtolower( $affiliate->user_email ) || ! apply_filters( 'awpm_tracking_allowed', true, $id ) ) { return; }
		$table = AWPM_Store::table( 'customers' );
		// A customer can only be attributed once, even if both creation hooks fire.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (customer_id,affiliate_id,created_at) VALUES (%d,%d,%s)", $customer_id, $id, current_time( 'mysql', true ) ) );
	}
	public static function delete_customer( $customer_id ) {
		global $wpdb;
		$table = AWPM_Store::table( 'customers' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE customer_id=%d OR affiliate_id=%d", $customer_id, $customer_id ) );
		$wpdb->delete( AWPM_Store::table( 'clicks' ), array( 'affiliate_id' => $customer_id ) );
	}
	public static function attach( $order ) {
		if ( 'yes' !== AWPM_Settings::get( 'enabled' ) || $order->get_meta( '_awpm_affiliate' ) ) { return; }
		$id = self::referral();
		$user = $id ? get_userdata( $id ) : false;
		if ( ! $user || $id === (int) $order->get_customer_id() || $id === get_current_user_id() || strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) ) { return; }
		$order->update_meta_data( '_awpm_affiliate', $id );
		$order->update_meta_data( '_awpm_currency', AWPM_Settings::get( 'currency' ) );
		$order->update_meta_data( '_awpm_purchase_policy', AWPM_Settings::get( 'purchase_policy' ) );
		$rates = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) { continue; }
			if ( $product->is_type( 'variation' ) ) { $product = wc_get_product( $product->get_parent_id() ); }
			$type = $product ? $product->get_meta( '_awpm_type' ) : '';
			$rates[ $item_id ] = array( 'type' => $type ?: AWPM_Settings::get( 'commission_type' ), 'rate' => $type ? (float) $product->get_meta( '_awpm_rate' ) : (float) AWPM_Settings::get( 'commission_rate' ), 'quantity' => (float) $item->get_quantity(), 'total' => (float) $item->get_total() );
		}
		$order->update_meta_data( '_awpm_rates', $rates );
	}
}
