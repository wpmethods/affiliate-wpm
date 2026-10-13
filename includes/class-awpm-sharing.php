<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Sharing {
	public static function platforms() {
		return array( 'facebook' => 'Facebook', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'x' => 'X', 'linkedin' => 'LinkedIn', 'copy' => __( 'Copy link', 'affiliate-wpm' ) );
	}
	public static function defaults() {
		return array( 'enabled' => 'yes', 'product_section' => 'yes', 'title' => 'Share & Earn', 'description' => '', 'platforms' => array( 'facebook', 'whatsapp', 'copy' ) );
	}
	public static function get( $key = null ) {
		$value = wp_parse_args( get_option( 'awpm_sharing', array() ), self::defaults() );
		return null === $key ? $value : ( $value[ $key ] ?? null );
	}
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();
		foreach ( array( 'enabled', 'product_section' ) as $key ) { $clean[ $key ] = isset( $input[ $key ] ) && 'yes' === $input[ $key ] ? 'yes' : 'no'; }
		foreach ( array( 'title' => 100, 'description' => 500 ) as $key => $length ) {
			$clean[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? substr( sanitize_text_field( $input[ $key ] ), 0, $length ) : '';
		}
		if ( ! $clean['title'] ) { $clean['title'] = self::defaults()['title']; }
		$requested = array_filter( (array) ( $input['platforms'] ?? array() ), 'is_string' );
		$clean['platforms'] = array_values( array_intersect( array_keys( self::platforms() ), $requested ) );
		return $clean;
	}
	public static function init() {
		add_action( 'admin_post_awpm_save_sharing', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_awpm_generate_link', array( __CLASS__, 'ajax' ) );
		add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'product_section' ), 20 );
		add_action( 'template_redirect', function () {
			if ( is_product() && self::allowed( get_current_user_id() ) && 'yes' === self::get( 'enabled' ) && 'yes' === self::get( 'product_section' ) ) {
				AWPM_Cache::no_cache( 'Affiliate WPM personalized product sharing' );
			}
		} );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}
	public static function allowed( $id ) {
		return AWPM_Settings::program_enabled() && AWPM_Tracking::active( $id );
	}
	public static function link( $id, $url ) {
		if ( ! self::allowed( $id ) ) { return new WP_Error( 'inactive', __( 'An active affiliate account is required.', 'affiliate-wpm' ) ); }
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\\\\]/', $url ) ) { return new WP_Error( 'url', __( 'Enter a valid product or page URL from this store.', 'affiliate-wpm' ) ); }
		$parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ?? '' ) ? 443 : 80 );
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === strtolower( $home['scheme'] ?? '' ) ? 443 : 80 );
		$base = rtrim( $home['path'] ?? '', '/' ); $path = $parts['path'] ?? '/';
		if ( ! $parts || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || $port !== $home_port || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( $base && $path !== $base && 0 !== strpos( $path, $base . '/' ) ) ) {
			return new WP_Error( 'url', __( 'Use a product or page URL from this store only.', 'affiliate-wpm' ) );
		}
		if ( ! self::public_url( $url, $parts ) ) { return new WP_Error( 'private_url', __( 'Use a public product or page link. Account, checkout, admin and action links cannot be shared.', 'affiliate-wpm' ) ); }
		return add_query_arg( AWPM_Settings::referral_parameter(), AWPM_Tracking::token( $id ), remove_query_arg( AWPM_Settings::referral_keys(), esc_url_raw( $url, array( 'http', 'https' ) ) ) );
	}
	private static function public_url( $url, $parts ) {
		$path = strtolower( rawurldecode( rawurldecode( $parts['path'] ?? '/' ) ) );
		if ( preg_match( '~(?:^|/)(?:wp-admin|wp-login\.php|wp-json|\.\.?)(?:/|$)~', $path ) || preg_match( '/[\x00-\x20\\\\]/', $path ) ) { return false; }
		foreach ( array( 'myaccount', 'checkout', 'cart' ) as $page ) {
			$base = wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH );
			if ( ! $base || '/' === $base ) { continue; }
			$base = strtolower( rtrim( rawurldecode( $base ), '/' ) );
			if ( rtrim( $path, '/' ) === $base || 0 === strpos( $path, $base . '/' ) ) { return false; }
		}
		parse_str( $parts['query'] ?? '', $query );
		foreach ( array_keys( $query ) as $key ) {
			$key = strtolower( $key );
			if ( false !== strpos( $key, 'nonce' ) || in_array( $key, array( 'action', 'wc-ajax', 'rest_route', 'key', 'order_key', 'pay_for_order', 'reset_key', 'password', 'token', 'access_token' ), true ) ) { return false; }
		}
		$post_id = absint( AWPM_Security::value( $query, 'p', AWPM_Security::value( $query, 'page_id' ) ) ) ?: url_to_postid( $url );
		if ( $post_id ) {
			$post = get_post( $post_id ); $status = $post ? get_post_status_object( $post->post_status ) : null;
			if ( ! $post || ! $status || ! $status->public || $post->post_password ) { return false; }
			if ( in_array( $post_id, array( wc_get_page_id( 'myaccount' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'cart' ) ), true ) ) { return false; }
		}
		return true;
	}
	public static function ajax() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_send_json_error( array( 'message' => __( 'Use the link generator form.', 'affiliate-wpm' ) ), 405 ); }
		$nonce = sanitize_text_field( wp_unslash( AWPM_Security::value( $_POST, 'nonce' ) ) );
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'awpm_generate_link' ) ) { wp_send_json_error( array( 'message' => __( 'Your session expired. Refresh and try again.', 'affiliate-wpm' ) ), 403 ); }
		$url = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? trim( wp_unslash( $_POST['url'] ) ) : '';
		$result = self::link( get_current_user_id(), $url );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( array( 'url' => $result ) );
	}
	public static function enqueue() {
		$account = is_account_page() && is_wc_endpoint_url( 'affiliate-wpm' );
		$product = is_product() && 'yes' === self::get( 'enabled' ) && 'yes' === self::get( 'product_section' ) && AWPM_Settings::program_enabled();
		if ( ! $account && ! $product ) { return; }
		wp_enqueue_style( 'awpm-sharing', AWPM_URL . 'assets/sharing.css', array(), (string) filemtime( AWPM_PATH . 'assets/sharing.css' ) );
		AWPM_Design::enqueue( 'awpm-sharing' );
		wp_enqueue_script( 'awpm-sharing', AWPM_URL . 'assets/sharing.js', array(), AWPM_VERSION, true );
		wp_localize_script( 'awpm-sharing', 'awpmSharing', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => self::allowed( get_current_user_id() ) ? wp_create_nonce( 'awpm_generate_link' ) : '', 'error' => __( 'Unable to generate the link. Please try again.', 'affiliate-wpm' ), 'copied' => __( 'Link copied.', 'affiliate-wpm' ), 'manual' => __( 'Select and copy the link above, then paste it into your message or post.', 'affiliate-wpm' ) ) );
	}
	public static function generator() {
		if ( ! self::allowed( get_current_user_id() ) ) { return; }
		echo '<div class="awpm-generator"><h4>' . esc_html__( 'Affiliate link generator', 'affiliate-wpm' ) . '</h4><p class="awpm-muted">' . esc_html__( 'Paste a product or page URL from this store to create your own affiliate link.', 'affiliate-wpm' ) . '</p><form data-awpm-generator><label for="awpm-generator-url">' . esc_html__( 'Product or page URL', 'affiliate-wpm' ) . '</label><div class="awpm-copy"><input id="awpm-generator-url" name="url" type="url" placeholder="' . esc_attr( home_url( '/product/example/' ) ) . '" required maxlength="2048"><button type="submit" class="button awpm-primary">' . esc_html__( 'Generate link', 'affiliate-wpm' ) . '</button></div></form><div class="awpm-generator-result" hidden><label for="awpm-generated-link">' . esc_html__( 'Generated affiliate link', 'affiliate-wpm' ) . '</label><div class="awpm-copy"><input id="awpm-generated-link" readonly><button type="button" class="button awpm-primary" data-awpm-share="copy">' . esc_html__( 'Copy', 'affiliate-wpm' ) . '</button></div></div><p class="awpm-share-status" role="status" aria-live="polite"></p></div>';
	}
	public static function product_section() {
		global $product;
		static $shown = array();
		if ( ! is_product() || ! $product instanceof WC_Product || 'yes' !== self::get( 'enabled' ) || 'yes' !== self::get( 'product_section' ) || ! AWPM_Settings::program_enabled() || isset( $shown[ $product->get_id() ] ) ) { return; }
		$shown[ $product->get_id() ] = true;
		echo '<section class="awpm awpm-product-share"><h3>' . esc_html( self::get( 'title' ) ) . '</h3>';
		if ( ! self::allowed( get_current_user_id() ) ) {
			$label = 'none' === AWPM_Affiliates::status( get_current_user_id() ) ? __( 'Join affiliate', 'affiliate-wpm' ) : __( 'Affiliate Area', 'affiliate-wpm' );
			echo '<a class="awpm-share-join awpm-primary" href="' . esc_url( wc_get_account_endpoint_url( 'affiliate-wpm' ) ) . '">' . esc_html( $label ) . '</a></section>'; return;
		}
		$link = self::link( get_current_user_id(), get_permalink( $product->get_id() ) );
		if ( is_wp_error( $link ) ) { echo '</section>'; return; }
		echo '<div class="awpm-share-icons">';
		foreach ( (array) self::get( 'platforms' ) as $key ) {
			$names = self::platforms(); if ( ! isset( $names[ $key ] ) ) { continue; }
			$name = $names[ $key ]; $url = self::share_url( $key, $link );
			if ( ! $url && 'copy' !== $key ) { continue; }
		/* translators: %s: sharing platform name. */
			$label = 'copy' === $key ? $name : sprintf( __( 'Share on %s', 'affiliate-wpm' ), $name );
			$attributes = ' class="awpm-share-icon awpm-share-' . esc_attr( $key ) . ' awpm-primary" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"';
			echo wp_kses( $url ? '<a' . $attributes . ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' : '<button type="button"' . $attributes . ' data-awpm-share="copy" data-awpm-link="' . esc_attr( $link ) . '">', AWPM_UI::allowed_html() );
			echo wp_kses( self::icon( $key ), AWPM_UI::allowed_html() );
			echo $url ? '</a>' : '</button>';
		}
		echo '</div><span class="awpm-share-status" role="status" aria-live="polite"></span></section>';
	}
	public static function share_url( $key, $link ) {
		$encoded = rawurlencode( $link );
		$urls = array( 'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=', 'whatsapp' => 'https://wa.me/?text=', 'telegram' => 'https://t.me/share/url?url=', 'x' => 'https://twitter.com/intent/tweet?url=', 'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' );
		return isset( $urls[ $key ] ) ? $urls[ $key ] . $encoded : '';
	}
	private static function icon( $key ) {
		$paths = array( 'facebook' => 'M14 21v-8h3l.5-4H14V7c0-1 .5-1.5 1.5-1.5H18V2h-3c-3 0-5 2-5 5v2H7v4h3v8z', 'whatsapp' => 'M5 19l-3 3 1-5A10 10 0 1 1 5 19z M8 7c0 5 4 9 9 9l1-3-3-1-1 2c-2-1-3-2-4-4l2-1-1-3z', 'messenger' => 'M3 18l-1 4 5-2a10 10 0 1 0-4-2z M6 14l4-5 4 3 4-3-4 6-4-3z', 'tiktok' => 'M14 3v12a5 5 0 1 1-5-5v3a2 2 0 1 0 2 2V3h3c1 3 3 4 6 4v3c-3 0-5-1-6-3', 'telegram' => 'M2 10l20-8-5 20-6-7-4 4v-6l11-8-13 7z', 'x' => 'M3 3l18 18M21 3L3 21', 'linkedin' => 'M4 9v12M4 3v1M10 21V9h4v2c3-4 7-2 7 2v8M14 21v-7', 'copy' => 'M9 8V3h12v14h-5M3 8h13v13H3z' );
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $paths[ $key ] ?? $paths['copy'] ) . '"></path></svg>';
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		AWPM_Security::admin_request( 'awpm_sharing' );
		update_option( 'awpm_sharing', self::clean( wp_unslash( $_POST['awpm_sharing'] ?? array() ) ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=sharing&saved=1' ) ); exit;
	}
	public static function render() {
		echo '<form class="awpm-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_save_sharing">'; wp_nonce_field( 'awpm_sharing' );
		echo '<section class="awpm-card"><h2>' . esc_html__( 'Sharing & product placement', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Let approved affiliates share products using their own referral links. Visitors get an invitation to join from Affiliate Area.', 'affiliate-wpm' ) . '</p>';
		foreach ( array( 'enabled' => __( 'Enable social sharing', 'affiliate-wpm' ), 'product_section' => __( 'Show Share & Earn below product cart controls', 'affiliate-wpm' ) ) as $key => $label ) {
			echo '<p><label class="awpm-toggle"><input type="checkbox" name="awpm_sharing[' . esc_attr( $key ) . ']" value="yes"' . checked( self::get( $key ), 'yes', false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<div class="awpm-form-grid">';
		foreach ( array( 'title' => __( 'Section title', 'affiliate-wpm' ) ) as $key => $label ) {
			echo '<div class="awpm-field"><label for="awpm-sharing-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="awpm-sharing-' . esc_attr( $key ) . '" name="awpm_sharing[' . esc_attr( $key ) . ']" type="text" maxlength="' . ( 'title' === $key ? '100' : '500' ) . '" value="' . esc_attr( self::get( $key ) ) . '"></div>';
		}
		echo '</div></section><section class="awpm-card"><h2>' . esc_html__( 'Active sharing channels', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Select the icons to display. No third-party scripts are loaded. Sharing opens the selected platform only when an affiliate clicks its icon.', 'affiliate-wpm' ) . '</p><div class="awpm-channel-grid">';
		foreach ( self::platforms() as $key => $label ) {
			echo '<label class="awpm-channel"><input type="checkbox" name="awpm_sharing[platforms][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, (array) self::get( 'platforms' ), true ), true, false ) . '>' . wp_kses( self::icon( $key ), AWPM_UI::allowed_html() ) . '<span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div><p class="awpm-muted">' . esc_html__( 'Only channels with direct link sharing are shown, plus the optional copy icon. The account link generator stays available independently of the product sharing section.', 'affiliate-wpm' ) . '</p></section>';
		AWPM_UI::footer( __( 'Save sharing settings', 'affiliate-wpm' ) ); echo '</form>';
	}
}
