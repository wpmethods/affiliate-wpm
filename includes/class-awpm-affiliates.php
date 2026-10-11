<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Affiliates {
	public static function status( $id ) {
		if ( 'yes' !== get_user_meta( $id, '_awpm_enrolled', true ) ) { return 'none'; }
		$status = get_user_meta( $id, '_awpm_approval', true );
		return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : 'approved';
	}
	public static function join( $id ) {
		if ( ! $id || $id !== get_current_user_id() || ! get_userdata( $id ) ) { return new WP_Error( 'access', __( 'Please sign in to join.', 'affiliate-wpm' ) ); }
		return AWPM_Store::locked( $id, function () use ( $id ) {
			wp_cache_delete( $id, 'user_meta' );
			if ( 'yes' !== AWPM_Settings::get( 'enabled' ) || 'yes' === get_user_meta( $id, '_awpm_blocked', true ) ) { return new WP_Error( 'unavailable', __( 'Affiliate enrollment is currently unavailable for your account.', 'affiliate-wpm' ) ); }
			$status = self::status( $id );
			if ( 'none' !== $status ) { return $status; }
			$status = 'yes' === AWPM_Settings::get( 'approval_required' ) ? 'pending' : 'approved';
			update_user_meta( $id, '_awpm_approval', $status );
			update_user_meta( $id, '_awpm_enrolled', 'yes' );
			$token = AWPM_Tracking::token( $id );
			if ( self::status( $id ) !== $status || ! $token ) { return new WP_Error( 'save', __( 'Your affiliate account could not be saved. Please try again.', 'affiliate-wpm' ) ); }
			AWPM_Emails::queue( 'joined_admin', $id, array( 'status' => $status ) );
			AWPM_Emails::queue( 'joined_affiliate', $id, array( 'status' => $status ) );
			return $status;
		} );
	}
	public static function review( $id, $status ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'access', __( 'Access denied.', 'affiliate-wpm' ) ); }
		return AWPM_Store::locked( $id, function () use ( $id, $status ) {
			wp_cache_delete( $id, 'user_meta' );
			$previous = self::status( $id );
			$allowed = array( 'pending' => array( 'approved', 'rejected' ), 'rejected' => array( 'approved' ) );
			if ( ! get_userdata( $id ) || ! in_array( $status, $allowed[ $previous ] ?? array(), true ) ) { return new WP_Error( 'state', __( 'This application cannot move to that status. Refresh the page.', 'affiliate-wpm' ) ); }
			update_user_meta( $id, '_awpm_approval', $status );
			if ( self::status( $id ) !== $status ) { return new WP_Error( 'save', __( 'Approval could not be saved.', 'affiliate-wpm' ) ); }
			AWPM_Emails::queue( $status . '_affiliate', $id, array( 'status' => $status ) );
			return true;
		} );
	}
}
