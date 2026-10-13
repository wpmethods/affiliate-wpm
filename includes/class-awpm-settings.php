<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Settings {
	/** Store accounting currency, independent of the visitor's currency-switcher selection. */
	public static function base_currency() {
		$currency = get_option( 'woocommerce_currency', 'USD' );
		$currency = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';
		$known = get_woocommerce_currencies();
		return isset( $known[ $currency ] ) ? $currency : 'USD';
	}
	/** Keep URL names separate from WooCommerce's stable internal endpoint key. */
	public static function url_name( $value, $kind ) {
		$default = 'account_slug' === $kind ? 'affiliate-wpm' : 'awpm_ref';
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		$pattern = 'account_slug' === $kind ? '/^[a-z][a-z0-9-]{0,59}$/' : '/^[a-z][a-z0-9_-]{0,59}$/';
		$reserved = array( 'action', 'security', 'nonce', '_wpnonce', 'wc-ajax', 'wc-api', 'rest_route', 'key', 'token', 'password', 'access_token', 'order_key', 'pay_for_order', 'wp-admin', 'wp-json', 'wp-login', 'feed', 'embed', 'dashboard', 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'lost-password', 'customer-logout', 'order-pay', 'order-received' );
		if ( class_exists( 'WP' ) ) { $reserved = array_merge( $reserved, ( new WP() )->public_query_vars ); }
		foreach ( array( 'orders', 'view_order', 'downloads', 'edit_account', 'edit_address', 'payment_methods', 'add_payment_method', 'delete_payment_method', 'set_default_payment_method', 'lost_password', 'customer_logout', 'pay', 'order_received' ) as $endpoint ) { $reserved[] = get_option( 'woocommerce_myaccount_' . $endpoint . '_endpoint', '' ); }
		foreach ( array( 'woocommerce_checkout_pay_endpoint', 'woocommerce_checkout_order_received_endpoint', 'woocommerce_logout_endpoint' ) as $option ) { $reserved[] = get_option( $option, '' ); }
		return preg_match( $pattern, $value ) && ! in_array( $value, $reserved, true ) ? $value : $default;
	}
	public static function referral_parameter() {
		return self::url_name( AWPM_Security::value( (array) get_option( 'awpm_settings', array() ), 'referral_parameter', 'awpm_ref' ), 'referral_parameter' );
	}
	public static function account_slug() {
		return self::url_name( AWPM_Security::value( (array) get_option( 'awpm_settings', array() ), 'account_slug', 'affiliate-wpm' ), 'account_slug' );
	}
	public static function referral_keys() { return array_values( array_unique( array( self::referral_parameter(), 'awpm_ref' ) ) ); }
	public static function referral_request_key() {
		foreach ( self::referral_keys() as $key ) { if ( isset( $_GET[ $key ] ) ) { return $key; } }
		return '';
	}
	public static function defaults() {
		return array( 'enabled' => 'yes', 'approval_required' => 'no', 'terms_content' => '', 'join_title' => __( 'Turn recommendations into rewards', 'affiliate-wpm' ), 'join_description' => __( 'Join our affiliate program and earn commissions when someone buys through your personal link.', 'affiliate-wpm' ), 'join_button_text' => __( 'Join affiliate program', 'affiliate-wpm' ), 'join_show_rate' => 'no', 'join_url_enabled' => 'no', 'join_promotion_enabled' => 'no', 'release_mode' => 'completed', 'release_days' => 7, 'commission_type' => 'percent', 'commission_rate' => '10', 'minimum' => '50', 'currency' => self::base_currency(), 'exchange_source' => 'auto', 'exchange_rates' => '', 'referral_parameter' => 'awpm_ref', 'account_slug' => 'affiliate-wpm', 'cookie_days' => 30, 'attribution' => 'first', 'purchase_policy' => 'multiple', 'payout_mode' => 'manual', 'method_selection' => 'multiple', 'methods' => array( array( 'id' => 'bank', 'name' => 'Bank transfer', 'instructions' => 'Enter the account holder, bank name and account details. Do not provide passwords or card details.', 'active' => 'yes' ) ) );
	}
	/** Effective program state; keep the merchant's saved preference intact. */
	public static function program_enabled() {
		return AWPM_License::active() && 'yes' === self::get( 'enabled' );
	}
	public static function get( $key = null ) {
		$settings = wp_parse_args( get_option( 'awpm_settings', array() ), self::defaults() );
		// Ignore the former plugin currency override, including older settings backups.
		$settings['currency'] = self::base_currency();
		$settings['referral_parameter'] = self::referral_parameter();
		$settings['account_slug'] = self::account_slug();
		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}
	/** Canonical content and version shared by display and enrollment validation. */
	public static function terms() {
		return trim( wp_kses_post( AWPM_Security::value( self::get(), 'terms_content' ) ) );
	}
	public static function terms_version() {
		$terms = self::terms();
		return '' === $terms ? '' : hash( 'sha256', $terms );
	}
	public static function init() {
		add_action( 'update_option_awpm_settings', array( 'AWPM_Account', 'settings_changed' ), 10, 2 );
		add_action( 'admin_post_awpm_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
	}
	public static function methods() {
		if ( 'manual' !== self::get( 'payout_mode' ) ) {
			return array();
		}
		$methods = array_values( array_filter( self::get( 'methods' ), function ( $method ) { return 'yes' === $method['active']; } ) );
		return 'single' === self::get( 'method_selection' ) ? array_slice( $methods, 0, 1 ) : $methods;
	}
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean = $defaults;
		foreach ( array( 'referral_parameter', 'account_slug' ) as $key ) { $clean[ $key ] = self::url_name( AWPM_Security::value( $input, $key, $defaults[ $key ] ), $key ); }
		if ( function_exists( 'WC' ) && WC()->query ) {
			foreach ( WC()->query->get_query_vars() as $key => $slug ) {
				if ( 'affiliate-wpm' === $key ) { continue; }
				foreach ( array( 'referral_parameter', 'account_slug' ) as $name ) { if ( in_array( $clean[ $name ], array( $key, $slug ), true ) ) { $clean[ $name ] = $defaults[ $name ]; } }
			}
		}
		foreach ( array( 'join_title', 'join_button_text' ) as $key ) {
			$value = mb_substr( sanitize_text_field( AWPM_Security::value( $input, $key, $defaults[ $key ] ) ), 0, 180 );
			$clean[ $key ] = '' !== trim( $value ) ? $value : $defaults[ $key ];
		}
		$clean['join_description'] = mb_substr( sanitize_textarea_field( AWPM_Security::value( $input, 'join_description', $defaults['join_description'] ) ), 0, 3000 );
		$clean['terms_content'] = wp_kses_post( mb_substr( AWPM_Security::value( $input, 'terms_content' ), 0, 50000 ) );
		$clean['enabled'] = 'yes' === AWPM_Security::value( $input, 'enabled' ) ? 'yes' : 'no';
		foreach ( array( 'join_url_enabled' => array( 'yes', 'no' ), 'join_promotion_enabled' => array( 'yes', 'no' ), 'join_show_rate' => array( 'yes', 'no' ), 'release_mode' => array( 'completed', 'delay' ), 'exchange_source' => array( 'auto', 'fox', 'curcy', 'manual', 'same' ), 'approval_required' => array( 'yes', 'no' ), 'commission_type' => array( 'percent', 'fixed' ), 'attribution' => array( 'first', 'last' ), 'purchase_policy' => array( 'once', 'multiple' ), 'payout_mode' => array( 'manual', 'disabled' ), 'method_selection' => array( 'single', 'multiple' ) ) as $key => $allowed ) {
			$clean[ $key ] = in_array( $input[ $key ] ?? '', $allowed, true ) ? $input[ $key ] : $defaults[ $key ];
		}
		$clean['commission_rate'] = wc_format_decimal( max( 0, min( 1000000, (float) wc_format_decimal( AWPM_Security::value( $input, 'commission_rate', '10' ) ) ) ), 6 );
		if ( 'percent' === $clean['commission_type'] ) {
			$clean['commission_rate'] = min( 100, (float) $clean['commission_rate'] );
		}
		$clean['minimum'] = wc_format_decimal( max( 0.01, min( 1000000, (float) wc_format_decimal( AWPM_Security::value( $input, 'minimum', '50' ) ) ) ), 6 );
		$clean['currency'] = self::base_currency();
		$clean['exchange_rates'] = AWPM_Currency::clean_rates( AWPM_Security::value( $input, 'exchange_rates' ) );
		$clean['release_days'] = max( 1, min( 365, absint( AWPM_Security::value( $input, 'release_days', '7' ) ) ) );
		$clean['cookie_days'] = max( 1, min( 365, absint( AWPM_Security::value( $input, 'cookie_days', '30' ) ) ) );
		$clean['methods'] = array();
		$ids = array();
		foreach ( array_slice( (array) ( $input['methods'] ?? array() ), 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$name = substr( sanitize_text_field( AWPM_Security::value( $row, 'name' ) ), 0, 120 );
			if ( '' === $name ) { continue; }
			$id = sanitize_key( AWPM_Security::value( $row, 'id' ) );
			if ( ! $id || isset( $ids[ $id ] ) ) { $id = wp_generate_uuid4(); }
			$ids[ $id ] = true;
			$clean['methods'][] = array( 'id' => $id, 'name' => $name, 'instructions' => substr( sanitize_textarea_field( AWPM_Security::value( $row, 'instructions' ) ), 0, 2000 ), 'active' => 'yes' === AWPM_Security::value( $row, 'active' ) ? 'yes' : 'no' );
		}
		if ( 'single' === $clean['method_selection'] ) {
			$found = false;
			foreach ( $clean['methods'] as &$method ) {
				if ( 'yes' === $method['active'] ) { $method['active'] = $found ? 'no' : 'yes'; $found = true; }
			}
			unset( $method );
		}
		return $clean;
	}
	public static function require_license() {
		if ( ! AWPM_License::active() ) {
			wp_die( esc_html__( 'Activate your Affiliate WPM license to change plugin settings.', 'affiliate-wpm' ), '', array( 'response' => 403 ) );
		}
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		AWPM_Security::admin_request( 'awpm_settings' );
		self::require_license();
		$input = isset( $_POST['awpm'] ) && is_array( $_POST['awpm'] ) ? wp_unslash( $_POST['awpm'] ) : array();
		update_option( 'awpm_settings', self::clean( $input ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=settings&saved=1' ) );
		exit;
	}
	public static function product_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_select( array( 'id' => '_awpm_type', 'label' => __( 'Affiliate commission', 'affiliate-wpm' ), 'options' => array( '' => __( 'Use global setting', 'affiliate-wpm' ), 'percent' => __( 'Percentage', 'affiliate-wpm' ), 'fixed' => __( 'Fixed per unit', 'affiliate-wpm' ), 'disabled' => __( 'No commission', 'affiliate-wpm' ) ) ) );
		woocommerce_wp_text_input( array( 'id' => '_awpm_rate', 'label' => __( 'Affiliate rate / amount', 'affiliate-wpm' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'step' => '0.000001' ), 'desc_tip' => true, 'description' => __( 'Percentage of the item total after discounts, excluding tax, or fixed amount per unit in the WooCommerce base currency. Applies to all variations.', 'affiliate-wpm' ) ) );
		echo '</div>';
	}
	public static function save_product( $product ) {
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) { return; }
		$type = sanitize_key( wp_unslash( AWPM_Security::value( $_POST, '_awpm_type' ) ) );
		$type = in_array( $type, array( 'percent', 'fixed', 'disabled' ), true ) ? $type : '';
		$rate = max( 0, min( 1000000, (float) wc_format_decimal( wp_unslash( AWPM_Security::value( $_POST, '_awpm_rate', '0' ) ) ) ) );
		$product->update_meta_data( '_awpm_type', $type );
		$product->update_meta_data( '_awpm_rate', 'percent' === $type ? min( 100, $rate ) : $rate );
	}
}
