<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Store {
	public static function table( $name ) {
		global $wpdb;
		if ( ! in_array( $name, array( 'commissions', 'payouts', 'clicks', 'customers' ), true ) ) { throw new InvalidArgumentException( 'Unknown Affiliate WPM table.' ); }
		return $wpdb->prefix . 'awpm_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$commissions = self::table( 'commissions' );
		$payouts = self::table( 'payouts' );
		$clicks = self::table( 'clicks' );
		$customers = self::table( 'customers' );
		add_option( 'awpm_identity_key', wp_generate_password( 64, true, true ), '', false );
		dbDelta( "CREATE TABLE $clicks (
			affiliate_id bigint(20) unsigned NOT NULL,
			click_date date NOT NULL,
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (affiliate_id,click_date)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $customers (
			customer_id bigint(20) unsigned NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (customer_id),
			KEY affiliate_created (affiliate_id,created_at)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $commissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			amount decimal(20,6) NOT NULL DEFAULT 0,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_hash varchar(64) NOT NULL DEFAULT '',
			eligibility varchar(20) NOT NULL DEFAULT 'eligible',
			paid_amount decimal(20,6) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY affiliate_status (affiliate_id,status),
			KEY customer_id (customer_id),
			KEY customer_hash (customer_hash),
			KEY payout_id (payout_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $payouts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_id bigint(20) unsigned NOT NULL,
			amount decimal(20,6) NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL,
			method varchar(190) NOT NULL,
			details text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			note text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_status (affiliate_id,status)
		) ENGINE=InnoDB $collate;" );
		update_option( 'awpm_db_version', AWPM_VERSION, false );
	}

	/** Serialize all balance mutations for one affiliate, including order refunds. */
	public static function locked( $affiliate_id, $callback, $additional_locks = array() ) {
		global $wpdb;
		$keys = array( 'awpm_' . md5( $wpdb->prefix . ':' . absint( $affiliate_id ) ) );
		foreach ( $additional_locks as $identity ) { $keys[] = 'awpm_' . md5( $wpdb->prefix . ':' . $identity ); }
		$keys = array_unique( $keys ); sort( $keys ); $held = array(); $committed = false;
		try {
			foreach ( $keys as $key ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
				if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) {
					return new WP_Error( 'busy', __( 'Your balance is being updated. Please try again.', 'affiliate-wpm' ) );
				}
				$held[] = $key;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			self::must( $wpdb->query( 'START TRANSACTION' ) );
			$result = call_user_func( $callback );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			if ( is_wp_error( $result ) ) { self::must( $wpdb->query( 'ROLLBACK' ) ); } else { self::must( $wpdb->query( 'COMMIT' ) ); }
			$committed = ! is_wp_error( $result );
			return $result;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'storage', __( 'The update could not be saved. Please try again.', 'affiliate-wpm' ) );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			foreach ( array_reverse( $held ) as $key ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
			if ( ! $committed ) { wp_cache_delete( absint( $affiliate_id ), 'user_meta' ); }
			AWPM_Emails::finish( $committed );
		}
	}

	public static function must( $result ) {
		if ( false === $result ) {
			throw new RuntimeException( 'Affiliate WPM database write failed.' );
		}
		return $result;
	}

	public static function balance( $user_id, $currency ) {
		global $wpdb;
		$table = self::table( 'commissions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount - paid_amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s AND payout_id=0 AND status IN (\'available\',\'paid\',\'rejected\')', $table, $user_id, $currency ) );
	}
	public static function referral_stats( $affiliate_id = null ) {
		global $wpdb;
		$clicks = self::table( 'clicks' );
		$customers = self::table( 'customers' );
		$all = null === $affiliate_id ? 1 : 0;
		$id = absint( $affiliate_id );
		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom financial tables/transaction locks require direct SQL and fresh state to preserve reservation and refund consistency.
			'clicks' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(clicks),0) FROM %i WHERE (%d=1 OR affiliate_id=%d)', $clicks, $all, $id ) ),
			'customers' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE (%d=1 OR affiliate_id=%d)', $customers, $all, $id ) ),
		);
	}

	public static function money( $amount, $currency = '' ) {
		return AWPM_Currency::money( $amount, $currency ?: AWPM_Settings::get( 'currency' ) );
	}

	public static function status( $status ) {
		$labels = array( 'processing' => __( 'Processing', 'affiliate-wpm' ), 'tested' => __( 'Sandbox successful', 'affiliate-wpm' ), 'failed' => __( 'Failed', 'affiliate-wpm' ), 'pending' => __( 'Pending', 'affiliate-wpm' ), 'available' => __( 'Available', 'affiliate-wpm' ), 'reserved' => __( 'In withdrawal', 'affiliate-wpm' ), 'paid' => __( 'Paid', 'affiliate-wpm' ), 'approved' => __( 'Approved', 'affiliate-wpm' ), 'completed' => __( 'Completed', 'affiliate-wpm' ), 'rejected' => __( 'Rejected', 'affiliate-wpm' ), 'paused' => __( 'Paused', 'affiliate-wpm' ) );
		return '<span class="awpm-badge awpm-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}
}
