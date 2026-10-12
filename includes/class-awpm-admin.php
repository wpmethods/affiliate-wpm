<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( 'woocommerce_page_affiliate-wpm' === $hook ) {
				wp_enqueue_style( 'awpm-admin', AWPM_URL . 'assets/affiliate-wpm.css', array(), AWPM_VERSION );
				wp_enqueue_style( 'awpm-panel', AWPM_URL . 'assets/panel.css', array( 'awpm-admin' ), (string) filemtime( AWPM_PATH . 'assets/panel.css' ) );
				AWPM_Design::enqueue( 'awpm-panel' );
				wp_enqueue_script( 'awpm-admin', AWPM_URL . 'assets/admin.js', array(), (string) filemtime( AWPM_PATH . 'assets/admin.js' ), true );
				wp_localize_script( 'awpm-admin', 'awpmAdmin', array( 'unsaved' => __( 'You have unsaved changes.', 'affiliate-wpm' ), 'saving' => __( 'Saving your changes...', 'affiliate-wpm' ) ) );
			}
		} );
		add_action( 'admin_post_awpm_payout', array( __CLASS__, 'payout_action' ) );
		add_action( 'admin_post_awpm_affiliate', array( __CLASS__, 'affiliate_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AWPM_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'affiliate-wpm' ) . '</a>' );
			return $links;
		} );
	}
	public static function pending_affiliates_count() {
		$query = new WP_User_Query( array(
			'fields' => 'ID', 'number' => 1, 'count_total' => true,
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_awpm_enrolled', 'value' => 'yes' ),
				array( 'key' => '_awpm_approval', 'value' => 'pending' ),
			),
		) );
		return absint( $query->get_total() );
	}
	public static function pending_counts() {
		global $wpdb;
		$commissions = AWPM_Store::table( 'commissions' );
		$payouts = AWPM_Store::table( 'payouts' );
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM $commissions WHERE status=%s) AS commissions, (SELECT COUNT(*) FROM $payouts WHERE status=%s) AS payouts", 'pending', 'pending' ), ARRAY_A );
		return array( 'affiliates' => self::pending_affiliates_count(), 'commissions' => absint( $counts['commissions'] ?? 0 ), 'payouts' => absint( $counts['payouts'] ?? 0 ) );
	}
	public static function pending_badge( $count ) {
		$count = absint( $count ); if ( ! $count ) { return ''; }
		$label = sprintf( _n( '%s pending item', '%s pending items', $count, 'affiliate-wpm' ), number_format_i18n( $count ) );
		return '<span class="awpm-nav-count" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}
	public static function menu() {
		$title = 'Affiliate WPM';
		$count = current_user_can( 'manage_woocommerce' ) ? self::pending_affiliates_count() : 0;
		if ( $count ) {
			$description = sprintf( _n( '%s pending affiliate application', '%s pending affiliate applications', $count, 'affiliate-wpm' ), number_format_i18n( $count ) );
			$title .= ' <span class="update-plugins count-' . $count . '"><span class="plugin-count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $description ) . '</span></span>';
		}
		add_submenu_page( 'woocommerce', 'Affiliate WPM', $title, 'manage_woocommerce', 'affiliate-wpm', array( __CLASS__, 'render' ) );
	}
	public static function payout_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		$id = absint( AWPM_Security::value( $_POST, 'payout_id', '0' ) );
		AWPM_Security::admin_request( 'awpm_payout_' . $id );
		$status = sanitize_key( wp_unslash( AWPM_Security::value( $_POST, 'status' ) ) );
		$note = sanitize_textarea_field( wp_unslash( AWPM_Security::value( $_POST, 'note' ) ) );
		$result = AWPM_Payouts::transition( $id, $status, $note );
		$url = add_query_arg( array( 'page' => 'affiliate-wpm', 'tab' => 'payouts', 'awpm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal updated.', 'affiliate-wpm' ), 'awpm_error' => is_wp_error( $result ) ? 1 : 0 ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}
	public static function affiliate_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		$id = absint( AWPM_Security::value( $_POST, 'affiliate_id', '0' ) );
		AWPM_Security::admin_request( 'awpm_affiliate_' . $id );
		$operation = sanitize_key( wp_unslash( AWPM_Security::value( $_POST, 'operation' ) ) ); $result = true;
		if ( in_array( $operation, array( 'approved', 'rejected' ), true ) ) { $result = AWPM_Affiliates::review( $id, $operation ); }
		elseif ( get_userdata( $id ) && in_array( $operation, array( 'block', 'unblock' ), true ) ) { $result = AWPM_Affiliates::set_blocked( $id, 'block' === $operation ); }
		else { $result = new WP_Error( 'invalid', __( 'Invalid affiliate action.', 'affiliate-wpm' ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'affiliate-wpm', 'tab' => 'affiliates', 'awpm_error' => is_wp_error( $result ) ? 1 : 0, 'awpm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Affiliate account updated.', 'affiliate-wpm' ) ), admin_url( 'admin.php' ) ) ); exit;
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$tabs = array( 'overview' => __( 'Overview', 'affiliate-wpm' ), 'affiliates' => __( 'Affiliates', 'affiliate-wpm' ), 'commissions' => __( 'Commissions', 'affiliate-wpm' ), 'payouts' => __( 'Withdrawals', 'affiliate-wpm' ), 'settings' => __( 'Settings', 'affiliate-wpm' ), 'sharing' => __( 'Sharing', 'affiliate-wpm' ), 'emails' => __( 'Emails', 'affiliate-wpm' ), 'customization' => __( 'Customization', 'affiliate-wpm' ) );
		$descriptions = array( 'overview' => __( 'A clear view of your partner program and recent activity.', 'affiliate-wpm' ), 'affiliates' => __( 'Review applications and manage your affiliate community.', 'affiliate-wpm' ), 'commissions' => __( 'Follow order earnings, adjustments and settled commissions.', 'affiliate-wpm' ), 'payouts' => __( 'Review withdrawal requests and record your manual payments.', 'affiliate-wpm' ), 'settings' => __( 'Set the rules for joining, commissions, referrals and withdrawals.', 'affiliate-wpm' ), 'sharing' => __( 'Choose how partners share your products.', 'affiliate-wpm' ), 'emails' => __( 'Keep affiliates informed with clear, personal notifications.', 'affiliate-wpm' ), 'customization' => __( 'Make the affiliate experience feel like your brand.', 'affiliate-wpm' ) );
		$tabs['tools'] = __( 'Tools', 'affiliate-wpm' );
		if ( current_user_can( 'manage_options' ) ) { $tabs['license'] = __( 'License', 'affiliate-wpm' ); }
		$descriptions['tools'] = __( 'Back up and restore settings, reset defaults and export your program records.', 'affiliate-wpm' );
		$descriptions['license'] = __( 'Manage your license and receive updates from WP Methods.', 'affiliate-wpm' );
		$tab = sanitize_key( AWPM_Security::value( $_GET, 'tab', 'overview' ) );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'overview'; }
		$active = AWPM_Settings::program_enabled();
		echo '<div class="wrap awpm awpm-admin"><a class="screen-reader-text" href="#awpm-main">' . esc_html__( 'Skip to panel content', 'affiliate-wpm' ) . '</a><header class="awpm-admin-header"><div class="awpm-brand"><span class="awpm-brand-icon">' . AWPM_UI::icon( 'sharing' ) . '</span><div><span class="awpm-eyebrow">WP METHODS</span><h1>Affiliate WPM <span class="awpm-version">' . esc_html( AWPM_VERSION ) . '</span></h1></div></div><div class="awpm-header-actions"><span class="awpm-program-state ' . ( $active ? 'is-active' : '' ) . '"><span aria-hidden="true"></span>' . esc_html( $active ? __( 'Program active', 'affiliate-wpm' ) : __( 'Program paused', 'affiliate-wpm' ) ) . '</span><a class="button awpm-primary" href="' . esc_url( wc_get_account_endpoint_url( 'affiliate-wpm' ) ) . '">' . esc_html__( 'Affiliate Area', 'affiliate-wpm' ) . AWPM_UI::icon( 'arrow' ) . '</a></div></header><div class="awpm-admin-layout"><aside class="awpm-sidebar"><p class="awpm-eyebrow">' . esc_html__( 'PROGRAM MANAGEMENT', 'affiliate-wpm' ) . '</p><nav class="awpm-tabs" aria-label="' . esc_attr__( 'Affiliate administration', 'affiliate-wpm' ) . '">';
		$pending_counts = self::pending_counts();
		foreach ( $tabs as $key => $label ) { echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=' . $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . AWPM_UI::icon( 'settings' === $key && ! AWPM_License::active() ? 'lock' : $key ) . '<span>' . esc_html( $label ) . '</span>' . self::pending_badge( $pending_counts[ $key ] ?? 0 ) . '</a>'; }
		echo '</nav><div class="awpm-sidebar-note">' . AWPM_UI::icon( 'shield' ) . '<strong>' . esc_html__( 'Built for your store', 'affiliate-wpm' ) . '</strong><p>' . esc_html__( 'Simple referrals. Clear earnings. Payouts under your control.', 'affiliate-wpm' ) . '</p></div></aside><main class="awpm-main" id="awpm-main"><div class="awpm-heading"><span class="awpm-eyebrow">' . esc_html__( 'YOUR PARTNER PROGRAM', 'affiliate-wpm' ) . '</span><h2>' . esc_html( $tabs[ $tab ] ) . '</h2><p>' . esc_html( $descriptions[ $tab ] ) . '</p></div>';
		AWPM_License::activation_notice( true );
		if ( '1' === AWPM_Security::value( $_GET, 'saved' ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'affiliate-wpm' ) . '</p></div>'; }
		if ( isset( $_GET['awpm_message'] ) && is_scalar( $_GET['awpm_message'] ) ) { echo '<div class="notice ' . ( '1' === AWPM_Security::value( $_GET, 'awpm_error' ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['awpm_message'] ) ) ) . '</p></div>'; }
		if ( 'settings' === $tab ) { if ( AWPM_License::active() ) { self::settings(); } else { self::locked_settings(); } }
		elseif ( 'overview' === $tab ) { self::overview(); }
		elseif ( 'affiliates' === $tab ) { self::affiliates(); }
		elseif ( 'sharing' === $tab ) { AWPM_Sharing::render(); }
		elseif ( 'emails' === $tab ) { AWPM_Emails::render(); }
		elseif ( 'customization' === $tab ) { AWPM_Design::render(); }
		elseif ( 'license' === $tab ) { AWPM_License::render(); }
		elseif ( 'tools' === $tab ) { AWPM_Tools::render(); }
		else { self::records( $tab ); }
		echo '<p class="awpm-footer">Affiliate WPM &middot; WP Methods</p></main></div></div>';
	}
	private static function overview() {
		global $wpdb;
		$commissions = AWPM_Store::table( 'commissions' );
		$payouts = AWPM_Store::table( 'payouts' );
		$currency = AWPM_Settings::get( 'currency' );
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $commissions WHERE currency=%s AND status='pending'", $currency ) );
		$available = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount-paid_amount),0) FROM $commissions WHERE currency=%s AND payout_id=0 AND status IN ('available','paid','rejected')", $currency ) );
		$requested = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $payouts WHERE currency=%s AND status IN ('pending','approved')", $currency ) );
		$paid = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $payouts WHERE currency=%s AND status='completed'", $currency ) );
		echo '<div class="awpm-metrics">';
		foreach ( array( __( 'Pending commissions', 'affiliate-wpm' ) => $pending, __( 'Available earnings', 'affiliate-wpm' ) => $available, __( 'Awaiting payout', 'affiliate-wpm' ) => $requested, __( 'Completed payouts', 'affiliate-wpm' ) => $paid ) as $label => $amount ) { echo '<div class="awpm-metric"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( AWPM_Store::money( $amount, $currency ) ) . '</strong></div>'; }
		echo '</div>';
		$stats = AWPM_Store::referral_stats();
		echo '<div class="awpm-referral-metrics">';
		foreach ( array( __( 'Referral clicks', 'affiliate-wpm' ) => $stats['clicks'], __( 'Registered customers', 'affiliate-wpm' ) => $stats['customers'] ) as $label => $value ) {
			echo '<div class="awpm-metric"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div>';
		}
		echo '</div>'; self::overview_lists();
		echo '<div class="awpm-grid"><section class="awpm-card"><h2>' . esc_html__( 'Program at a glance', 'affiliate-wpm' ) . '</h2><dl class="awpm-definition">';
		$rate = 'percent' === AWPM_Settings::get( 'commission_type' ) ? esc_html( AWPM_Settings::get( 'commission_rate' ) ) . '%' : wp_kses_post( AWPM_Store::money( AWPM_Settings::get( 'commission_rate' ) ) ) . ' ' . esc_html__( 'per unit', 'affiliate-wpm' );
		$values = array( __( 'Program', 'affiliate-wpm' ) => AWPM_Settings::program_enabled() ? esc_html__( 'Active', 'affiliate-wpm' ) : esc_html__( 'Paused', 'affiliate-wpm' ), __( 'Default commission', 'affiliate-wpm' ) => $rate, __( 'Cookie duration', 'affiliate-wpm' ) => (int) AWPM_Settings::get( 'cookie_days' ) . ' ' . esc_html__( 'days', 'affiliate-wpm' ), __( 'Minimum withdrawal', 'affiliate-wpm' ) => wp_kses_post( AWPM_Store::money( AWPM_Settings::get( 'minimum' ) ) ), __( 'Active payout methods', 'affiliate-wpm' ) => count( AWPM_Settings::methods() ) );
		foreach ( $values as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>'; }
		echo '</dl><a class="button awpm-primary" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=settings' ) ) . '">' . esc_html__( 'Manage settings', 'affiliate-wpm' ) . '</a></section><section class="awpm-card"><h2>' . esc_html__( 'How it works', 'affiliate-wpm' ) . '</h2><ol class="awpm-steps"><li>' . esc_html__( 'Customers join from My Account → Affiliate Area.', 'affiliate-wpm' ) . '</li><li>' . esc_html__( 'A referral link sets a signed cookie and attributes new orders.', 'affiliate-wpm' ) . '</li><li>' . esc_html__( 'Completed orders release commissions for withdrawal.', 'affiliate-wpm' ) . '</li><li>' . esc_html__( 'Approve a request, send the payment yourself, then mark it completed.', 'affiliate-wpm' ) . '</li></ol><p class="awpm-muted">' . esc_html__( 'Commission excludes tax and shipping. Refunds adjust balances automatically. No money is transferred by this plugin.', 'affiliate-wpm' ) . '</p></section></div>';
	}
	private static function field( $key, $label, $help, $type = 'text', $options = array(), $attributes = '' ) {
		$value = AWPM_Settings::get( $key );
		echo '<div class="awpm-field"><label for="awpm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			echo '<select name="awpm[' . esc_attr( $key ) . ']" id="awpm-' . esc_attr( $key ) . '">';
			foreach ( $options as $id => $name ) { echo '<option value="' . esc_attr( $id ) . '"' . selected( $value, $id, false ) . '>' . esc_html( $name ) . '</option>'; }
			echo '</select>';
		} else { echo '<input type="' . esc_attr( $type ) . '" name="awpm[' . esc_attr( $key ) . ']" id="awpm-' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" ' . $attributes . '>'; }
		echo '<p class="awpm-muted">' . esc_html( $help ) . '</p></div>';
	}
	private static function overview_lists() {
		global $wpdb;
		$table = AWPM_Store::table( 'payouts' );
		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 5" );
		echo '<div class="awpm-grid"><section class="awpm-card" id="awpm-overview-withdrawals"><div class="awpm-overview-heading"><h2>' . esc_html__( 'Latest withdrawal requests', 'affiliate-wpm' ) . '</h2><a class="awpm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=payouts' ) ) . '">' . esc_html__( 'See more withdrawals', 'affiliate-wpm' ) . '</a></div><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Amount', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . wp_kses_post( self::identity( $row->affiliate_id ) ) . '</td><td>' . wp_kses_post( AWPM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AWPM_Store::status( $row->status ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="3" class="awpm-empty">' . esc_html__( 'No withdrawal requests yet.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
		$users = get_users( array( 'meta_key' => '_awpm_enrolled', 'meta_value' => 'yes', 'number' => 5, 'orderby' => 'ID', 'order' => 'DESC' ) );
		echo '<section class="awpm-card" id="awpm-overview-affiliates"><div class="awpm-overview-heading"><h2>' . esc_html__( 'Latest affiliates', 'affiliate-wpm' ) . '</h2><a class="awpm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=affiliates' ) ) . '">' . esc_html__( 'See more affiliates', 'affiliate-wpm' ) . '</a></div><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Account', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) { echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . ( 'yes' === get_user_meta( $user->ID, '_awpm_blocked', true ) ? esc_html__( 'Blocked', 'affiliate-wpm' ) : wp_kses_post( AWPM_Store::status( AWPM_Affiliates::status( $user->ID ) ) ) ) . '</td></tr>'; }
		if ( ! $users ) { echo '<tr><td colspan="2" class="awpm-empty">' . esc_html__( 'No affiliates yet.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div></section></div>';
	}
	private static function locked_settings() {
		echo '<section class="awpm-card"><h2>' . AWPM_UI::icon( 'lock' ) . esc_html__( 'Settings are locked', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Activate your Affiliate WPM license to configure program settings.', 'affiliate-wpm' ) . '</p>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<a class="button awpm-primary" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=license' ) ) . '">' . AWPM_UI::icon( 'license' ) . esc_html__( 'Activate license', 'affiliate-wpm' ) . '</a>';
		} else {
			echo '<p>' . esc_html__( 'Ask your site administrator to activate the license.', 'affiliate-wpm' ) . '</p>';
		}
		echo '</section>';
	}
	private static function settings() {
		echo '<form id="awpm-program-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="awpm-settings"><input type="hidden" name="action" value="awpm_save_settings">';
		wp_nonce_field( 'awpm_settings' );
		echo '<nav class="awpm-section-nav" aria-label="' . esc_attr__( 'Settings sections', 'affiliate-wpm' ) . '"><a href="#awpm-program-settings">' . esc_html__( 'Enrollment', 'affiliate-wpm' ) . '</a><a href="#awpm-commissions-settings">' . esc_html__( 'Commissions', 'affiliate-wpm' ) . '</a><a href="#awpm-tracking-settings">' . esc_html__( 'Tracking', 'affiliate-wpm' ) . '</a><a href="#awpm-payouts-settings">' . esc_html__( 'Payouts', 'affiliate-wpm' ) . '</a><a href="#awpm-terms-settings">' . esc_html__( 'Terms & Conditions', 'affiliate-wpm' ) . '</a></nav>';
		echo '<section class="awpm-card" id="awpm-program-settings"><h2>' . AWPM_UI::icon( 'affiliates' ) . esc_html__( 'Program & enrollment', 'affiliate-wpm' ) . '</h2><label class="awpm-toggle"><input type="checkbox" name="awpm[enabled]" value="yes"' . checked( AWPM_Settings::get( 'enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable affiliate program', 'affiliate-wpm' ) . '</label><p class="awpm-muted">' . esc_html__( 'Pausing stops new enrollments, referrals and withdrawals. Existing order commissions and history continue to update.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'approval_required', __( 'Affiliate account approval', 'affiliate-wpm' ), __( 'New accounts join automatically by default. Enable review to approve or reject new applications from the Affiliates tab. Existing affiliates remain approved; pending applications still need review.', 'affiliate-wpm' ), 'select', array( 'no' => __( 'Join automatically', 'affiliate-wpm' ), 'yes' => __( 'Require administrator approval', 'affiliate-wpm' ) ) );
		echo '</div><h3>' . esc_html__( 'Join Affiliate page', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Customize the invitation customers see in the Affiliate Area before joining.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'join_title', __( 'Join Affiliate title', 'affiliate-wpm' ), __( 'Heading on the page before joining.', 'affiliate-wpm' ), 'text', array(), 'maxlength="180" required' );
		self::field( 'join_button_text', __( 'Join button text', 'affiliate-wpm' ), __( 'Label for the button that submits the affiliate application.', 'affiliate-wpm' ), 'text', array(), 'maxlength="180" required' );
		echo '<div class="awpm-field"><label for="awpm-join-description">' . esc_html__( 'Description text', 'affiliate-wpm' ) . '</label><textarea id="awpm-join-description" name="awpm[join_description]" rows="4" maxlength="3000" style="width:100%">' . esc_textarea( AWPM_Settings::get( 'join_description' ) ) . '</textarea><p>' . esc_html__( 'Plain text with line breaks. Leave empty to hide the description.', 'affiliate-wpm' ) . '</p></div>';
		self::field( 'join_show_rate', __( 'Default commission rate before joining', 'affiliate-wpm' ), __( 'Choose whether customers see your default percentage or fixed amount on the Join Affiliate page. Product-specific rates may differ.', 'affiliate-wpm' ), 'select', array( 'no' => __( 'Hide', 'affiliate-wpm' ), 'yes' => __( 'Show', 'affiliate-wpm' ) ) );
		echo '</div></section><section class="awpm-card" id="awpm-commissions-settings"><h2>' . AWPM_UI::icon( 'commissions' ) . esc_html__( 'Commission rules', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Set your default earnings and currency. Individual products can override these rates.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'commission_type', __( 'Commission type', 'affiliate-wpm' ), __( 'Product settings override this default.', 'affiliate-wpm' ), 'select', array( 'percent' => __( 'Percentage', 'affiliate-wpm' ), 'fixed' => __( 'Fixed amount per unit', 'affiliate-wpm' ) ) );
		self::field( 'commission_rate', __( 'Default commission', 'affiliate-wpm' ), __( 'Percentage: 0–100. Fixed: amount per purchased unit, capped at the item total.', 'affiliate-wpm' ), 'number', array(), 'min="0" max="1000000" step="0.000001" required' );
		self::field( 'release_mode', __( 'Commission availability', 'affiliate-wpm' ), __( 'By default, commissions become available as soon as the order is Completed. Optionally hold them for a number of days after completion. Refunds and cancellations still adjust commissions. Changes apply when an order first completes; previously released commissions stay available.', 'affiliate-wpm' ), 'select', array( 'completed' => __( 'Immediately after order completion (default)', 'affiliate-wpm' ), 'delay' => __( 'After a waiting period', 'affiliate-wpm' ) ) );
		echo '<div id="awpm-release-days"' . ( 'delay' === AWPM_Settings::get( 'release_mode' ) ? '' : ' hidden' ) . '>';
		self::field( 'release_days', __( 'Waiting period (days)', 'affiliate-wpm' ), __( '1 to 365 days, counted from the order completion time. A saved waiting period is not changed by later settings updates.', 'affiliate-wpm' ), 'number', array(), 'min="1" max="365" step="1" required' );
		echo '</div>';
		self::field( 'currency', __( 'Affiliate currency', 'affiliate-wpm' ), __( 'Commissions and fixed per-unit rates use this currency. Different order currencies use a saved exchange rate. Changing currency keeps existing balances in their original currency.', 'affiliate-wpm' ), 'select', get_woocommerce_currencies() );
		self::field( 'exchange_source', __( 'Exchange rate source', 'affiliate-wpm' ), __( 'Automatic detects FOX/WOOCS or CURCY, then uses manual rates if neither supplies this pair. Choose one provider explicitly if both are installed. The rate is saved per order.', 'affiliate-wpm' ), 'select', array( 'auto' => __( 'Automatic', 'affiliate-wpm' ), 'fox' => __( 'FOX / WOOCS', 'affiliate-wpm' ), 'curcy' => __( 'CURCY', 'affiliate-wpm' ), 'manual' => __( 'Manual rates only', 'affiliate-wpm' ), 'same' => __( 'Same currency only', 'affiliate-wpm' ) ) );
		echo '<div class="awpm-field" id="awpm-manual-exchange-rates"' . ( in_array( AWPM_Settings::get( 'exchange_source' ), array( 'auto', 'manual' ), true ) ? '' : ' hidden' ) . '><label for="awpm-exchange-rates">' . esc_html__( 'Manual exchange rates', 'affiliate-wpm' ) . '</label><textarea id="awpm-exchange-rates" name="awpm[exchange_rates]" class="awpm-code" rows="4" maxlength="6000" style="width:100%">' . esc_textarea( AWPM_Settings::get( 'exchange_rates' ) ) . '</textarea><p>' . esc_html__( 'One pair per line: FROM TO RATE. RATE means 1 FROM equals RATE TO. For example, if your chosen rate is 1 USD = 120 BDT, enter USD BDT 120; BDT to USD is derived automatically. This is a format example, not a live market rate. Up to 50 pairs. No valid rate means the order commission waits for administrator recovery.', 'affiliate-wpm' ) . '</p></div>';
		echo '</div></section><section class="awpm-card" id="awpm-tracking-settings"><h2>' . AWPM_UI::icon( 'clock' ) . esc_html__( 'Referral tracking & repeat purchases', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Choose how long referrals last and whether returning customers can earn additional commissions.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'cookie_days', __( 'Cookie lifetime (days)', 'affiliate-wpm' ), __( 'Between 1 and 365 days.', 'affiliate-wpm' ), 'number', array(), 'min="1" max="365" step="1" required' );
		self::field( 'attribution', __( 'Referral attribution', 'affiliate-wpm' ), __( 'First referral keeps the first valid cookie. Last referral replaces it.', 'affiliate-wpm' ), 'select', array( 'first' => __( 'First referral', 'affiliate-wpm' ), 'last' => __( 'Last referral', 'affiliate-wpm' ) ) );
		self::field( 'purchase_policy', __( 'Repeat purchase commissions', 'affiliate-wpm' ), __( 'Once: only one eligible commission-earning purchase per customer across all affiliates and products. Multiple: each eligible referred order earns commission. Customers are matched by account or billing email. Applies to new orders only.', 'affiliate-wpm' ), 'select', array( 'multiple' => __( 'Every eligible referred purchase', 'affiliate-wpm' ), 'once' => __( 'Once per customer', 'affiliate-wpm' ) ) );
		echo '</div></section><section class="awpm-card" id="awpm-payouts-settings"><h2>' . AWPM_UI::icon( 'payouts' ) . esc_html__( 'Payout settings', 'affiliate-wpm' ) . '</h2><div class="awpm-form-grid">';
		self::field( 'minimum', __( 'Minimum withdrawal', 'affiliate-wpm' ), __( 'Customers request their entire available balance, after refund adjustments.', 'affiliate-wpm' ), 'number', array(), 'min="0.01" max="1000000" step="0.000001" required' );
		self::field( 'payout_mode', __( 'Enable payout', 'affiliate-wpm' ), __( 'Manual payouts are processed outside this plugin. Pause withdrawals whenever needed.', 'affiliate-wpm' ), 'select', array( 'manual' => __( 'Enabled', 'affiliate-wpm' ), 'disabled' => __( 'Withdrawals paused', 'affiliate-wpm' ) ) );
		self::field( 'method_selection', __( 'Available methods', 'affiliate-wpm' ), __( 'Single keeps only the first checked method active. Multiple lets customers choose among all checked methods.', 'affiliate-wpm' ), 'select', array( 'single' => __( 'One active method', 'affiliate-wpm' ), 'multiple' => __( 'Multiple active methods', 'affiliate-wpm' ) ) );
		echo '</div><div id="awpm-manual-methods"><h3>' . esc_html__( 'Payout methods', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Add up to 20 methods, such as bank transfer, PayPal, or a mobile wallet. Instructions tell customers what destination details to provide.', 'affiliate-wpm' ) . '</p><div id="awpm-method-list">';
		foreach ( AWPM_Settings::get( 'methods' ) as $index => $method ) { self::method_row( $index, $method ); }
		echo '</div><button type="button" class="button awpm-primary" id="awpm-add-method">' . esc_html__( '+ Add payout method', 'affiliate-wpm' ) . '</button><template id="awpm-method-template">';
		self::method_row( '__INDEX__', array( 'id' => '', 'name' => '', 'instructions' => '', 'active' => 'yes' ) );
		echo '</template></div></section><section class="awpm-card" id="awpm-products-settings"><h2>' . AWPM_UI::icon( 'link' ) . esc_html__( 'Product commissions & tracking', 'affiliate-wpm' ) . '</h2><p>' . esc_html__( 'Edit a product → Product data → General to set a percentage, fixed amount per unit, or disable commission for that product. Parent product settings cover all variations.', 'affiliate-wpm' ) . '</p><p class="awpm-muted">' . esc_html__( 'Exclude URLs containing awpm_ref from full-page caching so referral cookies can be set. Review the Affiliate WPM privacy policy text in WordPress Settings → Privacy and connect cookie consent through the awpm_tracking_allowed filter when required.', 'affiliate-wpm' ) . '</p></section>';
		echo '<section class="awpm-card" id="awpm-terms-settings"><h2>' . AWPM_UI::icon( 'affiliates' ) . esc_html__( 'Affiliate Terms & Conditions', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Customers see this content in the Affiliate Area before joining and must accept it. Leave it empty to join without a terms checkbox. Updates apply to new applications; existing affiliates keep their enrollment.', 'affiliate-wpm' ) . '</p><label for="awpm_terms_content">' . esc_html__( 'Terms & Conditions content', 'affiliate-wpm' ) . '</label>';
		wp_editor( AWPM_Settings::terms(), 'awpm_terms_content', array( 'textarea_name' => 'awpm[terms_content]', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true, 'quicktags' => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ) ) );
		echo '</section>';
		echo '</form>';
		AWPM_Currency::recovery_form();
		AWPM_UI::footer( __( 'Save settings', 'affiliate-wpm' ), 'awpm-program-settings-form' );
	}
	private static function method_row( $index, $method ) {
		$prefix = 'awpm[methods][' . $index . ']';
		echo '<div class="awpm-method-row"><input type="hidden" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( $method['id'] ) . '"><div class="awpm-method-top"><label>' . esc_html__( 'Method name', 'affiliate-wpm' ) . '<input type="text" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $method['name'] ) . '" maxlength="120" placeholder="' . esc_attr__( 'e.g. Bank transfer', 'affiliate-wpm' ) . '"></label><label class="awpm-toggle"><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="yes"' . checked( $method['active'], 'yes', false ) . '> ' . esc_html__( 'Active', 'affiliate-wpm' ) . '</label><button type="button" class="button awpm-remove-method awpm-primary">' . esc_html__( 'Remove', 'affiliate-wpm' ) . '</button></div><label>' . esc_html__( 'Instructions for customers', 'affiliate-wpm' ) . '<textarea name="' . esc_attr( $prefix . '[instructions]' ) . '" rows="2" maxlength="2000">' . esc_textarea( $method['instructions'] ) . '</textarea></label></div>';
	}
	private static function identity( $id ) {
		$user = get_userdata( $id );
		return $user ? '<strong>' . esc_html( $user->display_name ) . '</strong><small class="awpm-note">' . esc_html( $user->user_email ) . '</small>' : '#' . absint( $id );
	}
	private static function affiliates() {
		$page = AWPM_Security::page( $_GET, 'paged' );
		$query = new WP_User_Query( array( 'meta_key' => '_awpm_enrolled', 'meta_value' => 'yes', 'number' => 20, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'ID', 'order' => 'DESC' ) );
		echo '<section class="awpm-card"><h2>' . esc_html__( 'Affiliates', 'affiliate-wpm' ) . '</h2><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Available balance', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Clicks', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Registered customers', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Account', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Action', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $query->get_results() as $user ) {
			$blocked = 'yes' === get_user_meta( $user->ID, '_awpm_blocked', true );
			$stats = AWPM_Store::referral_stats( $user->ID );
			$approval = AWPM_Affiliates::status( $user->ID );
			echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . wp_kses_post( AWPM_Store::money( AWPM_Store::balance( $user->ID, AWPM_Settings::get( 'currency' ) ) ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['customers'] ) ) . '</td><td>' . ( $blocked ? esc_html__( 'Blocked', 'affiliate-wpm' ) : wp_kses_post( AWPM_Store::status( $approval ) ) ) . '</td><td>';
			$actions = 'pending' === $approval ? array( 'approved' => __( 'Approve', 'affiliate-wpm' ), 'rejected' => __( 'Reject', 'affiliate-wpm' ) ) : ( 'rejected' === $approval ? array( 'approved' => __( 'Approve', 'affiliate-wpm' ) ) : array( $blocked ? 'unblock' : 'block' => $blocked ? __( 'Unblock', 'affiliate-wpm' ) : __( 'Block', 'affiliate-wpm' ) ) );
			echo '<form class="awpm-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_affiliate"><input type="hidden" name="affiliate_id" value="' . absint( $user->ID ) . '">'; wp_nonce_field( 'awpm_affiliate_' . $user->ID );
			foreach ( $actions as $operation => $label ) { echo '<button class="button awpm-primary" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button>'; }
			echo '</form></td></tr>';
		}
		if ( ! $query->get_results() ) { echo '<tr><td colspan="6" class="awpm-empty">' . esc_html__( 'Customers who join the program will appear here.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $query->get_total(), $page, 'affiliates' ); echo '</section>';
	}
	private static function records( $type ) {
		global $wpdb;
		$table = AWPM_Store::table( $type );
		$page = AWPM_Security::page( $_GET, 'paged' );
		$allowed = 'payouts' === $type ? array( 'pending', 'approved', 'completed', 'rejected' ) : array( 'pending', 'available', 'reserved', 'paid', 'rejected' );
		$status = sanitize_key( AWPM_Security::value( $_GET, 'status' ) );
		$where = in_array( $status, $allowed, true ) ? $wpdb->prepare( 'WHERE status=%s', $status ) : '';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table $where ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ) );
		echo '<section class="awpm-card"><div class="awpm-section-heading"><h2>' . esc_html( 'payouts' === $type ? __( 'Withdrawal requests', 'affiliate-wpm' ) : __( 'Commission ledger', 'affiliate-wpm' ) ) . '</h2><form method="get"><input type="hidden" name="page" value="affiliate-wpm"><input type="hidden" name="tab" value="' . esc_attr( $type ) . '"><label class="screen-reader-text" for="awpm-status-filter">' . esc_html__( 'Filter status', 'affiliate-wpm' ) . '</label><select id="awpm-status-filter" name="status"><option value="">' . esc_html__( 'All statuses', 'affiliate-wpm' ) . '</option>';
		foreach ( $allowed as $option ) { echo '<option value="' . esc_attr( $option ) . '"' . selected( $status, $option, false ) . '>' . esc_html( wp_strip_all_tags( AWPM_Store::status( $option ) ) ) . '</option>'; }
		echo '</select> <button class="button awpm-primary">' . esc_html__( 'Filter', 'affiliate-wpm' ) . '</button></form></div>';
		if ( 'payouts' === $type ) { echo '<p class="awpm-muted">' . esc_html__( 'Approve → send payment using the destination details → mark completed. Completing a withdrawal records your payment; it does not transfer funds. Notes are visible to the affiliate.', 'affiliate-wpm' ) . '</p>'; }
		echo '<div class="awpm-table-scroll"><table><thead><tr><th>#</th><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Amount', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Date', 'affiliate-wpm' ) . '</th><th>' . esc_html( 'payouts' === $type ? __( 'Payment & action', 'affiliate-wpm' ) : __( 'Order / paid', 'affiliate-wpm' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . absint( $row->id ) . '</td><td>' . wp_kses_post( self::identity( $row->affiliate_id ) ) . '</td><td>' . wp_kses_post( AWPM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AWPM_Store::status( $row->status ) ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td><td>';
			if ( 'commissions' === $type ) {
				$order = wc_get_order( $row->order_id );
				if ( 'repeat' === $row->eligibility ) { echo '<small class="awpm-note">' . esc_html__( 'Repeat purchase excluded', 'affiliate-wpm' ) . '</small>'; }
				if ( $order ) { echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . absint( $row->order_id ) . '</a>'; }
				else { echo '#' . absint( $row->order_id ); }
				echo '<small class="awpm-note">' . esc_html__( 'Settled:', 'affiliate-wpm' ) . ' ' . wp_kses_post( AWPM_Store::money( $row->paid_amount, $row->currency ) ) . '</small>';
			} else {
				echo '<strong>' . esc_html( $row->method ) . '</strong><details class="awpm-payment"><summary>' . esc_html__( 'Payment details', 'affiliate-wpm' ) . '</summary><p>' . nl2br( esc_html( $row->details ) ) . '</p></details>';
				if ( in_array( $row->status, array( 'pending', 'approved' ), true ) ) {
					echo '<form class="awpm-payout-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_payout"><input type="hidden" name="payout_id" value="' . absint( $row->id ) . '">';
					wp_nonce_field( 'awpm_payout_' . $row->id );
					echo '<label class="screen-reader-text" for="awpm-note-' . absint( $row->id ) . '">' . esc_html__( 'Note / payment reference', 'affiliate-wpm' ) . '</label><textarea id="awpm-note-' . absint( $row->id ) . '" name="note" rows="2" maxlength="2000" placeholder="' . esc_attr__( 'Note / payment reference', 'affiliate-wpm' ) . '">' . esc_textarea( $row->note ) . '</textarea><div class="awpm-actions">';
					if ( 'pending' === $row->status ) { echo '<button class="button button-primary awpm-primary" name="status" value="approved">' . esc_html__( 'Approve', 'affiliate-wpm' ) . '</button>'; }
					else { echo '<button class="button button-primary awpm-primary" name="status" value="completed" data-awpm-confirm="' . esc_attr__( 'Confirm that you have already sent this payment. This will settle the reserved commissions.', 'affiliate-wpm' ) . '">' . esc_html__( 'Mark paid', 'affiliate-wpm' ) . '</button>'; }
					echo '<button class="button awpm-primary" name="status" value="rejected">' . esc_html__( 'Reject', 'affiliate-wpm' ) . '</button></div></form>';
				} elseif ( $row->note ) { echo '<small class="awpm-note">' . esc_html( $row->note ) . '</small>'; }
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="6" class="awpm-empty">' . esc_html__( 'No records found.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, $page, $type, $status ); echo '</section>';
	}
	private static function pagination( $count, $page, $tab, $status = '' ) {
		if ( $count <= 20 ) { return; }
		$base = add_query_arg( array( 'page' => 'affiliate-wpm', 'tab' => $tab, 'status' => $status ), admin_url( 'admin.php' ) );
		echo '<nav class="awpm-pagination">';
		if ( $page > 1 ) { echo '<a class="button awpm-primary" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base ) ) . '">' . esc_html__( 'Previous', 'affiliate-wpm' ) . '</a>'; }
		echo '<span>' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affiliate-wpm' ), $page, (int) ceil( $count / 20 ) ) . '</span>';
		if ( $page * 20 < $count ) { echo '<a class="button awpm-primary" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base ) ) . '">' . esc_html__( 'Next', 'affiliate-wpm' ) . '</a>'; }
		echo '</nav>';
	}
}
