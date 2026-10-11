<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Account {
	public static function endpoint() { add_rewrite_endpoint( 'affiliate-wpm', EP_ROOT | EP_PAGES ); }
	public static function init() {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_affiliate-wpm_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_affiliate-wpm_title', function () { return __( 'Affiliate dashboard', 'affiliate-wpm' ); } );
		add_action( 'template_redirect', array( __CLASS__, 'submit' ) );
		add_action( 'wp_enqueue_scripts', function () {
			if ( is_account_page() && is_wc_endpoint_url( 'affiliate-wpm' ) ) {
				wp_enqueue_style( 'awpm-account', AWPM_URL . 'assets/affiliate-wpm.css', array(), AWPM_VERSION );
				AWPM_Design::enqueue( 'awpm-account' );
				wp_enqueue_script( 'awpm-account', AWPM_URL . 'assets/account.js', array(), AWPM_VERSION, true );
			}
		} );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}
	public static function query_vars( $vars ) {
		// WooCommerce conditionals, endpoint titles and URLs use its own registry.
		$vars['affiliate-wpm'] = 'affiliate-wpm';
		return $vars;
	}
	public static function menu( $items ) {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['affiliate-wpm'] = __( 'Affiliate dashboard', 'affiliate-wpm' );
		if ( $logout ) { $items['customer-logout'] = $logout; }
		return $items;
	}
	public static function submit() {
		if ( ! isset( $_POST['awpm_action'] ) || ! is_scalar( $_POST['awpm_action'] ) || ! is_account_page() || ! is_wc_endpoint_url( 'affiliate-wpm' ) ) { return; }
		if ( ! is_user_logged_in() ) { return; }
		$nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'awpm_account' ) ) { wc_add_notice( __( 'Your session expired. Please try again.', 'affiliate-wpm' ), 'error' ); }
		else {
			$id = get_current_user_id();
			$action = sanitize_key( wp_unslash( $_POST['awpm_action'] ) );
			if ( 'join' === $action ) {
				$result = AWPM_Affiliates::join( $id );
				$messages = array( 'approved' => __( 'Your affiliate account is ready. Start sharing your referral link!', 'affiliate-wpm' ), 'pending' => __( 'Your affiliate application is awaiting administrator approval.', 'affiliate-wpm' ), 'rejected' => __( 'Your affiliate application was rejected. Please contact the store.', 'affiliate-wpm' ) );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : ( $messages[ $result ] ?? '' ), is_wp_error( $result ) || 'rejected' === $result ? 'error' : 'success' );
			} elseif ( 'withdraw' === $action ) {
				$method = isset( $_POST['method'] ) && is_scalar( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
				$details = isset( $_POST['details'] ) && is_scalar( $_POST['details'] ) ? wp_unslash( $_POST['details'] ) : '';
				$result = AWPM_Payouts::request( $id, $method, $details );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal requested. You can follow its status below.', 'affiliate-wpm' ), is_wp_error( $result ) ? 'error' : 'success' );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'affiliate-wpm' ) );
		exit;
	}
	public static function render() {
		global $wpdb;
		$id = get_current_user_id();
		if ( ! $id ) { return; }
		nocache_headers();
		$currency = AWPM_Settings::get( 'currency' );
		$commissions = AWPM_Store::table( 'commissions' );
		$payouts = AWPM_Store::table( 'payouts' );
		echo '<div class="awpm"><div class="awpm-heading"><span class="awpm-eyebrow">' . esc_html__( 'PARTNER PROGRAM', 'affiliate-wpm' ) . '</span><h2>' . esc_html__( 'Your affiliate dashboard', 'affiliate-wpm' ) . '</h2><p>' . esc_html__( 'Share what you love. Keep track of every earning.', 'affiliate-wpm' ) . '</p></div>';
		if ( 'yes' !== get_user_meta( $id, '_awpm_enrolled', true ) ) {
			echo '<div class="awpm-card"><h3>' . esc_html__( 'Become an affiliate', 'affiliate-wpm' ) . '</h3><p>' . esc_html__( 'Join our affiliate program to earn commissions when someone orders using your referral link. Commissions are available after the order is completed.', 'affiliate-wpm' ) . '</p>';
			if ( 'yes' === AWPM_Settings::get( 'enabled' ) && 'yes' !== get_user_meta( $id, '_awpm_blocked', true ) ) {
				echo '<form method="post">'; wp_nonce_field( 'awpm_account' );
				echo '<input type="hidden" name="awpm_action" value="join"><button class="button awpm-primary">' . esc_html__( 'Join affiliate program', 'affiliate-wpm' ) . '</button></form>';
			} else { echo '<p>' . esc_html__( 'Enrollment is currently unavailable.', 'affiliate-wpm' ) . '</p>'; }
			echo '</div></div>'; return;
		}
		$approval = AWPM_Affiliates::status( $id );
		if ( in_array( $approval, array( 'pending', 'rejected' ), true ) ) {
			echo '<section class="awpm-card"><h3>' . esc_html__( 'Affiliate application', 'affiliate-wpm' ) . '</h3>' . wp_kses_post( AWPM_Store::status( $approval ) ) . '<p>' . esc_html( 'pending' === $approval ? __( 'Your application is awaiting administrator approval. Referral links and withdrawals become available after approval.', 'affiliate-wpm' ) : __( 'Your application was rejected. Contact the store if you have questions.', 'affiliate-wpm' ) ) . '</p></section></div>'; return;
		}
		$active = AWPM_Tracking::active( $id ) && 'yes' === AWPM_Settings::get( 'enabled' );
		if ( ! $active ) { echo '<p class="awpm-notice">' . esc_html__( 'The affiliate program or your account is paused. Your history is still available.', 'affiliate-wpm' ) . '</p>'; }
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $commissions WHERE affiliate_id=%d AND currency=%s AND status='pending'", $id, $currency ) );
		$reserved = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $payouts WHERE affiliate_id=%d AND currency=%s AND status IN ('pending','approved')", $id, $currency ) );
		$paid = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $payouts WHERE affiliate_id=%d AND currency=%s AND status='completed'", $id, $currency ) );
		$balance = AWPM_Store::balance( $id, $currency );
		echo '<div class="awpm-metrics">';
		foreach ( array( __( 'Available balance', 'affiliate-wpm' ) => $balance, __( 'Pending earnings', 'affiliate-wpm' ) => $pending, __( 'In withdrawal', 'affiliate-wpm' ) => $reserved, __( 'Total paid out', 'affiliate-wpm' ) => $paid ) as $label => $value ) {
			echo '<div class="awpm-metric"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( AWPM_Store::money( $value, $currency ) ) . '</strong></div>';
		}
		echo '</div>';
		$stats = AWPM_Store::referral_stats( $id );
		echo '<div class="awpm-referral-metrics">';
		foreach ( array( __( 'Referral clicks', 'affiliate-wpm' ) => $stats['clicks'], __( 'Registered customers', 'affiliate-wpm' ) => $stats['customers'] ) as $label => $value ) {
			echo '<div class="awpm-metric"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div>';
		}
		echo '</div><p class="awpm-muted">' . esc_html__( 'Consecutive clicks on the same referral count once per browser within 30 minutes. Registered customers are new accounts created through your referral cookie. These statistics start from when tracking was enabled.', 'affiliate-wpm' ) . '</p><div class="awpm-grid"><section class="awpm-card"><h3>' . esc_html__( 'Your referral link', 'affiliate-wpm' ) . '</h3><p>' . esc_html__( 'Share this link with your audience. Add the same awpm_ref parameter to a product link to promote a specific product.', 'affiliate-wpm' ) . '</p>';
		$link = add_query_arg( 'awpm_ref', AWPM_Tracking::token( $id ), home_url( '/' ) );
		echo '<label for="awpm-ref-link" class="screen-reader-text">' . esc_html__( 'Referral link', 'affiliate-wpm' ) . '</label><div class="awpm-copy"><input id="awpm-ref-link" readonly value="' . esc_attr( $link ) . '"><button type="button" class="button" data-awpm-copy data-copied="' . esc_attr__( 'Copied!', 'affiliate-wpm' ) . '">' . esc_html__( 'Copy', 'affiliate-wpm' ) . '</button></div><p class="awpm-muted">' . sprintf( esc_html__( 'Referral cookie: %d days. Self-referrals do not earn commission.', 'affiliate-wpm' ), (int) AWPM_Settings::get( 'cookie_days' ) ) . '</p></section>';
		echo '<section class="awpm-card"><h3>' . esc_html__( 'Request a withdrawal', 'affiliate-wpm' ) . '</h3><p>' . esc_html__( 'Minimum withdrawal:', 'affiliate-wpm' ) . ' ' . wp_kses_post( AWPM_Store::money( AWPM_Settings::get( 'minimum' ), $currency ) ) . '</p>';
		$methods = AWPM_Settings::methods();
		$open = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $payouts WHERE affiliate_id=%d AND status IN ('pending','approved') LIMIT 1", $id ) );
		if ( $active && $methods && ! $open ) {
			echo '<form method="post" class="awpm-withdraw">'; wp_nonce_field( 'awpm_account' );
			echo '<input type="hidden" name="awpm_action" value="withdraw"><label for="awpm-method">' . esc_html__( 'Payout method', 'affiliate-wpm' ) . '</label><select id="awpm-method" name="method">';
			foreach ( $methods as $method ) { echo '<option value="' . esc_attr( $method['id'] ) . '" data-instructions="' . esc_attr( $method['instructions'] ) . '">' . esc_html( $method['name'] ) . '</option>'; }
			echo '</select><p id="awpm-instructions" class="awpm-muted">' . esc_html( $methods[0]['instructions'] ) . '</p><label for="awpm-details">' . esc_html__( 'Payment destination details', 'affiliate-wpm' ) . '</label><textarea id="awpm-details" name="details" rows="3" required minlength="3" maxlength="2000"></textarea><p class="awpm-muted">' . esc_html__( 'Only enter information needed to receive payment. Never enter passwords or card security codes.', 'affiliate-wpm' ) . '</p><button class="button awpm-primary"' . disabled( $balance < (float) AWPM_Settings::get( 'minimum' ), true, false ) . '>' . esc_html__( 'Withdraw available balance', 'affiliate-wpm' ) . '</button></form>';
		} else { echo '<p class="awpm-muted">' . esc_html( $open ? __( 'Your withdrawal is being reviewed. Check its status below.', 'affiliate-wpm' ) : __( 'Withdrawals are currently unavailable.', 'affiliate-wpm' ) ) . '</p>'; }
		echo '</section></div><p class="awpm-muted">' . esc_html__( 'Refunds reduce earnings. If an order is refunded after a payout, the adjustment is deducted from future earnings. Balances are shown in the current affiliate currency; history keeps its original currency.', 'affiliate-wpm' ) . '</p>';
		self::history( 'commissions', $id );
		self::history( 'payouts', $id );
		self::customers( $id );
		echo '</div>';
	}
	private static function customers( $id ) {
		global $wpdb;
		$table = AWPM_Store::table( 'customers' );
		$page = max( 1, min( 100000, absint( $_GET['awpm_rp'] ?? 1 ) ) );
		$count = AWPM_Store::referral_stats( $id )['customers'];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM $table WHERE affiliate_id=%d ORDER BY created_at DESC,customer_id DESC LIMIT 10 OFFSET %d", $id, ( $page - 1 ) * 10 ) );
		if ( $ids ) { update_meta_cache( 'user', $ids ); }
		echo '<section class="awpm-card awpm-history"><h3>' . esc_html__( 'Your registered customers', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'For customer privacy, only first names are shown.', 'affiliate-wpm' ) . '</p><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'First name', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $customer_id ) {
			$name = get_user_meta( $customer_id, 'first_name', true );
			if ( ! $name ) { $name = get_user_meta( $customer_id, 'billing_first_name', true ); }
			echo '<tr><td>' . esc_html( $name ?: __( 'Customer', 'affiliate-wpm' ) ) . '</td></tr>';
		}
		if ( ! $ids ) { echo '<tr><td class="awpm-empty">' . esc_html__( 'No registered customers yet.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		if ( $count > 10 ) {
			echo '<nav class="awpm-pagination" aria-label="' . esc_attr__( 'Registered customer pages', 'affiliate-wpm' ) . '">';
			if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( 'awpm_rp', $page - 1, wc_get_account_endpoint_url( 'affiliate-wpm' ) ) ) . '">' . esc_html__( 'Previous', 'affiliate-wpm' ) . '</a>'; }
			echo '<span>' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affiliate-wpm' ), $page, (int) ceil( $count / 10 ) ) . '</span>';
			if ( $count > $page * 10 ) { echo '<a href="' . esc_url( add_query_arg( 'awpm_rp', $page + 1, wc_get_account_endpoint_url( 'affiliate-wpm' ) ) ) . '">' . esc_html__( 'Next', 'affiliate-wpm' ) . '</a>'; }
			echo '</nav>';
		}
		echo '</section>';
	}
	private static function history( $type, $id ) {
		global $wpdb;
		$table = AWPM_Store::table( $type );
		$key = 'commissions' === $type ? 'awpm_cp' : 'awpm_pp';
		$page = isset( $_GET[ $key ] ) ? max( 1, min( 100000, absint( $_GET[ $key ] ) ) ) : 1;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE affiliate_id=%d", $id ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE affiliate_id=%d ORDER BY id DESC LIMIT 10 OFFSET %d", $id, ( $page - 1 ) * 10 ) );
		echo '<section class="awpm-card awpm-history"><h3>' . esc_html( 'commissions' === $type ? __( 'Commission history', 'affiliate-wpm' ) : __( 'Withdrawal history', 'affiliate-wpm' ) ) . '</h3><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Reference', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Date', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Amount', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-wpm' ) . '</th><th>' . esc_html( 'commissions' === $type ? __( 'Adjustment', 'affiliate-wpm' ) : __( 'Method / note', 'affiliate-wpm' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . esc_html( 'commissions' === $type ? $row->order_id : $row->id ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td><td>' . wp_kses_post( AWPM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AWPM_Store::status( $row->status ) ) . '</td><td>';
			if ( 'commissions' === $type ) {
				if ( 'repeat' === $row->eligibility ) { echo esc_html__( 'Repeat purchase excluded', 'affiliate-wpm' ); }
				else { echo (float) $row->paid_amount > (float) $row->amount ? wp_kses_post( AWPM_Store::money( (float) $row->amount - (float) $row->paid_amount, $row->currency ) ) : '&mdash;'; }
			}
			else { echo esc_html( $row->method ); if ( $row->note ) { echo '<small class="awpm-note">' . esc_html( $row->note ) . '</small>'; } }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="5" class="awpm-empty">' . esc_html__( 'Nothing here yet. Your activity will appear here.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		if ( $count > 10 ) {
			echo '<nav class="awpm-pagination" aria-label="' . esc_attr__( 'History pages', 'affiliate-wpm' ) . '">';
			if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( $key, $page - 1, wc_get_account_endpoint_url( 'affiliate-wpm' ) ) ) . '">' . esc_html__( 'Previous', 'affiliate-wpm' ) . '</a>'; }
			echo '<span>' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affiliate-wpm' ), $page, (int) ceil( $count / 10 ) ) . '</span>';
			if ( $page * 10 < $count ) { echo '<a href="' . esc_url( add_query_arg( $key, $page + 1, wc_get_account_endpoint_url( 'affiliate-wpm' ) ) ) . '">' . esc_html__( 'Next', 'affiliate-wpm' ) . '</a>'; }
			echo '</nav>';
		}
		echo '</section>';
	}
	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Affiliate WPM', '<p>' . esc_html__( 'We count referral clicks in daily aggregate counters, without visitor IP addresses or browser fingerprints. A second signed cookie suppresses repeated consecutive clicks for 30 minutes. New customer account registrations may be linked to the referring affiliate while a valid referral cookie is present. Affiliates see only the customer first name; email addresses, surnames and other account details are not shown. Registration attribution and affiliate counters are removed through WordPress personal-data erasure or when the associated account is deleted.', 'affiliate-wpm' ) . '</p>' );
			wp_add_privacy_policy_content( 'Affiliate WPM', '<p>' . esc_html__( 'Our affiliate program uses a signed referral cookie to attribute purchases. The cookie stores an affiliate identifier and expiry time. We store affiliate enrollment, order-linked commissions, and withdrawal requests with payment destination details in our WordPress database. Authorized store managers can access these records to process payouts. Referral cookies expire after the configured duration. Financial records are retained until the store administrator removes them according to the store retention policy. We do not send this information to an external payout provider automatically.', 'affiliate-wpm' ) . '</p>' );
		}
	}
	public static function exporter( $exporters ) {
		$exporters['affiliate-wpm'] = array( 'exporter_friendly_name' => 'Affiliate WPM', 'callback' => array( __CLASS__, 'export_data' ) );
		return $exporters;
	}
	public static function export_data( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) { return array( 'data' => array(), 'done' => true ); }
		$data = array();
		$done = true;
		$page = max( 1, absint( $page ) );
		if ( 1 === $page && get_user_meta( $user->ID, '_awpm_enrolled', true ) ) {
			$data[] = array( 'group_id' => 'affiliate-wpm', 'group_label' => 'Affiliate WPM', 'item_id' => 'awpm-profile-' . $user->ID, 'data' => array( array( 'name' => __( 'Enrollment', 'affiliate-wpm' ), 'value' => get_user_meta( $user->ID, '_awpm_enrolled', true ) ), array( 'name' => __( 'Referral token', 'affiliate-wpm' ), 'value' => get_user_meta( $user->ID, '_awpm_token', true ) ), array( 'name' => __( 'Blocked', 'affiliate-wpm' ), 'value' => get_user_meta( $user->ID, '_awpm_blocked', true ) ) ) );
		}
		$customers = AWPM_Store::table( 'customers' );
		$referrals = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $customers WHERE customer_id=%d OR affiliate_id=%d ORDER BY customer_id ASC LIMIT 50 OFFSET %d", $user->ID, $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
		if ( 1 === $page && 'none' !== AWPM_Affiliates::status( $user->ID ) ) {
			$data[] = array( 'group_id' => 'affiliate-wpm', 'group_label' => 'Affiliate WPM', 'item_id' => 'awpm-approval-' . $user->ID, 'data' => array( array( 'name' => __( 'Affiliate approval status', 'affiliate-wpm' ), 'value' => AWPM_Affiliates::status( $user->ID ) ) ) );
		}
		if ( count( $referrals ) === 50 ) { $done = false; }
		foreach ( $referrals as $referral ) {
			$values = array();
			foreach ( $referral as $key => $value ) { $values[] = array( 'name' => $key, 'value' => (string) $value ); }
			$data[] = array( 'group_id' => 'awpm-customers', 'group_label' => __( 'Affiliate customer registration', 'affiliate-wpm' ), 'item_id' => 'awpm-customer-' . $referral['customer_id'], 'data' => $values );
		}
		$clicks = AWPM_Store::table( 'clicks' );
		$days = $wpdb->get_results( $wpdb->prepare( "SELECT click_date,clicks FROM $clicks WHERE affiliate_id=%d ORDER BY click_date ASC LIMIT 50 OFFSET %d", $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
		if ( count( $days ) === 50 ) { $done = false; }
		foreach ( $days as $day ) {
			$data[] = array( 'group_id' => 'awpm-clicks', 'group_label' => __( 'Affiliate click counters', 'affiliate-wpm' ), 'item_id' => 'awpm-clicks-' . $day['click_date'], 'data' => array( array( 'name' => 'click_date', 'value' => $day['click_date'] ), array( 'name' => 'clicks', 'value' => (string) $day['clicks'] ) ) );
		}
		foreach ( array( 'commissions', 'payouts' ) as $type ) {
			$table = AWPM_Store::table( $type );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE affiliate_id=%d ORDER BY id ASC LIMIT 50 OFFSET %d", $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
			if ( count( $rows ) === 50 ) { $done = false; }
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $key => $value ) { $values[] = array( 'name' => $key, 'value' => (string) $value ); }
				$data[] = array( 'group_id' => 'awpm-' . $type, 'group_label' => 'commissions' === $type ? __( 'Affiliate commissions', 'affiliate-wpm' ) : __( 'Affiliate withdrawals', 'affiliate-wpm' ), 'item_id' => 'awpm-' . $type . '-' . $row['id'], 'data' => $values );
			}
		}
		return array( 'data' => $data, 'done' => $done );
	}
	public static function eraser( $erasers ) {
		$erasers['affiliate-wpm'] = array( 'eraser_friendly_name' => 'Affiliate WPM', 'callback' => array( __CLASS__, 'erase_data' ) );
		return $erasers;
	}
	public static function erase_data( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( ! $user ) { return $result; }
		foreach ( array( '_awpm_token', '_awpm_enrolled', '_awpm_blocked', '_awpm_approval' ) as $key ) { $result['items_removed'] = delete_user_meta( $user->ID, $key ) || $result['items_removed']; }
		$customers = AWPM_Store::table( 'customers' );
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM $customers WHERE customer_id=%d OR affiliate_id=%d", $user->ID, $user->ID ) );
		$result['items_removed'] = $removed > 0 || $result['items_removed'];
		$result['items_removed'] = $wpdb->delete( AWPM_Store::table( 'clicks' ), array( 'affiliate_id' => $user->ID ) ) > 0 || $result['items_removed'];
		foreach ( array( 'commissions', 'payouts' ) as $type ) {
			$table = AWPM_Store::table( $type );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE affiliate_id=%d LIMIT 1", $user->ID ) ) ) { $result['items_retained'] = true; }
		}
		if ( $result['items_retained'] ) { $result['messages'][] = __( 'Affiliate WPM retained commission and withdrawal records, including payment destinations, for financial reconciliation. The store administrator must review their retention policy before removing these records.', 'affiliate-wpm' ); }
		return $result;
	}
}
