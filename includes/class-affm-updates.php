<?php
defined( 'ABSPATH' ) || exit;
/** Free updates are exclusively handled by WordPress.org. */
final class AFFM_Updates {
	public static function init() {
	}
	public static function native_only( $transient ) {
		$file = plugin_basename( AFFM_FILE );
		if ( is_object( $transient ) && isset( $transient->response[ $file ] ) ) {
			$package = $transient->response[ $file ]->package ?? '';
			if ( $package && 'downloads.wordpress.org' !== wp_parse_url( $package, PHP_URL_HOST ) ) { unset( $transient->response[ $file ] ); }
		}
		return $transient;
	}
	public static function clear() { if ( class_exists( 'AFFM_Pro_Updates', false ) ) { AFFM_Pro_Updates::clear(); } }
	public static function manual_check() {
		if ( class_exists( 'AFFM_Pro_Updates', false ) ) { return AFFM_Pro_Updates::manual_check(); }
		if ( ! current_user_can( 'update_plugins' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'affmint' ) ); }
		wp_update_plugins(); return __( 'WordPress update check requested. Open Dashboard → Updates to review available releases.', 'affmint' );
	}
}