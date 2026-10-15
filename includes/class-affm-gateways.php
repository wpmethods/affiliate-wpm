<?php
defined( 'ABSPATH' ) || exit;

/** Provider extension bridge; no automatic payment implementation ships in Free. */
final class AFFM_Gateways {
	const OPTION = 'affm_payment_gateways';
	const HOOK = 'affm_process_gateway_payout';
	public static function init() { if ( class_exists( 'AFFM_Pro_Gateways', false ) ) { AFFM_Pro_Gateways::init(); } }
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'AFFM_Pro_Gateways', false ) && is_callable( array( 'AFFM_Pro_Gateways', $name ) ) ) { return call_user_func_array( array( 'AFFM_Pro_Gateways', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Automatic payouts require the separate AffMint Pro add-on.', 'affmint' ) );
	}
	public static function ready( $provider, $config = null ) { return class_exists( 'AFFM_Pro_Gateways', false ) && AFFM_Pro_Gateways::ready( $provider, $config ); }
	public static function methods() { return class_exists( 'AFFM_Pro_Gateways', false ) ? AFFM_Pro_Gateways::methods() : array(); }
	public static function job( $id ) { wp_cache_delete( 'affm_gateway_job_' . absint( $id ), 'options' ); return (array) get_option( 'affm_gateway_job_' . absint( $id ), array() ); }
	public static function connection_ui( $uid ) { if ( class_exists( 'AFFM_Pro_Gateways', false ) ) { AFFM_Pro_Gateways::connection_ui( $uid ); } }
	public static function summary( $id ) {
		$job = self::job( $id ); if ( empty( $job['provider'] ) ) { return; }
		echo '<small class="affm-note">' . esc_html( ( 'live' === ( $job['mode'] ?? '' ) ? 'Live' : 'Sandbox/Test' ) . ' · ' . ( $job['currency'] ?? '' ) . ' ' . ( $job['amount'] ?? '' ) ) . '</small>';
		if ( ! empty( $job['remote_id'] ) && current_user_can( 'manage_woocommerce' ) ) { echo '<small class="affm-note">' . esc_html( 'Provider reference: ' . $job['remote_id'] ) . '</small>'; }
	}
	public static function fields() {
		if ( AFFM_License::active() && class_exists( 'AFFM_Pro_Gateways', false ) ) { AFFM_Pro_Gateways::fields(); return; }
		echo '<div class="affm-gateway-settings affm-premium-card"><h3>' . wp_kses( AFFM_UI::icon( 'lock' ), AFFM_UI::allowed_html() ) . esc_html__( 'Automatic payouts', 'affmint' ) . ' ' . wp_kses_post( AFFM_License::premium_badge() ) . '</h3><p class="affm-muted">' . esc_html__( 'PayPal Payouts and Stripe Connect are provided by the separate Pro add-on. Free supports one manual payment method.', 'affmint' ) . '</p><p>' . wp_kses_post( AFFM_License::premium_link() ) . '</p></div>';
	}
}