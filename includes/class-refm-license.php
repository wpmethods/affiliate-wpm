<?php
defined( 'ABSPATH' ) || exit;

/** Optional add-on bridge. The Free core never validates a remote license. */
final class REFM_License {
	const STATE = 'refm_license_state';
	const CREDENTIALS = 'refm_license_credentials';
	const CRON = 'refm_license_revalidate';
	public static function init() { add_action( 'admin_post_refm_license', array( __CLASS__, 'submit' ) ); }
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'REFM_Pro_License', false ) && is_callable( array( 'REFM_Pro_License', $name ) ) ) { return call_user_func_array( array( 'REFM_Pro_License', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Install the separate Refmora Pro add-on to manage a premium license.', 'refmora' ) );
	}
	public static function premium_badge() { return '<span class="refm-premium-badge">' . esc_html__( 'Premium Feature', 'refmora' ) . '</span>'; }
	public static function premium_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=refmora&tab=license' ) ) . '">' . esc_html( class_exists( 'REFM_Pro_License', false ) ? __( 'Activate Pro license', 'refmora' ) : __( 'Explore Pro add-on', 'refmora' ) ) . '</a>';
	}
	public static function premium_card( $title, $description ) {
		echo '<section class="refm-card refm-premium-card"><h2>' . wp_kses( REFM_UI::icon( 'lock' ), REFM_UI::allowed_html() ) . esc_html( $title ) . ' ' . wp_kses_post( self::premium_badge() ) . '</h2><p class="refm-muted">' . esc_html( $description ) . '</p><p>' . wp_kses_post( self::premium_link() ) . '</p></section>';
	}
	public static function activation_notice( $inline = false ) {
		if ( ! $inline || ! current_user_can( 'manage_woocommerce' ) || class_exists( 'REFM_Pro_License', false ) ) { return; }
		echo '<div class="refm-notice refm-free-notice" role="status"><p><strong>' . esc_html__( 'Free mode', 'refmora' ) . '</strong> ' . esc_html__( 'Your affiliate program and settings work without a license. The separate Pro add-on provides automatic payouts, currency conversion, CSV exports.', 'refmora' ) . '</p></div>';
	}
	public static function submit() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'Use the license form.', 'refmora' ), '', array( 'response' => 405 ) ); }
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'refmora' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'refm_license' );
		if ( class_exists( 'REFM_Pro_License', false ) ) { REFM_Pro_License::submit(); return; }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), '', array( 'response' => 403 ) ); }
		wp_die( esc_html__( 'Free does not require activation. Install Pro separately to activate premium features.', 'refmora' ), '', array( 'response' => 403 ) );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( class_exists( 'REFM_Pro_License', false ) ) { REFM_Pro_License::render(); return; }
		echo '<section class="refm-card"><h2>' . esc_html__( 'Refmora Free', 'refmora' ) . '</h2><p>' . esc_html__( 'No license is needed for referrals, commissions, sharing, enrollment, settings, manual payout methods and settings backups.', 'refmora' ) . '</p><h3>' . esc_html__( 'Optional Pro add-on', 'refmora' ) . '</h3><p>' . esc_html__( 'Install Pro separately to add PayPal/Stripe payouts, exchange rates, CSV exports. It uses your existing accounts and financial records.', 'refmora' ) . '</p><a class="button refm-primary" href="https://wpmethods.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn about Refmora Pro', 'refmora' ) . '</a></section><section class="refm-card"><h2>' . esc_html__( 'Plugin updates', 'refmora' ) . '</h2><p>' . esc_html__( 'Free core updates use WordPress.org and work without a license after directory publication. Pro add-on updates are separate.', 'refmora' ) . '</p><a class="button refm-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'WordPress Updates', 'refmora' ) . '</a></section>';
	}
}