<?php
defined( 'ABSPATH' ) || exit;
/** Compatibility bridge for the separately installed Stripe integration. */
final class REFM_Stripe {
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'REFM_Pro_Stripe', false ) && is_callable( array( 'REFM_Pro_Stripe', $name ) ) ) { return call_user_func_array( array( 'REFM_Pro_Stripe', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Stripe is available in the separate Refmora Pro add-on.', 'refmora' ) );
	}
}