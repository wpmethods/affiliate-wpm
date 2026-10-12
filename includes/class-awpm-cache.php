<?php
 defined( 'ABSPATH' ) || exit;

/** Request protection; cache/CDN hits that skip PHP still require server exclusions. */
final class AWPM_Cache {
	public static function init() {
		self::early();
		add_action( 'init', array( __CLASS__, 'early' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'protect' ), 0 );
		add_filter( 'litespeed_optm_js_defer_exc', array( __CLASS__, 'scripts' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'scripts' ) );
	}
	public static function early() {
		if ( isset( $_GET['awpm_ref'] ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			if ( did_action( 'plugins_loaded' ) ) { self::no_cache( 'Affiliate WPM referral landing' ); }
		}
	}
	public static function protect() {
		if ( is_admin() ) { return; }
		if ( isset( $_GET['awpm_ref'] ) ) { self::no_cache( 'Affiliate WPM referral landing' ); return; }
		if ( function_exists( 'is_account_page' ) && ( is_account_page() || is_cart() || is_checkout() ) ) { self::no_cache( 'Affiliate WPM account or checkout' ); return; }
		if ( function_exists( 'is_product' ) && is_product() && is_user_logged_in() && 'yes' === AWPM_Sharing::get( 'enabled' ) && 'yes' === AWPM_Sharing::get( 'product_section' ) ) { self::no_cache( 'Affiliate WPM personalized product sharing' ); }
	}
	public static function no_cache( $reason = 'Affiliate WPM private content' ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', $reason );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		}
	}
	public static function scripts( $list ) {
		$list = is_array( $list ) ? $list : array();
		$path = (string) wp_parse_url( AWPM_URL, PHP_URL_PATH );
		foreach ( array( 'account.js', 'sharing.js' ) as $file ) { $list[] = $path . 'assets/' . $file; }
		// Localized configuration must run before the corresponding scripts.
		$list[] = 'awpmSharing';
		return array_values( array_unique( $list ) );
	}
}
