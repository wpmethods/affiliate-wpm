<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Payouts {
	public static function request( $user_id, $method_id, $details ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id || $user_id !== get_current_user_id() ) { return new WP_Error( 'access', __( 'You can only request withdrawals for your own account.', 'affiliate-wpm' ) ); }
		if ( ! AWPM_Tracking::active( $user_id ) || ! AWPM_Settings::program_enabled() ) { return new WP_Error( 'inactive', __( 'Your affiliate account is not active.', 'affiliate-wpm' ) ); }
		$method = null;
		foreach ( AWPM_Settings::methods() as $option ) { if ( $option['id'] === $method_id ) { $method = $option; break; } }
		if ( ! $method ) { return new WP_Error( 'method', __( 'Choose an active payout method.', 'affiliate-wpm' ) ); }
		$details = substr( sanitize_textarea_field( $details ), 0, 2000 );
		if ( strlen( trim( $details ) ) < 3 ) { return new WP_Error( 'details', __( 'Enter your payment destination details.', 'affiliate-wpm' ) ); }
		return AWPM_Store::locked( $user_id, function () use ( $wpdb, $user_id, $method, $details ) {
			wp_cache_delete( $user_id, 'user_meta' );
			if ( ! AWPM_Tracking::active( $user_id ) || ! AWPM_Settings::program_enabled() ) { return new WP_Error( 'inactive', __( 'Your affiliate account is not active.', 'affiliate-wpm' ) ); }
			$payouts = AWPM_Store::table( 'payouts' );
			$commissions = AWPM_Store::table( 'commissions' );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $payouts WHERE affiliate_id=%d AND status IN ('pending','approved') LIMIT 1", $user_id ) ) ) { return new WP_Error( 'pending', __( 'You already have a withdrawal awaiting processing.', 'affiliate-wpm' ) ); }
			$currency = AWPM_Settings::get( 'currency' );
			$amount = round( AWPM_Store::balance( $user_id, $currency ), AWPM_Currency::precision( $currency ) );
			if ( $amount <= 0 || $amount < (float) AWPM_Settings::get( 'minimum' ) ) { return new WP_Error( 'minimum', __( 'Your available balance is below the minimum withdrawal.', 'affiliate-wpm' ) ); }
			$now = current_time( 'mysql', true );
			AWPM_Store::must( $wpdb->insert( $payouts, array( 'affiliate_id' => $user_id, 'amount' => $amount, 'currency' => $currency, 'method' => $method['name'], 'details' => $details, 'status' => 'pending', 'note' => '', 'created_at' => $now, 'updated_at' => $now ) ) );
			$id = $wpdb->insert_id;
			AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $commissions SET payout_id=%d,status='reserved',updated_at=%s WHERE affiliate_id=%d AND currency=%s AND payout_id=0 AND status IN ('available','paid','rejected') AND amount<>paid_amount", $id, $now, $user_id, $currency ) ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $payouts WHERE id=%d", $id ) );
			AWPM_Emails::payout( 'withdrawal_admin', $row ); AWPM_Emails::payout( 'withdrawal_affiliate', $row );
			return $id;
		} );
	}
	/** Must be called inside the affiliate lock / transaction. */
	public static function release( $id, $note ) {
		global $wpdb;
		$payouts = AWPM_Store::table( 'payouts' );
		$commissions = AWPM_Store::table( 'commissions' );
		$updated = AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $payouts SET status='rejected',note=%s,updated_at=%s WHERE id=%d AND status IN ('pending','approved')", $note, current_time( 'mysql', true ), $id ) ) );
		AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $commissions SET payout_id=0,status=IF(paid_amount>0,'paid','available') WHERE payout_id=%d", $id ) ) );
		if ( $updated ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $payouts WHERE id=%d", $id ) );
			AWPM_Emails::payout( 'payout_affiliate', $row ); AWPM_Emails::payout( 'payout_admin', $row );
		}
	}
	public static function transition( $id, $status, $note ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'forbidden', __( 'Access denied.', 'affiliate-wpm' ) ); }
		$table = AWPM_Store::table( 'payouts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
		if ( ! $row ) { return new WP_Error( 'missing', __( 'Withdrawal not found.', 'affiliate-wpm' ) ); }
		return AWPM_Store::locked( $row->affiliate_id, function () use ( $wpdb, $table, $id, $status, $note ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
			if ( ! $row ) { return new WP_Error( 'missing', __( 'Withdrawal not found.', 'affiliate-wpm' ) ); }
			$allowed = array( 'pending' => array( 'approved', 'rejected' ), 'approved' => array( 'completed', 'rejected' ) );
			if ( ! in_array( $status, $allowed[ $row->status ] ?? array(), true ) ) { return new WP_Error( 'transition', __( 'This withdrawal cannot move to that status. Refresh the page.', 'affiliate-wpm' ) ); }
			$note = substr( sanitize_textarea_field( $note ), 0, 2000 );
			if ( 'rejected' === $status ) { self::release( $id, $note ); return true; }
			if ( 'completed' === $status ) {
				$commissions = AWPM_Store::table( 'commissions' );
				$total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount-paid_amount) FROM $commissions WHERE payout_id=%d", $id ) );
				if ( abs( $total - (float) $row->amount ) > 0.000001 ) { return new WP_Error( 'changed', __( 'The reserved balance changed. Reject this request and review the commissions.', 'affiliate-wpm' ) ); }
				AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $commissions SET paid_amount=amount,payout_id=0,status='paid',updated_at=%s WHERE payout_id=%d", current_time( 'mysql', true ), $id ) ) );
			}
			AWPM_Store::must( $wpdb->update( $table, array( 'status' => $status, 'note' => $note, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
			AWPM_Emails::payout( 'payout_affiliate', $row ); AWPM_Emails::payout( 'payout_admin', $row );
			return true;
		} );
	}
}
