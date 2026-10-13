<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Account {
	public static function endpoint() {
		global $wp_rewrite;
		foreach ( array_unique( array( 'affiliate-wpm', AWPM_Settings::account_slug() ) ) as $slug ) {
			if ( ! in_array( $slug, array_column( $wp_rewrite->endpoints, 1 ), true ) ) { add_rewrite_endpoint( $slug, EP_ROOT | EP_PAGES ); }
		}
	}
	public static function settings_changed( $old, $new ) {
		$previous = AWPM_Settings::url_name( AWPM_Security::value( (array) $old, 'account_slug', 'affiliate-wpm' ), 'account_slug' );
		$current = AWPM_Settings::url_name( AWPM_Security::value( (array) $new, 'account_slug', 'affiliate-wpm' ), 'account_slug' );
		if ( $previous === $current ) { return; }
		global $wp_rewrite;
		if ( 'affiliate-wpm' !== $previous ) { $wp_rewrite->endpoints = array_values( array_filter( $wp_rewrite->endpoints, function ( $endpoint ) use ( $previous ) { return $endpoint[1] !== $previous; } ) ); }
		self::endpoint();
		flush_rewrite_rules( false );
	}
	/** Normalize the default URL alias so WooCommerce conditionals also recognize it. */
	public static function parse_request( $request ) {
		$slug = AWPM_Settings::account_slug();
		if ( 'affiliate-wpm' !== $slug && isset( $request->query_vars['affiliate-wpm'] ) && ! isset( $request->query_vars[ $slug ] ) ) { $request->query_vars[ $slug ] = $request->query_vars['affiliate-wpm']; }
	}
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_affiliate-wpm_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_affiliate-wpm_title', function () { return __( 'Affiliate Area', 'affiliate-wpm' ); } );
		add_action( 'template_redirect', array( __CLASS__, 'submit' ) );
		add_action( 'wp_enqueue_scripts', function () {
			if ( is_account_page() && is_wc_endpoint_url( 'affiliate-wpm' ) ) {
				wp_enqueue_style( 'awpm-account', AWPM_URL . 'assets/affiliate-wpm.css', array(), (string) filemtime( AWPM_PATH . 'assets/affiliate-wpm.css' ) );
				wp_enqueue_style( 'awpm-panel', AWPM_URL . 'assets/panel.css', array( 'awpm-account' ), (string) filemtime( AWPM_PATH . 'assets/panel.css' ) );
				AWPM_Design::enqueue( 'awpm-panel' );
				wp_enqueue_script( 'awpm-account', AWPM_URL . 'assets/account.js', array(), (string) filemtime( AWPM_PATH . 'assets/account.js' ), true );
			}
		} );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}
	public static function query_vars( $vars ) {
		// WooCommerce conditionals, endpoint titles and URLs use its own registry.
		$vars['affiliate-wpm'] = AWPM_Settings::account_slug();
		return $vars;
	}
	public static function menu( $items ) {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['affiliate-wpm'] = __( 'Affiliate Area', 'affiliate-wpm' );
		if ( $logout ) { $items['customer-logout'] = $logout; }
		return $items;
	}
	public static function submit() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return; }
		if ( ! isset( $_POST['awpm_action'] ) || ! is_scalar( $_POST['awpm_action'] ) || ! is_account_page() || ! is_wc_endpoint_url( 'affiliate-wpm' ) ) { return; }
		if ( ! is_user_logged_in() ) { return; }
		$nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'awpm_account' ) ) { wc_add_notice( __( 'Your session expired. Please try again.', 'affiliate-wpm' ), 'error' ); }
		else {
			$id = get_current_user_id();
			$action = sanitize_key( wp_unslash( $_POST['awpm_action'] ) );
			if ( 'join' === $action ) {
				$result = AWPM_Affiliates::join( $id, 'yes' === AWPM_Security::value( $_POST, 'awpm_accept_terms' ), wp_unslash( AWPM_Security::value( $_POST, 'awpm_terms_version' ) ), wp_unslash( AWPM_Security::rows( $_POST, 'awpm_join' ) ) );
				$messages = array( 'approved' => __( 'Your affiliate account is ready. Start sharing your referral link!', 'affiliate-wpm' ), 'pending' => __( 'Your affiliate application is awaiting administrator approval.', 'affiliate-wpm' ), 'rejected' => __( 'Your affiliate application was rejected. Please contact the store.', 'affiliate-wpm' ) );
				wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : ( $messages[ $result ] ?? '' ), is_wp_error( $result ) || 'rejected' === $result ? 'error' : 'success' );
			} elseif ( 'stripe_connect' === $action ) {
				$result = AWPM_Gateways::connect( sanitize_text_field( wp_unslash( AWPM_Security::value( $_POST, 'stripe_country' ) ) ) ); if ( is_wp_error( $result ) ) { wc_add_notice( $result->get_error_message(), 'error' ); }
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
		$id = get_current_user_id(); if ( ! $id ) { return; }
		AWPM_Cache::no_cache( 'Affiliate WPM customer dashboard' );
		$user = wp_get_current_user(); $status = AWPM_Affiliates::status( $id );
		$active = AWPM_Sharing::allowed( $id ); $currency = AWPM_Settings::get( 'currency' );
		$name = $user->first_name ?: $user->display_name;
		echo '<div class="awpm awpm-account"><header class="awpm-account-hero"><div><span class="awpm-eyebrow">' . esc_html__( 'YOUR PARTNER SPACE', 'affiliate-wpm' ) . '</span><h2>' . esc_html__( 'Affiliate Area', 'affiliate-wpm' ) . '</h2><p>' . sprintf( esc_html__( 'Hello %s. Share your favorites and follow your earnings.', 'affiliate-wpm' ), esc_html( $name ) ) . '</p></div><div class="awpm-hero-side">';
		if ( 'none' !== $status ) { echo wp_kses_post( AWPM_Store::status( $active ? 'approved' : ( 'approved' === $status ? 'paused' : $status ) ) ); }
		else { echo '<span class="awpm-hero-mark">' . AWPM_UI::icon( 'sharing' ) . '</span>'; }
		echo '</div></header>';
		if ( 'none' === $status ) {
			echo '<section class="awpm-card awpm-onboarding"><span class="awpm-icon-tile">' . AWPM_UI::icon( 'link' ) . '</span><h3>' . esc_html( AWPM_Settings::get( 'join_title' ) ) . '</h3>' . ( '' !== trim( AWPM_Settings::get( 'join_description' ) ) ? '<p>' . nl2br( esc_html( AWPM_Settings::get( 'join_description' ) ) ) . '</p>' : '' ) . '<ol class="awpm-onboarding-steps"><li><strong>' . esc_html__( 'Join the program', 'affiliate-wpm' ) . '</strong><span>' . esc_html__( 'Create your affiliate account from here.', 'affiliate-wpm' ) . '</span></li><li><strong>' . esc_html__( 'Share your link', 'affiliate-wpm' ) . '</strong><span>' . esc_html__( 'Recommend a product or a page you love.', 'affiliate-wpm' ) . '</span></li><li><strong>' . esc_html__( 'Earn & withdraw', 'affiliate-wpm' ) . '</strong><span>' . esc_html__( 'Completed orders release eligible earnings.', 'affiliate-wpm' ) . '</span></li></ol>';
			if ( 'yes' === AWPM_Settings::get( 'join_show_rate' ) ) {
				$rate = 'percent' === AWPM_Settings::get( 'commission_type' ) ? esc_html( wc_format_decimal( AWPM_Settings::get( 'commission_rate' ), 6, true ) . '%' ) : wp_kses_post( AWPM_Store::money( AWPM_Settings::get( 'commission_rate' ), $currency ) ) . ' ' . esc_html__( 'per purchased unit', 'affiliate-wpm' );
				echo '<div class="awpm-join-commission"><strong>' . esc_html__( 'Default commission:', 'affiliate-wpm' ) . ' ' . $rate . '</strong><p class="awpm-muted">' . esc_html__( 'Individual products may have different commission rates. Commissions apply to eligible purchases.', 'affiliate-wpm' ) . '</p></div>';
			}
			if ( AWPM_Settings::program_enabled() && 'yes' !== get_user_meta( $id, '_awpm_blocked', true ) ) {
				echo '<form method="post">'; wp_nonce_field( 'awpm_account' );
				if ( 'yes' === AWPM_Settings::get( 'join_url_enabled' ) ) { echo '<div class="awpm-field"><label for="awpm-join-website">' . esc_html__( 'Website / Promote URL', 'affiliate-wpm' ) . '</label><input type="url" id="awpm-join-website" name="awpm_join[website]" maxlength="2048" placeholder="https://example.com/your-profile" autocomplete="url" required><p class="awpm-muted">' . esc_html__( 'Your website, blog, channel or social profile.', 'affiliate-wpm' ) . '</p></div>'; }
				if ( 'yes' === AWPM_Settings::get( 'join_promotion_enabled' ) ) { echo '<div class="awpm-field"><label for="awpm-join-promotion">' . esc_html__( 'How will you promote us?', 'affiliate-wpm' ) . '</label><textarea id="awpm-join-promotion" name="awpm_join[promotion]" maxlength="3000" rows="4" style="width:100%" required></textarea><p class="awpm-muted">' . esc_html__( 'Tell us about your audience and how you plan to share our products.', 'affiliate-wpm' ) . '</p></div>'; }
				$terms = AWPM_Settings::terms();
				if ( '' !== $terms ) {
					echo '<section class="awpm-join-terms" aria-labelledby="awpm-terms-title"><h3 id="awpm-terms-title">' . esc_html__( 'Terms & Conditions', 'affiliate-wpm' ) . '</h3><div class="awpm-terms-content" tabindex="0" role="region" aria-labelledby="awpm-terms-title">' . wp_kses_post( wpautop( $terms ) ) . '</div><label class="awpm-terms-accept"><input type="checkbox" name="awpm_accept_terms" value="yes" required><span>' . esc_html__( 'I have read and agree to the affiliate Terms & Conditions.', 'affiliate-wpm' ) . '</span></label><input type="hidden" name="awpm_terms_version" value="' . esc_attr( AWPM_Settings::terms_version() ) . '"></section>';
				}
				echo '<input type="hidden" name="awpm_action" value="join"><button class="button awpm-primary">' . esc_html( AWPM_Settings::get( 'join_button_text' ) ) . AWPM_UI::icon( 'arrow' ) . '</button></form><p class="awpm-muted">' . esc_html( 'yes' === AWPM_Settings::get( 'approval_required' ) ? __( 'Your application will be reviewed by the store.', 'affiliate-wpm' ) : __( 'Join now to get your personal referral link.', 'affiliate-wpm' ) ) . '</p>';
			} else { echo '<p class="awpm-notice">' . esc_html__( 'Enrollment is currently unavailable.', 'affiliate-wpm' ) . '</p>'; }
			echo '</section></div>'; return;
		}
		if ( in_array( $status, array( 'pending', 'rejected' ), true ) ) {
			echo '<section class="awpm-card awpm-application"><span class="awpm-icon-tile">' . AWPM_UI::icon( 'clock' ) . '</span><h3>' . esc_html__( 'Affiliate application', 'affiliate-wpm' ) . '</h3><p>' . esc_html( 'pending' === $status ? __( 'Your application is awaiting administrator approval. Your links and withdrawal tools will be ready once you are approved.', 'affiliate-wpm' ) : __( 'Your application was rejected. Please contact the store if you have questions.', 'affiliate-wpm' ) ) . '</p></section></div>'; return;
		}
		echo '<nav class="awpm-account-nav" aria-label="' . esc_attr__( 'Affiliate sections', 'affiliate-wpm' ) . '">';
		foreach ( array( 'earnings' => __( 'Overview', 'affiliate-wpm' ), 'sharing' => __( 'Sharing tools', 'affiliate-wpm' ), 'withdraw' => __( 'Withdraw', 'affiliate-wpm' ), 'commissions' => __( 'History', 'affiliate-wpm' ), 'customers' => __( 'Customers', 'affiliate-wpm' ) ) as $key => $label ) { echo '<a href="#awpm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		if ( ! $active ) { echo '<p class="awpm-notice">' . esc_html__( 'The affiliate program or your account is paused. Your history is still available.', 'affiliate-wpm' ) . '</p>'; }
		$commissions = AWPM_Store::table( 'commissions' ); $payouts = AWPM_Store::table( 'payouts' );
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $commissions WHERE affiliate_id=%d AND currency=%s AND status='pending'", $id, $currency ) );
		$reserved = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $payouts WHERE affiliate_id=%d AND currency=%s AND status IN ('pending','approved','processing')", $id, $currency ) );
		$paid = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(paid_amount),0) FROM $commissions WHERE affiliate_id=%d AND currency=%s", $id, $currency ) );
		$balance = AWPM_Store::balance( $id, $currency );
		echo '<div class="awpm-metrics" id="awpm-earnings">';
		echo AWPM_UI::metric( __( 'Available balance', 'affiliate-wpm' ), AWPM_Store::money( $balance, $currency ), 'wallet', __( 'Ready for withdrawal', 'affiliate-wpm' ) );
		echo AWPM_UI::metric( __( 'Pending earnings', 'affiliate-wpm' ), AWPM_Store::money( $pending, $currency ), 'clock', __( 'Awaiting order completion or the waiting period', 'affiliate-wpm' ) );
		echo AWPM_UI::metric( __( 'In withdrawal', 'affiliate-wpm' ), AWPM_Store::money( $reserved, $currency ), 'send', __( 'Requests in progress', 'affiliate-wpm' ) );
		echo AWPM_UI::metric( __( 'Total paid out', 'affiliate-wpm' ), AWPM_Store::money( $paid, $currency ), 'award', __( 'Completed payouts', 'affiliate-wpm' ) );
		$stats = AWPM_Store::referral_stats( $id );
		echo '</div><div class="awpm-referral-metrics">' . AWPM_UI::metric( __( 'Referral clicks', 'affiliate-wpm' ), number_format_i18n( $stats['clicks'] ), 'click' ) . AWPM_UI::metric( __( 'Registered customers', 'affiliate-wpm' ), number_format_i18n( $stats['customers'] ), 'user' ) . '</div><details class="awpm-stat-help"><summary>' . esc_html__( 'How your activity is counted', 'affiliate-wpm' ) . '</summary><p>' . esc_html__( 'Consecutive clicks on the same referral count once per browser within 30 minutes. Registered customers are new accounts created through your referral cookie. These statistics start from when tracking was enabled.', 'affiliate-wpm' ) . '</p></details><div class="awpm-grid awpm-account-tools"><section class="awpm-card awpm-link-card" id="awpm-sharing"><div class="awpm-card-heading"><span class="awpm-icon-tile">' . AWPM_UI::icon( 'link' ) . '</span><div><h3>' . esc_html__( 'Your referral link', 'affiliate-wpm' ) . '</h3><p>' . esc_html__( 'One link to share your favorite store.', 'affiliate-wpm' ) . '</p></div></div>';
		if ( $active ) {
			$link = add_query_arg( AWPM_Settings::referral_parameter(), AWPM_Tracking::token( $id ), home_url( '/' ) );
			echo '<label for="awpm-ref-link" class="screen-reader-text">' . esc_html__( 'Referral link', 'affiliate-wpm' ) . '</label><div class="awpm-copy"><input id="awpm-ref-link" readonly value="' . esc_attr( $link ) . '"><button type="button" class="button awpm-primary" data-awpm-copy data-copied="' . esc_attr__( 'Copied!', 'affiliate-wpm' ) . '">' . esc_html__( 'Copy', 'affiliate-wpm' ) . '</button></div><p class="awpm-muted awpm-link-hint">' . sprintf( esc_html__( 'Referral cookie: %d days. Self-referrals do not earn commission.', 'affiliate-wpm' ), (int) AWPM_Settings::get( 'cookie_days' ) ) . '</p><p class="awpm-copy-feedback" role="status" aria-live="polite"></p>';
			AWPM_Sharing::generator();
			self::best_sellers( $id );
		} else { echo '<p class="awpm-muted">' . esc_html__( 'Your sharing tools will return when your affiliate account and the program are active.', 'affiliate-wpm' ) . '</p>'; }
		echo '</section><section class="awpm-card awpm-withdraw-card" id="awpm-withdraw"><div class="awpm-card-heading"><span class="awpm-icon-tile">' . AWPM_UI::icon( 'payouts' ) . '</span><div><h3>' . esc_html__( 'Request a withdrawal', 'affiliate-wpm' ) . '</h3><p>' . esc_html__( 'Choose how you receive your earnings.', 'affiliate-wpm' ) . '</p></div></div>';
		$minimum = max( 0.01, (float) AWPM_Settings::get( 'minimum' ) );
		echo '<div class="awpm-withdraw-balance"><span>' . esc_html__( 'Available to withdraw', 'affiliate-wpm' ) . '</span><strong>' . wp_kses_post( AWPM_Store::money( $balance, $currency ) ) . '</strong><p class="awpm-muted">' . esc_html__( 'Minimum withdrawal:', 'affiliate-wpm' ) . ' ' . wp_kses_post( AWPM_Store::money( $minimum, $currency ) ) . '</p>';
		if ( $balance < $minimum ) { echo '<progress max="' . esc_attr( $minimum ) . '" value="' . esc_attr( max( 0, $balance ) ) . '" aria-label="' . esc_attr__( 'Progress toward minimum withdrawal', 'affiliate-wpm' ) . '"></progress><p class="awpm-muted">' . sprintf( esc_html__( '%s more to reach your minimum.', 'affiliate-wpm' ), wp_kses_post( AWPM_Store::money( $minimum - $balance, $currency ) ) ) . '</p>'; }
		echo '</div>';
		AWPM_Gateways::connection_ui( $id );
		$methods = AWPM_Settings::methods(); $open = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $payouts WHERE affiliate_id=%d AND status IN ('pending','approved','processing') LIMIT 1", $id ) );
		if ( $active && $methods && ! $open ) {
			echo '<form method="post" class="awpm-withdraw">'; wp_nonce_field( 'awpm_account' );
			echo '<input type="hidden" name="awpm_action" value="withdraw"><label for="awpm-method">' . esc_html__( 'Payout method', 'affiliate-wpm' ) . '</label><select id="awpm-method" name="method">';
			foreach ( $methods as $method ) { echo '<option value="' . esc_attr( $method['id'] ) . '" data-provider="' . esc_attr( $method['provider'] ?? 'manual' ) . '" data-instructions="' . esc_attr( $method['instructions'] ) . '">' . esc_html( $method['name'] ) . '</option>'; }
			echo '</select><p id="awpm-instructions" class="awpm-muted">' . esc_html( $methods[0]['instructions'] ) . '</p><label for="awpm-details">' . esc_html__( 'Payment destination details', 'affiliate-wpm' ) . '</label><textarea id="awpm-details" name="details" rows="3" required minlength="3" maxlength="2000"></textarea><p class="awpm-muted">' . esc_html__( 'Enter only the details needed to receive payment. Never share passwords or card security codes.', 'affiliate-wpm' ) . '</p><button class="button awpm-primary"' . disabled( $balance < $minimum, true, false ) . '>' . esc_html__( 'Withdraw available balance', 'affiliate-wpm' ) . AWPM_UI::icon( 'arrow' ) . '</button></form>';
		} else { echo '<p class="awpm-notice">' . esc_html( $open ? __( 'Your withdrawal is being reviewed. Follow its status in the history below.', 'affiliate-wpm' ) : __( 'Withdrawals are currently unavailable.', 'affiliate-wpm' ) ) . '</p>'; }
		echo '</section></div><p class="awpm-muted awpm-balance-note">' . esc_html__( 'Refunds adjust your earnings. Refunds after a payout reduce future earnings. History keeps each transaction in its original currency.', 'affiliate-wpm' ) . '</p>';
		self::history( 'commissions', $id ); self::history( 'payouts', $id ); self::customers( $id ); echo '</div>';
	}
	private static function best_sellers( $id ) {
		if ( get_current_user_id() !== (int) $id || ! AWPM_Sharing::allowed( $id ) ) { return; }
		// Use WooCommerce's stored lifetime unit sales; no order scan or customer data.
		$query = new WP_Query( array(
			'post_type' => 'product', 'post_status' => 'publish', 'post_password' => '',
			'posts_per_page' => 5, 'no_found_rows' => true, 'ignore_sticky_posts' => true,
			'meta_key' => 'total_sales', 'orderby' => array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' ),
			'meta_query' => array( 'relation' => 'AND',
				array( 'key' => 'total_sales', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
				array( 'relation' => 'OR', array( 'key' => '_awpm_type', 'compare' => 'NOT EXISTS' ), array( 'key' => '_awpm_type', 'value' => 'disabled', 'compare' => '!=' ) ),
			),
			'tax_query' => array( 'relation' => 'AND',
				array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => array( 'simple', 'variable' ) ),
				array( 'taxonomy' => 'product_visibility', 'field' => 'slug', 'terms' => array( 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
			),
		) );
		echo '<section class="awpm-best-sellers"><h3>' . esc_html__( 'Top selling products', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Popular products across the store, ranked by lifetime sales. Copy a link to share.', 'affiliate-wpm' ) . '</p><ol class="awpm-best-seller-list">';
		$shown = 0;
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product || ! $product->is_visible() ) { continue; }
			$url = $product->get_permalink(); $link = AWPM_Sharing::link( $id, $url );
			if ( is_wp_error( $link ) ) { continue; }
			$shown++;
			echo '<li><span class="awpm-best-seller-rank" aria-hidden="true">' . esc_html( $shown ) . '</span><a class="awpm-best-seller-product" href="' . esc_url( $url ) . '">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ) ) . '<span>' . esc_html( $product->get_name() ) . '</span></a><button type="button" class="button awpm-primary" data-awpm-copy data-awpm-url="' . esc_attr( $link ) . '" data-copied="' . esc_attr__( 'Copied!', 'affiliate-wpm' ) . '" data-copy-failed="' . esc_attr__( 'Could not copy. Please try again.', 'affiliate-wpm' ) . '" aria-label="' . esc_attr( sprintf( __( 'Copy affiliate link for %s', 'affiliate-wpm' ), $product->get_name() ) ) . '">' . esc_html__( 'Copy link', 'affiliate-wpm' ) . '</button></li>';
		}
		echo '</ol>';
		if ( ! $shown ) { echo '<p class="awpm-muted">' . esc_html__( 'Top-selling products will appear here when eligible products have sales.', 'affiliate-wpm' ) . '</p>'; }
		echo '<p class="awpm-copy-feedback" role="status" aria-live="polite"></p></section>';
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
		foreach ( array( 'awpm_cp', 'awpm_pp', 'awpm_rp' ) as $other ) { if ( isset( $_GET[ $other ] ) ) { $args[ $other ] = AWPM_Security::page( $_GET, $other ); } }
		echo '<nav class="awpm-pagination" aria-label="' . esc_attr__( 'History pages', 'affiliate-wpm' ) . '">';
		foreach ( array( -1 => __( 'Previous', 'affiliate-wpm' ), 1 => __( 'Next', 'affiliate-wpm' ) ) as $step => $label ) {
			if ( -1 === $step && $page > 1 || 1 === $step && $page * 10 < $count ) {
				$args[ $key ] = $page + $step;
				echo '<a class="button awpm-primary" href="' . esc_url( add_query_arg( $args, wc_get_account_endpoint_url( 'affiliate-wpm' ) ) . '#' . $anchor ) . '">' . esc_html( $label ) . '</a>';
			}
			if ( -1 === $step ) { echo '<span>' . sprintf( esc_html__( 'Page %1$d of %2$d', 'affiliate-wpm' ), $page, (int) ceil( $count / 10 ) ) . '</span>'; }
		}
		echo '</nav>';
	}
	private static function customers( $id ) {
		global $wpdb;
		$table = AWPM_Store::table( 'customers' );
		$count = AWPM_Store::referral_stats( $id )['customers'];
		$page = min( AWPM_Security::page( $_GET, 'awpm_rp' ), max( 1, (int) ceil( $count / 10 ) ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM $table WHERE affiliate_id=%d ORDER BY created_at DESC,customer_id DESC LIMIT 10 OFFSET %d", $id, ( $page - 1 ) * 10 ) );
		if ( $ids ) { update_meta_cache( 'user', $ids ); }
		echo '<section class="awpm-card awpm-history" id="awpm-customers"><h3>' . AWPM_UI::icon( 'affiliates' ) . esc_html__( 'Your registered customers', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Only first names and masked contact details are shown.', 'affiliate-wpm' ) . '</p><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'First name', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Email', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Phone', 'affiliate-wpm' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $customer_id ) {
			$name = get_user_meta( $customer_id, 'first_name', true ) ?: get_user_meta( $customer_id, 'billing_first_name', true );
			$user = get_userdata( $customer_id );
			$email = self::masked_email( $user ? $user->user_email : '' );
			$phone = self::masked_phone( get_user_meta( $customer_id, 'billing_phone', true ) );
			echo '<tr><td>' . esc_html( $name ?: __( 'Customer', 'affiliate-wpm' ) ) . '</td><td>' . esc_html( $email ?: '—' ) . '</td><td>' . esc_html( $phone ?: '—' ) . '</td></tr>';
		}
		if ( ! $ids ) { echo '<tr><td colspan="3" class="awpm-empty">' . esc_html__( 'No registered customers yet.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( 'awpm_rp', $page, $count, 'awpm-customers' ); echo '</section>';
	}
	private static function commission_items( $row, $id ) {
		$order = wc_get_order( $row->order_id );
		// Never expose items from a different affiliate's order, even if history is inconsistent.
		if ( ! $order || $order instanceof WC_Order_Refund || (int) $order->get_meta( '_awpm_affiliate' ) !== (int) $id ) { return '<span class="awpm-muted">' . esc_html__( 'Item details unavailable', 'affiliate-wpm' ) . '</span>'; }
		$rates = $order->get_meta( '_awpm_rates' );
		if ( ! is_array( $rates ) || ! $rates ) { return '<span class="awpm-muted">' . esc_html__( 'Saved rates unavailable', 'affiliate-wpm' ) . '</span>'; }
		$html = '<ul class="awpm-commission-items">';
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
			$title = isset( $rate['title'] ) && is_scalar( $rate['title'] ) && '' !== trim( (string) $rate['title'] ) ? $rate['title'] : ( $item instanceof WC_Order_Item_Product ? self::item_title( $item ) : __( 'Item unavailable', 'affiliate-wpm' ) );
			$type = $rate['type'] ?? ''; $value = isset( $rate['rate'] ) && is_numeric( $rate['rate'] ) ? max( 0, (float) $rate['rate'] ) : 0;
			$quantity = isset( $rate['quantity'] ) && is_numeric( $rate['quantity'] ) ? max( 0, (float) $rate['quantity'] ) : 0;
			if ( 'percent' === $type ) { $label = esc_html( wc_format_localized_decimal( wc_format_decimal( min( 100, $value ), 4, true ) ) . '%' ); }
			elseif ( 'fixed' === $type ) { $label = wp_kses_post( AWPM_Store::money( $value, $row->currency ) ) . ' ' . esc_html__( 'fixed / unit', 'affiliate-wpm' ); }
			elseif ( 'disabled' === $type ) { $label = esc_html__( 'Excluded', 'affiliate-wpm' ); }
			else { $label = esc_html__( 'Rate unavailable', 'affiliate-wpm' ); }
			$html .= '<li><strong>' . esc_html( $title ) . '</strong><small>' . $label . ' · ' . sprintf( esc_html__( 'Qty: %s', 'affiliate-wpm' ), esc_html( wc_format_localized_decimal( $quantity ) ) ) . '</small></li>';
		}
		$html .= '</ul>';
		$fx = $order->get_meta( '_awpm_fx' );
		if ( is_array( $fx ) && ( $fx['from'] ?? '' ) !== ( $fx['to'] ?? '' ) && AWPM_Currency::valid_rate( $fx['rate'] ?? null ) ) {
			$html .= '<small class="awpm-note">' . esc_html( sprintf( __( 'Saved rate: 1 %1$s = %2$s %3$s', 'affiliate-wpm' ), $fx['from'], rtrim( rtrim( sprintf( '%.12f', $fx['rate'] ), '0' ), '.' ), $fx['to'] ) ) . '</small>';
		}
		return $html;
	}
	private static function history( $type, $id ) {
		global $wpdb;
		$commission = 'commissions' === $type; $table = AWPM_Store::table( $type );
		$key = $commission ? 'awpm_cp' : 'awpm_pp';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE affiliate_id=%d", $id ) );
		$page = min( AWPM_Security::page( $_GET, $key ), max( 1, (int) ceil( $count / 10 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE affiliate_id=%d ORDER BY id DESC LIMIT 10 OFFSET %d", $id, ( $page - 1 ) * 10 ) );
		echo '<section class="awpm-card awpm-history" id="awpm-' . esc_attr( $type ) . '"><h3>' . AWPM_UI::icon( $type ) . esc_html( $commission ? __( 'Commission history', 'affiliate-wpm' ) : __( 'Withdrawal history', 'affiliate-wpm' ) ) . '</h3><div class="awpm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Reference', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Date', 'affiliate-wpm' ) . '</th>';
		if ( $commission ) { echo '<th>' . esc_html__( 'Items / commission rate', 'affiliate-wpm' ) . '</th>'; }
		echo '<th>' . esc_html__( 'Amount', 'affiliate-wpm' ) . '</th><th>' . esc_html__( 'Status', 'affiliate-wpm' ) . '</th><th>' . esc_html( $commission ? __( 'Adjustment', 'affiliate-wpm' ) : __( 'Method / note', 'affiliate-wpm' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . esc_html( $commission ? $row->order_id : $row->id ) . '</td><td>' . esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) ) ) . '</td>';
			if ( $commission ) { echo '<td>' . self::commission_items( $row, $id ) . '</td>'; }
			echo '<td>' . wp_kses_post( AWPM_Store::money( $row->amount, $row->currency ) ) . '</td><td>' . wp_kses_post( AWPM_Store::status( $row->status ) ) . '</td><td>';
			if ( $commission ) {
				if ( 'repeat' === $row->eligibility ) { echo esc_html__( 'Repeat purchase excluded', 'affiliate-wpm' ); }
				else { echo (float) $row->paid_amount > (float) $row->amount ? wp_kses_post( AWPM_Store::money( (float) $row->amount - (float) $row->paid_amount, $row->currency ) ) : '&mdash;'; }
			} else { echo esc_html( $row->method ); AWPM_Gateways::summary( $row->id ); if ( $row->note ) { echo '<small class="awpm-note">' . esc_html( $row->note ) . '</small>'; } }
			echo '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="' . ( $commission ? '6' : '5' ) . '" class="awpm-empty">' . esc_html__( 'Nothing here yet. Your activity will appear here.', 'affiliate-wpm' ) . '</td></tr>'; }
		echo '</tbody></table></div>'; self::pagination( $key, $page, $count, 'awpm-' . $type ); echo '</section>';
	}
	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Affiliate WPM', '<p>' . esc_html__( 'We count referral clicks in daily aggregate counters, without visitor IP addresses or browser fingerprints. A second signed cookie suppresses repeated consecutive clicks for 30 minutes. New customer account registrations may be linked to the referring affiliate while a valid referral cookie is present. Affiliates see the customer first name and masked email and phone details; full contact details, surnames and other account details are not shown. Registration attribution and affiliate counters are removed through WordPress personal-data erasure or when the associated account is deleted.', 'affiliate-wpm' ) . '</p>' );
			wp_add_privacy_policy_content( 'Affiliate WPM', '<p>' . esc_html__( 'Our affiliate program uses a signed referral cookie to attribute purchases. The cookie stores an affiliate identifier and expiry time. Affiliate applications may include a website/profile URL and promotion plan when these fields are enabled for administrator review. We store affiliate enrollment, order-linked commissions, and withdrawal requests with payment destination details in our WordPress database. Authorized store managers can access these records to process payouts. Referral cookies expire after the configured duration. Financial records are retained until the store administrator removes them according to the store retention policy. If automatic payouts are configured, payment recipient identifiers, payout amounts and currencies are sent to PayPal or Stripe when a store manager sends an approved withdrawal. Connected Stripe account identifiers are stored for this purpose. Provider transaction records are retained for financial reconciliation.', 'affiliate-wpm' ) . '</p>' );
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
		if ( 1 === $page ) {
			foreach ( array( 'test', 'live' ) as $mode ) {
				$connection = get_user_meta( $user->ID, '_awpm_stripe_' . $mode, true );
				if ( is_array( $connection ) && ! empty( $connection['id'] ) ) { $data[] = array( 'group_id' => 'affiliate-wpm', 'group_label' => 'Affiliate WPM', 'item_id' => 'awpm-stripe-' . $mode . '-' . $user->ID, 'data' => array( array( 'name' => 'Stripe ' . $mode . ' connected account', 'value' => $connection['id'] ) ) ); }
			}
		}
		$customers = AWPM_Store::table( 'customers' );
		$application = get_user_meta( $user->ID, '_awpm_application', true );
		if ( 1 === $page && is_array( $application ) ) {
			$values = array(); foreach ( array( 'website', 'promotion', 'submitted_at' ) as $key ) { $values[] = array( 'name' => $key, 'value' => AWPM_Security::value( $application, $key ) ); }
			$data[] = array( 'group_id' => 'affiliate-wpm', 'group_label' => 'Affiliate WPM', 'item_id' => 'awpm-application-' . $user->ID, 'data' => $values );
		}
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
		foreach ( array( '_awpm_token', '_awpm_enrolled', '_awpm_blocked', '_awpm_approval', '_awpm_application', '_awpm_stripe_test', '_awpm_stripe_live', '_awpm_stripe_oauth', '_awpm_stripe_onboarding', '_awpm_stripe_create_test', '_awpm_stripe_create_live', '_awpm_stripe_country_test', '_awpm_stripe_country_live' ) as $key ) { $result['items_removed'] = delete_user_meta( $user->ID, $key ) || $result['items_removed']; }
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
