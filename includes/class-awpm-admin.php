<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Admin {
	const PAGE_SIZE = 10;
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( 'woocommerce_page_affiliate-wpm' === $hook ) {
				wp_enqueue_style( 'awpm-admin', AWPM_URL . 'assets/affiliate-wpm.css', array(), (string) filemtime( AWPM_PATH . 'assets/affiliate-wpm.css' ) );
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
			'fields' => 'ID', 'number' => 1, 'count_total' => true, 'cache_results' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required enrollment/pending predicates; WordPress caches IDs and totals and invalidates them when user metadata changes.
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$counts = $wpdb->get_row( $wpdb->prepare( 'SELECT (SELECT COUNT(*) FROM %i WHERE status=%s) AS commissions, (SELECT COUNT(*) FROM %i WHERE status=%s) AS payouts', $commissions, 'pending', $payouts, 'pending' ), ARRAY_A );
		return array( 'affiliates' => self::pending_affiliates_count(), 'commissions' => absint( $counts['commissions'] ?? 0 ), 'payouts' => absint( $counts['payouts'] ?? 0 ) );
	}
	public static function pending_badge( $count ) {
		$count = absint( $count ); if ( ! $count ) { return ''; }
		/* translators: %s: formatted count of pending items. */
		$label = sprintf( _n( '%s pending item', '%s pending items', $count, 'affiliate-wpm' ), number_format_i18n( $count ) );
		return '<span class="awpm-nav-count" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}
	public static function menu() {
		$title = 'Affiliate WPM';
		$count = current_user_can( 'manage_woocommerce' ) ? self::pending_affiliates_count() : 0;
		if ( $count ) {
		/* translators: %s: formatted count of pending affiliate applications. */
			$description = sprintf( _n( '%s pending affiliate application', '%s pending affiliate applications', $count, 'affiliate-wpm' ), number_format_i18n( $count ) );
			$title .= ' <span class="update-plugins count-' . $count . '"><span class="plugin-count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $description ) . '</span></span>';
		}
		add_submenu_page( 'woocommerce', 'Affiliate WPM', $title, 'manage_woocommerce', 'affiliate-wpm', array( __CLASS__, 'render' ) );
	}
	public static function payout_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validated integer ID is read only to construct the entity-specific nonce; admin_request() verifies that nonce before mutation.
		$id = absint( AWPM_Security::value( $_POST, 'payout_id', '0' ) );
		AWPM_Security::admin_request( 'awpm_payout_' . $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AWPM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$status = sanitize_key( wp_unslash( AWPM_Security::value( $_POST, 'status' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AWPM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$note = sanitize_textarea_field( wp_unslash( AWPM_Security::value( $_POST, 'note' ) ) );
		$result = AWPM_Payouts::transition( $id, $status, $note );
		$url = add_query_arg( array( 'page' => 'affiliate-wpm', 'tab' => 'payouts', 'awpm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal updated.', 'affiliate-wpm' ), 'awpm_error' => is_wp_error( $result ) ? 1 : 0 ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}
	public static function affiliate_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validated integer ID is read only to construct the entity-specific nonce; admin_request() verifies that nonce before mutation.
		$id = absint( AWPM_Security::value( $_POST, 'affiliate_id', '0' ) );
		AWPM_Security::admin_request( 'awpm_affiliate_' . $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AWPM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
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
		if ( current_user_can( 'manage_options' ) ) { $tabs['license'] = class_exists( 'AWPM_Pro_License', false ) ? __( 'License', 'affiliate-wpm' ) : __( 'Premium', 'affiliate-wpm' ); }
		$descriptions['tools'] = __( 'Back up and restore settings, reset defaults and export your program records.', 'affiliate-wpm' );
		$descriptions['license'] = __( 'Manage your license and receive updates from WP Methods.', 'affiliate-wpm' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$tab = sanitize_key( AWPM_Security::value( $_GET, 'tab', 'overview' ) );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'overview'; }
		$active = AWPM_Settings::program_enabled();
		echo '<div class="wrap awpm awpm-admin"><a class="screen-reader-text" href="#awpm-main">' . esc_html__( 'Skip to panel content', 'affiliate-wpm' ) . '</a><header class="awpm-admin-header"><div class="awpm-brand"><span class="awpm-brand-icon">' . wp_kses( AWPM_UI::icon( 'sharing' ), AWPM_UI::allowed_html() ) . '</span><div><span class="awpm-eyebrow">WP METHODS</span><h1>Affiliate WPM <span class="awpm-version">' . esc_html( AWPM_VERSION ) . '</span> <span class="awpm-tier-badge">' . esc_html( AWPM_License::active() ? __( 'Pro', 'affiliate-wpm' ) : __( 'Free', 'affiliate-wpm' ) ) . '</span></h1></div></div><div class="awpm-header-actions"><span class="awpm-program-state ' . ( $active ? 'is-active' : '' ) . '"><span aria-hidden="true"></span>' . esc_html( $active ? __( 'Program active', 'affiliate-wpm' ) : __( 'Program paused', 'affiliate-wpm' ) ) . '</span><a class="button awpm-primary" href="' . esc_url( wc_get_account_endpoint_url( 'affiliate-wpm' ) ) . '">' . esc_html__( 'Affiliate Area', 'affiliate-wpm' ) . wp_kses( AWPM_UI::icon( 'arrow' ), AWPM_UI::allowed_html() ) . '</a></div></header><div class="awpm-admin-layout"><aside class="awpm-sidebar"><p class="awpm-eyebrow">' . esc_html__( 'PROGRAM MANAGEMENT', 'affiliate-wpm' ) . '</p><nav class="awpm-tabs" aria-label="' . esc_attr__( 'Affiliate administration', 'affiliate-wpm' ) . '">';
		$pending_counts = self::pending_counts();
		foreach ( $tabs as $key => $label ) { echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=' . $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . wp_kses( AWPM_UI::icon( $key ), AWPM_UI::allowed_html() ) . '<span>' . esc_html( $label ) . '</span>' . wp_kses_post( self::pending_badge( $pending_counts[ $key ] ?? 0 ) ) . '</a>'; }
		echo '</nav><div class="awpm-sidebar-note">' . wp_kses( AWPM_UI::icon( 'shield' ), AWPM_UI::allowed_html() ) . '<strong>' . esc_html__( 'Built for your store', 'affiliate-wpm' ) . '</strong><p>' . esc_html__( 'Simple referrals. Clear earnings. Payouts under your control.', 'affiliate-wpm' ) . '</p></div></aside><main class="awpm-main" id="awpm-main"><div class="awpm-heading"><span class="awpm-eyebrow">' . esc_html__( 'YOUR PARTNER PROGRAM', 'affiliate-wpm' ) . '</span><h2>' . esc_html( $tabs[ $tab ] ) . '</h2><p>' . esc_html( $descriptions[ $tab ] ) . '</p></div>';
		AWPM_License::activation_notice( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		if ( '1' === AWPM_Security::value( $_GET, 'saved' ) ) { AWPM_UI::toast( __( 'Settings saved.', 'affiliate-wpm' ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		if ( isset( $_GET['awpm_message'] ) && is_scalar( $_GET['awpm_message'] ) ) { AWPM_UI::toast( sanitize_text_field( wp_unslash( $_GET['awpm_message'] ) ), '1' === AWPM_Security::value( $_GET, 'awpm_error' ) ); }
		if ( 'settings' === $tab ) { self::settings(); }
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE currency=%s AND status=\'pending\'', $commissions, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$available = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount-paid_amount),0) FROM %i WHERE currency=%s AND payout_id=0 AND status IN (\'available\',\'paid\',\'rejected\')', $commissions, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$requested = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE currency=%s AND status IN (\'pending\',\'approved\',\'processing\')', $payouts, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$paid = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(paid_amount),0) FROM %i WHERE currency=%s', $commissions, $currency ) );
		echo '<div class="awpm-metrics">';
		echo wp_kses( AWPM_UI::metric( __( 'Pending commissions', 'affiliate-wpm' ), AWPM_Store::money( $pending, $currency ), 'clock' ), AWPM_UI::allowed_html() );
		echo wp_kses( AWPM_UI::metric( __( 'Available earnings', 'affiliate-wpm' ), AWPM_Store::money( $available, $currency ), 'wallet' ), AWPM_UI::allowed_html() );
		echo wp_kses( AWPM_UI::metric( __( 'Awaiting payout', 'affiliate-wpm' ), AWPM_Store::money( $requested, $currency ), 'send' ), AWPM_UI::allowed_html() );
		echo wp_kses( AWPM_UI::metric( __( 'Completed payouts', 'affiliate-wpm' ), AWPM_Store::money( $paid, $currency ), 'award' ), AWPM_UI::allowed_html() );
		echo '</div>';
		$stats = AWPM_Store::referral_stats();
		echo '<div class="awpm-referral-metrics">';
		echo wp_kses( AWPM_UI::metric( __( 'Referral clicks', 'affiliate-wpm' ), number_format_i18n( $stats['clicks'] ), 'click' ), AWPM_UI::allowed_html() );
		echo wp_kses( AWPM_UI::metric( __( 'Registered customers', 'affiliate-wpm' ), number_format_i18n( $stats['customers'] ), 'user' ), AWPM_UI::allowed_html() );
		echo '</div>'; self::overview_lists();
		echo '<div class="awpm-grid"><section class="awpm-card"><h2>' . esc_html__( 'Program at a glance', 'affiliate-wpm' ) . '</h2><dl class="awpm-definition">';
		$rate = 'percent' === AWPM_Settings::get( 'commission_type' ) ? esc_html( AWPM_Settings::get( 'commission_rate' ) ) . '%' : wp_kses_post( AWPM_Store::money( AWPM_Settings::get( 'commission_rate' ) ) ) . ' ' . esc_html__( 'per unit', 'affiliate-wpm' );
		$values = array( __( 'Program', 'affiliate-wpm' ) => AWPM_Settings::program_enabled() ? esc_html__( 'Active', 'affiliate-wpm' ) : esc_html__( 'Paused', 'affiliate-wpm' ), __( 'Default commission', 'affiliate-wpm' ) => $rate, __( 'Cookie duration', 'affiliate-wpm' ) => (int) AWPM_Settings::get( 'cookie_days' ) . ' ' . esc_html__( 'days', 'affiliate-wpm' ), __( 'Minimum withdrawal', 'affiliate-wpm' ) => wp_kses_post( AWPM_Store::money( AWPM_Settings::get( 'minimum' ) ) ), __( 'Active payout methods', 'affiliate-wpm' ) => count( AWPM_Settings::methods() ) );
		foreach ( $values as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>'; }
		echo '</dl><a class="button awpm-primary" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=settings' ) ) . '">' . esc_html__( 'Manage settings', 'affiliate-wpm' ) . '</a></section><section class="awpm-card"><h2>' . esc_html__( 'How it works', 'affiliate-wpm' ) . '</h2><ol class="awpm-steps"><li>' . esc_html__( 'Customers join from My Account → Affiliate Area.', 'affiliate-wpm' ) . '</li><li>' . esc_html__( 'A referral link sets a signed cookie and attributes new orders.', 'affiliate-wpm' ) . '</li><li>' . esc_html__( 'Completed orders release commissions for withdrawal.', 'affiliate-wpm' ) . '</li><li>' . esc_html__( 'Approve a request, then send it through PayPal or Stripe. For manual methods, send it yourself and mark it paid.', 'affiliate-wpm' ) . '</li></ol><p class="awpm-muted">' . esc_html__( 'Commission excludes tax and shipping. Refunds adjust balances automatically. Automatic payouts require a configured provider and an explicit administrator Send payment action.', 'affiliate-wpm' ) . '</p></section></div>';
	}
	public static function field( $key, $label, $help, $type = 'text', $options = array(), $attributes = '' ) {
		$value = AWPM_Settings::get( $key );
		echo '<div class="awpm-field"><label for="awpm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			echo '<select name="awpm[' . esc_attr( $key ) . ']" id="awpm-' . esc_attr( $key ) . '">';
			foreach ( $options as $id => $name ) { echo '<option value="' . esc_attr( $id ) . '"' . selected( $value, $id, false ) . '>' . esc_html( $name ) . '</option>'; }
			echo '</select>';
		} else { echo wp_kses( '<input type="' . esc_attr( $type ) . '" name="awpm[' . esc_attr( $key ) . ']" id="awpm-' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" ' . $attributes . '>', AWPM_UI::allowed_html() ); }
		echo '<p class="awpm-muted">' . esc_html( $help ) . '</p></div>';
	}
	private static function overview_lists() {
		global $wpdb;
		$table = AWPM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 5', $table ) );
		echo '<div class="awpm-grid"><section class="awpm-card" id="awpm-overview-withdrawals"><div class="awpm-overview-heading"><h2>' . esc_html__( 'Latest withdrawal requests', 'affiliate-wpm' ) . '</h2><a class="awpm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=payouts' ) ) . '">' . esc_html__( 'See more withdrawals', 'affiliate-wpm' ) . '</a></div><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Amount', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . wp_kses_post( self::identity( $row->affiliate_id ) ) . '</td><td>' . wp_kses_post( AWPM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AWPM_Store::status( $row->status ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="3" class="awpm-empty">' . esc_html__( 'No withdrawal requests yet.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Required enrolled-affiliate predicate; only five users are fetched, no total count, with native WordPress query caching.
		$users = get_users( array( 'count_total' => false, 'cache_results' => true, 'meta_key' => '_awpm_enrolled', 'meta_value' => 'yes', 'number' => 5, 'orderby' => 'ID', 'order' => 'DESC' ) );
		echo '<section class="awpm-card" id="awpm-overview-affiliates"><div class="awpm-overview-heading"><h2>' . esc_html__( 'Latest affiliates', 'affiliate-wpm' ) . '</h2><a class="awpm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=affiliates' ) ) . '">' . esc_html__( 'See more affiliates', 'affiliate-wpm' ) . '</a></div><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Account', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) { echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . ( 'yes' === get_user_meta( $user->ID, '_awpm_blocked', true ) ? esc_html__( 'Blocked', 'affiliate-wpm' ) : wp_kses_post( AWPM_Store::status( AWPM_Affiliates::status( $user->ID ) ) ) ) . '</td></tr>'; }
		if ( ! $users ) { echo '<tr><td colspan="2" class="awpm-empty">' . esc_html__( 'No affiliates yet.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div></section></div>';
	}
	private static function settings() {
		echo '<form id="awpm-program-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="awpm-settings"><input type="hidden" name="action" value="awpm_save_settings">';
		wp_nonce_field( 'awpm_settings' );
		echo '<nav class="awpm-section-nav" aria-label="' . esc_attr__( 'Settings sections', 'affiliate-wpm' ) . '"><a href="#awpm-program-settings">' . esc_html__( 'Enrollment', 'affiliate-wpm' ) . '</a><a href="#awpm-commissions-settings">' . esc_html__( 'Commissions', 'affiliate-wpm' ) . '</a><a href="#awpm-tracking-settings">' . esc_html__( 'Tracking', 'affiliate-wpm' ) . '</a><a href="#awpm-payouts-settings">' . esc_html__( 'Payouts', 'affiliate-wpm' ) . '</a><a href="#awpm-terms-settings">' . esc_html__( 'Terms & Conditions', 'affiliate-wpm' ) . '</a></nav>';
		echo '<section class="awpm-card" id="awpm-program-settings"><h2>' . wp_kses( AWPM_UI::icon( 'affiliates' ), AWPM_UI::allowed_html() ) . esc_html__( 'Program & enrollment', 'affiliate-wpm' ) . '</h2><label class="awpm-toggle"><input type="checkbox" name="awpm[enabled]" value="yes"' . checked( AWPM_Settings::get( 'enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable affiliate program', 'affiliate-wpm' ) . '</label><p class="awpm-muted">' . esc_html__( 'Pausing stops new enrollments, referrals and withdrawals. Existing order commissions and history continue to update.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'approval_required', __( 'Affiliate account approval', 'affiliate-wpm' ), __( 'New accounts join automatically by default. Enable review to approve or reject new applications from the Affiliates tab. Existing affiliates remain approved; pending applications still need review.', 'affiliate-wpm' ), 'select', array( 'no' => __( 'Join automatically', 'affiliate-wpm' ), 'yes' => __( 'Require administrator approval', 'affiliate-wpm' ) ) );
		echo '</div><h3>' . esc_html__( 'Join Affiliate page', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Customize the invitation customers see in the Affiliate Area before joining.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'join_title', __( 'Join Affiliate title', 'affiliate-wpm' ), __( 'Heading on the page before joining.', 'affiliate-wpm' ), 'text', array(), 'maxlength="180" required' );
		self::field( 'join_button_text', __( 'Join button text', 'affiliate-wpm' ), __( 'Label for the button that submits the affiliate application.', 'affiliate-wpm' ), 'text', array(), 'maxlength="180" required' );
		echo '<div class="awpm-field"><label for="awpm-join-description">' . esc_html__( 'Description text', 'affiliate-wpm' ) . '</label><textarea id="awpm-join-description" name="awpm[join_description]" rows="4" maxlength="3000" style="width:100%">' . esc_textarea( AWPM_Settings::get( 'join_description' ) ) . '</textarea><p>' . esc_html__( 'Plain text with line breaks. Leave empty to hide the description.', 'affiliate-wpm' ) . '</p></div>';
		self::field( 'join_show_rate', __( 'Default commission rate before joining', 'affiliate-wpm' ), __( 'Choose whether customers see your default percentage or fixed amount on the Join Affiliate page. Product-specific rates may differ.', 'affiliate-wpm' ), 'select', array( 'no' => __( 'Hide', 'affiliate-wpm' ), 'yes' => __( 'Show', 'affiliate-wpm' ) ) );
		echo '<div><label class="awpm-toggle"><input type="checkbox" name="awpm[join_url_enabled]" value="yes"' . checked( AWPM_Settings::get( 'join_url_enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable Website / Promote URL field', 'affiliate-wpm' ) . '</label><p class="awpm-muted">' . esc_html__( 'Disabled by default. When enabled, applicants must provide a website or social profile URL.', 'affiliate-wpm' ) . '</p></div><div><label class="awpm-toggle"><input type="checkbox" name="awpm[join_promotion_enabled]" value="yes"' . checked( AWPM_Settings::get( 'join_promotion_enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable How will you promote us? field', 'affiliate-wpm' ) . '</label><p class="awpm-muted">' . esc_html__( 'Disabled by default. When enabled, applicants must describe their promotion plan. View answers in the Affiliates list.', 'affiliate-wpm' ) . '</p></div>';
		echo '</div></section><section class="awpm-card" id="awpm-commissions-settings"><h2>' . wp_kses( AWPM_UI::icon( 'commissions' ), AWPM_UI::allowed_html() ) . esc_html__( 'Commission rules', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Set your default earnings. Commissions use the WooCommerce base currency; individual products can override these rates.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'commission_type', __( 'Commission type', 'affiliate-wpm' ), __( 'Product settings override this default.', 'affiliate-wpm' ), 'select', array( 'percent' => __( 'Percentage', 'affiliate-wpm' ), 'fixed' => __( 'Fixed amount per unit', 'affiliate-wpm' ) ) );
		self::field( 'commission_rate', __( 'Default commission', 'affiliate-wpm' ), __( 'Percentage: 0–100. Fixed: amount per purchased unit, capped at the item total.', 'affiliate-wpm' ), 'number', array(), 'min="0" max="1000000" step="0.000001" required' );
		self::field( 'release_mode', __( 'Commission availability', 'affiliate-wpm' ), __( 'By default, commissions become available as soon as the order is Completed. Optionally hold them for a number of days after completion. Refunds and cancellations still adjust commissions. Changes apply when an order first completes; previously released commissions stay available.', 'affiliate-wpm' ), 'select', array( 'completed' => __( 'Immediately after order completion (default)', 'affiliate-wpm' ), 'delay' => __( 'After a waiting period', 'affiliate-wpm' ) ) );
		echo '<div id="awpm-release-days"' . ( 'delay' === AWPM_Settings::get( 'release_mode' ) ? '' : ' hidden' ) . '>';
		self::field( 'release_days', __( 'Waiting period (days)', 'affiliate-wpm' ), __( '1 to 365 days, counted from the order completion time. A saved waiting period is not changed by later settings updates.', 'affiliate-wpm' ), 'number', array(), 'min="1" max="365" step="1" required' );
		echo '</div>';
		$base_currency = AWPM_Settings::base_currency(); $currency_names = get_woocommerce_currencies();
		echo '<div class="awpm-field awpm-base-currency"><span class="awpm-muted">' . esc_html__( 'WooCommerce base currency', 'affiliate-wpm' ) . '</span><p><strong>' . esc_html( $base_currency . ' - ' . $currency_names[ $base_currency ] ) . '</strong></p><p class="awpm-muted">' . esc_html__( 'New commissions, fixed per-unit rates, minimum withdrawal and dashboard totals use the base currency from WooCommerce Settings. The Pro add-on can convert FOX/CURCY orders in another currency using a saved exchange rate. Existing transactions retain their original currency.', 'affiliate-wpm' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'WooCommerce Settings', 'affiliate-wpm' ) . '</a></p></div>';
		if ( AWPM_License::active() && has_action( 'awpm_exchange_settings' ) ) { do_action( 'awpm_exchange_settings' ); }
		else { echo '<div class="awpm-field awpm-premium-card"><label>' . esc_html__( 'Exchange rate source', 'affiliate-wpm' ) . ' ' . wp_kses_post( AWPM_License::premium_badge() ) . '</label><select disabled aria-label="' . esc_attr__( 'Free currency mode', 'affiliate-wpm' ) . '"><option>' . esc_html__( 'WooCommerce base currency only', 'affiliate-wpm' ) . '</option></select><p class="awpm-muted">' . esc_html__( 'Currency conversion is provided by the separate Pro add-on. Free earns commissions in the WooCommerce base currency.', 'affiliate-wpm' ) . '</p>' . wp_kses_post( AWPM_License::premium_link() ) . '</div>'; }
		echo '</div></section><section class="awpm-card" id="awpm-tracking-settings"><h2>' . wp_kses( AWPM_UI::icon( 'clock' ), AWPM_UI::allowed_html() ) . esc_html__( 'Referral tracking & repeat purchases', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Choose how long referrals last and whether returning customers can earn additional commissions.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		self::field( 'referral_parameter', __( 'Referral link parameter', 'affiliate-wpm' ), __( 'Default: awpm_ref. Use a name such as ref or partner. Letters, numbers, underscores and hyphens only, starting with a letter. Existing awpm_ref links continue to work. Exclude both names from page caching.', 'affiliate-wpm' ), 'text', array(), 'maxlength="60" pattern="[a-zA-Z][a-zA-Z0-9_-]{0,59}" required' );
		self::field( 'account_slug', __( 'Affiliate Area URL slug', 'affiliate-wpm' ), __( 'Default: affiliate-wpm. Example: partners creates /my-account/partners/. Use letters, numbers and hyphens, starting with a letter. Avoid existing WordPress and account endpoints. The default URL continues to work.', 'affiliate-wpm' ), 'text', array(), 'maxlength="60" pattern="[a-zA-Z][a-zA-Z0-9-]{0,59}" required' );
		self::field( 'cookie_days', __( 'Cookie lifetime (days)', 'affiliate-wpm' ), __( 'Between 1 and 365 days.', 'affiliate-wpm' ), 'number', array(), 'min="1" max="365" step="1" required' );
		self::field( 'attribution', __( 'Referral attribution', 'affiliate-wpm' ), __( 'First referral keeps the first valid cookie. Last referral replaces it.', 'affiliate-wpm' ), 'select', array( 'first' => __( 'First referral', 'affiliate-wpm' ), 'last' => __( 'Last referral', 'affiliate-wpm' ) ) );
		self::field( 'purchase_policy', __( 'Repeat purchase commissions', 'affiliate-wpm' ), __( 'Once: only one eligible commission-earning purchase per customer across all affiliates and products. Multiple: each eligible referred order earns commission. Customers are matched by account or billing email. Applies to new orders only.', 'affiliate-wpm' ), 'select', array( 'multiple' => __( 'Every eligible referred purchase', 'affiliate-wpm' ), 'once' => __( 'Once per customer', 'affiliate-wpm' ) ) );
		echo '</div></section><section class="awpm-card" id="awpm-payouts-settings"><h2>' . wp_kses( AWPM_UI::icon( 'payouts' ), AWPM_UI::allowed_html() ) . esc_html__( 'Payout settings', 'affiliate-wpm' ) . '</h2><div class="awpm-form-grid">';
		self::field( 'minimum', __( 'Minimum withdrawal', 'affiliate-wpm' ), __( 'Customers request their entire available balance, after refund adjustments.', 'affiliate-wpm' ), 'number', array(), 'min="0.01" max="1000000" step="0.000001" required' );
		self::field( 'payout_mode', __( 'Enable payout', 'affiliate-wpm' ), __( 'Manual methods are paid outside the plugin. Automatic providers send only after approval and Send payment. Pause new withdrawals whenever needed.', 'affiliate-wpm' ), 'select', array( 'manual' => __( 'Enabled', 'affiliate-wpm' ), 'disabled' => __( 'Withdrawals paused', 'affiliate-wpm' ) ) );
		self::field( 'method_selection', __( 'Available methods', 'affiliate-wpm' ), __( 'Single keeps only the first checked method active. Multiple lets customers choose among all checked methods.', 'affiliate-wpm' ), 'select', array( 'single' => __( 'One active method', 'affiliate-wpm' ), 'multiple' => __( 'Multiple active methods', 'affiliate-wpm' ) ) );
		echo '</div>';
		if ( AWPM_License::active() && has_action( 'awpm_manual_methods_editor' ) ) { do_action( 'awpm_manual_methods_editor' ); }
		else {
			echo '<div id="awpm-manual-methods"><h3>' . esc_html__( 'Manual payout method', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Configure your one manual payment method. Additional methods are provided by the separate Pro add-on.', 'affiliate-wpm' ) . ' ' . wp_kses_post( AWPM_License::premium_badge() ) . '</p>';
			$method = AWPM_Settings::get( 'methods' )[0] ?? array( 'id' => 'bank', 'name' => '', 'instructions' => '', 'active' => 'no' );
			self::method_row( 0, $method, false ); echo '</div>';
		}
		 AWPM_Gateways::fields(); echo '</section><section class="awpm-card" id="awpm-products-settings"><h2>' . wp_kses( AWPM_UI::icon( 'link' ), AWPM_UI::allowed_html() ) . esc_html__( 'Product commissions & tracking', 'affiliate-wpm' ) . '</h2><p>' . esc_html__( 'Edit a product → Product data → General to set a percentage, fixed amount per unit, or disable commission for that product. Parent product settings cover all variations.', 'affiliate-wpm' ) . '</p><p class="awpm-muted">' . esc_html( str_replace( 'awpm_ref', implode( ', ', AWPM_Settings::referral_keys() ), __( 'Caching: exclude awpm_ref query strings from full-page cache, and keep My Account, Cart and Checkout uncached. With LiteSpeed, add awpm_ref under Cache → Excludes → Do Not Cache Query Strings; do not add it to Drop Query String. Hosting/CDN caches must also bypass referral URLs and logged-in users. Purge existing page caches after applying these rules. Guest product pages remain cacheable. Review the Affiliate WPM privacy policy text in WordPress Settings → Privacy and connect cookie consent through the awpm_tracking_allowed filter when required.', 'affiliate-wpm' ) ) ) . '</p></section>';
		echo '<section class="awpm-card" id="awpm-terms-settings"><h2>' . wp_kses( AWPM_UI::icon( 'affiliates' ), AWPM_UI::allowed_html() ) . esc_html__( 'Affiliate Terms & Conditions', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Customers see this content in the Affiliate Area before joining and must accept it. Leave it empty to join without a terms checkbox. Updates apply to new applications; existing affiliates keep their enrollment.', 'affiliate-wpm' ) . '</p><label for="awpm_terms_content">' . esc_html__( 'Terms & Conditions content', 'affiliate-wpm' ) . '</label>';
		wp_editor( AWPM_Settings::terms(), 'awpm_terms_content', array( 'textarea_name' => 'awpm[terms_content]', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true, 'quicktags' => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ) ) );
		echo '</section>';
		echo '</form>';
		AWPM_Currency::recovery_form();
		AWPM_UI::footer( __( 'Save settings', 'affiliate-wpm' ), 'awpm-program-settings-form' );
	}
	public static function method_row( $index, $method, $removable = true ) {
		$prefix = 'awpm[methods][' . $index . ']';
		echo '<div class="awpm-method-row"><input type="hidden" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( $method['id'] ) . '"><div class="awpm-method-top"><label>' . esc_html__( 'Method name', 'affiliate-wpm' ) . '<input type="text" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $method['name'] ) . '" maxlength="120" placeholder="' . esc_attr__( 'e.g. Bank transfer', 'affiliate-wpm' ) . '"></label><label class="awpm-toggle"><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="yes"' . checked( $method['active'], 'yes', false ) . '> ' . esc_html__( 'Active', 'affiliate-wpm' ) . '</label>' . ( $removable ? '<button type="button" class="button awpm-remove-method awpm-primary">' . esc_html__( 'Remove', 'affiliate-wpm' ) . '</button>' : '' ) . '</div><label>' . esc_html__( 'Instructions for customers', 'affiliate-wpm' ) . '<textarea name="' . esc_attr( $prefix . '[instructions]' ) . '" rows="2" maxlength="2000">' . esc_textarea( $method['instructions'] ) . '</textarea></label></div>';
	}
	private static function identity( $id ) {
		$user = get_userdata( $id );
		return $user ? '<strong>' . esc_html( $user->display_name ) . '</strong><small class="awpm-note">' . esc_html( $user->user_email ) . '</small>' : '#' . absint( $id );
	}
	private static function search_term() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		return mb_substr( sanitize_text_field( wp_unslash( AWPM_Security::value( $_GET, 'q' ) ) ), 0, 160 );
	}
	private static function list_statuses( $tab ) {
		return 'affiliates' === $tab ? array( 'pending', 'approved', 'rejected', 'blocked' ) : ( 'payouts' === $tab ? array( 'pending', 'approved', 'processing', 'completed', 'tested', 'failed', 'rejected' ) : array( 'pending', 'available', 'reserved', 'paid', 'rejected' ) );
	}
	private static function list_status( $tab ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$status = sanitize_key( AWPM_Security::value( $_GET, 'status' ) );
		return in_array( $status, self::list_statuses( $tab ), true ) ? $status : '';
	}
	private static function filters( $tab ) {
		$search = self::search_term(); $status = self::list_status( $tab );
		$placeholder = 'affiliates' === $tab ? __( 'Name, email or user ID', 'affiliate-wpm' ) : ( 'commissions' === $tab ? __( 'Affiliate, email, order or commission ID', 'affiliate-wpm' ) : __( 'Affiliate, email or withdrawal ID', 'affiliate-wpm' ) );
		echo '<form class="awpm-list-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="affiliate-wpm"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label class="screen-reader-text" for="awpm-list-search">' . esc_html__( 'Search records', 'affiliate-wpm' ) . '</label><input type="search" id="awpm-list-search" name="q" value="' . esc_attr( $search ) . '" maxlength="160" placeholder="' . esc_attr( $placeholder ) . '"><label class="screen-reader-text" for="awpm-status-filter">' . esc_html__( 'Filter status', 'affiliate-wpm' ) . '</label><select id="awpm-status-filter" name="status"><option value="">' . esc_html__( 'All statuses', 'affiliate-wpm' ) . '</option>';
		foreach ( self::list_statuses( $tab ) as $option ) { echo '<option value="' . esc_attr( $option ) . '"' . selected( $status, $option, false ) . '>' . esc_html( 'blocked' === $option ? __( 'Blocked', 'affiliate-wpm' ) : wp_strip_all_tags( AWPM_Store::status( $option ) ) ) . '</option>'; }
		echo '</select><button type="submit" class="button awpm-primary">' . esc_html__( 'Search', 'affiliate-wpm' ) . '</button>';
		if ( '' !== $search || '' !== $status ) { echo '<a class="awpm-filter-reset" href="' . esc_url( add_query_arg( array( 'page' => 'affiliate-wpm', 'tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Reset', 'affiliate-wpm' ) . '</a>'; }
		echo '</form>';
	}
	private static function affiliates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = AWPM_Security::page( $_GET, 'paged' );
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Required enrollment predicate; results are paginated at ten users and WordPress caches query IDs/totals.
		$args = array( 'cache_results' => true, 'meta_key' => '_awpm_enrolled', 'meta_value' => 'yes', 'number' => self::PAGE_SIZE, 'offset' => ( $page - 1 ) * self::PAGE_SIZE, 'orderby' => 'ID', 'order' => 'DESC' );
		$search = self::search_term(); $status = self::list_status( 'affiliates' );
		if ( '' !== $search ) { $args['search'] = '*' . ltrim( $search, '#' ) . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'ID' ); }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required account-status predicate for this paginated, cached user query.
		if ( 'blocked' === $status ) { $args['meta_query'] = array( array( 'key' => '_awpm_blocked', 'value' => 'yes' ) ); }
		elseif ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Preserve legacy approval and blocked-account semantics; native user-query caches are invalidated by metadata updates.
			$args['meta_query'] = array( 'relation' => 'AND', array( 'relation' => 'OR', array( 'key' => '_awpm_blocked', 'compare' => 'NOT EXISTS' ), array( 'key' => '_awpm_blocked', 'value' => 'yes', 'compare' => '!=' ) ) );
			$args['meta_query'][] = 'approved' === $status ? array( 'relation' => 'OR', array( 'key' => '_awpm_approval', 'compare' => 'NOT EXISTS' ), array( 'key' => '_awpm_approval', 'value' => array( 'pending', 'rejected' ), 'compare' => 'NOT IN' ) ) : array( 'key' => '_awpm_approval', 'value' => $status );
		}
		$query = new WP_User_Query( $args );
		$count = (int) $query->get_total();
		$last = max( 1, (int) ceil( $count / self::PAGE_SIZE ) );
		if ( $page > $last ) {
			$page = $last; $args['offset'] = ( $page - 1 ) * self::PAGE_SIZE; $args['count_total'] = false;
			$query = new WP_User_Query( $args );
		}
		echo '<section class="awpm-card"><div class="awpm-card-toolbar"><h2>' . esc_html__( 'Affiliates', 'affiliate-wpm' ) . '</h2>'; self::filters( 'affiliates' ); echo '</div><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Available balance', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Clicks', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Registered customers', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Account', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Action', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $query->get_results() as $user ) {
			$blocked = 'yes' === get_user_meta( $user->ID, '_awpm_blocked', true );
			$stats = AWPM_Store::referral_stats( $user->ID );
			$approval = AWPM_Affiliates::status( $user->ID );
			echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . wp_kses_post( AWPM_Store::money( AWPM_Store::balance( $user->ID, AWPM_Settings::get( 'currency' ) ) ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['customers'] ) ) . '</td><td>' . ( $blocked ? esc_html__( 'Blocked', 'affiliate-wpm' ) : wp_kses_post( AWPM_Store::status( $approval ) ) ) . '</td><td>';
			self::application_details( $user->ID );
			$actions = 'pending' === $approval ? array( 'approved' => __( 'Approve', 'affiliate-wpm' ), 'rejected' => __( 'Reject', 'affiliate-wpm' ) ) : ( 'rejected' === $approval ? array( 'approved' => __( 'Approve', 'affiliate-wpm' ) ) : array( $blocked ? 'unblock' : 'block' => $blocked ? __( 'Unblock', 'affiliate-wpm' ) : __( 'Block', 'affiliate-wpm' ) ) );
			echo '<form class="awpm-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_affiliate"><input type="hidden" name="affiliate_id" value="' . absint( $user->ID ) . '">'; wp_nonce_field( 'awpm_affiliate_' . $user->ID );
			foreach ( $actions as $operation => $label ) { echo '<button class="button awpm-primary" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button>'; }
			echo '</form></td></tr>';
		}
		if ( ! $query->get_results() ) { echo '<tr><td colspan="6" class="awpm-empty">' . esc_html( '' !== $search || '' !== $status ? __( 'No affiliates match your search or filters.', 'affiliate-wpm' ) : __( 'Customers who join the program will appear here.', 'affiliate-wpm' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, esc_html( $page ), 'affiliates', $status ); echo '</section>';
	}
	private static function application_details( $id ) {
		$application = get_user_meta( $id, '_awpm_application', true );
		if ( ! is_array( $application ) || ( empty( $application['website'] ) && empty( $application['promotion'] ) ) ) { return; }
		echo '<details class="awpm-payment"><summary>' . esc_html__( 'Application details', 'affiliate-wpm' ) . '</summary>';
		$url = esc_url( AWPM_Security::value( $application, 'website' ), array( 'http', 'https' ) );
		if ( $url ) { echo '<p><strong>' . esc_html__( 'Website / Promote URL', 'affiliate-wpm' ) . '</strong><br><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( AWPM_Security::value( $application, 'website' ) ) . '</a></p>'; }
		if ( ! empty( $application['promotion'] ) ) { echo '<p><strong>' . esc_html__( 'Promotion plan', 'affiliate-wpm' ) . '</strong><br>' . nl2br( esc_html( AWPM_Security::value( $application, 'promotion' ) ) ) . '</p>'; }
		echo '</details>';
	}
	private static function records( $type ) {
		global $wpdb;
		$table = AWPM_Store::table( $type );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = AWPM_Security::page( $_GET, 'paged' );
		$status = self::list_status( $type ); $search = self::search_term();
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$number = ltrim( $search, '#' );
		$numeric = ctype_digit( $number ) && strlen( $number ) <= 18;
		$number = $numeric ? (int) $number : 0;
		$order_column = 'commissions' === $type ? 'order_id' : 'id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i r LEFT JOIN %i u ON u.ID=r.affiliate_id WHERE (%s=\'\' OR r.status=%s) AND (%s=\'\' OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR (%d=1 AND (r.id=%d OR r.affiliate_id=%d OR r.%i=%d)))',
			$table, $wpdb->users, $status, $status, $search, $like, $like, $like, (int) $numeric, $number, $number, $order_column, $number
		) );
		$page = min( $page, max( 1, (int) ceil( $count / self::PAGE_SIZE ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT r.* FROM %i r LEFT JOIN %i u ON u.ID=r.affiliate_id WHERE (%s=\'\' OR r.status=%s) AND (%s=\'\' OR u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR (%d=1 AND (r.id=%d OR r.affiliate_id=%d OR r.%i=%d))) ORDER BY r.id DESC LIMIT %d OFFSET %d',
			$table, $wpdb->users, $status, $status, $search, $like, $like, $like, (int) $numeric, $number, $number, $order_column, $number, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE
		) );
		echo '<section class="awpm-card"><div class="awpm-card-toolbar"><h2>' . esc_html( 'payouts' === $type ? __( 'Withdrawal requests', 'affiliate-wpm' ) : __( 'Commission ledger', 'affiliate-wpm' ) ) . '</h2>'; self::filters( $type ); echo '</div>';
		if ( 'payouts' === $type ) { echo '<p class="awpm-muted">' . esc_html__( 'Approve each request first. For manual methods, send money outside the plugin and choose Mark paid. For PayPal or Stripe, choose Send payment; completion requires provider confirmation. Sandbox/Test does not settle real earnings. Notes are visible to the affiliate.', 'affiliate-wpm' ) . '</p>'; }
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
				$gateway_job = AWPM_Gateways::job( $row->id ); AWPM_Gateways::summary( $row->id );
				if ( in_array( $row->status, array( 'pending', 'approved', 'processing' ), true ) ) {
					echo '<form class="awpm-payout-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_payout"><input type="hidden" name="payout_id" value="' . absint( $row->id ) . '">';
					wp_nonce_field( 'awpm_payout_' . $row->id );
					echo '<label class="screen-reader-text" for="awpm-note-' . absint( $row->id ) . '">' . esc_html__( 'Note / payment reference', 'affiliate-wpm' ) . '</label><textarea id="awpm-note-' . absint( $row->id ) . '" name="note" rows="2" maxlength="2000" placeholder="' . esc_attr__( 'Note / payment reference', 'affiliate-wpm' ) . '">' . esc_textarea( $row->note ) . '</textarea><div class="awpm-actions">';
					if ( 'processing' === $row->status ) { echo '<button class="button awpm-primary" name="status" value="check">' . esc_html__( 'Check payment status', 'affiliate-wpm' ) . '</button>'; }
					elseif ( 'pending' === $row->status ) { echo '<button class="button button-primary awpm-primary" name="status" value="approved">' . esc_html__( 'Approve', 'affiliate-wpm' ) . '</button>'; }
					elseif ( $gateway_job ) { echo '<button class="button awpm-primary" name="status" value="processing" data-awpm-confirm="' . esc_attr__( 'Send this approved payout through the configured provider? Sandbox/Test sends no real money.', 'affiliate-wpm' ) . '">' . esc_html( 'live' === $gateway_job['mode'] ? __( 'Send payment', 'affiliate-wpm' ) : __( 'Run Sandbox payout', 'affiliate-wpm' ) ) . '</button>'; }
					else { echo '<button class="button button-primary awpm-primary" name="status" value="completed" data-awpm-confirm="' . esc_attr__( 'Confirm that you have already sent this payment. This will settle the reserved commissions.', 'affiliate-wpm' ) . '">' . esc_html__( 'Mark paid', 'affiliate-wpm' ) . '</button>'; }
					if ( 'processing' !== $row->status ) { echo '<button class="button awpm-primary" name="status" value="rejected">' . esc_html__( 'Reject', 'affiliate-wpm' ) . '</button>'; } echo '</div></form>';
				} elseif ( $row->note ) { echo '<small class="awpm-note">' . esc_html( $row->note ) . '</small>'; }
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="6" class="awpm-empty">' . esc_html__( 'No records found.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, esc_html( $page ), $type, $status ); echo '</section>';
	}
	private static function pagination( $count, $page, $tab, $status = '' ) {
		$total = (int) ceil( $count / self::PAGE_SIZE );
		if ( $total <= 1 ) { return; }
		$page = min( max( 1, $page ), $total );
		$base = add_query_arg( array( 'page' => 'affiliate-wpm', 'tab' => $tab, 'status' => $status, 'q' => self::search_term(), 'paged' => 999999999 ), admin_url( 'admin.php' ) );
		$clean_url = function ( $url ) { return remove_query_arg( array( 'saved', 'awpm_message', 'awpm_error' ), $url ); };
		add_filter( 'get_pagenum_link', $clean_url );
		try { $links = paginate_links( array( 'base' => str_replace( '999999999', '%#%', $base ), 'format' => '', 'current' => $page, 'total' => $total, 'type' => 'array', 'add_args' => false, 'mid_size' => 1, 'end_size' => 1, 'prev_text' => __( 'Previous', 'affiliate-wpm' ), 'next_text' => __( 'Next', 'affiliate-wpm' ) ) ); } finally { remove_filter( 'get_pagenum_link', $clean_url ); }
		/* translators: %1$d: current page, %2$d: total pages. */
		echo '<nav class="awpm-pagination awpm-admin-pagination" aria-label="' . esc_attr__( 'List pages', 'affiliate-wpm' ) . '"><span class="awpm-page-summary">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affiliate-wpm' ), esc_html( $page ), esc_html( $total ) ) . '</span><div class="awpm-page-links">';
		foreach ( (array) $links as $link ) {
			$link = preg_replace( '/(<a\s[^>]*class=")([^"]*)(")/', '$1$2 awpm-primary$3', $link );
			echo wp_kses_post( $link );
		}
		echo '</div></nav>';
	}
}
