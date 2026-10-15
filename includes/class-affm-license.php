<?php
defined( 'ABSPATH' ) || exit;

/** Optional add-on bridge. The Free core never validates a remote license. */
final class AFFM_License {
	const STATE = 'affm_license_state';
	const CREDENTIALS = 'affm_license_credentials';
	const CRON = 'affm_license_revalidate';
	public static function init() { add_action( 'admin_post_affm_license', array( __CLASS__, 'submit' ) ); }
	public static function active() { return class_exists( 'AFFM_Pro_License', false ) && AFFM_Pro_License::active(); }
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'AFFM_Pro_License', false ) && is_callable( array( 'AFFM_Pro_License', $name ) ) ) { return call_user_func_array( array( 'AFFM_Pro_License', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Install the separate AffMint Pro add-on to manage a premium license.', 'affmint' ) );
	}
	public static function premium_badge() { if ( self::active() ) { return ''; } return '<span class="affm-premium-badge">' . esc_html__( 'Premium Feature', 'affmint' ) . '</span>'; }
	public static function premium_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=affmint&tab=license' ) ) . '">' . esc_html( class_exists( 'AFFM_Pro_License', false ) ? __( 'Activate Pro license', 'affmint' ) : __( 'Explore Pro add-on', 'affmint' ) ) . '</a>';
	}
	public static function premium_card( $title, $description ) {
		echo '<section class="affm-card affm-premium-card"><h2>' . wp_kses( AFFM_UI::icon( 'lock' ), AFFM_UI::allowed_html() ) . esc_html( $title ) . ' ' . wp_kses_post( self::premium_badge() ) . '</h2><p class="affm-muted">' . esc_html( $description ) . '</p><p>' . wp_kses_post( self::premium_link() ) . '</p></section>';
	}
	public static function activation_notice( $inline = false ) {
		if ( ! $inline || ! current_user_can( 'manage_woocommerce' ) || self::active() ) { return; }
		echo '<div class="affm-notice affm-free-notice" role="status"><p><strong>' . esc_html__( 'Free mode', 'affmint' ) . '</strong> ' . esc_html__( 'Your affiliate program and settings work without a license. The separate Pro add-on provides automatic payouts, currency conversion, CSV exports and additional manual methods.', 'affmint' ) . '</p></div>';
	}
	public static function submit() {
		if ( class_exists( 'AFFM_Pro_License', false ) ) { AFFM_Pro_License::submit(); return; }
		AFFM_Security::admin_request( 'affm_license' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'affmint' ), '', array( 'response' => 403 ) ); }
		wp_die( esc_html__( 'Free does not require activation. Install Pro separately to activate premium features.', 'affmint' ), '', array( 'response' => 403 ) );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( class_exists( 'AFFM_Pro_License', false ) ) { AFFM_Pro_License::render(); return; }
		echo '<section class="affm-card"><h2>' . esc_html__( 'AffMint Free', 'affmint' ) . '</h2><p>' . esc_html__( 'No license is needed for referrals, commissions, sharing, enrollment, settings, one manual payout method and settings backups.', 'affmint' ) . '</p><h3>' . esc_html__( 'Optional Pro add-on', 'affmint' ) . '</h3><p>' . esc_html__( 'Install Pro separately to add PayPal/Stripe payouts, exchange rates, CSV exports and more manual payout methods. It uses your existing accounts and financial records.', 'affmint' ) . '</p><a class="button affm-primary" href="https://wpmethods.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about AffMint Pro', 'affmint' ) . '</a></section><section class="affm-card"><h2>' . esc_html__( 'Plugin updates', 'affmint' ) . '</h2><p>' . esc_html__( 'Free core updates use WordPress.org and work without a license after directory publication. Pro add-on updates are separate.', 'affmint' ) . '</p><a class="button affm-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'WordPress Updates', 'affmint' ) . '</a></section>';
	}
}