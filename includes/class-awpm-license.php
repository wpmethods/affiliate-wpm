<?php
defined( 'ABSPATH' ) || exit;

/** Optional add-on bridge. The Free core never validates a remote license. */
final class AWPM_License {
	const STATE = 'awpm_license_state';
	const CREDENTIALS = 'awpm_license_credentials';
	const CRON = 'awpm_license_revalidate';
	public static function init() { add_action( 'admin_post_awpm_license', array( __CLASS__, 'submit' ) ); }
	public static function active() { return class_exists( 'AWPM_Pro_License', false ) && AWPM_Pro_License::active(); }
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'AWPM_Pro_License', false ) && is_callable( array( 'AWPM_Pro_License', $name ) ) ) { return call_user_func_array( array( 'AWPM_Pro_License', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Install the separate Affiliate WPM Pro add-on to manage a premium license.', 'affiliate-wpm' ) );
	}
	public static function premium_badge() { return '<span class="awpm-premium-badge">' . esc_html__( 'Premium Feature', 'affiliate-wpm' ) . '</span>'; }
	public static function premium_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=license' ) ) . '">' . esc_html( class_exists( 'AWPM_Pro_License', false ) ? __( 'Activate Pro license', 'affiliate-wpm' ) : __( 'Explore Pro add-on', 'affiliate-wpm' ) ) . '</a>';
	}
	public static function premium_card( $title, $description ) {
		echo '<section class="awpm-card awpm-premium-card"><h2>' . wp_kses( AWPM_UI::icon( 'lock' ), AWPM_UI::allowed_html() ) . esc_html( $title ) . ' ' . wp_kses_post( self::premium_badge() ) . '</h2><p class="awpm-muted">' . esc_html( $description ) . '</p><p>' . wp_kses_post( self::premium_link() ) . '</p></section>';
	}
	public static function activation_notice( $inline = false ) {
		if ( ! $inline || ! current_user_can( 'manage_woocommerce' ) || self::active() ) { return; }
		echo '<div class="awpm-notice awpm-free-notice" role="status"><p><strong>' . esc_html__( 'Free mode', 'affiliate-wpm' ) . '</strong> ' . esc_html__( 'Your affiliate program and settings work without a license. The separate Pro add-on provides automatic payouts, currency conversion, CSV exports and additional manual methods.', 'affiliate-wpm' ) . '</p></div>';
	}
	public static function submit() {
		if ( class_exists( 'AWPM_Pro_License', false ) ) { AWPM_Pro_License::submit(); return; }
		AWPM_Security::admin_request( 'awpm_license' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), '', array( 'response' => 403 ) ); }
		wp_die( esc_html__( 'Free does not require activation. Install Pro separately to activate premium features.', 'affiliate-wpm' ), '', array( 'response' => 403 ) );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( class_exists( 'AWPM_Pro_License', false ) ) { AWPM_Pro_License::render(); return; }
		echo '<section class="awpm-card"><h2>' . esc_html__( 'Affiliate WPM Free', 'affiliate-wpm' ) . '</h2><p>' . esc_html__( 'No license is needed for referrals, commissions, sharing, enrollment, settings, one manual payout method and settings backups.', 'affiliate-wpm' ) . '</p><h3>' . esc_html__( 'Optional Pro add-on', 'affiliate-wpm' ) . '</h3><p>' . esc_html__( 'Install Pro separately to add PayPal/Stripe payouts, exchange rates, CSV exports and more manual payout methods. It uses your existing accounts and financial records.', 'affiliate-wpm' ) . '</p><a class="button awpm-primary" href="https://wpmethods.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about Affiliate WPM Pro', 'affiliate-wpm' ) . '</a></section><section class="awpm-card"><h2>' . esc_html__( 'Plugin updates', 'affiliate-wpm' ) . '</h2><p>' . esc_html__( 'Free core updates use WordPress.org and work without a license after directory publication. Pro add-on updates are separate.', 'affiliate-wpm' ) . '</p><a class="button awpm-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'WordPress Updates', 'affiliate-wpm' ) . '</a></section>';
	}
}