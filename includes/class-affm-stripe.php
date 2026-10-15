<?php
defined( 'ABSPATH' ) || exit;
/** Compatibility bridge for the separately installed Stripe integration. */
final class AFFM_Stripe {
	public static function __callStatic( $name, $args ) {
		if ( class_exists( 'AFFM_Pro_Stripe', false ) && is_callable( array( 'AFFM_Pro_Stripe', $name ) ) ) { return call_user_func_array( array( 'AFFM_Pro_Stripe', $name ), $args ); }
		return new WP_Error( 'addon', __( 'Stripe is available in the separate AffMint Pro add-on.', 'affmint' ) );
	}
}