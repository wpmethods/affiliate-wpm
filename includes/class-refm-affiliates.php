<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Affiliates {
	public static function status( $id ) {
		if ( 'yes' !== get_user_meta( $id, '_refm_enrolled', true ) ) { return 'none'; }
		$status = get_user_meta( $id, '_refm_approval', true );
		return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : 'approved';
	}
	public static function join( $id, $accepted = false, $terms_version = '', $application = array() ) {
		if ( ! $id || $id !== get_current_user_id() || ! get_userdata( $id ) ) { return new WP_Error( 'access', __( 'Please sign in to join.', 'refmora' ) ); }
		return REFM_Store::locked( $id, function () use ( $id, $accepted, $terms_version, $application ) {
			wp_cache_delete( $id, 'user_meta' );
			if ( ! REFM_Settings::program_enabled() || 'yes' === get_user_meta( $id, '_refm_blocked', true ) ) { return new WP_Error( 'unavailable', __( 'Affiliate enrollment is currently unavailable for your account.', 'refmora' ) ); }
			$status = self::status( $id );
			if ( 'none' !== $status ) { return $status; }
			$application = self::clean_application( $application );
			if ( is_wp_error( $application ) ) { return $application; }
			$version = REFM_Settings::terms_version();
			if ( '' !== $version ) {
				if ( true !== $accepted ) { return new WP_Error( 'terms_required', __( 'Please read and accept the affiliate Terms & Conditions before joining.', 'refmora' ) ); }
				if ( ! is_string( $terms_version ) || ! hash_equals( $version, $terms_version ) ) { return new WP_Error( 'terms_changed', __( 'The affiliate Terms & Conditions have changed. Please review them and join again.', 'refmora' ) ); }
				$accepted_at = current_time( 'mysql', true );
				update_user_meta( $id, '_refm_terms_version', $version );
				update_user_meta( $id, '_refm_terms_accepted_at', $accepted_at );
				if ( get_user_meta( $id, '_refm_terms_version', true ) !== $version || get_user_meta( $id, '_refm_terms_accepted_at', true ) !== $accepted_at ) { return new WP_Error( 'save', __( 'Your terms acceptance could not be saved. Please try again.', 'refmora' ) ); }
			}
			if ( 'yes' === REFM_Settings::get( 'join_url_enabled' ) || 'yes' === REFM_Settings::get( 'join_promotion_enabled' ) ) {
				$application['submitted_at'] = current_time( 'mysql', true );
				update_user_meta( $id, '_refm_application', $application );
				if ( get_user_meta( $id, '_refm_application', true ) !== $application ) { return new WP_Error( 'save', __( 'Your application could not be saved. Please try again.', 'refmora' ) ); }
			}
			$status = 'yes' === REFM_Settings::get( 'approval_required' ) ? 'pending' : 'approved';
			update_user_meta( $id, '_refm_approval', $status );
			update_user_meta( $id, '_refm_enrolled', 'yes' );
			$token = REFM_Tracking::token( $id );
			if ( self::status( $id ) !== $status || ! $token ) { return new WP_Error( 'save', __( 'Your affiliate account could not be saved. Please try again.', 'refmora' ) ); }
			REFM_Emails::queue( 'joined_admin', $id, array( 'status' => $status ) );
			REFM_Emails::queue( 'joined_affiliate', $id, array( 'status' => $status ) );
			return $status;
		} );
	}
	public static function clean_application( $input ) {
		$input = is_array( $input ) ? $input : array(); $clean = array( 'website' => '', 'promotion' => '' );
		foreach ( array( 'website' => 'join_url_enabled', 'promotion' => 'join_promotion_enabled' ) as $key => $setting ) {
			if ( 'yes' !== REFM_Settings::get( $setting ) ) { continue; }
			if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) { return new WP_Error( 'application', __( 'Please enter valid application details.', 'refmora' ) ); }
			$value = trim( REFM_Security::value( $input, $key ) );
			if ( mb_strlen( $value ) > ( 'website' === $key ? 2048 : 3000 ) ) { return new WP_Error( 'application', __( 'Your application details are too long. Please shorten them.', 'refmora' ) ); }
			if ( 'website' === $key && '' !== $value ) {
				$url = esc_url_raw( $value, array( 'http', 'https' ) ); $parts = wp_parse_url( $url );
				if ( ! preg_match( '~^https?://~i', $value ) || ! $url || ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { return new WP_Error( 'application_url', __( 'Enter a complete website or profile URL starting with https:// or http://.', 'refmora' ) ); }
				$clean[ $key ] = $url;
			} elseif ( 'promotion' === $key ) { $clean[ $key ] = sanitize_textarea_field( $value ); }
			if ( '' === trim( $clean[ $key ] ) ) { return new WP_Error( 'application_required', 'website' === $key ? __( 'Please enter your website or promotion URL.', 'refmora' ) : __( 'Please describe how you will promote us.', 'refmora' ) ); }
		}
		return $clean;
	}
	public static function review( $id, $status ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'access', __( 'Access denied.', 'refmora' ) ); }
		return REFM_Store::locked( $id, function () use ( $id, $status ) {
			wp_cache_delete( $id, 'user_meta' );
			$previous = self::status( $id );
			$allowed = array( 'pending' => array( 'approved', 'rejected' ), 'rejected' => array( 'approved' ) );
			if ( ! get_userdata( $id ) || ! in_array( $status, $allowed[ $previous ] ?? array(), true ) ) { return new WP_Error( 'state', __( 'This application cannot move to that status. Refresh the page.', 'refmora' ) ); }
			update_user_meta( $id, '_refm_approval', $status );
			if ( self::status( $id ) !== $status ) { return new WP_Error( 'save', __( 'Approval could not be saved.', 'refmora' ) ); }
			REFM_Emails::queue( $status . '_affiliate', $id, array( 'status' => $status ) );
			return true;
		} );
	}
	public static function set_blocked( $id, $blocked ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'access', __( 'Access denied.', 'refmora' ) ); }
		return REFM_Store::locked( $id, function () use ( $id, $blocked ) {
			wp_cache_delete( $id, 'user_meta' );
			if ( ! get_userdata( $id ) || 'none' === self::status( $id ) ) { return new WP_Error( 'missing', __( 'Affiliate account not found.', 'refmora' ) ); }
			$value = $blocked ? 'yes' : 'no'; update_user_meta( $id, '_refm_blocked', $value );
			return get_user_meta( $id, '_refm_blocked', true ) === $value ? true : new WP_Error( 'save', __( 'Account status could not be saved.', 'refmora' ) );
		} );
	}
}
