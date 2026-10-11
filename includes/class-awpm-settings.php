<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Settings {
	public static function defaults() {
		return array( 'enabled' => 'yes', 'approval_required' => 'no', 'commission_type' => 'percent', 'commission_rate' => '10', 'minimum' => '50', 'currency' => get_woocommerce_currency(), 'cookie_days' => 30, 'attribution' => 'first', 'purchase_policy' => 'multiple', 'payout_mode' => 'manual', 'method_selection' => 'multiple', 'methods' => array( array( 'id' => 'bank', 'name' => 'Bank transfer', 'instructions' => 'Enter the account holder, bank name and account details. Do not provide passwords or card details.', 'active' => 'yes' ) ) );
	}
	public static function get( $key = null ) {
		$settings = wp_parse_args( get_option( 'awpm_settings', array() ), self::defaults() );
		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}
	public static function init() {
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
		$defaults = self::defaults();
		$clean = $defaults;
		$clean['enabled'] = isset( $input['enabled'] ) ? 'yes' : 'no';
		foreach ( array( 'approval_required' => array( 'yes', 'no' ), 'commission_type' => array( 'percent', 'fixed' ), 'attribution' => array( 'first', 'last' ), 'purchase_policy' => array( 'once', 'multiple' ), 'payout_mode' => array( 'manual', 'disabled' ), 'method_selection' => array( 'single', 'multiple' ) ) as $key => $allowed ) {
			$clean[ $key ] = in_array( $input[ $key ] ?? '', $allowed, true ) ? $input[ $key ] : $defaults[ $key ];
		}
		$clean['commission_rate'] = wc_format_decimal( max( 0, min( 1000000, (float) wc_format_decimal( $input['commission_rate'] ?? 10 ) ) ), 6 );
		if ( 'percent' === $clean['commission_type'] ) {
			$clean['commission_rate'] = min( 100, (float) $clean['commission_rate'] );
		}
		$clean['minimum'] = wc_format_decimal( max( 0.01, min( 1000000, (float) wc_format_decimal( $input['minimum'] ?? 50 ) ) ), 6 );
		$currencies = get_woocommerce_currencies();
		$currency = sanitize_text_field( $input['currency'] ?? '' );
		$clean['currency'] = isset( $currencies[ $currency ] ) ? $currency : $defaults['currency'];
		$clean['cookie_days'] = max( 1, min( 365, absint( $input['cookie_days'] ?? 30 ) ) );
		$clean['methods'] = array();
		$ids = array();
		foreach ( array_slice( (array) ( $input['methods'] ?? array() ), 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$name = substr( sanitize_text_field( $row['name'] ?? '' ), 0, 120 );
			if ( '' === $name ) { continue; }
			$id = sanitize_key( $row['id'] ?? '' );
			if ( ! $id || isset( $ids[ $id ] ) ) { $id = wp_generate_uuid4(); }
			$ids[ $id ] = true;
			$clean['methods'][] = array( 'id' => $id, 'name' => $name, 'instructions' => substr( sanitize_textarea_field( $row['instructions'] ?? '' ), 0, 2000 ), 'active' => isset( $row['active'] ) ? 'yes' : 'no' );
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
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		check_admin_referer( 'awpm_settings' );
		$input = isset( $_POST['awpm'] ) && is_array( $_POST['awpm'] ) ? wp_unslash( $_POST['awpm'] ) : array();
		update_option( 'awpm_settings', self::clean( $input ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=settings&saved=1' ) );
		exit;
	}
	public static function product_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_select( array( 'id' => '_awpm_type', 'label' => __( 'Affiliate commission', 'affiliate-wpm' ), 'options' => array( '' => __( 'Use global setting', 'affiliate-wpm' ), 'percent' => __( 'Percentage', 'affiliate-wpm' ), 'fixed' => __( 'Fixed per unit', 'affiliate-wpm' ), 'disabled' => __( 'No commission', 'affiliate-wpm' ) ) ) );
		woocommerce_wp_text_input( array( 'id' => '_awpm_rate', 'label' => __( 'Affiliate rate / amount', 'affiliate-wpm' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'step' => '0.000001' ), 'desc_tip' => true, 'description' => __( 'Percentage of the item total after discounts, excluding tax, or fixed amount per unit in the affiliate currency. Applies to all variations.', 'affiliate-wpm' ) ) );
		echo '</div>';
	}
	public static function save_product( $product ) {
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) { return; }
		$type = isset( $_POST['_awpm_type'] ) ? sanitize_key( wp_unslash( $_POST['_awpm_type'] ) ) : '';
		$type = in_array( $type, array( 'percent', 'fixed', 'disabled' ), true ) ? $type : '';
		$rate = max( 0, min( 1000000, (float) wc_format_decimal( isset( $_POST['_awpm_rate'] ) ? wp_unslash( $_POST['_awpm_rate'] ) : 0 ) ) );
		$product->update_meta_data( '_awpm_type', $type );
		$product->update_meta_data( '_awpm_rate', 'percent' === $type ? min( 100, $rate ) : $rate );
	}
}
