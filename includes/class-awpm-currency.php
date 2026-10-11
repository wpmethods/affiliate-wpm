<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Currency {
	public static function valid_rate( $rate ) { return is_scalar( $rate ) && is_numeric( $rate ) && is_finite( (float) $rate ) && (float) $rate >= 0.000000000001 && (float) $rate <= 1000000000000; }
	public static function precision( $currency ) {
		if ( in_array( $currency, array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ), true ) ) { return 3; }
		return in_array( $currency, array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), true ) ? 0 : max( 0, min( 6, (int) get_option( 'woocommerce_price_num_decimals', 2 ) ) );
	}
	public static function clean_rates( $text ) {
		if ( ! is_scalar( $text ) ) { return ''; }
		$known = get_woocommerce_currencies(); $rows = array();
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', substr( (string) $text, 0, 6000 ) ), 0, 50 ) as $line ) {
			if ( ! preg_match( '/^\s*([A-Z]{3})\s+([A-Z]{3})\s+([0-9]+(?:\.[0-9]+)?)\s*$/i', $line, $m ) ) { continue; }
			$from = strtoupper( $m[1] ); $to = strtoupper( $m[2] );
			if ( $from !== $to && isset( $known[ $from ], $known[ $to ] ) && self::valid_rate( $m[3] ) ) { $rows[ $from . ' ' . $to ] = $from . ' ' . $to . ' ' . $m[3]; }
		}
		return implode( "\n", $rows );
	}
	private static function table_rate( $table, $from, $to ) {
		if ( ! is_array( $table ) || ! isset( $table[ $from ]['rate'], $table[ $to ]['rate'] ) || ! self::valid_rate( $table[ $from ]['rate'] ) || ! self::valid_rate( $table[ $to ]['rate'] ) ) { return false; }
		$rate = (float) $table[ $to ]['rate'] / (float) $table[ $from ]['rate'];
		return self::valid_rate( $rate ) ? $rate : false;
	}
	public static function rate( $from, $to, $order = null ) {
		if ( $from === $to ) { return array( 'rate' => 1, 'source' => 'same-currency' ); }
		$mode = AWPM_Settings::get( 'exchange_source' );
		if ( 'same' === $mode ) { return new WP_Error( 'currency', __( 'Different order and affiliate currencies require an exchange rate.', 'affiliate-wpm' ) ); }
		if ( 'manual' !== $mode ) {
			$candidates = array();
			// Prefer historical order metadata when it contains the complete currency pair.
			if ( $order ) {
				$curcy = $order->get_meta( 'wmc_order_info' ); $rate = self::table_rate( $curcy, $from, $to );
				if ( $rate && in_array( $mode, array( 'auto', 'curcy' ), true ) ) { $candidates['curcy-order'] = $rate; }
				$base = $order->get_meta( '_woocs_order_base_currency' ); $fox_rate = $order->get_meta( '_woocs_order_rate' );
				if ( $to === $base && self::valid_rate( $fox_rate ) && in_array( $mode, array( 'auto', 'fox' ), true ) ) { $candidates['fox-order'] = 1 / (float) $fox_rate; }
			}
			if ( ! $candidates ) {
				global $WOOCS;
				if ( in_array( $mode, array( 'auto', 'fox' ), true ) && is_object( $WOOCS ) && is_callable( array( $WOOCS, 'get_currencies' ) ) ) {
					$rate = self::table_rate( $WOOCS->get_currencies(), $from, $to ); if ( $rate ) { $candidates['fox'] = $rate; }
				}
				foreach ( array( 'WOOMULTI_CURRENCY_Data', 'WOOMULTI_CURRENCY_F_Data' ) as $class ) {
					if ( ! in_array( $mode, array( 'auto', 'curcy' ), true ) || ! is_callable( array( $class, 'get_ins' ) ) ) { continue; }
					$settings = $class::get_ins();
					if ( ! is_callable( array( $settings, 'get_enable' ) ) || ! $settings->get_enable() || ! is_callable( array( $settings, 'get_list_currencies' ) ) ) { continue; }
					$rate = self::table_rate( $settings->get_list_currencies(), $from, $to ); if ( $rate ) { $candidates['curcy'] = $rate; } break;
				}
			}
			if ( $candidates ) {
				$rate = reset( $candidates );
				foreach ( $candidates as $other ) { if ( abs( $rate - $other ) > max( 0.000000000001, abs( $rate ) * 0.000001 ) ) { return new WP_Error( 'currency', __( 'Currency providers disagree. Choose FOX or CURCY explicitly in Affiliate WPM Settings.', 'affiliate-wpm' ) ); } }
				return array( 'rate' => $rate, 'source' => key( $candidates ) );
			}
			if ( 'auto' !== $mode ) { return new WP_Error( 'currency', __( 'The selected currency provider has no valid rate for this currency pair.', 'affiliate-wpm' ) ); }
		}
		$manual = array();
		foreach ( explode( "\n", self::clean_rates( AWPM_Settings::get( 'exchange_rates' ) ) ) as $line ) { $parts = explode( ' ', $line ); if ( 3 === count( $parts ) ) { $manual[ $parts[0] . ' ' . $parts[1] ] = (float) $parts[2]; } }
		if ( isset( $manual[ $from . ' ' . $to ] ) ) { return array( 'rate' => $manual[ $from . ' ' . $to ], 'source' => 'manual' ); }
		if ( isset( $manual[ $to . ' ' . $from ] ) ) { return array( 'rate' => 1 / $manual[ $to . ' ' . $from ], 'source' => 'manual-inverse' ); }
		return new WP_Error( 'currency', sprintf( __( 'No exchange rate for %1$s to %2$s. Configure FOX/CURCY or a manual rate in Affiliate WPM Settings.', 'affiliate-wpm' ), $from, $to ) );
	}
	public static function snapshot( $order, $legacy = false ) {
		$from = $order->get_currency(); $to = $order->get_meta( '_awpm_currency' ); $saved = $order->get_meta( '_awpm_fx' );
		if ( is_array( $saved ) && $saved ) {
			if ( ( $saved['from'] ?? '' ) !== $from || ( $saved['to'] ?? '' ) !== $to || ! self::valid_rate( $saved['rate'] ?? null ) ) { return new WP_Error( 'currency', __( 'Order currency changed after the affiliate exchange rate was saved. Review the order before recalculating.', 'affiliate-wpm' ) ); }
			return $saved;
		}
		$known = get_woocommerce_currencies();
		if ( ! isset( $known[ $from ], $known[ $to ] ) ) { return new WP_Error( 'currency', __( 'Invalid order or affiliate currency.', 'affiliate-wpm' ) ); }
		try { $result = self::rate( $from, $to, $order ); } catch ( Throwable $error ) { $result = new WP_Error( 'currency', __( 'The currency provider could not supply a rate. Review the exchange settings.', 'affiliate-wpm' ) ); }
		if ( is_wp_error( $result ) ) { return $result; }
		$saved = array( 'from' => $from, 'to' => $to, 'rate' => (float) $result['rate'], 'source' => $result['source'], 'precision' => self::precision( $to ), 'version' => $legacy ? 1 : 2, 'created_at' => current_time( 'mysql', true ) );
		$order->update_meta_data( '_awpm_fx', $saved ); $order->delete_meta_data( '_awpm_fx_error' );
		return $saved;
	}
	public static function issue( $order, $error ) {
		$message = $error->get_error_message();
		if ( $message !== $order->get_meta( '_awpm_fx_error' ) ) { $order->update_meta_data( '_awpm_fx_error', $message ); $order->save_meta_data(); $order->add_order_note( 'Affiliate WPM: ' . $message ); }
	}
	/** Stored affiliate amounts must not pass through a visitor's shop conversion filters. */
	public static function money( $amount, $currency ) {
		$decimals = self::precision( $currency );
		$number = number_format( abs( (float) $amount ), $decimals, (string) get_option( 'woocommerce_price_decimal_sep', '.' ), (string) get_option( 'woocommerce_price_thousand_sep', ',' ) );
		return '<span class="woocommerce-Price-amount amount"><bdi>' . ( $amount < 0 ? '-' : '' ) . '<span class="woocommerce-Price-currencySymbol">' . esc_html( $currency ) . '</span>&nbsp;' . esc_html( $number ) . '</bdi></span>';
	}
	public static function retry_order( $id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'affiliate-wpm' ) ); }
		global $wpdb; $order = wc_get_order( absint( $id ) );
		if ( ! $order || $order instanceof WC_Order_Refund || ! $order->get_meta( '_awpm_affiliate' ) || ! $order->get_meta( '_awpm_rates' ) ) { return new WP_Error( 'order', __( 'This order has no saved affiliate attribution.', 'affiliate-wpm' ) ); }
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . AWPM_Store::table( 'commissions' ) . ' WHERE order_id=%d', $order->get_id() ) ) ) { return new WP_Error( 'exists', __( 'This order already has a commission record. Its financial history was left unchanged.', 'affiliate-wpm' ) ); }
		return AWPM_Commissions::sync( $order, true );
	}
	public static function recover() {
		AWPM_Security::admin_request( 'awpm_currency_recovery' );
		$result = self::retry_order( AWPM_Security::value( $_POST, 'order_id' ) );
		set_transient( 'awpm_currency_result_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : __( 'Order processed using its saved attribution and exchange rate. Check Commissions for its status.', 'affiliate-wpm' ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=settings' ) ); exit;
	}
	public static function recovery_form() {
		$message = get_transient( 'awpm_currency_result_' . get_current_user_id() ); if ( $message ) { delete_transient( 'awpm_currency_result_' . get_current_user_id() ); echo '<p class="awpm-notice">' . esc_html( $message ) . '</p>'; }
		echo '<section class="awpm-card"><h2>' . esc_html__( 'Recover a missing currency commission', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Save valid exchange settings first. Only orders with saved affiliate attribution and no commission record can be processed. Historical provider rates are used when available; otherwise the current configured rate is saved. Existing paid or reserved records are never replaced.', 'affiliate-wpm' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_currency_recovery">';
		wp_nonce_field( 'awpm_currency_recovery' );
		echo '<div class="awpm-copy"><label for="awpm-recovery-order" class="screen-reader-text">' . esc_html__( 'Order ID', 'affiliate-wpm' ) . '</label><input id="awpm-recovery-order" name="order_id" type="number" min="1" required placeholder="' . esc_attr__( 'Order ID', 'affiliate-wpm' ) . '"><button class="button awpm-primary">' . esc_html__( 'Recover commission', 'affiliate-wpm' ) . '</button></div></form></section>';
	}
}
