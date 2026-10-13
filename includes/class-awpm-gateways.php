<?php
defined( 'ABSPATH' ) || exit;

/** Provider extension bridge; no automatic payment implementation ships in Free. */
final class AWPM_Gateways {
	const OPTION = 'awpm_payment_gateways';
	const HOOK = 'awpm_process_gateway_payout';
	public static function init() { if ( class_exists( 'AWPM_Pro_Gateways', false ) ) { AWPM_Pro_Gateways::init(); } }
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'AWPM_Pro_Gateways', false ) && is_callable( array( 'AWPM_Pro_Gateways', $name ) ) ) { return call_user_func_array( array( 'AWPM_Pro_Gateways', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Automatic payouts require the separate Affiliate WPM Pro add-on.', 'affiliate-wpm' ) );
	}
	public static function ready( $provider, $config = null ) { return class_exists( 'AWPM_Pro_Gateways', false ) && AWPM_Pro_Gateways::ready( $provider, $config ); }
	public static function methods() { return class_exists( 'AWPM_Pro_Gateways', false ) ? AWPM_Pro_Gateways::methods() : array(); }
	public static function job( $id ) { wp_cache_delete( 'awpm_gateway_job_' . absint( $id ), 'options' ); return (array) get_option( 'awpm_gateway_job_' . absint( $id ), array() ); }
	public static function connection_ui( $uid ) { if ( class_exists( 'AWPM_Pro_Gateways', false ) ) { AWPM_Pro_Gateways::connection_ui( $uid ); } }
	public static function summary( $id ) {
		$job = self::job( $id ); if ( empty( $job['provider'] ) ) { return; }
		echo '<small class="awpm-note">' . esc_html( ( 'live' === ( $job['mode'] ?? '' ) ? 'Live' : 'Sandbox/Test' ) . ' · ' . ( $job['currency'] ?? '' ) . ' ' . ( $job['amount'] ?? '' ) ) . '</small>';
		if ( ! empty( $job['remote_id'] ) && current_user_can( 'manage_woocommerce' ) ) { echo '<small class="awpm-note">' . esc_html( 'Provider reference: ' . $job['remote_id'] ) . '</small>'; }
	}
	public static function fields() {
		if ( AWPM_License::active() && class_exists( 'AWPM_Pro_Gateways', false ) ) { AWPM_Pro_Gateways::fields(); return; }
		echo '<div class="awpm-gateway-settings awpm-premium-card"><h3>' . wp_kses( AWPM_UI::icon( 'lock' ), AWPM_UI::allowed_html() ) . esc_html__( 'Automatic payouts', 'affiliate-wpm' ) . ' ' . wp_kses_post( AWPM_License::premium_badge() ) . '</h3><p class="awpm-muted">' . esc_html__( 'PayPal Payouts and Stripe Connect are provided by the separate Pro add-on. Free supports one manual payment method.', 'affiliate-wpm' ) . '</p><p>' . wp_kses_post( AWPM_License::premium_link() ) . '</p></div>';
	}
}