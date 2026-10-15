<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Security {
	public static function value( $input, $key, $default = '' ) {
		return is_array( $input ) && isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : $default;
	}
	public static function rows( $input, $key ) {
		return is_array( $input ) && isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
	}
	public static function page( $input, $key ) { return max( 1, min( 100000, absint( self::value( $input, $key, '1' ) ) ) ); }
	public static function admin_request( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affmint' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { wp_die( esc_html__( 'Use the settings form to make changes.', 'affmint' ), '', array( 'response' => 405 ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read the scalar nonce only to validate it immediately with wp_verify_nonce; no state change occurs before validation.
		$nonce = sanitize_text_field( wp_unslash( self::value( $_POST, '_wpnonce' ) ) );
		if ( ! wp_verify_nonce( $nonce, $action ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'affmint' ), '', array( 'response' => 403 ) ); }
	}
}
