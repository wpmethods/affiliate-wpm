<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Payouts {
	public static function request( $user_id, $method_id, $details ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id || $user_id !== get_current_user_id() ) { return new WP_Error( 'access', __( 'You can only request withdrawals for your own account.', 'refmora' ) ); }
		if ( ! REFM_Tracking::active( $user_id ) || ! REFM_Settings::program_enabled() ) { return new WP_Error( 'inactive', __( 'Your affiliate account is not active.', 'refmora' ) ); }
		$method = null;
		foreach ( REFM_Settings::methods() as $option ) { if ( $option['id'] === $method_id ) { $method = $option; break; } }
		if ( ! $method ) { return new WP_Error( 'method', __( 'Choose an active payout method.', 'refmora' ) ); }
		$details = substr( sanitize_textarea_field( $details ), 0, 2000 );
		$provider = $method['provider'] ?? '';
		$job = $provider ? REFM_Gateways::prepare( $provider, $user_id, $details ) : array();
		if ( is_wp_error( $job ) ) { return $job; }
		if ( $provider ) { $details = $job['recipient']; }
		if ( strlen( trim( $details ) ) < 3 ) { return new WP_Error( 'details', __( 'Enter your payment destination details.', 'refmora' ) ); }
		return REFM_Store::locked( $user_id, function () use ( $wpdb, $user_id, $method, $details, $job ) {
			wp_cache_delete( $user_id, 'user_meta' );
			if ( ! REFM_Tracking::active( $user_id ) || ! REFM_Settings::program_enabled() ) { return new WP_Error( 'inactive', __( 'Your affiliate account is not active.', 'refmora' ) ); }
			$payouts = REFM_Store::table( 'payouts' );
			$commissions = REFM_Store::table( 'commissions' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE affiliate_id=%d AND status IN (\'pending\',\'approved\',\'processing\') LIMIT 1', $payouts, $user_id ) ) ) { return new WP_Error( 'pending', __( 'You already have a withdrawal awaiting processing.', 'refmora' ) ); }
			$currency = REFM_Settings::get( 'currency' );
			$amount = round( REFM_Store::balance( $user_id, $currency ), REFM_Currency::precision( $currency ) );
			if ( $amount <= 0 || $amount < (float) REFM_Settings::get( 'minimum' ) ) { return new WP_Error( 'minimum', __( 'Your available balance is below the minimum withdrawal.', 'refmora' ) ); }
			if ( $job ) { $job = REFM_Gateways::quote( $job, $amount, $currency ); if ( is_wp_error( $job ) ) { return $job; } }
			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			REFM_Store::must( $wpdb->insert( $payouts, array( 'affiliate_id' => $user_id, 'amount' => $amount, 'currency' => $currency, 'method' => $method['name'], 'details' => $details, 'status' => 'pending', 'note' => '', 'created_at' => $now, 'updated_at' => $now ) ) );
			$id = $wpdb->insert_id;
			if ( $job ) { REFM_Gateways::store_job( $id, $job ); }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			REFM_Store::must( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET payout_id=%d,status=\'reserved\',updated_at=%s WHERE affiliate_id=%d AND currency=%s AND payout_id=0 AND status IN (\'available\',\'paid\',\'rejected\') AND amount<>paid_amount', $commissions, $id, $now, $user_id, $currency ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $payouts, $id ) );
			REFM_Emails::payout( 'withdrawal_admin', $row ); REFM_Emails::payout( 'withdrawal_affiliate', $row );
			return $id;
		} );
	}
	/** Must be called inside the affiliate lock / transaction. */
	public static function release( $id, $note ) {
		global $wpdb;
		$payouts = REFM_Store::table( 'payouts' );
		$commissions = REFM_Store::table( 'commissions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
		$updated = REFM_Store::must( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status=\'rejected\',note=%s,updated_at=%s WHERE id=%d AND status IN (\'pending\',\'approved\')', $payouts, $note, current_time( 'mysql', true ), $id ) ) );
		if ( ! $updated ) { return new WP_Error( 'processing', __( 'Payment has already started or this withdrawal is closed. Funds cannot be released manually.', 'refmora' ) ); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
		REFM_Store::must( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET payout_id=0,status=IF(paid_amount>0,\'paid\',\'available\') WHERE payout_id=%d', $commissions, $id ) ) );
		if ( $updated ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $payouts, $id ) );
			REFM_Emails::payout( 'payout_affiliate', $row ); REFM_Emails::payout( 'payout_admin', $row );
		}
	}
	public static function transition( $id, $status, $note ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'forbidden', __( 'Access denied.', 'refmora' ) ); }
		if ( 'processing' === $status ) { return REFM_Gateways::start( $id ); }
		if ( 'check' === $status && REFM_Gateways::job( $id ) ) { $result = REFM_Gateways::process( $id ); return is_wp_error( $result ) ? $result : true; }
		if ( 'completed' === $status && REFM_Gateways::job( $id ) ) { return new WP_Error( 'provider', __( 'Automatic payouts complete only after provider confirmation.', 'refmora' ) ); }
		$table = REFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $id ) );
		if ( ! $row ) { return new WP_Error( 'missing', __( 'Withdrawal not found.', 'refmora' ) ); }
		return REFM_Store::locked( $row->affiliate_id, function () use ( $wpdb, $table, $id, $status, $note ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $id ) );
			if ( ! $row ) { return new WP_Error( 'missing', __( 'Withdrawal not found.', 'refmora' ) ); }
			$allowed = array( 'pending' => array( 'approved', 'rejected' ), 'approved' => array( 'completed', 'rejected' ) );
			if ( ! in_array( $status, $allowed[ $row->status ] ?? array(), true ) ) { return new WP_Error( 'transition', __( 'This withdrawal cannot move to that status. Refresh the page.', 'refmora' ) ); }
			$note = substr( sanitize_textarea_field( $note ), 0, 2000 );
			if ( 'rejected' === $status ) { self::release( $id, $note ); return true; }
			if ( 'completed' === $status ) {
				$commissions = REFM_Store::table( 'commissions' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
				$total = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount-paid_amount) FROM %i WHERE payout_id=%d', $commissions, $id ) );
				if ( abs( $total - (float) $row->amount ) > 0.000001 ) { return new WP_Error( 'changed', __( 'The reserved balance changed. Reject this request and review the commissions.', 'refmora' ) ); }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
				REFM_Store::must( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET paid_amount=amount,payout_id=0,status=\'paid\',updated_at=%s WHERE payout_id=%d', $commissions, current_time( 'mysql', true ), $id ) ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			REFM_Store::must( $wpdb->update( $table, array( 'status' => $status, 'note' => $note, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $id ) );
			REFM_Emails::payout( 'payout_affiliate', $row ); REFM_Emails::payout( 'payout_admin', $row );
			return true;
		} );
	}
}
