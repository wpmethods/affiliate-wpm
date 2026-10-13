<?php
defined( 'ABSPATH' ) || exit;
/** Compatibility bridge for the separately installed Stripe integration. */
final class AWPM_Stripe {
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'AWPM_Pro_Stripe', false ) && is_callable( array( 'AWPM_Pro_Stripe', $name ) ) ) { return call_user_func_array( array( 'AWPM_Pro_Stripe', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Stripe is available in the separate Affiliate WPM Pro add-on.', 'affiliate-wpm' ) );
	}
}