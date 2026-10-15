<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Currency {
	public static function valid_rate( $rate ) { return is_scalar( $rate ) && is_numeric( $rate ) && is_finite( (float) $rate ) && (float) $rate >= 0.000000000001 && (float) $rate <= 1000000000000; }
	public static function precision( $currency ) {
		if ( in_array( $currency, array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ), true ) ) { return 3; }
		return in_array( $currency, array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), true ) ? 0 : max( 0, min( 6, (int) get_option( 'woocommerce_price_num_decimals', 2 ) ) );
	}
	public static function clean_rates( $text ) { return apply_filters( 'affm_clean_exchange_rates', '', $text ); }

	public static function rate( $from, $to, $order = null ) {
		if ( $from === $to ) { return array( 'rate' => 1, 'source' => 'same-currency' ); }
		return apply_filters( 'affm_exchange_rate', new WP_Error( 'currency', __( 'Free uses the WooCommerce base currency. Cross-currency commissions require the Pro add-on.', 'affmint' ) ), $from, $to, $order );
	}
	public static function snapshot( $order, $legacy = false ) {
		$from = $order->get_currency(); $to = $order->get_meta( '_affm_currency' ); $saved = $order->get_meta( '_affm_fx' );
		if ( is_array( $saved ) && $saved ) {
			if ( ( $saved['from'] ?? '' ) !== $from || ( $saved['to'] ?? '' ) !== $to || ! self::valid_rate( $saved['rate'] ?? null ) ) { return new WP_Error( 'currency', __( 'Order currency changed after the affiliate exchange rate was saved. Review the order before recalculating.', 'affmint' ) ); }
			return $saved;
		}
		$known = get_woocommerce_currencies();
		if ( ! isset( $known[ $from ], $known[ $to ] ) ) { return new WP_Error( 'currency', __( 'Invalid order or affiliate currency.', 'affmint' ) ); }
		try { $result = self::rate( $from, $to, $order ); } catch ( Throwable $error ) { $result = new WP_Error( 'currency', __( 'The currency provider could not supply a rate. Review the exchange settings.', 'affmint' ) ); }
		if ( is_wp_error( $result ) ) { return $result; }
		$saved = array( 'from' => $from, 'to' => $to, 'rate' => (float) $result['rate'], 'source' => $result['source'], 'precision' => self::precision( $to ), 'version' => $legacy ? 1 : 2, 'created_at' => current_time( 'mysql', true ) );
		$order->update_meta_data( '_affm_fx', $saved ); $order->delete_meta_data( '_affm_fx_error' );
		return $saved;
	}
	public static function issue( $order, $error ) {
		$message = $error->get_error_message();
		if ( $message !== $order->get_meta( '_affm_fx_error' ) ) { $order->update_meta_data( '_affm_fx_error', $message ); $order->save_meta_data(); $order->add_order_note( 'AffMint: ' . $message ); }
	}
	/** Stored affiliate amounts must not pass through a visitor's shop conversion filters. */
	public static function money( $amount, $currency ) {
		$decimals = self::precision( $currency );
		$number = number_format( abs( (float) $amount ), $decimals, (string) get_option( 'woocommerce_price_decimal_sep', '.' ), (string) get_option( 'woocommerce_price_thousand_sep', ',' ) );
		return '<span class="woocommerce-Price-amount amount"><bdi>' . ( $amount < 0 ? '-' : '' ) . '<span class="woocommerce-Price-currencySymbol">' . esc_html( $currency ) . '</span>&nbsp;' . esc_html( $number ) . '</bdi></span>';
	}
	public static function retry_order( $id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'affmint' ) ); }
		global $wpdb; $order = wc_get_order( absint( $id ) );
		if ( ! $order || $order instanceof WC_Order_Refund || ! $order->get_meta( '_affm_affiliate' ) || ! $order->get_meta( '_affm_rates' ) ) { return new WP_Error( 'order', __( 'This order has no saved affiliate attribution.', 'affmint' ) ); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id=%d', AFFM_Store::table( 'commissions' ), $order->get_id() ) ) ) { return new WP_Error( 'exists', __( 'This order already has a commission record. Its financial history was left unchanged.', 'affmint' ) ); }
		return AFFM_Commissions::sync( $order, true );
	}
	public static function recover() {
		AFFM_Security::admin_request( 'affm_currency_recovery' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$result = self::retry_order( AFFM_Security::value( $_POST, 'order_id' ) );
		set_transient( 'affm_currency_result_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : __( 'Order processed using its saved attribution and exchange rate. Check Commissions for its status.', 'affmint' ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=affmint&tab=settings' ) ); exit;
	}
	public static function recovery_form() {
		$message = get_transient( 'affm_currency_result_' . get_current_user_id() ); if ( $message ) { delete_transient( 'affm_currency_result_' . get_current_user_id() ); echo '<p class="affm-notice">' . esc_html( $message ) . '</p>'; }
		echo '<section class="affm-card"><h2>' . esc_html__( 'Recover a missing currency commission', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Save valid exchange settings first. Only orders with saved affiliate attribution and no commission record can be processed. Historical provider rates are used when available; otherwise the current configured rate is saved. Existing paid or reserved records are never replaced.', 'affmint' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="affm_currency_recovery">';
		wp_nonce_field( 'affm_currency_recovery' );
		echo '<div class="affm-copy"><label for="affm-recovery-order" class="screen-reader-text">' . esc_html__( 'Order ID', 'affmint' ) . '</label><input id="affm-recovery-order" name="order_id" type="number" min="1" required placeholder="' . esc_attr__( 'Order ID', 'affmint' ) . '"><button class="button affm-primary">' . esc_html__( 'Recover commission', 'affmint' ) . '</button></div></form></section>';
	}
}
