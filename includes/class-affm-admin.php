<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Admin {
	const PAGE_SIZE = 10;
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( 'woocommerce_page_affmint' === $hook ) {
				wp_enqueue_style( 'affm-admin', AFFM_URL . 'assets/affmint.css', array(), (string) filemtime( AFFM_PATH . 'assets/affmint.css' ) );
				wp_enqueue_style( 'affm-panel', AFFM_URL . 'assets/panel.css', array( 'affm-admin' ), (string) filemtime( AFFM_PATH . 'assets/panel.css' ) );
				AFFM_Design::enqueue( 'affm-panel' );
				wp_enqueue_script( 'affm-admin', AFFM_URL . 'assets/admin.js', array(), (string) filemtime( AFFM_PATH . 'assets/admin.js' ), true );
				wp_localize_script( 'affm-admin', 'affmAdmin', array( 'unsaved' => __( 'You have unsaved changes.', 'affmint' ), 'saving' => __( 'Saving your changes...', 'affmint' ) ) );
			}
		} );
		add_action( 'admin_post_affm_payout', array( __CLASS__, 'payout_action' ) );
		add_action( 'admin_post_affm_affiliate', array( __CLASS__, 'affiliate_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AFFM_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=affmint&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'affmint' ) . '</a>' );
			return $links;
		} );
	}
	public static function pending_affiliates_count() {
		$query = new WP_User_Query( array(
			'fields' => 'ID', 'number' => 1, 'count_total' => true, 'cache_results' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required enrollment/pending predicates; WordPress caches IDs and totals and invalidates them when user metadata changes.
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_affm_enrolled', 'value' => 'yes' ),
				array( 'key' => '_affm_approval', 'value' => 'pending' ),
			),
		) );
		return absint( $query->get_total() );
	}
	public static function pending_counts() {
		global $wpdb;
		$commissions = AFFM_Store::table( 'commissions' );
		$payouts = AFFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$counts = $wpdb->get_row( $wpdb->prepare( 'SELECT (SELECT COUNT(*) FROM %i WHERE status=%s) AS commissions, (SELECT COUNT(*) FROM %i WHERE status=%s) AS payouts', $commissions, 'pending', $payouts, 'pending' ), ARRAY_A );
		return array( 'affiliates' => self::pending_affiliates_count(), 'commissions' => absint( $counts['commissions'] ?? 0 ), 'payouts' => absint( $counts['payouts'] ?? 0 ) );
	}
	public static function pending_badge( $count ) {
		$count = absint( $count ); if ( ! $count ) { return ''; }
		/* translators: %s: formatted count of pending items. */
		$label = sprintf( _n( '%s pending item', '%s pending items', $count, 'affmint' ), number_format_i18n( $count ) );
		return '<span class="affm-nav-count" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
	}
	public static function menu() {
		$title = 'AffMint';
		$count = current_user_can( 'manage_woocommerce' ) ? self::pending_affiliates_count() : 0;
		if ( $count ) {
		/* translators: %s: formatted count of pending affiliate applications. */
			$description = sprintf( _n( '%s pending affiliate application', '%s pending affiliate applications', $count, 'affmint' ), number_format_i18n( $count ) );
			$title .= ' <span class="update-plugins count-' . $count . '"><span class="plugin-count" aria-hidden="true">' . esc_html( number_format_i18n( $count ) ) . '</span><span class="screen-reader-text">' . esc_html( $description ) . '</span></span>';
		}
		add_submenu_page( 'woocommerce', 'AffMint', $title, 'manage_woocommerce', 'affmint', array( __CLASS__, 'render' ) );
	}
	public static function payout_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affmint' ), 403 ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validated integer ID is read only to construct the entity-specific nonce; admin_request() verifies that nonce before mutation.
		$id = absint( AFFM_Security::value( $_POST, 'payout_id', '0' ) );
		AFFM_Security::admin_request( 'affm_payout_' . $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$status = sanitize_key( wp_unslash( AFFM_Security::value( $_POST, 'status' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$note = sanitize_textarea_field( wp_unslash( AFFM_Security::value( $_POST, 'note' ) ) );
		$result = AFFM_Payouts::transition( $id, $status, $note );
		$url = add_query_arg( array( 'page' => 'affmint', 'tab' => 'payouts', 'affm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal updated.', 'affmint' ), 'affm_error' => is_wp_error( $result ) ? 1 : 0 ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}
	public static function affiliate_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affmint' ), 403 ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validated integer ID is read only to construct the entity-specific nonce; admin_request() verifies that nonce before mutation.
		$id = absint( AFFM_Security::value( $_POST, 'affiliate_id', '0' ) );
		AFFM_Security::admin_request( 'affm_affiliate_' . $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$operation = sanitize_key( wp_unslash( AFFM_Security::value( $_POST, 'operation' ) ) ); $result = true;
		if ( in_array( $operation, array( 'approved', 'rejected' ), true ) ) { $result = AFFM_Affiliates::review( $id, $operation ); }
		elseif ( get_userdata( $id ) && in_array( $operation, array( 'block', 'unblock' ), true ) ) { $result = AFFM_Affiliates::set_blocked( $id, 'block' === $operation ); }
		else { $result = new WP_Error( 'invalid', __( 'Invalid affiliate action.', 'affmint' ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'affmint', 'tab' => 'affiliates', 'affm_error' => is_wp_error( $result ) ? 1 : 0, 'affm_message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Affiliate account updated.', 'affmint' ) ), admin_url( 'admin.php' ) ) ); exit;
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$tabs = array( 'overview' => __( 'Overview', 'affmint' ), 'affiliates' => __( 'Affiliates', 'affmint' ), 'commissions' => __( 'Commissions', 'affmint' ), 'payouts' => __( 'Withdrawals', 'affmint' ), 'settings' => __( 'Settings', 'affmint' ), 'sharing' => __( 'Sharing', 'affmint' ), 'emails' => __( 'Emails', 'affmint' ), 'customization' => __( 'Customization', 'affmint' ) );
		$descriptions = array( 'overview' => __( 'A clear view of your partner program and recent activity.', 'affmint' ), 'affiliates' => __( 'Review applications and manage your affiliate community.', 'affmint' ), 'commissions' => __( 'Follow order earnings, adjustments and settled commissions.', 'affmint' ), 'payouts' => __( 'Review withdrawal requests and record your manual payments.', 'affmint' ), 'settings' => __( 'Set the rules for joining, commissions, referrals and withdrawals.', 'affmint' ), 'sharing' => __( 'Choose how partners share your products.', 'affmint' ), 'emails' => __( 'Keep affiliates informed with clear, personal notifications.', 'affmint' ), 'customization' => __( 'Make the affiliate experience feel like your brand.', 'affmint' ) );
		$tabs['tools'] = __( 'Tools', 'affmint' );
		if ( current_user_can( 'manage_options' ) ) { $tabs['license'] = class_exists( 'AFFM_Pro_License', false ) ? __( 'License', 'affmint' ) : __( 'Premium', 'affmint' ); }
		$descriptions['tools'] = __( 'Back up and restore settings, reset defaults and export your program records.', 'affmint' );
		$descriptions['license'] = __( 'Manage your license and receive updates from WP Methods.', 'affmint' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$tab = sanitize_key( AFFM_Security::value( $_GET, 'tab', 'overview' ) );
		if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'overview'; }
		$active = AFFM_Settings::program_enabled();
		echo '<div class="wrap affm affm-admin"><a class="screen-reader-text" href="#affm-main">' . esc_html__( 'Skip to panel content', 'affmint' ) . '</a><header class="affm-admin-header"><div class="affm-brand"><span class="affm-brand-icon">' . wp_kses( AFFM_UI::icon( 'sharing' ), AFFM_UI::allowed_html() ) . '</span><div><span class="affm-eyebrow">WP METHODS</span><h1>AffMint <span class="affm-version">' . esc_html( AFFM_VERSION ) . '</span> <span class="affm-tier-badge">' . esc_html( AFFM_License::active() ? __( 'Pro', 'affmint' ) : __( 'Free', 'affmint' ) ) . '</span></h1></div></div><div class="affm-header-actions"><span class="affm-program-state ' . ( $active ? 'is-active' : '' ) . '"><span aria-hidden="true"></span>' . esc_html( $active ? __( 'Program active', 'affmint' ) : __( 'Program paused', 'affmint' ) ) . '</span><a class="button affm-primary" href="' . esc_url( wc_get_account_endpoint_url( 'affmint' ) ) . '">' . esc_html__( 'Affiliate Area', 'affmint' ) . wp_kses( AFFM_UI::icon( 'arrow' ), AFFM_UI::allowed_html() ) . '</a></div></header><div class="affm-admin-layout"><aside class="affm-sidebar"><p class="affm-eyebrow">' . esc_html__( 'PROGRAM MANAGEMENT', 'affmint' ) . '</p><nav class="affm-tabs" aria-label="' . esc_attr__( 'Affiliate administration', 'affmint' ) . '">';
		$pending_counts = self::pending_counts();
		foreach ( $tabs as $key => $label ) { echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=affmint&tab=' . $key ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '>' . wp_kses( AFFM_UI::icon( $key ), AFFM_UI::allowed_html() ) . '<span>' . esc_html( $label ) . '</span>' . wp_kses_post( self::pending_badge( $pending_counts[ $key ] ?? 0 ) ) . '</a>'; }
		echo '</nav><div class="affm-sidebar-note">' . wp_kses( AFFM_UI::icon( 'shield' ), AFFM_UI::allowed_html() ) . '<strong>' . esc_html__( 'Built for your store', 'affmint' ) . '</strong><p>' . esc_html__( 'Simple referrals. Clear earnings. Payouts under your control.', 'affmint' ) . '</p></div></aside><main class="affm-main" id="affm-main"><div class="affm-heading"><span class="affm-eyebrow">' . esc_html__( 'YOUR PARTNER PROGRAM', 'affmint' ) . '</span><h2>' . esc_html( $tabs[ $tab ] ) . '</h2><p>' . esc_html( $descriptions[ $tab ] ) . '</p></div>';
		AFFM_License::activation_notice( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		if ( '1' === AFFM_Security::value( $_GET, 'saved' ) ) { AFFM_UI::toast( __( 'Settings saved.', 'affmint' ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		if ( isset( $_GET['affm_message'] ) && is_scalar( $_GET['affm_message'] ) ) { AFFM_UI::toast( sanitize_text_field( wp_unslash( $_GET['affm_message'] ) ), '1' === AFFM_Security::value( $_GET, 'affm_error' ) ); }
		if ( 'settings' === $tab ) { self::settings(); }
		elseif ( 'overview' === $tab ) { self::overview(); }
		elseif ( 'affiliates' === $tab ) { self::affiliates(); }
		elseif ( 'sharing' === $tab ) { AFFM_Sharing::render(); }
		elseif ( 'emails' === $tab ) { AFFM_Emails::render(); }
		elseif ( 'customization' === $tab ) { AFFM_Design::render(); }
		elseif ( 'license' === $tab ) { AFFM_License::render(); }
		elseif ( 'tools' === $tab ) { AFFM_Tools::render(); }
		else { self::records( $tab ); }
		echo '<p class="affm-footer">AffMint &middot; WP Methods</p></main></div></div>';
	}
	private static function overview() {
		global $wpdb;
		$commissions = AFFM_Store::table( 'commissions' );
		$payouts = AFFM_Store::table( 'payouts' );
		$currency = AFFM_Settings::get( 'currency' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE currency=%s AND status=\'pending\'', $commissions, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$available = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount-paid_amount),0) FROM %i WHERE currency=%s AND payout_id=0 AND status IN (\'available\',\'paid\',\'rejected\')', $commissions, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$requested = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE currency=%s AND status IN (\'pending\',\'approved\',\'processing\')', $payouts, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$paid = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(paid_amount),0) FROM %i WHERE currency=%s', $commissions, $currency ) );
		echo '<div class="affm-metrics">';
		echo wp_kses( AFFM_UI::metric( __( 'Pending commissions', 'affmint' ), AFFM_Store::money( $pending, $currency ), 'clock' ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'Available earnings', 'affmint' ), AFFM_Store::money( $available, $currency ), 'wallet' ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'Awaiting payout', 'affmint' ), AFFM_Store::money( $requested, $currency ), 'send' ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'Completed payouts', 'affmint' ), AFFM_Store::money( $paid, $currency ), 'award' ), AFFM_UI::allowed_html() );
		echo '</div>';
		$stats = AFFM_Store::referral_stats();
		echo '<div class="affm-referral-metrics">';
		echo wp_kses( AFFM_UI::metric( __( 'Referral clicks', 'affmint' ), number_format_i18n( $stats['clicks'] ), 'click' ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'Registered customers', 'affmint' ), number_format_i18n( $stats['customers'] ), 'user' ), AFFM_UI::allowed_html() );
		echo '</div>'; self::overview_lists();
		echo '<div class="affm-grid"><section class="affm-card"><h2>' . esc_html__( 'Program at a glance', 'affmint' ) . '</h2><dl class="affm-definition">';
		$rate = 'percent' === AFFM_Settings::get( 'commission_type' ) ? esc_html( AFFM_Settings::get( 'commission_rate' ) ) . '%' : wp_kses_post( AFFM_Store::money( AFFM_Settings::get( 'commission_rate' ) ) ) . ' ' . esc_html__( 'per unit', 'affmint' );
		$values = array( __( 'Program', 'affmint' ) => AFFM_Settings::program_enabled() ? esc_html__( 'Active', 'affmint' ) : esc_html__( 'Paused', 'affmint' ), __( 'Default commission', 'affmint' ) => $rate, __( 'Cookie duration', 'affmint' ) => (int) AFFM_Settings::get( 'cookie_days' ) . ' ' . esc_html__( 'days', 'affmint' ), __( 'Minimum withdrawal', 'affmint' ) => wp_kses_post( AFFM_Store::money( AFFM_Settings::get( 'minimum' ) ) ), __( 'Active payout methods', 'affmint' ) => count( AFFM_Settings::methods() ) );
		foreach ( $values as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>'; }
		echo '</dl><a class="button affm-primary" href="' . esc_url( admin_url( 'admin.php?page=affmint&tab=settings' ) ) . '">' . esc_html__( 'Manage settings', 'affmint' ) . '</a></section><section class="affm-card"><h2>' . esc_html__( 'How it works', 'affmint' ) . '</h2><ol class="affm-steps"><li>' . esc_html__( 'Customers join from My Account → Affiliate Area.', 'affmint' ) . '</li><li>' . esc_html__( 'A referral link sets a signed cookie and attributes new orders.', 'affmint' ) . '</li><li>' . esc_html__( 'Completed orders release commissions for withdrawal.', 'affmint' ) . '</li><li>' . esc_html__( 'Approve a request, then send it through PayPal or Stripe. For manual methods, send it yourself and mark it paid.', 'affmint' ) . '</li></ol><p class="affm-muted">' . esc_html__( 'Commission excludes tax and shipping. Refunds adjust balances automatically. Automatic payouts require a configured provider and an explicit administrator Send payment action.', 'affmint' ) . '</p></section></div>';
	}
	public static function field( $key, $label, $help, $type = 'text', $options = array(), $attributes = '' ) {
		$value = AFFM_Settings::get( $key );
		echo '<div class="affm-field"><label for="affm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			echo '<select name="affm[' . esc_attr( $key ) . ']" id="affm-' . esc_attr( $key ) . '">';
			foreach ( $options as $id => $name ) { echo '<option value="' . esc_attr( $id ) . '"' . selected( $value, $id, false ) . '>' . esc_html( $name ) . '</option>'; }
			echo '</select>';
		} else { echo wp_kses( '<input type="' . esc_attr( $type ) . '" name="affm[' . esc_attr( $key ) . ']" id="affm-' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" ' . $attributes . '>', AFFM_UI::allowed_html() ); }
		echo '<p class="affm-muted">' . esc_html( $help ) . '</p></div>';
	}
	private static function overview_lists() {
		global $wpdb;
		$table = AFFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 5', $table ) );
		echo '<div class="affm-grid"><section class="affm-card" id="affm-overview-withdrawals"><div class="affm-overview-heading"><h2>' . esc_html__( 'Latest withdrawal requests', 'affmint' ) . '</h2><a class="affm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=affmint&tab=payouts' ) ) . '">' . esc_html__( 'See more withdrawals', 'affmint' ) . '</a></div><div class="affm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affmint' ) . '</th><th>' . esc_html__( 'Amount', 'affmint' ) . '</th><th>' . esc_html__( 'Status', 'affmint' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . wp_kses_post( self::identity( $row->affiliate_id ) ) . '</td><td>' . wp_kses_post( AFFM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AFFM_Store::status( $row->status ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="3" class="affm-empty">' . esc_html__( 'No withdrawal requests yet.', 'affmint' ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Required enrolled-affiliate predicate; only five users are fetched, no total count, with native WordPress query caching.
		$users = get_users( array( 'count_total' => false, 'cache_results' => true, 'meta_key' => '_affm_enrolled', 'meta_value' => 'yes', 'number' => 5, 'orderby' => 'ID', 'order' => 'DESC' ) );
		echo '<section class="affm-card" id="affm-overview-affiliates"><div class="affm-overview-heading"><h2>' . esc_html__( 'Latest affiliates', 'affmint' ) . '</h2><a class="affm-overview-link" href="' . esc_url( admin_url( 'admin.php?page=affmint&tab=affiliates' ) ) . '">' . esc_html__( 'See more affiliates', 'affmint' ) . '</a></div><div class="affm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affmint' ) . '</th><th>' . esc_html__( 'Account', 'affmint' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) { echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . ( 'yes' === get_user_meta( $user->ID, '_affm_blocked', true ) ? esc_html__( 'Blocked', 'affmint' ) : wp_kses_post( AFFM_Store::status( AFFM_Affiliates::status( $user->ID ) ) ) ) . '</td></tr>'; }
		if ( ! $users ) { echo '<tr><td colspan="2" class="affm-empty">' . esc_html__( 'No affiliates yet.', 'affmint' ) . '</td></tr>'; }
		echo '</tbody></table></div></section></div>';
	}
	private static function settings() {
		echo '<form id="affm-program-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="affm-settings"><input type="hidden" name="action" value="affm_save_settings">';
		wp_nonce_field( 'affm_settings' );
		echo '<nav class="affm-section-nav" aria-label="' . esc_attr__( 'Settings sections', 'affmint' ) . '"><a href="#affm-program-settings">' . esc_html__( 'Enrollment', 'affmint' ) . '</a><a href="#affm-commissions-settings">' . esc_html__( 'Commissions', 'affmint' ) . '</a><a href="#affm-tracking-settings">' . esc_html__( 'Tracking', 'affmint' ) . '</a><a href="#affm-payouts-settings">' . esc_html__( 'Payouts', 'affmint' ) . '</a><a href="#affm-terms-settings">' . esc_html__( 'Terms & Conditions', 'affmint' ) . '</a></nav>';
		echo '<section class="affm-card" id="affm-program-settings"><h2>' . wp_kses( AFFM_UI::icon( 'affiliates' ), AFFM_UI::allowed_html() ) . esc_html__( 'Program & enrollment', 'affmint' ) . '</h2><label class="affm-toggle"><input type="checkbox" name="affm[enabled]" value="yes"' . checked( AFFM_Settings::get( 'enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable affiliate program', 'affmint' ) . '</label><p class="affm-muted">' . esc_html__( 'Pausing stops new enrollments, referrals and withdrawals. Existing order commissions and history continue to update.', 'affmint' ) . '</p><div class="affm-form-grid">';
		self::field( 'approval_required', __( 'Affiliate account approval', 'affmint' ), __( 'New accounts join automatically by default. Enable review to approve or reject new applications from the Affiliates tab. Existing affiliates remain approved; pending applications still need review.', 'affmint' ), 'select', array( 'no' => __( 'Join automatically', 'affmint' ), 'yes' => __( 'Require administrator approval', 'affmint' ) ) );
		echo '</div><h3>' . esc_html__( 'Join Affiliate page', 'affmint' ) . '</h3><p class="affm-muted">' . esc_html__( 'Customize the invitation customers see in the Affiliate Area before joining.', 'affmint' ) . '</p><div class="affm-form-grid">';
		self::field( 'join_title', __( 'Join Affiliate title', 'affmint' ), __( 'Heading on the page before joining.', 'affmint' ), 'text', array(), 'maxlength="180" required' );
		self::field( 'join_button_text', __( 'Join button text', 'affmint' ), __( 'Label for the button that submits the affiliate application.', 'affmint' ), 'text', array(), 'maxlength="180" required' );
		echo '<div class="affm-field"><label for="affm-join-description">' . esc_html__( 'Description text', 'affmint' ) . '</label><textarea id="affm-join-description" name="affm[join_description]" rows="4" maxlength="3000" style="width:100%">' . esc_textarea( AFFM_Settings::get( 'join_description' ) ) . '</textarea><p>' . esc_html__( 'Plain text with line breaks. Leave empty to hide the description.', 'affmint' ) . '</p></div>';
		self::field( 'join_show_rate', __( 'Default commission rate before joining', 'affmint' ), __( 'Choose whether customers see your default percentage or fixed amount on the Join Affiliate page. Product-specific rates may differ.', 'affmint' ), 'select', array( 'no' => __( 'Hide', 'affmint' ), 'yes' => __( 'Show', 'affmint' ) ) );
		echo '<div><label class="affm-toggle"><input type="checkbox" name="affm[join_url_enabled]" value="yes"' . checked( AFFM_Settings::get( 'join_url_enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable Website / Promote URL field', 'affmint' ) . '</label><p class="affm-muted">' . esc_html__( 'Disabled by default. When enabled, applicants must provide a website or social profile URL.', 'affmint' ) . '</p></div><div><label class="affm-toggle"><input type="checkbox" name="affm[join_promotion_enabled]" value="yes"' . checked( AFFM_Settings::get( 'join_promotion_enabled' ), 'yes', false ) . '> ' . esc_html__( 'Enable How will you promote us? field', 'affmint' ) . '</label><p class="affm-muted">' . esc_html__( 'Disabled by default. When enabled, applicants must describe their promotion plan. View answers in the Affiliates list.', 'affmint' ) . '</p></div>';
		echo '</div></section><section class="affm-card" id="affm-commissions-settings"><h2>' . wp_kses( AFFM_UI::icon( 'commissions' ), AFFM_UI::allowed_html() ) . esc_html__( 'Commission rules', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Set your default earnings. Commissions use the WooCommerce base currency; individual products can override these rates.', 'affmint' ) . '</p><div class="affm-form-grid">';
		self::field( 'commission_type', __( 'Commission type', 'affmint' ), __( 'Product settings override this default.', 'affmint' ), 'select', array( 'percent' => __( 'Percentage', 'affmint' ), 'fixed' => __( 'Fixed amount per unit', 'affmint' ) ) );
		self::field( 'commission_rate', __( 'Default commission', 'affmint' ), __( 'Percentage: 0–100. Fixed: amount per purchased unit, capped at the item total.', 'affmint' ), 'number', array(), 'min="0" max="1000000" step="0.000001" required' );
		self::field( 'release_mode', __( 'Commission availability', 'affmint' ), __( 'By default, commissions become available as soon as the order is Completed. Optionally hold them for a number of days after completion. Refunds and cancellations still adjust commissions. Changes apply when an order first completes; previously released commissions stay available.', 'affmint' ), 'select', array( 'completed' => __( 'Immediately after order completion (default)', 'affmint' ), 'delay' => __( 'After a waiting period', 'affmint' ) ) );
		echo '<div id="affm-release-days"' . ( 'delay' === AFFM_Settings::get( 'release_mode' ) ? '' : ' hidden' ) . '>';
		self::field( 'release_days', __( 'Waiting period (days)', 'affmint' ), __( '1 to 365 days, counted from the order completion time. A saved waiting period is not changed by later settings updates.', 'affmint' ), 'number', array(), 'min="1" max="365" step="1" required' );
		echo '</div>';
		$base_currency = AFFM_Settings::base_currency(); $currency_names = get_woocommerce_currencies();
		echo '<div class="affm-field affm-base-currency"><span class="affm-muted">' . esc_html__( 'WooCommerce base currency', 'affmint' ) . '</span><p><strong>' . esc_html( $base_currency . ' - ' . $currency_names[ $base_currency ] ) . '</strong></p><p class="affm-muted">' . esc_html__( 'New commissions, fixed per-unit rates, minimum withdrawal and dashboard totals use the base currency from WooCommerce Settings. The Pro add-on can convert FOX/CURCY orders in another currency using a saved exchange rate. Existing transactions retain their original currency.', 'affmint' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'WooCommerce Settings', 'affmint' ) . '</a></p></div>';
		if ( AFFM_License::active() && has_action( 'affm_exchange_settings' ) ) { do_action( 'affm_exchange_settings' ); }
		else { echo '<div class="affm-field affm-premium-card"><label>' . esc_html__( 'Exchange rate source', 'affmint' ) . ' ' . wp_kses_post( AFFM_License::premium_badge() ) . '</label><select disabled aria-label="' . esc_attr__( 'Free currency mode', 'affmint' ) . '"><option>' . esc_html__( 'WooCommerce base currency only', 'affmint' ) . '</option></select><p class="affm-muted">' . esc_html__( 'Currency conversion is provided by the separate Pro add-on. Free earns commissions in the WooCommerce base currency.', 'affmint' ) . '</p>' . wp_kses_post( AFFM_License::premium_link() ) . '</div>'; }
		echo '</div></section><section class="affm-card" id="affm-tracking-settings"><h2>' . wp_kses( AFFM_UI::icon( 'clock' ), AFFM_UI::allowed_html() ) . esc_html__( 'Referral tracking & repeat purchases', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Choose how long referrals last and whether returning customers can earn additional commissions.', 'affmint' ) . '</p><div class="affm-form-grid">';
		self::field( 'referral_parameter', __( 'Referral link parameter', 'affmint' ), __( 'Default: affm_ref. Use a name such as ref or partner. Letters, numbers, underscores and hyphens only, starting with a letter. Existing affm_ref links continue to work. Exclude both names from page caching.', 'affmint' ), 'text', array(), 'maxlength="60" pattern="[a-zA-Z][a-zA-Z0-9_-]{0,59}" required' );
		self::field( 'account_slug', __( 'Affiliate Area URL slug', 'affmint' ), __( 'Default: affmint. Example: partners creates /my-account/partners/. Use letters, numbers and hyphens, starting with a letter. Avoid existing WordPress and account endpoints. The default URL continues to work.', 'affmint' ), 'text', array(), 'maxlength="60" pattern="[a-zA-Z][a-zA-Z0-9-]{0,59}" required' );
		self::field( 'cookie_days', __( 'Cookie lifetime (days)', 'affmint' ), __( 'Between 1 and 365 days.', 'affmint' ), 'number', array(), 'min="1" max="365" step="1" required' );
		self::field( 'attribution', __( 'Referral attribution', 'affmint' ), __( 'First referral keeps the first valid cookie. Last referral replaces it.', 'affmint' ), 'select', array( 'first' => __( 'First referral', 'affmint' ), 'last' => __( 'Last referral', 'affmint' ) ) );
		self::field( 'purchase_policy', __( 'Repeat purchase commissions', 'affmint' ), __( 'Once: only one eligible commission-earning purchase per customer across all affiliates and products. Multiple: each eligible referred order earns commission. Customers are matched by account or billing email. Applies to new orders only.', 'affmint' ), 'select', array( 'multiple' => __( 'Every eligible referred purchase', 'affmint' ), 'once' => __( 'Once per customer', 'affmint' ) ) );
		echo '</div></section><section class="affm-card" id="affm-payouts-settings"><h2>' . wp_kses( AFFM_UI::icon( 'payouts' ), AFFM_UI::allowed_html() ) . esc_html__( 'Payout settings', 'affmint' ) . '</h2><div class="affm-form-grid">';
		self::field( 'minimum', __( 'Minimum withdrawal', 'affmint' ), __( 'Customers request their entire available balance, after refund adjustments.', 'affmint' ), 'number', array(), 'min="0.01" max="1000000" step="0.000001" required' );
		self::field( 'payout_mode', __( 'Enable payout', 'affmint' ), __( 'Manual methods are paid outside the plugin. Automatic providers send only after approval and Send payment. Pause new withdrawals whenever needed.', 'affmint' ), 'select', array( 'manual' => __( 'Enabled', 'affmint' ), 'disabled' => __( 'Withdrawals paused', 'affmint' ) ) );
		self::field( 'method_selection', __( 'Available methods', 'affmint' ), __( 'Single keeps only the first checked method active. Multiple lets customers choose among all checked methods.', 'affmint' ), 'select', array( 'single' => __( 'One active method', 'affmint' ), 'multiple' => __( 'Multiple active methods', 'affmint' ) ) );
		echo '</div>';
		if ( AFFM_License::active() && has_action( 'affm_manual_methods_editor' ) ) { do_action( 'affm_manual_methods_editor' ); }
		else {
			echo '<div id="affm-manual-methods"><h3>' . esc_html__( 'Manual payout method', 'affmint' ) . '</h3><p class="affm-muted">' . esc_html__( 'Configure your one manual payment method. Additional methods are provided by the separate Pro add-on.', 'affmint' ) . ' ' . wp_kses_post( AFFM_License::premium_badge() ) . '</p>';
			$method = AFFM_Settings::get( 'methods' )[0] ?? array( 'id' => 'bank', 'name' => '', 'instructions' => '', 'active' => 'no' );
			self::method_row( 0, $method, false ); echo '</div>';
		}
		 AFFM_Gateways::fields(); echo '</section><section class="affm-card" id="affm-products-settings"><h2>' . wp_kses( AFFM_UI::icon( 'link' ), AFFM_UI::allowed_html() ) . esc_html__( 'Product commissions & tracking', 'affmint' ) . '</h2><p>' . esc_html__( 'Edit a product → Product data → General to set a percentage, fixed amount per unit, or disable commission for that product. Parent product settings cover all variations.', 'affmint' ) . '</p><p class="affm-muted">' . esc_html( str_replace( 'affm_ref', implode( ', ', AFFM_Settings::referral_keys() ), __( 'Caching: exclude affm_ref query strings from full-page cache, and keep My Account, Cart and Checkout uncached. With LiteSpeed, add affm_ref under Cache → Excludes → Do Not Cache Query Strings; do not add it to Drop Query String. Hosting/CDN caches must also bypass referral URLs and logged-in users. Purge existing page caches after applying these rules. Guest product pages remain cacheable. Review the AffMint privacy policy text in WordPress Settings → Privacy and connect cookie consent through the affm_tracking_allowed filter when required.', 'affmint' ) ) ) . '</p></section>';
		echo '<section class="affm-card" id="affm-terms-settings"><h2>' . wp_kses( AFFM_UI::icon( 'affiliates' ), AFFM_UI::allowed_html() ) . esc_html__( 'Affiliate Terms & Conditions', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Customers see this content in the Affiliate Area before joining and must accept it. Leave it empty to join without a terms checkbox. Updates apply to new applications; existing affiliates keep their enrollment.', 'affmint' ) . '</p><label for="affm_terms_content">' . esc_html__( 'Terms & Conditions content', 'affmint' ) . '</label>';
		wp_editor( AFFM_Settings::terms(), 'affm_terms_content', array( 'textarea_name' => 'affm[terms_content]', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true, 'quicktags' => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ) ) );
		echo '</section>';
		echo '</form>';
		AFFM_Currency::recovery_form();
		AFFM_UI::footer( __( 'Save settings', 'affmint' ), 'affm-program-settings-form' );
	}
	public static function method_row( $index, $method, $removable = true ) {
		$prefix = 'affm[methods][' . $index . ']';
		echo '<div class="affm-method-row"><input type="hidden" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( $method['id'] ) . '"><div class="affm-method-top"><label>' . esc_html__( 'Method name', 'affmint' ) . '<input type="text" name="' . esc_attr( $prefix . '[name]' ) . '" value="' . esc_attr( $method['name'] ) . '" maxlength="120" placeholder="' . esc_attr__( 'e.g. Bank transfer', 'affmint' ) . '"></label><label class="affm-toggle"><input type="checkbox" name="' . esc_attr( $prefix . '[active]' ) . '" value="yes"' . checked( $method['active'], 'yes', false ) . '> ' . esc_html__( 'Active', 'affmint' ) . '</label>' . ( $removable ? '<button type="button" class="button affm-remove-method affm-primary">' . esc_html__( 'Remove', 'affmint' ) . '</button>' : '' ) . '</div><label>' . esc_html__( 'Instructions for customers', 'affmint' ) . '<textarea name="' . esc_attr( $prefix . '[instructions]' ) . '" rows="2" maxlength="2000">' . esc_textarea( $method['instructions'] ) . '</textarea></label></div>';
	}
	private static function identity( $id ) {
		$user = get_userdata( $id );
		return $user ? '<strong>' . esc_html( $user->display_name ) . '</strong><small class="affm-note">' . esc_html( $user->user_email ) . '</small>' : '#' . absint( $id );
	}
	private static function search_term() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		return mb_substr( sanitize_text_field( wp_unslash( AFFM_Security::value( $_GET, 'q' ) ) ), 0, 160 );
	}
	private static function list_statuses( $tab ) {
		return 'affiliates' === $tab ? array( 'pending', 'approved', 'rejected', 'blocked' ) : ( 'payouts' === $tab ? array( 'pending', 'approved', 'processing', 'completed', 'tested', 'failed', 'rejected' ) : array( 'pending', 'available', 'reserved', 'paid', 'rejected' ) );
	}
	private static function list_status( $tab ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$status = sanitize_key( AFFM_Security::value( $_GET, 'status' ) );
		return in_array( $status, self::list_statuses( $tab ), true ) ? $status : '';
	}
	private static function filters( $tab ) {
		$search = self::search_term(); $status = self::list_status( $tab );
		$placeholder = 'affiliates' === $tab ? __( 'Name, email or user ID', 'affmint' ) : ( 'commissions' === $tab ? __( 'Affiliate, email, order or commission ID', 'affmint' ) : __( 'Affiliate, email or withdrawal ID', 'affmint' ) );
		echo '<form class="affm-list-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="affmint"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label class="screen-reader-text" for="affm-list-search">' . esc_html__( 'Search records', 'affmint' ) . '</label><input type="search" id="affm-list-search" name="q" value="' . esc_attr( $search ) . '" maxlength="160" placeholder="' . esc_attr( $placeholder ) . '"><label class="screen-reader-text" for="affm-status-filter">' . esc_html__( 'Filter status', 'affmint' ) . '</label><select id="affm-status-filter" name="status"><option value="">' . esc_html__( 'All statuses', 'affmint' ) . '</option>';
		foreach ( self::list_statuses( $tab ) as $option ) { echo '<option value="' . esc_attr( $option ) . '"' . selected( $status, $option, false ) . '>' . esc_html( 'blocked' === $option ? __( 'Blocked', 'affmint' ) : wp_strip_all_tags( AFFM_Store::status( $option ) ) ) . '</option>'; }
		echo '</select><button type="submit" class="button affm-primary">' . esc_html__( 'Search', 'affmint' ) . '</button>';
		if ( '' !== $search || '' !== $status ) { echo '<a class="affm-filter-reset" href="' . esc_url( add_query_arg( array( 'page' => 'affmint', 'tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Reset', 'affmint' ) . '</a>'; }
		echo '</form>';
	}
	private static function affiliates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = AFFM_Security::page( $_GET, 'paged' );
		// phpcs:ignore WordPress.DB.SlowDBQuery -- Required enrollment predicate; results are paginated at ten users and WordPress caches query IDs/totals.
		$args = array( 'cache_results' => true, 'meta_key' => '_affm_enrolled', 'meta_value' => 'yes', 'number' => self::PAGE_SIZE, 'offset' => ( $page - 1 ) * self::PAGE_SIZE, 'orderby' => 'ID', 'order' => 'DESC' );
		$search = self::search_term(); $status = self::list_status( 'affiliates' );
		if ( '' !== $search ) { $args['search'] = '*' . ltrim( $search, '#' ) . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'ID' ); }
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required account-status predicate for this paginated, cached user query.
		if ( 'blocked' === $status ) { $args['meta_query'] = array( array( 'key' => '_affm_blocked', 'value' => 'yes' ) ); }
		elseif ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Preserve legacy approval and blocked-account semantics; native user-query caches are invalidated by metadata updates.
			$args['meta_query'] = array( 'relation' => 'AND', array( 'relation' => 'OR', array( 'key' => '_affm_blocked', 'compare' => 'NOT EXISTS' ), array( 'key' => '_affm_blocked', 'value' => 'yes', 'compare' => '!=' ) ) );
			$args['meta_query'][] = 'approved' === $status ? array( 'relation' => 'OR', array( 'key' => '_affm_approval', 'compare' => 'NOT EXISTS' ), array( 'key' => '_affm_approval', 'value' => array( 'pending', 'rejected' ), 'compare' => 'NOT IN' ) ) : array( 'key' => '_affm_approval', 'value' => $status );
		}
		$query = new WP_User_Query( $args );
		$count = (int) $query->get_total();
		$last = max( 1, (int) ceil( $count / self::PAGE_SIZE ) );
		if ( $page > $last ) {
			$page = $last; $args['offset'] = ( $page - 1 ) * self::PAGE_SIZE; $args['count_total'] = false;
			$query = new WP_User_Query( $args );
		}
		echo '<section class="affm-card"><div class="affm-card-toolbar"><h2>' . esc_html__( 'Affiliates', 'affmint' ) . '</h2>'; self::filters( 'affiliates' ); echo '</div><div class="affm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Affiliate', 'affmint' ) . '</th><th>' . esc_html__( 'Available balance', 'affmint' ) . '</th><th>' . esc_html__( 'Clicks', 'affmint' ) . '</th><th>' . esc_html__( 'Registered customers', 'affmint' ) . '</th><th>' . esc_html__( 'Account', 'affmint' ) . '</th><th>' . esc_html__( 'Action', 'affmint' ) . '</th></tr></thead><tbody>';
		foreach ( $query->get_results() as $user ) {
			$blocked = 'yes' === get_user_meta( $user->ID, '_affm_blocked', true );
			$stats = AFFM_Store::referral_stats( $user->ID );
			$approval = AFFM_Affiliates::status( $user->ID );
			echo '<tr><td>' . wp_kses_post( self::identity( $user->ID ) ) . '</td><td>' . wp_kses_post( AFFM_Store::money( AFFM_Store::balance( $user->ID, AFFM_Settings::get( 'currency' ) ) ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $stats['customers'] ) ) . '</td><td>' . ( $blocked ? esc_html__( 'Blocked', 'affmint' ) : wp_kses_post( AFFM_Store::status( $approval ) ) ) . '</td><td>';
			self::application_details( $user->ID );
			$actions = 'pending' === $approval ? array( 'approved' => __( 'Approve', 'affmint' ), 'rejected' => __( 'Reject', 'affmint' ) ) : ( 'rejected' === $approval ? array( 'approved' => __( 'Approve', 'affmint' ) ) : array( $blocked ? 'unblock' : 'block' => $blocked ? __( 'Unblock', 'affmint' ) : __( 'Block', 'affmint' ) ) );
			echo '<form class="affm-actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="affm_affiliate"><input type="hidden" name="affiliate_id" value="' . absint( $user->ID ) . '">'; wp_nonce_field( 'affm_affiliate_' . $user->ID );
			foreach ( $actions as $operation => $label ) { echo '<button class="button affm-primary" name="operation" value="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button>'; }
			echo '</form></td></tr>';
		}
		if ( ! $query->get_results() ) { echo '<tr><td colspan="6" class="affm-empty">' . esc_html( '' !== $search || '' !== $status ? __( 'No affiliates match your search or filters.', 'affmint' ) : __( 'Customers who join the program will appear here.', 'affmint' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, esc_html( $page ), 'affiliates', $status ); echo '</section>';
	}
	private static function application_details( $id ) {
		$application = get_user_meta( $id, '_affm_application', true );
		if ( ! is_array( $application ) || ( empty( $application['website'] ) && empty( $application['promotion'] ) ) ) { return; }
		echo '<details class="affm-payment"><summary>' . esc_html__( 'Application details', 'affmint' ) . '</summary>';
		$url = esc_url( AFFM_Security::value( $application, 'website' ), array( 'http', 'https' ) );
		if ( $url ) { echo '<p><strong>' . esc_html__( 'Website / Promote URL', 'affmint' ) . '</strong><br><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( AFFM_Security::value( $application, 'website' ) ) . '</a></p>'; }
		if ( ! empty( $application['promotion'] ) ) { echo '<p><strong>' . esc_html__( 'Promotion plan', 'affmint' ) . '</strong><br>' . nl2br( esc_html( AFFM_Security::value( $application, 'promotion' ) ) ) . '</p>'; }
		echo '</details>';
	}
	private static function records( $type ) {
		global $wpdb;
		$table = AFFM_Store::table( $type );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = AFFM_Security::page( $_GET, 'paged' );
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
		echo '<section class="affm-card"><div class="affm-card-toolbar"><h2>' . esc_html( 'payouts' === $type ? __( 'Withdrawal requests', 'affmint' ) : __( 'Commission ledger', 'affmint' ) ) . '</h2>'; self::filters( $type ); echo '</div>';
		if ( 'payouts' === $type ) { echo '<p class="affm-muted">' . esc_html__( 'Approve each request first. For manual methods, send money outside the plugin and choose Mark paid. For PayPal or Stripe, choose Send payment; completion requires provider confirmation. Sandbox/Test does not settle real earnings. Notes are visible to the affiliate.', 'affmint' ) . '</p>'; }
		echo '<div class="affm-table-scroll"><table><thead><tr><th>#</th><th>' . esc_html__( 'Affiliate', 'affmint' ) . '</th><th>' . esc_html__( 'Amount', 'affmint' ) . '</th><th>' . esc_html__( 'Status', 'affmint' ) . '</th><th>' . esc_html__( 'Date', 'affmint' ) . '</th><th>' . esc_html( 'payouts' === $type ? __( 'Payment & action', 'affmint' ) : __( 'Order / paid', 'affmint' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . absint( $row->id ) . '</td><td>' . wp_kses_post( self::identity( $row->affiliate_id ) ) . '</td><td>' . wp_kses_post( AFFM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AFFM_Store::status( $row->status ) ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td><td>';
			if ( 'commissions' === $type ) {
				$order = wc_get_order( $row->order_id );
				if ( 'repeat' === $row->eligibility ) { echo '<small class="affm-note">' . esc_html__( 'Repeat purchase excluded', 'affmint' ) . '</small>'; }
				if ( $order ) { echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . absint( $row->order_id ) . '</a>'; }
				else { echo '#' . absint( $row->order_id ); }
				echo '<small class="affm-note">' . esc_html__( 'Settled:', 'affmint' ) . ' ' . wp_kses_post( AFFM_Store::money( $row->paid_amount, $row->currency ) ) . '</small>';
			} else {
				echo '<strong>' . esc_html( $row->method ) . '</strong><details class="affm-payment"><summary>' . esc_html__( 'Payment details', 'affmint' ) . '</summary><p>' . nl2br( esc_html( $row->details ) ) . '</p></details>';
				$gateway_job = AFFM_Gateways::job( $row->id ); AFFM_Gateways::summary( $row->id );
				if ( in_array( $row->status, array( 'pending', 'approved', 'processing' ), true ) ) {
					echo '<form class="affm-payout-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="affm_payout"><input type="hidden" name="payout_id" value="' . absint( $row->id ) . '">';
					wp_nonce_field( 'affm_payout_' . $row->id );
					echo '<label class="screen-reader-text" for="affm-note-' . absint( $row->id ) . '">' . esc_html__( 'Note / payment reference', 'affmint' ) . '</label><textarea id="affm-note-' . absint( $row->id ) . '" name="note" rows="2" maxlength="2000" placeholder="' . esc_attr__( 'Note / payment reference', 'affmint' ) . '">' . esc_textarea( $row->note ) . '</textarea><div class="affm-actions">';
					if ( 'processing' === $row->status ) { echo '<button class="button affm-primary" name="status" value="check">' . esc_html__( 'Check payment status', 'affmint' ) . '</button>'; }
					elseif ( 'pending' === $row->status ) { echo '<button class="button button-primary affm-primary" name="status" value="approved">' . esc_html__( 'Approve', 'affmint' ) . '</button>'; }
					elseif ( $gateway_job ) { echo '<button class="button affm-primary" name="status" value="processing" data-affm-confirm="' . esc_attr__( 'Send this approved payout through the configured provider? Sandbox/Test sends no real money.', 'affmint' ) . '">' . esc_html( 'live' === $gateway_job['mode'] ? __( 'Send payment', 'affmint' ) : __( 'Run Sandbox payout', 'affmint' ) ) . '</button>'; }
					else { echo '<button class="button button-primary affm-primary" name="status" value="completed" data-affm-confirm="' . esc_attr__( 'Confirm that you have already sent this payment. This will settle the reserved commissions.', 'affmint' ) . '">' . esc_html__( 'Mark paid', 'affmint' ) . '</button>'; }
					if ( 'processing' !== $row->status ) { echo '<button class="button affm-primary" name="status" value="rejected">' . esc_html__( 'Reject', 'affmint' ) . '</button>'; } echo '</div></form>';
				} elseif ( $row->note ) { echo '<small class="affm-note">' . esc_html( $row->note ) . '</small>'; }
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="6" class="affm-empty">' . esc_html__( 'No records found.', 'affmint' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $count, esc_html( $page ), $type, $status ); echo '</section>';
	}
	private static function pagination( $count, $page, $tab, $status = '' ) {
		$total = (int) ceil( $count / self::PAGE_SIZE );
		if ( $total <= 1 ) { return; }
		$page = min( max( 1, $page ), $total );
		$base = add_query_arg( array( 'page' => 'affmint', 'tab' => $tab, 'status' => $status, 'q' => self::search_term(), 'paged' => 999999999 ), admin_url( 'admin.php' ) );
		$clean_url = function ( $url ) { return remove_query_arg( array( 'saved', 'affm_message', 'affm_error' ), $url ); };
		add_filter( 'get_pagenum_link', $clean_url );
		try { $links = paginate_links( array( 'base' => str_replace( '999999999', '%#%', $base ), 'format' => '', 'current' => $page, 'total' => $total, 'type' => 'array', 'add_args' => false, 'mid_size' => 1, 'end_size' => 1, 'prev_text' => __( 'Previous', 'affmint' ), 'next_text' => __( 'Next', 'affmint' ) ) ); } finally { remove_filter( 'get_pagenum_link', $clean_url ); }
		/* translators: %1$d: current page, %2$d: total pages. */
		echo '<nav class="affm-pagination affm-admin-pagination" aria-label="' . esc_attr__( 'List pages', 'affmint' ) . '"><span class="affm-page-summary">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affmint' ), esc_html( $page ), esc_html( $total ) ) . '</span><div class="affm-page-links">';
		foreach ( (array) $links as $link ) {
			$link = preg_replace( '/(<a\s[^>]*class=")([^"]*)(")/', '$1$2 affm-primary$3', $link );
			echo wp_kses_post( $link );
		}
		echo '</div></nav>';
	}
}
