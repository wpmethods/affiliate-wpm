<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Account {
	public static function endpoint() {
		global $wp_rewrite;
		foreach ( array_unique( array( 'affmint', AFFM_Settings::account_slug() ) ) as $slug ) {
			if ( ! in_array( $slug, array_column( $wp_rewrite->endpoints, 1 ), true ) ) { add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES ); }
		}
	}
	public static function settings_changed( $old, $new ) {
		$previous = AFFM_Settings::url_name( AFFM_Security::value( (array) $old, 'account_slug', 'affmint' ), 'account_slug' );
		$current = AFFM_Settings::url_name( AFFM_Security::value( (array) $new, 'account_slug', 'affmint' ), 'account_slug' );
		if ( $previous === $current ) { return; }
		global $wp_rewrite;
		if ( 'affmint' !== $previous ) { $wp_rewrite->endpoints = array_values( array_filter( $wp_rewrite->endpoints, function ( $endpoint ) use ( $previous ) { return $endpoint[1] !== $previous; } ) ); }
		self::endpoint();
		flush_rewrite_rules( false );
	}
	/** Normalize the default URL alias so WooCommerce conditionals also recognize it. */
	public static function parse_request( $request ) {
		$slug = AFFM_Settings::account_slug();
		if ( 'affmint' !== $slug && isset( $request->query_vars['affmint'] ) && ! isset( $request->query_vars[ $slug ] ) ) { $request->query_vars[ $slug ] = $request->query_vars['affmint']; }
	}
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_affmint_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_affmint_title', function () { return __( 'Affiliate Area', 'affmint' ); } );
		add_action( 'template_redirect', array( __CLASS__, 'submit' ) );
		add_action( 'wp_enqueue_scripts', function () {
			if ( is_account_page() && is_wc_endpoint_url( 'affmint' ) ) {
				wp_enqueue_style( 'affm-account', AFFM_URL . 'assets/affmint.css', array(), (string) filemtime( AFFM_PATH . 'assets/affmint.css' ) );
				wp_enqueue_style( 'affm-panel', AFFM_URL . 'assets/panel.css', array( 'affm-account' ), (string) filemtime( AFFM_PATH . 'assets/panel.css' ) );
				AFFM_Design::enqueue( 'affm-panel' );
				wp_enqueue_script( 'affm-account', AFFM_URL . 'assets/account.js', array(), (string) filemtime( AFFM_PATH . 'assets/account.js' ), true );
			}
		} );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}
	public static function query_vars( $vars ) {
		// WooCommerce conditionals, endpoint titles and URLs use its own registry.
		$vars['affmint'] = AFFM_Settings::account_slug();
		return $vars;
	}
	public static function menu( $items ) {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['affmint'] = __( 'Affiliate Area', 'affmint' );
		if ( $logout ) { $items['customer-logout'] = $logout; }
		return $items;
	}
	public static function submit() {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { return; }
		if ( ! isset( $_POST['affm_action'] ) || ! is_scalar( $_POST['affm_action'] ) || ! is_account_page() || ! is_wc_endpoint_url( 'affmint' ) ) { return; }
		if ( ! is_user_logged_in() ) { return; }
		$nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'affm_account' ) ) { wc_add_notice( __( 'Your session expired. Please try again.', 'affmint' ), 'error' ); }
		else {
			$id = get_current_user_id();
			$action = sanitize_key( wp_unslash( $_POST['affm_action'] ) );
			if ( 'join' === $action ) {
				$result = AFFM_Affiliates::join( $id, 'yes' === AFFM_Security::value( $_POST, 'affm_accept_terms' ), wp_unslash( AFFM_Security::value( $_POST, 'affm_terms_version' ) ), wp_unslash( AFFM_Security::rows( $_POST, 'affm_join' ) ) );
				$messages = array( 'approved' => __( 'Your affiliate account is ready. Start sharing your referral link!', 'affmint' ), 'pending' => __( 'Your affiliate application is awaiting administrator approval.', 'affmint' ), 'rejected' => __( 'Your affiliate application was rejected. Please contact the store.', 'affmint' ) );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : ( $messages[ $result ] ?? '' ), is_wp_error( $result ) || 'rejected' === $result ? 'error' : 'success' );
			} elseif ( 'stripe_connect' === $action ) {
				$result = AFFM_Gateways::connect( sanitize_text_field( wp_unslash( AFFM_Security::value( $_POST, 'stripe_country' ) ) ) ); if ( is_wp_error( $result ) ) { wc_add_notice( $result->get_error_message(), 'error' ); }
			} elseif ( 'withdraw' === $action ) {
				$method = isset( $_POST['method'] ) && is_scalar( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
				$details = isset( $_POST['details'] ) && is_scalar( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
				$result = AFFM_Payouts::request( $id, $method, $details );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Withdrawal requested. You can follow its status below.', 'affmint' ), is_wp_error( $result ) ? 'error' : 'success' );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'affmint' ) );
		exit;
	}
	public static function render() {
		global $wpdb;
		$id = get_current_user_id(); if ( ! $id ) { return; }
		AFFM_Cache::no_cache( 'AffMint customer dashboard' );
		$user = wp_get_current_user(); $status = AFFM_Affiliates::status( $id );
		$active = AFFM_Sharing::allowed( $id ); $currency = AFFM_Settings::get( 'currency' );
		$name = $user->first_name ?: $user->display_name;
		/* translators: %s: affiliate display name. */
		echo '<div class="affm affm-account"><header class="affm-account-hero"><div><span class="affm-eyebrow">' . esc_html__( 'YOUR PARTNER SPACE', 'affmint' ) . '</span><h2>' . esc_html__( 'Affiliate Area', 'affmint' ) . '</h2><p>' . sprintf( esc_html__( 'Hello %s. Share your favorites and follow your earnings.', 'affmint' ), esc_html( $name ) ) . '</p></div><div class="affm-hero-side">';
		if ( 'none' !== $status ) { echo wp_kses_post( AFFM_Store::status( $active ? 'approved' : ( 'approved' === $status ? 'paused' : $status ) ) ); }
		else { echo '<span class="affm-hero-mark">' . wp_kses( AFFM_UI::icon( 'sharing' ), AFFM_UI::allowed_html() ) . '</span>'; }
		echo '</div></header>';
		if ( 'none' === $status ) {
			echo '<section class="affm-card affm-onboarding"><span class="affm-icon-tile">' . wp_kses( AFFM_UI::icon( 'link' ), AFFM_UI::allowed_html() ) . '</span><h3>' . esc_html( AFFM_Settings::get( 'join_title' ) ) . '</h3>' . ( '' !== trim( AFFM_Settings::get( 'join_description' ) ) ? '<p>' . nl2br( esc_html( AFFM_Settings::get( 'join_description' ) ) ) . '</p>' : '' ) . '<ol class="affm-onboarding-steps"><li><strong>' . esc_html__( 'Join the program', 'affmint' ) . '</strong><span>' . esc_html__( 'Create your affiliate account from here.', 'affmint' ) . '</span></li><li><strong>' . esc_html__( 'Share your link', 'affmint' ) . '</strong><span>' . esc_html__( 'Recommend a product or a page you love.', 'affmint' ) . '</span></li><li><strong>' . esc_html__( 'Earn & withdraw', 'affmint' ) . '</strong><span>' . esc_html__( 'Completed orders release eligible earnings.', 'affmint' ) . '</span></li></ol>';
			if ( 'yes' === AFFM_Settings::get( 'join_show_rate' ) ) {
				$rate = 'percent' === AFFM_Settings::get( 'commission_type' ) ? esc_html( wc_format_decimal( AFFM_Settings::get( 'commission_rate' ), 6, true ) . '%' ) : wp_kses_post( AFFM_Store::money( AFFM_Settings::get( 'commission_rate' ), $currency ) ) . ' ' . esc_html__( 'per purchased unit', 'affmint' );
				echo '<div class="affm-join-commission"><strong>' . esc_html__( 'Default commission:', 'affmint' ) . ' ' . wp_kses_post( $rate ) . '</strong><p class="affm-muted">' . esc_html__( 'Individual products may have different commission rates. Commissions apply to eligible purchases.', 'affmint' ) . '</p></div>';
			}
			if ( AFFM_Settings::program_enabled() && 'yes' !== get_user_meta( $id, '_affm_blocked', true ) ) {
				echo '<form method="post">'; wp_nonce_field( 'affm_account' );
				if ( 'yes' === AFFM_Settings::get( 'join_url_enabled' ) ) { echo '<div class="affm-field"><label for="affm-join-website">' . esc_html__( 'Website / Promote URL', 'affmint' ) . '</label><input type="url" id="affm-join-website" name="affm_join[website]" maxlength="2048" placeholder="https://example.com/your-profile" autocomplete="url" required><p class="affm-muted">' . esc_html__( 'Your website, blog, channel or social profile.', 'affmint' ) . '</p></div>'; }
				if ( 'yes' === AFFM_Settings::get( 'join_promotion_enabled' ) ) { echo '<div class="affm-field"><label for="affm-join-promotion">' . esc_html__( 'How will you promote us?', 'affmint' ) . '</label><textarea id="affm-join-promotion" name="affm_join[promotion]" maxlength="3000" rows="4" style="width:100%" required></textarea><p class="affm-muted">' . esc_html__( 'Tell us about your audience and how you plan to share our products.', 'affmint' ) . '</p></div>'; }
				$terms = AFFM_Settings::terms();
				if ( '' !== $terms ) {
					echo '<section class="affm-join-terms" aria-labelledby="affm-terms-title"><h3 id="affm-terms-title">' . esc_html__( 'Terms & Conditions', 'affmint' ) . '</h3><div class="affm-terms-content" tabindex="0" role="region" aria-labelledby="affm-terms-title">' . wp_kses_post( wpautop( $terms ) ) . '</div><label class="affm-terms-accept"><input type="checkbox" name="affm_accept_terms" value="yes" required><span>' . esc_html__( 'I have read and agree to the affiliate Terms & Conditions.', 'affmint' ) . '</span></label><input type="hidden" name="affm_terms_version" value="' . esc_attr( AFFM_Settings::terms_version() ) . '"></section>';
				}
				echo '<input type="hidden" name="affm_action" value="join"><button class="button affm-primary">' . esc_html( AFFM_Settings::get( 'join_button_text' ) ) . wp_kses( AFFM_UI::icon( 'arrow' ), AFFM_UI::allowed_html() ) . '</button></form><p class="affm-muted">' . esc_html( 'yes' === AFFM_Settings::get( 'approval_required' ) ? __( 'Your application will be reviewed by the store.', 'affmint' ) : __( 'Join now to get your personal referral link.', 'affmint' ) ) . '</p>';
			} else { echo '<p class="affm-notice">' . esc_html__( 'Enrollment is currently unavailable.', 'affmint' ) . '</p>'; }
			echo '</section></div>'; return;
		}
		if ( in_array( $status, array( 'pending', 'rejected' ), true ) ) {
			echo '<section class="affm-card affm-application"><span class="affm-icon-tile">' . wp_kses( AFFM_UI::icon( 'clock' ), AFFM_UI::allowed_html() ) . '</span><h3>' . esc_html__( 'Affiliate application', 'affmint' ) . '</h3><p>' . esc_html( 'pending' === $status ? __( 'Your application is awaiting administrator approval. Your links and withdrawal tools will be ready once you are approved.', 'affmint' ) : __( 'Your application was rejected. Please contact the store if you have questions.', 'affmint' ) ) . '</p></section></div>'; return;
		}
		echo '<nav class="affm-account-nav" aria-label="' . esc_attr__( 'Affiliate sections', 'affmint' ) . '">';
		foreach ( array( 'earnings' => __( 'Overview', 'affmint' ), 'sharing' => __( 'Sharing tools', 'affmint' ), 'withdraw' => __( 'Withdraw', 'affmint' ), 'commissions' => __( 'History', 'affmint' ), 'customers' => __( 'Customers', 'affmint' ) ) as $key => $label ) { echo '<a href="#affm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		if ( ! $active ) { echo '<p class="affm-notice">' . esc_html__( 'The affiliate program or your account is paused. Your history is still available.', 'affmint' ) . '</p>'; }
		$commissions = AFFM_Store::table( 'commissions' ); $payouts = AFFM_Store::table( 'payouts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s AND status=\'pending\'', $commissions, $id, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$reserved = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s AND status IN (\'pending\',\'approved\',\'processing\')', $payouts, $id, $currency ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$paid = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(paid_amount),0) FROM %i WHERE affiliate_id=%d AND currency=%s', $commissions, $id, $currency ) );
		$balance = AFFM_Store::balance( $id, $currency );
		echo '<div class="affm-metrics" id="affm-earnings">';
		echo wp_kses( AFFM_UI::metric( __( 'Available balance', 'affmint' ), AFFM_Store::money( $balance, $currency ), 'wallet', __( 'Ready for withdrawal', 'affmint' ) ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'Pending earnings', 'affmint' ), AFFM_Store::money( $pending, $currency ), 'clock', __( 'Awaiting order completion or the waiting period', 'affmint' ) ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'In withdrawal', 'affmint' ), AFFM_Store::money( $reserved, $currency ), 'send', __( 'Requests in progress', 'affmint' ) ), AFFM_UI::allowed_html() );
		echo wp_kses( AFFM_UI::metric( __( 'Total paid out', 'affmint' ), AFFM_Store::money( $paid, $currency ), 'award', __( 'Completed payouts', 'affmint' ) ), AFFM_UI::allowed_html() );
		$stats = AFFM_Store::referral_stats( $id );
		echo '</div><div class="affm-referral-metrics">' . wp_kses( AFFM_UI::metric( __( 'Referral clicks', 'affmint' ), number_format_i18n( $stats['clicks'] ), 'click' ), AFFM_UI::allowed_html() ) . wp_kses( AFFM_UI::metric( __( 'Registered customers', 'affmint' ), number_format_i18n( $stats['customers'] ), 'user' ), AFFM_UI::allowed_html() ) . '</div><details class="affm-stat-help"><summary>' . esc_html__( 'How your activity is counted', 'affmint' ) . '</summary><p>' . esc_html__( 'Consecutive clicks on the same referral count once per browser within 30 minutes. Registered customers are new accounts created through your referral cookie. These statistics start from when tracking was enabled.', 'affmint' ) . '</p></details><div class="affm-grid affm-account-tools"><section class="affm-card affm-link-card" id="affm-sharing"><div class="affm-card-heading"><span class="affm-icon-tile">' . wp_kses( AFFM_UI::icon( 'link' ), AFFM_UI::allowed_html() ) . '</span><div><h3>' . esc_html__( 'Your referral link', 'affmint' ) . '</h3><p>' . esc_html__( 'One link to share your favorite store.', 'affmint' ) . '</p></div></div>';
		if ( $active ) {
			$link = add_query_arg( AFFM_Settings::referral_parameter(), AFFM_Tracking::token( $id ), home_url( '/' ) );
		/* translators: %d: referral cookie lifetime in days. */
			echo '<label for="affm-ref-link" class="screen-reader-text">' . esc_html__( 'Referral link', 'affmint' ) . '</label><div class="affm-copy"><input id="affm-ref-link" readonly value="' . esc_attr( $link ) . '"><button type="button" class="button affm-primary" data-affm-copy data-copied="' . esc_attr__( 'Copied!', 'affmint' ) . '">' . esc_html__( 'Copy', 'affmint' ) . '</button></div><p class="affm-muted affm-link-hint">' . sprintf( esc_html__( 'Referral cookie: %d days. Self-referrals do not earn commission.', 'affmint' ), (int) AFFM_Settings::get( 'cookie_days' ) ) . '</p><p class="affm-copy-feedback" role="status" aria-live="polite"></p>';
			AFFM_Sharing::generator();
			self::best_sellers( $id );
		} else { echo '<p class="affm-muted">' . esc_html__( 'Your sharing tools will return when your affiliate account and the program are active.', 'affmint' ) . '</p>'; }
		echo '</section><section class="affm-card affm-withdraw-card" id="affm-withdraw"><div class="affm-card-heading"><span class="affm-icon-tile">' . wp_kses( AFFM_UI::icon( 'payouts' ), AFFM_UI::allowed_html() ) . '</span><div><h3>' . esc_html__( 'Request a withdrawal', 'affmint' ) . '</h3><p>' . esc_html__( 'Choose how you receive your earnings.', 'affmint' ) . '</p></div></div>';
		$minimum = max( 0.01, (float) AFFM_Settings::get( 'minimum' ) );
		echo '<div class="affm-withdraw-balance"><span>' . esc_html__( 'Available to withdraw', 'affmint' ) . '</span><strong>' . wp_kses_post( AFFM_Store::money( $balance, $currency ) ) . '</strong><p class="affm-muted">' . esc_html__( 'Minimum withdrawal:', 'affmint' ) . ' ' . wp_kses_post( AFFM_Store::money( $minimum, $currency ) ) . '</p>';
		/* translators: %s: formatted amount needed to reach the withdrawal minimum. */
		if ( $balance < $minimum ) { echo '<progress max="' . esc_attr( $minimum ) . '" value="' . esc_attr( max( 0, $balance ) ) . '" aria-label="' . esc_attr__( 'Progress toward minimum withdrawal', 'affmint' ) . '"></progress><p class="affm-muted">' . sprintf( esc_html__( '%s more to reach your minimum.', 'affmint' ), wp_kses_post( AFFM_Store::money( $minimum - $balance, $currency ) ) ) . '</p>'; }
		echo '</div>';
		AFFM_Gateways::connection_ui( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$methods = AFFM_Settings::methods(); $open = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE affiliate_id=%d AND status IN (\'pending\',\'approved\',\'processing\') LIMIT 1', $payouts, $id ) );
		if ( $active && $methods && ! $open ) {
			echo '<form method="post" class="affm-withdraw">'; wp_nonce_field( 'affm_account' );
			echo '<input type="hidden" name="affm_action" value="withdraw"><label for="affm-method">' . esc_html__( 'Payout method', 'affmint' ) . '</label><select id="affm-method" name="method">';
			foreach ( $methods as $method ) { echo '<option value="' . esc_attr( $method['id'] ) . '" data-provider="' . esc_attr( $method['provider'] ?? 'manual' ) . '" data-instructions="' . esc_attr( $method['instructions'] ) . '">' . esc_html( $method['name'] ) . '</option>'; }
			echo '</select><p id="affm-instructions" class="affm-muted">' . esc_html( $methods[0]['instructions'] ) . '</p><label for="affm-details">' . esc_html__( 'Payment destination details', 'affmint' ) . '</label><textarea id="affm-details" name="details" rows="3" required minlength="3" maxlength="2000"></textarea><p class="affm-muted">' . esc_html__( 'Enter only the details needed to receive payment. Never share passwords or card security codes.', 'affmint' ) . '</p><button class="button affm-primary"' . disabled( $balance < $minimum, true, false ) . '>' . esc_html__( 'Withdraw available balance', 'affmint' ) . wp_kses( AFFM_UI::icon( 'arrow' ), AFFM_UI::allowed_html() ) . '</button></form>';
		} else { echo '<p class="affm-notice">' . esc_html( $open ? __( 'Your withdrawal is being reviewed. Follow its status in the history below.', 'affmint' ) : __( 'Withdrawals are currently unavailable.', 'affmint' ) ) . '</p>'; }
		echo '</section></div><p class="affm-muted affm-balance-note">' . esc_html__( 'Refunds adjust your earnings. Refunds after a payout reduce future earnings. History keeps each transaction in its original currency.', 'affmint' ) . '</p>';
		self::history( 'commissions', $id ); self::history( 'payouts', $id ); self::customers( $id ); echo '</div>';
	}
	private static function best_sellers( $id ) {
		if ( get_current_user_id() !== (int) $id || ! AFFM_Sharing::allowed( $id ) ) { return; }
		// Rank with WooCommerce's product lookup table instead of casting/sorting postmeta.
		// Scope the adapter to this query; never change unrelated product/archive queries.
		$rank = static function ( $clauses, $query ) {
			if ( ! $query->get( 'affm_best_sellers' ) ) { return $clauses; }
			$clauses = WC()->query->order_by_popularity_post_clauses( $clauses );
			$clauses['where'] .= ' AND wc_product_meta_lookup.total_sales > 0';
			return $clauses;
		};
		add_filter( 'posts_clauses', $rank, 20, 2 );
		try {
			$query = new WP_Query( array(
				'post_type' => 'product', 'post_status' => 'publish', 'post_password' => '',
				'posts_per_page' => 5, 'fields' => 'ids', 'no_found_rows' => true,
				'ignore_sticky_posts' => true, 'cache_results' => true,
				'affm_best_sellers' => true, 'orderby' => 'none',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required commission eligibility predicate; only five IDs are returned and WordPress query caching remains enabled.
				'meta_query' => array( 'relation' => 'OR',
					array( 'key' => '_affm_type', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_affm_type', 'value' => 'disabled', 'compare' => '!=' ),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- WooCommerce type/catalog visibility rules exclude hidden and unsupported products; the bounded query uses WordPress caches.
				'tax_query' => array( 'relation' => 'AND',
					array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => array( 'simple', 'variable' ) ),
					array( 'taxonomy' => 'product_visibility', 'field' => 'slug', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
				),
			) );
		} finally {
			remove_filter( 'posts_clauses', $rank, 20 );
		}
		echo '<section class="affm-best-sellers"><h3>' . esc_html__( 'Top selling products', 'affmint' ) . '</h3><p class="affm-muted">' . esc_html__( 'Popular products across the store, ranked by lifetime sales. Copy a link to share.', 'affmint' ) . '</p><ol class="affm-best-seller-list">';
		$shown = 0;
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_visible() ) { continue; }
			$url = $product->get_permalink(); $link = AFFM_Sharing::link( $id, $url );
			if ( is_wp_error( $link ) ) { continue; }
			$shown++;
		/* translators: %s: product name. */
			echo '<li><span class="affm-best-seller-rank" aria-hidden="true">' . esc_html( $shown ) . '</span><a class="affm-best-seller-product" href="' . esc_url( $url ) . '">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ) ) . '<span>' . esc_html( $product->get_name() ) . '</span></a><button type="button" class="button affm-primary" data-affm-copy data-affm-url="' . esc_attr( $link ) . '" data-copied="' . esc_attr__( 'Copied!', 'affmint' ) . '" data-copy-failed="' . esc_attr__( 'Could not copy. Please try again.', 'affmint' ) . '" aria-label="' . esc_attr( sprintf( __( 'Copy affiliate link for %s', 'affmint' ), $product->get_name() ) ) . '">' . esc_html__( 'Copy link', 'affmint' ) . '</button></li>';
		}
		echo '</ol>';
		if ( ! $shown ) { echo '<p class="affm-muted">' . esc_html__( 'Top-selling products will appear here when eligible products have sales.', 'affmint' ) . '</p>'; }
		echo '<p class="affm-copy-feedback" role="status" aria-live="polite"></p></section>';
	}
	/** Include only variation attributes, never arbitrary order-item/customer metadata. */
	public static function item_title( $item ) {
		$title = wp_strip_all_tags( $item->get_name() );
		$product = $item->get_product();
		$details = array();
		if ( $product && $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes() as $attribute => $fallback ) {
				$key = preg_replace( '/^attribute_/', '', $attribute );
				$value = $item->get_meta( $key, true );
				$value = is_scalar( $value ) && '' !== (string) $value ? (string) $value : $fallback;
				if ( ! is_scalar( $value ) || '' === (string) $value ) { continue; }
				if ( taxonomy_exists( $key ) ) { $term = get_term_by( 'slug', $value, $key ); if ( $term && ! is_wp_error( $term ) ) { $value = $term->name; } }
				if ( false === stripos( $title, (string) $value ) ) { $details[] = wc_attribute_label( $key ) . ': ' . wp_strip_all_tags( (string) $value ); }
			}
		}
		return $title . ( $details ? ' — ' . implode( ', ', $details ) : '' );
	}
	public static function masked_email( $email ) {
		$email = is_scalar( $email ) ? sanitize_email( (string) $email ) : '';
		if ( ! is_email( $email ) ) { return ''; }
		list( $local, $domain ) = explode( '@', $email, 2 );
		$suffix = substr( $domain, strrpos( $domain, '.' ) );
		return substr( $local, 0, min( 4, max( 0, strlen( $local ) - 2 ) ) ) . '**@*****' . $suffix;
	}
	public static function masked_phone( $phone ) {
		$digits = is_scalar( $phone ) ? preg_replace( '/\D/', '', (string) $phone ) : '';
		return $digits ? substr( $digits, 0, min( 3, max( 0, strlen( $digits ) - 2 ) ) ) . '*****' : '';
	}
	private static function pagination( $key, $page, $count, $anchor ) {
		if ( $count <= 10 ) { return; }
		$args = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		foreach ( array( 'affm_cp', 'affm_pp', 'affm_rp' ) as $other ) { if ( isset( $_GET[ $other ] ) ) { $args[ $other ] = AFFM_Security::page( $_GET, $other ); } }
		echo '<nav class="affm-pagination" aria-label="' . esc_attr__( 'History pages', 'affmint' ) . '">';
		foreach ( array( -1 => __( 'Previous', 'affmint' ), 1 => __( 'Next', 'affmint' ) ) as $step => $label ) {
			if ( -1 === $step && $page > 1 || 1 === $step && $page * 10 < $count ) {
				$args[ $key ] = $page + $step;
				echo '<a class="button affm-primary" href="' . esc_url( add_query_arg( $args, wc_get_account_endpoint_url( 'affmint' ) ) . '#' . $anchor ) . '">' . esc_html( $label ) . '</a>';
			}
		/* translators: %1$d: current page, %2$d: total pages. */
			if ( -1 === $step ) { echo '<span>' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affmint' ), esc_html( $page ), (int) ceil( $count / 10 ) ) . '</span>'; }
		}
		echo '</nav>';
	}
	private static function customers( $id ) {
		global $wpdb;
		$table = AFFM_Store::table( 'customers' );
		$count = AFFM_Store::referral_stats( $id )['customers'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = min( AFFM_Security::page( $_GET, 'affm_rp' ), max( 1, (int) ceil( $count / 10 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE affiliate_id=%d ORDER BY created_at DESC,customer_id DESC LIMIT 10 OFFSET %d', $table, $id, ( $page - 1 ) * 10 ) );
		if ( $ids ) { update_meta_cache( 'user', $ids ); }
		echo '<section class="affm-card affm-history" id="affm-customers"><h3>' . wp_kses( AFFM_UI::icon( 'affiliates' ), AFFM_UI::allowed_html() ) . esc_html__( 'Your registered customers', 'affmint' ) . '</h3><p class="affm-muted">' . esc_html__( 'Only first names and masked contact details are shown.', 'affmint' ) . '</p><div class="affm-table-scroll"><table><thead><tr><th>' . esc_html__( 'First name', 'affmint' ) . '</th><th>' . esc_html__( 'Email', 'affmint' ) . '</th><th>' . esc_html__( 'Phone', 'affmint' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $customer_id ) {
			$name = get_user_meta( $customer_id, 'first_name', true ) ?: get_user_meta( $customer_id, 'billing_first_name', true );
			$user = get_userdata( $customer_id );
			$email = self::masked_email( $user ? $user->user_email : '' );
			$phone = self::masked_phone( get_user_meta( $customer_id, 'billing_phone', true ) );
			echo '<tr><td>' . esc_html( $name ?: __( 'Customer', 'affmint' ) ) . '</td><td>' . esc_html( $email ?: '—' ) . '</td><td>' . esc_html( $phone ?: '—' ) . '</td></tr>';
		}
		if ( ! $ids ) { echo '<tr><td colspan="3" class="affm-empty">' . esc_html__( 'No registered customers yet.', 'affmint' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( 'affm_rp', esc_html( $page ), $count, 'affm-customers' ); echo '</section>';
	}
	private static function commission_items( $row, $id ) {
		$order = wc_get_order( $row->order_id );
		// Never expose items from a different affiliate's order, even if history is inconsistent.
		if ( ! $order || $order instanceof WC_Order_Refund || (int) $order->get_meta( '_affm_affiliate' ) !== (int) $id ) { return '<span class="affm-muted">' . esc_html__( 'Item details unavailable', 'affmint' ) . '</span>'; }
		$rates = $order->get_meta( '_affm_rates' );
		if ( ! is_array( $rates ) || ! $rates ) { return '<span class="affm-muted">' . esc_html__( 'Saved rates unavailable', 'affmint' ) . '</span>'; }
		$html = '<ul class="affm-commission-items">';
		$items = $order->get_items( 'line_item' );
		$positions = array_values( $items );
		foreach ( $rates as $item_id => $rate ) {
			if ( ! is_array( $rate ) ) { continue; }
			// Historical checkout snapshots may use keys assigned before the order is saved.
			// Only resolve items within this order; never load an arbitrary global item ID.
			$item = $items[ $item_id ] ?? false;
			if ( ! $item && preg_match( '/^new:line_items([0-9]+)$/', (string) $item_id, $match ) ) {
				$candidate = $positions[ (int) $match[1] ] ?? false;
				if ( $candidate instanceof WC_Order_Item_Product && isset( $rate['quantity'], $rate['total'] ) && is_numeric( $rate['quantity'] ) && is_numeric( $rate['total'] ) && abs( (float) $candidate->get_quantity() - (float) $rate['quantity'] ) < 0.000001 && abs( (float) $candidate->get_total() - (float) $rate['total'] ) < 0.000001 ) { $item = $candidate; }
			}
			$title = isset( $rate['title'] ) && is_scalar( $rate['title'] ) && '' !== trim( (string) $rate['title'] ) ? $rate['title'] : ( $item instanceof WC_Order_Item_Product ? self::item_title( $item ) : __( 'Item unavailable', 'affmint' ) );
			$type = $rate['type'] ?? ''; $value = isset( $rate['rate'] ) && is_numeric( $rate['rate'] ) ? max( 0, (float) $rate['rate'] ) : 0;
			$quantity = isset( $rate['quantity'] ) && is_numeric( $rate['quantity'] ) ? max( 0, (float) $rate['quantity'] ) : 0;
			if ( 'percent' === $type ) { $label = esc_html( wc_format_localized_decimal( wc_format_decimal( min( 100, $value ), 4, true ) ) . '%' ); }
			elseif ( 'fixed' === $type ) { $label = wp_kses_post( AFFM_Store::money( $value, $row->currency ) ) . ' ' . esc_html__( 'fixed / unit', 'affmint' ); }
			elseif ( 'disabled' === $type ) { $label = esc_html__( 'Excluded', 'affmint' ); }
			else { $label = esc_html__( 'Rate unavailable', 'affmint' ); }
		/* translators: %s: item quantity. */
			$html .= '<li><strong>' . esc_html( $title ) . '</strong><small>' . $label . ' · ' . sprintf( esc_html__( 'Qty: %s', 'affmint' ), esc_html( wc_format_localized_decimal( $quantity ) ) ) . '</small></li>';
		}
		$html .= '</ul>';
		$fx = $order->get_meta( '_affm_fx' );
		if ( is_array( $fx ) && ( $fx['from'] ?? '' ) !== ( $fx['to'] ?? '' ) && AFFM_Currency::valid_rate( $fx['rate'] ?? null ) ) {
		/* translators: %1$s: source currency, %2$s: exchange rate, %3$s: target currency. */
			$html .= '<small class="affm-note">' . esc_html( sprintf( __( 'Saved rate: 1 %1$s = %2$s %3$s', 'affmint' ), $fx['from'], rtrim( rtrim( sprintf( '%.12f', $fx['rate'] ), '0' ), '.' ), $fx['to'] ) ) . '</small>';
		}
		return $html;
	}
	private static function history( $type, $id ) {
		global $wpdb;
		$commission = 'commissions' === $type; $table = AFFM_Store::table( $type );
		$key = $commission ? 'affm_cp' : 'affm_pp';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE affiliate_id=%d', $table, $id ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, sanitized account/admin routing, search, pagination or feedback; no record mutation.
		$page = min( AFFM_Security::page( $_GET, $key ), max( 1, (int) ceil( $count / 10 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE affiliate_id=%d ORDER BY id DESC LIMIT 10 OFFSET %d', $table, $id, ( $page - 1 ) * 10 ) );
		echo '<section class="affm-card affm-history" id="affm-' . esc_attr( $type ) . '"><h3>' . wp_kses( AFFM_UI::icon( $type ), AFFM_UI::allowed_html() ) . esc_html( $commission ? __( 'Commission history', 'affmint' ) : __( 'Withdrawal history', 'affmint' ) ) . '</h3><div class="affm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Reference', 'affmint' ) . '</th><th>' . esc_html__( 'Date', 'affmint' ) . '</th>';
		if ( $commission ) { echo '<th>' . esc_html__( 'Items / commission rate', 'affmint' ) . '</th>'; }
		echo '<th>' . esc_html__( 'Amount', 'affmint' ) . '</th><th>' . esc_html__( 'Status', 'affmint' ) . '</th><th>' . esc_html( $commission ? __( 'Adjustment', 'affmint' ) : __( 'Method / note', 'affmint' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . esc_html( $commission ? $row->order_id : $row->id ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td>';
			if ( $commission ) { echo '<td>' . wp_kses_post( self::commission_items( $row, $id ) ) . '</td>'; }
			echo '<td>' . wp_kses_post( AFFM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AFFM_Store::status( $row->status ) ) . '</td><td>';
			if ( $commission ) {
				if ( 'repeat' === $row->eligibility ) { echo esc_html__( 'Repeat purchase excluded', 'affmint' ); }
				else { echo (float) $row->paid_amount > (float) $row->amount ? wp_kses_post( AFFM_Store::money( (float) $row->amount - (float) $row->paid_amount, $row->currency ) ) : '&mdash;'; }
			} else { echo esc_html( $row->method ); AFFM_Gateways::summary( $row->id ); if ( $row->note ) { echo '<small class="affm-note">' . esc_html( $row->note ) . '</small>'; } }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="' . ( $commission ? '6' : '5' ) . '" class="affm-empty">' . esc_html__( 'Nothing here yet. Your activity will appear here.', 'affmint' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $key, esc_html( $page ), $count, 'affm-' . $type ); echo '</section>';
	}
	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'AffMint', '<p>' . esc_html__( 'We count referral clicks in daily aggregate counters, without visitor IP addresses or browser fingerprints. A second signed cookie suppresses repeated consecutive clicks for 30 minutes. New customer account registrations may be linked to the referring affiliate while a valid referral cookie is present. Affiliates see the customer first name and masked email and phone details; full contact details, surnames and other account details are not shown. Registration attribution and affiliate counters are removed through WordPress personal-data erasure or when the associated account is deleted.', 'affmint' ) . '</p>' );
			wp_add_privacy_policy_content( 'AffMint', '<p>' . esc_html__( 'Our affiliate program uses a signed referral cookie to attribute purchases. The cookie stores an affiliate identifier and expiry time. Affiliate applications may include a website/profile URL and promotion plan when these fields are enabled for administrator review. We store affiliate enrollment, order-linked commissions, and withdrawal requests with payment destination details in our WordPress database. Authorized store managers can access these records to process payouts. Referral cookies expire after the configured duration. Financial records are retained until the store administrator removes them according to the store retention policy. If automatic payouts are configured, payment recipient identifiers, payout amounts and currencies are sent to PayPal or Stripe when a store manager sends an approved withdrawal. Connected Stripe account identifiers are stored for this purpose. Provider transaction records are retained for financial reconciliation.', 'affmint' ) . '</p>' );
		}
	}
	public static function exporter( $exporters ) {
		$exporters['affmint'] = array( 'exporter_friendly_name' => 'AffMint', 'callback' => array( __CLASS__, 'export_data' ) );
		return $exporters;
	}
	public static function export_data( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) { return array( 'data' => array(), 'done' => true ); }
		$data = array();
		$done = true;
		$page = max( 1, absint( $page ) );
		if ( 1 === $page && get_user_meta( $user->ID, '_affm_enrolled', true ) ) {
			$data[] = array( 'group_id' => 'affmint', 'group_label' => 'AffMint', 'item_id' => 'affm-profile-' . $user->ID, 'data' => array( array( 'name' => __( 'Enrollment', 'affmint' ), 'value' => get_user_meta( $user->ID, '_affm_enrolled', true ) ), array( 'name' => __( 'Referral token', 'affmint' ), 'value' => get_user_meta( $user->ID, '_affm_token', true ) ), array( 'name' => __( 'Blocked', 'affmint' ), 'value' => get_user_meta( $user->ID, '_affm_blocked', true ) ) ) );
		}
		if ( 1 === $page ) {
			foreach ( array( 'test', 'live' ) as $mode ) {
				$connection = get_user_meta( $user->ID, '_affm_stripe_' . $mode, true );
				if ( is_array( $connection ) && ! empty( $connection['id'] ) ) { $data[] = array( 'group_id' => 'affmint', 'group_label' => 'AffMint', 'item_id' => 'affm-stripe-' . $mode . '-' . $user->ID, 'data' => array( array( 'name' => 'Stripe ' . $mode . ' connected account', 'value' => $connection['id'] ) ) ); }
			}
		}
		$customers = AFFM_Store::table( 'customers' );
		$application = get_user_meta( $user->ID, '_affm_application', true );
		if ( 1 === $page && is_array( $application ) ) {
			$values = array(); foreach ( array( 'website', 'promotion', 'submitted_at' ) as $key ) { $values[] = array( 'name' => $key, 'value' => AFFM_Security::value( $application, $key ) ); }
			$data[] = array( 'group_id' => 'affmint', 'group_label' => 'AffMint', 'item_id' => 'affm-application-' . $user->ID, 'data' => $values );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$referrals = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d OR affiliate_id=%d ORDER BY customer_id ASC LIMIT 50 OFFSET %d', $customers, $user->ID, $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
		if ( 1 === $page && 'none' !== AFFM_Affiliates::status( $user->ID ) ) {
			$data[] = array( 'group_id' => 'affmint', 'group_label' => 'AffMint', 'item_id' => 'affm-approval-' . $user->ID, 'data' => array( array( 'name' => __( 'Affiliate approval status', 'affmint' ), 'value' => AFFM_Affiliates::status( $user->ID ) ) ) );
		}
		if ( count( $referrals ) === 50 ) { $done = false; }
		foreach ( $referrals as $referral ) {
			$values = array();
			foreach ( $referral as $key => $value ) { $values[] = array( 'name' => $key, 'value' => (string) $value ); }
			$data[] = array( 'group_id' => 'affm-customers', 'group_label' => __( 'Affiliate customer registration', 'affmint' ), 'item_id' => 'affm-customer-' . $referral['customer_id'], 'data' => $values );
		}
		$clicks = AFFM_Store::table( 'clicks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$days = $wpdb->get_results( $wpdb->prepare( 'SELECT click_date,clicks FROM %i WHERE affiliate_id=%d ORDER BY click_date ASC LIMIT 50 OFFSET %d', $clicks, $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
		if ( count( $days ) === 50 ) { $done = false; }
		foreach ( $days as $day ) {
			$data[] = array( 'group_id' => 'affm-clicks', 'group_label' => __( 'Affiliate click counters', 'affmint' ), 'item_id' => 'affm-clicks-' . $day['click_date'], 'data' => array( array( 'name' => 'click_date', 'value' => $day['click_date'] ), array( 'name' => 'clicks', 'value' => (string) $day['clicks'] ) ) );
		}
		foreach ( array( 'commissions', 'payouts' ) as $type ) {
			$table = AFFM_Store::table( $type );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE affiliate_id=%d ORDER BY id ASC LIMIT 50 OFFSET %d', $table, $user->ID, ( $page - 1 ) * 50 ), ARRAY_A );
			if ( count( $rows ) === 50 ) { $done = false; }
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $key => $value ) { $values[] = array( 'name' => $key, 'value' => (string) $value ); }
				$data[] = array( 'group_id' => 'affm-' . $type, 'group_label' => 'commissions' === $type ? __( 'Affiliate commissions', 'affmint' ) : __( 'Affiliate withdrawals', 'affmint' ), 'item_id' => 'affm-' . $type . '-' . $row['id'], 'data' => $values );
			}
		}
		return array( 'data' => $data, 'done' => $done );
	}
	public static function eraser( $erasers ) {
		$erasers['affmint'] = array( 'eraser_friendly_name' => 'AffMint', 'callback' => array( __CLASS__, 'erase_data' ) );
		return $erasers;
	}
	public static function erase_data( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( ! $user ) { return $result; }
		foreach ( array( '_affm_token', '_affm_enrolled', '_affm_blocked', '_affm_approval', '_affm_application', '_affm_stripe_test', '_affm_stripe_live', '_affm_stripe_oauth', '_affm_stripe_onboarding', '_affm_stripe_create_test', '_affm_stripe_create_live', '_affm_stripe_country_test', '_affm_stripe_country_live' ) as $key ) { $result['items_removed'] = delete_user_meta( $user->ID, $key ) || $result['items_removed']; }
		$customers = AFFM_Store::table( 'customers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE customer_id=%d OR affiliate_id=%d', $customers, $user->ID, $user->ID ) );
		$result['items_removed'] = $removed > 0 || $result['items_removed'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
		$result['items_removed'] = $wpdb->delete( AFFM_Store::table( 'clicks' ), array( 'affiliate_id' => $user->ID ) ) > 0 || $result['items_removed'];
		foreach ( array( 'commissions', 'payouts' ) as $type ) {
			$table = AFFM_Store::table( $type );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom affiliate tables have no WordPress CRUD API; read current totals, history and privacy records without stale cross-request caches.
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE affiliate_id=%d LIMIT 1', $table, $user->ID ) ) ) { $result['items_retained'] = true; }
		}
		if ( $result['items_retained'] ) { $result['messages'][] = __( 'AffMint retained commission and withdrawal records, including payment destinations, for financial reconciliation. The store administrator must review their retention policy before removing these records.', 'affmint' ); }
		return $result;
	}
}
