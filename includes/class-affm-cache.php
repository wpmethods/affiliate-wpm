<?php
 defined( 'ABSPATH' ) || exit;

/** Request protection; cache/CDN hits that skip PHP still require server exclusions. */
final class AFFM_Cache {
	public static function init() {
		self::early();
		add_action( 'init', array( __CLASS__, 'early' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'protect' ), 0 );
		add_filter( 'litespeed_optm_js_defer_exc', array( __CLASS__, 'scripts' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'scripts' ) );
	}
	public static function early() {
		if ( '' !== AFFM_Settings::referral_request_key() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This compatibility name is defined by WordPress/LiteSpeed and must match the external cache integration API.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			if ( did_action( 'plugins_loaded' ) ) { self::no_cache( 'AffMint referral landing' ); }
		}
	}
	public static function protect() {
		if ( is_admin() ) { return; }
		if ( '' !== AFFM_Settings::referral_request_key() ) { self::no_cache( 'AffMint referral landing' ); return; }
		if ( function_exists( 'is_account_page' ) && ( is_account_page() || is_cart() || is_checkout() ) ) { self::no_cache( 'AffMint account or checkout' ); return; }
		if ( function_exists( 'is_product' ) && is_product() && is_user_logged_in() && 'yes' === AFFM_Sharing::get( 'enabled' ) && 'yes' === AFFM_Sharing::get( 'product_section' ) ) { self::no_cache( 'AffMint personalized product sharing' ); }
	}
	public static function no_cache( $reason = 'AffMint private content' ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This compatibility name is defined by WordPress/LiteSpeed and must match the external cache integration API.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This compatibility name is defined by WordPress/LiteSpeed and must match the external cache integration API.
		do_action( 'litespeed_control_set_nocache', $reason );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		}
	}
	public static function scripts( $list ) {
		$list = is_array( $list ) ? $list : array();
		$path = (string) wp_parse_url( AFFM_URL, PHP_URL_PATH );
		foreach ( array( 'account.js', 'sharing.js' ) as $file ) { $list[] = $path . 'assets/' . $file; }
		// Localized configuration must run before the corresponding scripts.
		$list[] = 'affmSharing';
		return array_values( array_unique( $list ) );
	}
}
