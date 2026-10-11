<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Tools {
	const MAX_BACKUP_SIZE = 1048576;
	public static function init() { add_action( 'admin_post_awpm_tools', array( __CLASS__, 'submit' ) ); }
	public static function guard( $operation ) {
		AWPM_Security::admin_request( 'awpm_tools' );
		if ( 'csv' !== $operation && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only administrators can manage settings backups.', 'affiliate-wpm' ), '', array( 'response' => 403 ) ); }
		if ( in_array( $operation, array( 'restore', 'reset' ), true ) ) { AWPM_Settings::require_license(); }
	}
	public static function backup() {
		return array( 'format' => 'affiliate-wpm-settings', 'schema_version' => 1, 'plugin_version' => AWPM_VERSION, 'created_at' => gmdate( 'c' ), 'settings' => array( 'program' => AWPM_Settings::get(), 'sharing' => AWPM_Sharing::get(), 'emails' => AWPM_Emails::get(), 'appearance' => AWPM_Design::get() ) );
	}
	public static function decode_backup( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'backup', __( 'Choose a settings JSON backup smaller than 1 MB.', 'affiliate-wpm' ) ); }
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $json ), true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || 'affiliate-wpm-settings' !== ( $data['format'] ?? '' ) || 1 !== ( $data['schema_version'] ?? null ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) || count( $data['settings'] ) !== 4 ) { return new WP_Error( 'backup', __( 'This is not a supported Affiliate WPM settings backup.', 'affiliate-wpm' ) ); }
		foreach ( array( 'program', 'sharing', 'emails', 'appearance' ) as $key ) { if ( ! isset( $data['settings'][ $key ] ) || ! is_array( $data['settings'][ $key ] ) || empty( $data['settings'][ $key ] ) ) { return new WP_Error( 'backup', __( 'The backup is incomplete. No settings were changed.', 'affiliate-wpm' ) ); } }
		return array( 'awpm_settings' => AWPM_Settings::clean( $data['settings']['program'] ), 'awpm_sharing' => AWPM_Sharing::clean( $data['settings']['sharing'] ), 'awpm_emails' => AWPM_Emails::clean( $data['settings']['emails'] ), 'awpm_design' => AWPM_Design::clean( $data['settings']['appearance'] ) );
	}
	public static function defaults() {
		$emails = array( 'admin_email' => get_option( 'admin_email' ), 'from_email' => get_option( 'admin_email' ), 'from_name' => get_bloginfo( 'name' ), 'templates' => array() );
		foreach ( AWPM_Emails::definitions() as $key => $template ) { $emails['templates'][ $key ] = array( 'enabled' => 'yes', 'subject' => $template['subject'], 'body' => $template['body'] ); }
		return array( 'awpm_settings' => AWPM_Settings::defaults(), 'awpm_sharing' => AWPM_Sharing::defaults(), 'awpm_emails' => AWPM_Emails::clean( $emails ), 'awpm_design' => AWPM_Design::defaults() );
	}
	private static function apply( $settings ) { foreach ( $settings as $key => $value ) { update_option( $key, $value, false ); } }
	private static function upload() {
		$file = isset( $_FILES['backup'] ) && is_array( $_FILES['backup'] ) ? $_FILES['backup'] : array();
		$path = AWPM_Security::value( $file, 'tmp_name' );
		if ( '0' !== AWPM_Security::value( $file, 'error', '-1' ) || ! $path || ! is_uploaded_file( $path ) || filesize( $path ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'upload', __( 'Upload a valid JSON settings backup, maximum 1 MB.', 'affiliate-wpm' ) ); }
		return self::decode_backup( file_get_contents( $path ) );
	}
	public static function submit() {
		$operation = AWPM_Security::value( $_POST, 'operation' ); self::guard( $operation );
		if ( 'backup' === $operation ) { self::download_headers( 'settings', 'json', 'application/json' ); echo wp_json_encode( self::backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); exit; }
		if ( 'csv' === $operation ) {
			$type = AWPM_Security::value( $_POST, 'dataset' );
			if ( ! isset( self::datasets()[ $type ] ) ) { wp_die( esc_html__( 'Choose a valid export dataset.', 'affiliate-wpm' ), '', array( 'response' => 400 ) ); }
			$start = $_POST['date_start'] ?? ''; $end = $_POST['date_end'] ?? '';
			$range = self::date_range( $start, $end ); if ( is_wp_error( $range ) ) { wp_die( esc_html( $range->get_error_message() ), '', array( 'response' => 400 ) ); }
			self::download_headers( $type, 'csv', 'text/csv' ); $stream = fopen( 'php://output', 'w' ); self::write_csv( $stream, $type, $start, $end ); fclose( $stream ); exit;
		}
		if ( 'restore' === $operation ) { $result = self::upload(); if ( ! is_wp_error( $result ) ) { self::apply( $result ); $result = __( 'Settings restored successfully.', 'affiliate-wpm' ); } }
		elseif ( 'reset' === $operation ) {
			if ( 'RESET' !== AWPM_Security::value( $_POST, 'confirmation' ) ) { $result = new WP_Error( 'confirm', __( 'Type RESET to confirm resetting plugin settings.', 'affiliate-wpm' ) ); }
			else { self::apply( self::defaults() ); $result = __( 'Plugin settings reset to defaults. Your license and financial records were preserved.', 'affiliate-wpm' ); }
		} else { $result = new WP_Error( 'operation', __( 'Unknown tools action.', 'affiliate-wpm' ) ); }
		set_transient( 'awpm_tools_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $result ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=tools' ) ); exit;
	}
	public static function date_range( $start = '', $end = '' ) {
		foreach ( array( $start, $end ) as $date ) {
			if ( ! is_string( $date ) || ( '' !== $date && ( ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $parts ) || (int) $parts[1] < 1000 || (int) $parts[1] > 9998 || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) ) ) { return new WP_Error( 'dates', __( 'Enter valid export dates in YYYY-MM-DD format.', 'affiliate-wpm' ) ); }
		}
		if ( $start && $end && $start > $end ) { return new WP_Error( 'dates', __( 'The start date must be on or before the end date.', 'affiliate-wpm' ) ); }
		$utc = new DateTimeZone( 'UTC' ); $zone = wp_timezone();
		return array( 'start' => $start, 'end' => $end, 'utc_start' => $start ? ( new DateTimeImmutable( $start . ' 00:00:00', $zone ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '', 'utc_end' => $end ? ( new DateTimeImmutable( $end . ' 00:00:00', $zone ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '' );
	}
	private static function date_sql( $column, $range, $local_date = false ) {
		global $wpdb; $sql = '';
		if ( $range['start'] ) { $sql .= $wpdb->prepare( " AND $column >= %s", $local_date ? $range['start'] : $range['utc_start'] ); }
		if ( $range['end'] ) { $sql .= $wpdb->prepare( " AND $column " . ( $local_date ? '<=' : '<' ) . ' %s', $local_date ? $range['end'] : $range['utc_end'] ); }
		return $sql;
	}
	private static function download_headers( $name, $extension, $mime ) {
		while ( ob_get_level() ) { if ( ! ob_end_clean() ) { break; } }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: ' . $mime . '; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="affiliate-wpm-' . $name . '-' . gmdate( 'Y-m-d' ) . '.' . $extension . '"' );
	}
	public static function datasets() { return array( 'affiliates' => __( 'Affiliates', 'affiliate-wpm' ), 'commissions' => __( 'Commissions', 'affiliate-wpm' ), 'payouts' => __( 'Withdrawals', 'affiliate-wpm' ), 'customers' => __( 'Referred customers', 'affiliate-wpm' ), 'clicks' => __( 'Daily referral clicks', 'affiliate-wpm' ) ); }
	public static function csv_cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) || preg_match( '/^[\s\x{FEFF}]*[=+\-@]/u', $value ) || preg_match( '/^[\t\r\n]/', $value ) ? "'" . $value : $value;
	}
	private static function csv_row( $stream, $row ) { fputcsv( $stream, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '' ); }
	private static function affiliate_rows( $range ) {
		global $wpdb; $after = 0; $max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );
		do {
			$query = new WP_User_Query();
			$filter = function ( $candidate ) use ( $query, $after, $max, $wpdb, $range ) { if ( $candidate === $query ) { $candidate->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d AND {$wpdb->users}.ID <= %d", $after, $max ) . self::date_sql( "{$wpdb->users}.user_registered", $range ); } };
			add_action( 'pre_user_query', $filter );
			try { $query->prepare_query( array( 'meta_key' => '_awpm_enrolled', 'meta_value' => 'yes', 'number' => 200, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => false, 'cache_results' => false ) ); $query->query(); } finally { remove_action( 'pre_user_query', $filter ); }
			$ids = $query->get_results();
			foreach ( $ids as $id ) { $after = (int) $id; $user = get_userdata( $id ); if ( $user ) { yield array( $id, $user->user_login, $user->display_name, $user->user_email, AWPM_Affiliates::status( $id ), 'yes' === get_user_meta( $id, '_awpm_blocked', true ) ? 'yes' : 'no', $user->user_registered ); } }
		} while ( count( $ids ) === 200 );
	}
	private static function items( $row ) {
		$order = wc_get_order( $row['order_id'] ); $titles = array(); $rates = array();
		if ( ! $order || $order instanceof WC_Order_Refund || (int) $order->get_meta( '_awpm_affiliate' ) !== (int) $row['affiliate_id'] ) { return array( '', '' ); }
		foreach ( (array) $order->get_meta( '_awpm_rates' ) as $key => $rate ) {
			if ( ! is_array( $rate ) ) { continue; }
			$item = AWPM_Commissions::rate_item( $order, $key, $rate );
			$title = isset( $rate['title'] ) && is_scalar( $rate['title'] ) && '' !== trim( (string) $rate['title'] ) ? $rate['title'] : ( $item instanceof WC_Order_Item_Product ? AWPM_Account::item_title( $item ) : __( 'Item unavailable', 'affiliate-wpm' ) );
			$titles[] = wp_strip_all_tags( $title ); $value = is_numeric( $rate['rate'] ?? null ) ? (string) $rate['rate'] : '';
			$rates[] = ( 'percent' === ( $rate['type'] ?? '' ) ? $value . '%' : ( 'fixed' === ( $rate['type'] ?? '' ) ? $row['currency'] . ' ' . $value . ' fixed / unit' : 'Excluded' ) ) . ' | Qty: ' . ( is_numeric( $rate['quantity'] ?? null ) ? $rate['quantity'] : '' );
		}
		return array( implode( "\n", $titles ), implode( "\n", $rates ) );
	}
	public static function write_csv( $stream, $type, $start = '', $end = '' ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_string( $type ) || ! isset( self::datasets()[ $type ] ) || ! is_resource( $stream ) ) { return new WP_Error( 'export', __( 'Export is not available.', 'affiliate-wpm' ) ); }
		$range = self::date_range( $start, $end ); if ( is_wp_error( $range ) ) { return $range; }
		$headers = array(
			'affiliates' => array( 'affiliate_id', 'username', 'name', 'email', 'account_status', 'blocked', 'registered_at_utc' ),
			'commissions' => array( 'id', 'order_id', 'affiliate_id', 'affiliate_name', 'items', 'commission_rates', 'amount', 'paid_amount', 'currency', 'status', 'eligibility', 'payout_id', 'created_at', 'updated_at' ),
			'payouts' => array( 'id', 'affiliate_id', 'affiliate_name', 'amount', 'currency', 'method', 'payment_destination', 'status', 'admin_note', 'created_at', 'updated_at' ),
			'customers' => array( 'customer_id', 'first_name', 'masked_email', 'masked_phone', 'affiliate_id', 'affiliate_name', 'created_at_utc' ),
			'clicks' => array( 'affiliate_id', 'affiliate_name', 'date', 'clicks' ),
		);
		fwrite( $stream, "\xEF\xBB\xBF" ); self::csv_row( $stream, $headers[ $type ] );
		if ( 'affiliates' === $type ) { foreach ( self::affiliate_rows( $range ) as $row ) { self::csv_row( $stream, $row ); } return true; }
		global $wpdb; $table = AWPM_Store::table( $type ); $key = 'customers' === $type ? 'customer_id' : 'id'; $after = 0; $date = '1000-01-01';
		$max = 'clicks' === $type ? null : (int) $wpdb->get_var( "SELECT MAX($key) FROM $table" );
		$last_click = 'clicks' === $type ? $wpdb->get_row( "SELECT affiliate_id,click_date FROM $table ORDER BY affiliate_id DESC,click_date DESC LIMIT 1", ARRAY_A ) : null;
		$date_filter = self::date_sql( 'clicks' === $type ? 'click_date' : 'created_at', $range, 'clicks' === $type );
		do {
			if ( 'clicks' === $type ) { $rows = $wpdb->get_results( $wpdb->prepare( "SELECT affiliate_id,click_date,clicks FROM $table WHERE (affiliate_id>%d OR (affiliate_id=%d AND click_date>%s)) AND (affiliate_id<%d OR (affiliate_id=%d AND click_date<=%s)) $date_filter ORDER BY affiliate_id,click_date LIMIT 200", $after, $after, $date, (int) ( $last_click['affiliate_id'] ?? 0 ), (int) ( $last_click['affiliate_id'] ?? 0 ), $last_click['click_date'] ?? '1000-01-01' ), ARRAY_A ); }
			else { $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $key>%d AND $key<=%d $date_filter ORDER BY $key LIMIT 200", $after, $max ), ARRAY_A ); }
			foreach ( $rows as $row ) {
				$affiliate = get_userdata( $row['affiliate_id'] ); $name = $affiliate ? $affiliate->display_name : '';
				if ( 'commissions' === $type ) { $items = self::items( $row ); $values = array( $row['id'], $row['order_id'], $row['affiliate_id'], $name, $items[0], $items[1], $row['amount'], $row['paid_amount'], $row['currency'], $row['status'], $row['eligibility'], $row['payout_id'], $row['created_at'], $row['updated_at'] ); }
				elseif ( 'payouts' === $type ) { $values = array( $row['id'], $row['affiliate_id'], $name, $row['amount'], $row['currency'], $row['method'], $row['details'], $row['status'], $row['note'], $row['created_at'], $row['updated_at'] ); }
				elseif ( 'customers' === $type ) { $user = get_userdata( $row['customer_id'] ); $values = array( $row['customer_id'], get_user_meta( $row['customer_id'], 'first_name', true ) ?: get_user_meta( $row['customer_id'], 'billing_first_name', true ), AWPM_Account::masked_email( $user ? $user->user_email : '' ), AWPM_Account::masked_phone( get_user_meta( $row['customer_id'], 'billing_phone', true ) ), $row['affiliate_id'], $name, $row['created_at'] ); }
				else { $values = array( $row['affiliate_id'], $name, $row['click_date'], $row['clicks'] ); }
				self::csv_row( $stream, $values ); $after = (int) $row[ 'clicks' === $type ? 'affiliate_id' : $key ]; if ( 'clicks' === $type ) { $date = $row['click_date']; }
			}
		} while ( count( $rows ) === 200 );
		return true;
	}
	private static function form( $operation, $upload = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="awpm_tools"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'awpm_tools' );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$notice = get_transient( 'awpm_tools_notice_' . get_current_user_id() ); if ( is_array( $notice ) ) { delete_transient( 'awpm_tools_notice_' . get_current_user_id() ); echo '<div class="awpm-notice" role="status">' . esc_html( $notice['message'] ?? '' ) . '</div>'; }
		if ( current_user_can( 'manage_options' ) ) {
			$disabled = AWPM_License::active() ? '' : ' disabled';
			echo '<div class="awpm-form-grid"><section class="awpm-card"><h2>' . AWPM_UI::icon( 'tools' ) . esc_html__( 'Back up settings', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Download program, sharing, email and appearance settings as a JSON backup.', 'affiliate-wpm' ) . '</p>'; self::form( 'backup' ); echo '<button class="button awpm-primary">' . esc_html__( 'Download settings backup', 'affiliate-wpm' ) . '</button></form></section>';
			echo '<section class="awpm-card"><h2>' . esc_html__( 'Restore settings', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Upload a settings backup to replace those four settings groups. Maximum size: 1 MB.', 'affiliate-wpm' ) . '</p>'; self::form( 'restore', true ); echo '<div class="awpm-tools-action-row"><div class="awpm-field"><label for="awpm-backup-file">' . esc_html__( 'Settings backup (.json)', 'affiliate-wpm' ) . '</label><input type="file" id="awpm-backup-file" name="backup" accept=".json,application/json" required' . $disabled . '></div><button class="button awpm-primary"' . $disabled . '>' . esc_html__( 'Restore settings', 'affiliate-wpm' ) . '</button></div></form></section></div>';
			echo '<section class="awpm-card"><h2>' . esc_html__( 'Reset plugin settings', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Restore program, sharing, email and appearance defaults. Your license, affiliate accounts, per-product commission rules and financial history are kept. Download a backup first.', 'affiliate-wpm' ) . '</p>'; self::form( 'reset' ); echo '<div class="awpm-tools-action-row"><div class="awpm-field"><label for="awpm-reset-confirmation">' . esc_html__( 'Type RESET to confirm', 'affiliate-wpm' ) . '</label><input type="text" id="awpm-reset-confirmation" name="confirmation" placeholder="RESET" pattern="RESET" maxlength="5" required autocomplete="off"' . $disabled . '></div><button class="button awpm-primary"' . $disabled . '>' . esc_html__( 'Reset settings', 'affiliate-wpm' ) . '</button></div></form>';
			if ( $disabled ) { echo '<p class="awpm-muted">' . esc_html__( 'Activate your license to restore or reset settings.', 'affiliate-wpm' ) . '</p>'; } echo '</section>';
		}
		echo '<section class="awpm-card"><h2>' . esc_html__( 'Export data', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Download all records from a dataset as CSV, including all statuses and original transaction currencies. Referred customer contacts stay masked. Withdrawal exports include payment destination details for your records.', 'affiliate-wpm' ) . '</p>'; self::form( 'csv' ); echo '<div class="awpm-field"><label for="awpm-export-dataset">' . esc_html__( 'Choose data to export', 'affiliate-wpm' ) . '</label><select id="awpm-export-dataset" name="dataset">'; foreach ( self::datasets() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; } echo '</select></div><div class="awpm-form-grid"><div class="awpm-field"><label for="awpm-export-start">' . esc_html__( 'Start date (optional)', 'affiliate-wpm' ) . '</label><input type="date" id="awpm-export-start" name="date_start"></div><div class="awpm-field"><label for="awpm-export-end">' . esc_html__( 'End date (optional)', 'affiliate-wpm' ) . '</label><input type="date" id="awpm-export-end" name="date_end"></div></div><p class="awpm-muted">' . esc_html__( 'Leave dates blank to export all records. The end date includes the whole day in your WordPress site time zone. Affiliates use account registration dates; other exports use record creation or click dates.', 'affiliate-wpm' ) . '</p><button class="button awpm-primary">' . esc_html__( 'Export CSV', 'affiliate-wpm' ) . '</button></form></section>';
	}
}
