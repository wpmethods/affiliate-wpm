<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_License {
	const STATE = 'awpm_license_state';
	const CREDENTIALS = 'awpm_license_credentials';
	const CRON = 'awpm_license_revalidate';
	public static function init() {
		add_action( 'admin_post_awpm_license', array( __CLASS__, 'submit' ) );
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );
		add_action( self::CRON, array( __CLASS__, 'refresh' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
		add_action( 'init', array( __CLASS__, 'check_domain' ), 5 );
	}
	public static function activation_notice( $inline = false ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || self::active() ) { return; }
		if ( ! $inline && 'affiliate-wpm' === AWPM_Security::value( $_GET, 'page' ) ) { return; }
		$classes = $inline ? 'awpm-notice awpm-license-notice' : 'notice notice-warning awpm-license-notice';
		echo '<div class="' . esc_attr( $classes ) . '" role="status"><p><strong>' . esc_html__( 'Affiliate WPM: license activation required.', 'affiliate-wpm' ) . '</strong> ' . esc_html__( 'The affiliate program is disabled. Activate your license to enable the program, unlock Settings and receive updates.', 'affiliate-wpm' ) . ' ';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=affiliate-wpm&tab=license' ) ) . '">' . esc_html__( 'Activate license', 'affiliate-wpm' ) . '</a>';
		} else {
			echo esc_html__( 'Ask your site administrator to activate it.', 'affiliate-wpm' );
		}
		echo '</p></div>';
	}
	public static function endpoint() { return defined( 'AWPM_LICENSE_API_URL' ) ? AWPM_LICENSE_API_URL : 'https://wpmethods.com/LicenseValidationAffiliateWPM/api.php'; }
	public static function product_id() { return defined( 'AWPM_LICENSE_PRODUCT_ID' ) ? (string) AWPM_LICENSE_PRODUCT_ID : ''; }
	public static function domain() { return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ) ); }
	public static function state() { $state = get_option( self::STATE, array() ); return wp_parse_args( is_array( $state ) ? $state : array(), array( 'status' => 'inactive', 'domain' => '', 'activated_at' => '', 'checked_at' => 0, 'last_error' => '' ) ); }
	public static function put_state( $changes ) { update_option( self::STATE, array_merge( self::state(), $changes ), false ); }
	private static function secret_key() { return hash( 'sha256', wp_salt( 'auth' ) . '|affiliate-wpm-license', true ); }
	private static function seal( $data ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return new WP_Error( 'encryption', __( 'PHP OpenSSL is required to store license credentials securely.', 'affiliate-wpm' ) ); }
		$iv = random_bytes( 12 ); $tag = '';
		$ciphertext = openssl_encrypt( wp_json_encode( $data ), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ciphertext ? new WP_Error( 'encryption', __( 'License credentials could not be stored securely.', 'affiliate-wpm' ) ) : base64_encode( $iv . $tag . $ciphertext );
	}
	public static function credentials() {
		$empty = array( 'key' => '', 'token' => '' ); $stored = get_option( self::CREDENTIALS, '' );
		if ( ! is_string( $stored ) || ! $stored || ! function_exists( 'openssl_decrypt' ) ) { return $empty; }
		$decoded = base64_decode( $stored, true ); if ( false === $decoded || strlen( $decoded ) < 29 ) { return $empty; }
		$plaintext = openssl_decrypt( substr( $decoded, 28 ), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, substr( $decoded, 0, 12 ), substr( $decoded, 12, 16 ) );
		$data = false === $plaintext ? null : json_decode( $plaintext, true );
		return is_array( $data ) && isset( $data['key'], $data['token'] ) && is_string( $data['key'] ) && is_string( $data['token'] ) ? $data : $empty;
	}
	private static function store( $key, $token ) {
		$sealed = self::seal( array( 'key' => $key, 'token' => $token ) ); if ( is_wp_error( $sealed ) ) { return $sealed; }
		update_option( self::CREDENTIALS, $sealed, false ); return true;
	}
	public static function active() {
		$state = self::state(); $credentials = self::credentials();
		return 'active' === $state['status'] && self::domain() === $state['domain'] && $credentials['key'] && $credentials['token'] && (int) $state['checked_at'] >= time() - 7 * DAY_IN_SECONDS;
	}
	public static function check_domain() {
		$state = self::state();
		if ( 'active' === $state['status'] && self::domain() !== $state['domain'] ) {
			self::put_state( array( 'status' => 'domain_mismatch', 'last_error' => __( 'The site domain changed. Verify the license for this domain or deactivate it on the original site.', 'affiliate-wpm' ) ) );
			AWPM_Updates::clear();
		}
	}
	public static function schedule() {
		if ( ! self::credentials()['key'] ) { wp_clear_scheduled_hook( self::CRON ); return; }
		if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON ); }
	}
	private static function clean_secret( $value ) {
		return is_scalar( $value ) ? substr( trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) ), 0, 512 ) : '';
	}
	public static function safe_message( $message, $extra = '' ) {
		$message = is_scalar( $message ) ? sanitize_text_field( (string) $message ) : __( 'The license server returned an invalid response.', 'affiliate-wpm' );
		foreach ( array_merge( array_values( self::credentials() ), array( $extra ) ) as $secret ) { if ( $secret ) { $message = str_replace( array( $secret, rawurlencode( $secret ) ), '[redacted]', $message ); } }
		return substr( $message, 0, 500 );
	}
	public static function api( $action, $key, $token = '' ) {
		$url = self::endpoint(); $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		if ( ! $parts || ( $parts['scheme'] ?? '' ) !== 'https' || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['port'] ) && 443 !== $parts['port'] ) { return new WP_Error( 'api', __( 'The license API must use a trusted HTTPS URL.', 'affiliate-wpm' ) ); }
		$body = array( 'action' => $action, 'license_key' => $key, 'domain' => self::domain(), 'slug' => 'affiliate-wpm', 'product_slug' => 'affiliate-wpm' );
		if ( self::product_id() ) { $body['product_id'] = self::product_id(); }
		if ( $token ) { $body['token'] = $token; }
		$response = wp_safe_remote_post( $url, array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 131072, 'headers' => array( 'Accept' => 'application/json' ), 'body' => $body ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) { return new WP_Error( 'api', __( 'The license server could not be reached. Please try again.', 'affiliate-wpm' ) ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'success', $data ) || ! in_array( $data['success'], array( true, false, 1, 0, '1', '0' ), true ) ) { return new WP_Error( 'api', __( 'The license server returned an invalid response.', 'affiliate-wpm' ) ); }
		$data['success'] = in_array( $data['success'], array( true, 1, '1' ), true );
		if ( $data['success'] && ( isset( $data['product_slug'] ) && 'affiliate-wpm' !== $data['product_slug'] || self::product_id() && isset( $data['product_id'] ) && self::product_id() !== (string) $data['product_id'] ) ) { return new WP_Error( 'product', __( 'This license belongs to a different product.', 'affiliate-wpm' ) ); }
		return $data;
	}
	private static function mutation( $callback ) {
		$lock = 'awpm_license_mutation';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) < time() - 120 ) { delete_option( $lock ); }
			if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', __( 'A license request is already running. Please try again shortly.', 'affiliate-wpm' ) ); }
		}
		try { return $callback(); } finally { delete_option( $lock ); }
	}
	public static function activate( $key ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'affiliate-wpm' ) ); }
		$key = self::clean_secret( $key ); if ( ! $key ) { return new WP_Error( 'key', __( 'Enter your license key.', 'affiliate-wpm' ) ); }
		return self::mutation( function () use ( $key ) {
			$old = self::credentials();
			if ( self::active() && $old['key'] !== $key ) { return new WP_Error( 'active', __( 'Deactivate the current license before entering a different key.', 'affiliate-wpm' ) ); }
			$stored = self::seal( array( 'key' => $key, 'token' => '' ) ); if ( is_wp_error( $stored ) ) { return $stored; }
			$validated = self::api( 'validate_license', $key ); if ( is_wp_error( $validated ) ) { return $validated; }
			if ( ! $validated['success'] ) { return new WP_Error( 'license', self::safe_message( $validated['message'] ?? __( 'Invalid license key.', 'affiliate-wpm' ), $key ) ); }
			$check = self::api( 'check_domain_activation', $key );
			if ( is_wp_error( $check ) && 'product' === $check->get_error_code() ) { return $check; }
			$recovered = ! is_wp_error( $check ) && $check['success'] && true === ( $check['active_domain_match'] ?? false ) && ! empty( $check['matching_token'] );
			$data = $recovered ? $check : self::api( 'activate_license', $key );
			if ( is_wp_error( $data ) ) { return $data; }
			if ( ! $data['success'] ) { return new WP_Error( 'license', self::safe_message( $data['message'] ?? __( 'License activation failed.', 'affiliate-wpm' ), $key ) ); }
			$token = self::clean_secret( ! empty( $data['token'] ) ? $data['token'] : ( $data['matching_token'] ?? '' ) );
			if ( ! $token ) { return new WP_Error( 'token', __( 'The server did not return an activation token. Please contact WP Methods.', 'affiliate-wpm' ) ); }
			$saved = self::store( $key, $token ); if ( is_wp_error( $saved ) ) { return $saved; }
			self::put_state( array( 'status' => 'active', 'domain' => self::domain(), 'activated_at' => current_time( 'mysql' ), 'checked_at' => time(), 'last_error' => '' ) );
			AWPM_Updates::clear(); self::schedule();
			return $recovered || true === ( $data['recovered'] ?? false ) ? __( 'License recovered and activated for this domain.', 'affiliate-wpm' ) : __( 'License activated for this domain.', 'affiliate-wpm' );
		} );
	}
	public static function refresh() {
		return self::mutation( function () {
			self::check_domain(); $state = self::state(); $credentials = self::credentials();
			if ( ! $credentials['key'] || self::domain() !== $state['domain'] ) { return new WP_Error( 'inactive', __( 'Verify your license for this domain.', 'affiliate-wpm' ) ); }
			$data = self::api( 'check_domain_activation', $credentials['key'] );
			if ( is_wp_error( $data ) ) { self::put_state( array( 'last_error' => $data->get_error_message() ) ); if ( 'product' === $data->get_error_code() ) { self::put_state( array( 'status' => 'inactive' ) ); AWPM_Updates::clear(); } return $data; }
			if ( ! $data['success'] ) { $message = self::safe_message( $data['message'] ?? __( 'The license is no longer valid.', 'affiliate-wpm' ) ); self::put_state( array( 'status' => 'inactive', 'last_error' => $message ) ); AWPM_Updates::clear(); return new WP_Error( 'license', $message ); }
			if ( ! array_key_exists( 'active_domain_match', $data ) || ! is_bool( $data['active_domain_match'] ) ) { return new WP_Error( 'api', __( 'The license server returned an invalid domain status.', 'affiliate-wpm' ) ); }
			if ( ! $data['active_domain_match'] ) { self::put_state( array( 'status' => 'inactive', 'last_error' => __( 'The license is not activated for this domain.', 'affiliate-wpm' ) ) ); AWPM_Updates::clear(); return new WP_Error( 'inactive', __( 'The license is not activated for this domain.', 'affiliate-wpm' ) ); }
			$token = self::clean_secret( $data['matching_token'] ?? $credentials['token'] );
			if ( ! $token ) { return new WP_Error( 'token', __( 'An activation token is required.', 'affiliate-wpm' ) ); }
			$saved = self::store( $credentials['key'], $token ); if ( is_wp_error( $saved ) ) { return $saved; } self::put_state( array( 'status' => 'active', 'checked_at' => time(), 'last_error' => '' ) ); return __( 'License status refreshed.', 'affiliate-wpm' );
		} );
	}
	public static function deactivate() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'affiliate-wpm' ) ); }
		return self::mutation( function () {
			$credentials = self::credentials(); $state = self::state();
			if ( ! $credentials['key'] ) { return new WP_Error( 'key', __( 'No license is stored on this site.', 'affiliate-wpm' ) ); }
			if ( self::domain() !== $state['domain'] ) { return new WP_Error( 'domain', __( 'Deactivate this license on its original domain before moving it.', 'affiliate-wpm' ) ); }
			$token = $credentials['token'];
			if ( ! $token ) { $check = self::api( 'check_domain_activation', $credentials['key'] ); if ( ! is_wp_error( $check ) && $check['success'] && true === ( $check['active_domain_match'] ?? false ) ) { $token = self::clean_secret( $check['matching_token'] ?? '' ); } }
			if ( ! $token ) { return new WP_Error( 'token', __( 'Verify the license to recover its activation token before deactivating.', 'affiliate-wpm' ) ); }
			$data = self::api( 'deactivate_license', $credentials['key'], $token );
			if ( is_wp_error( $data ) ) { return $data; }
			if ( ! $data['success'] ) { return new WP_Error( 'license', self::safe_message( $data['message'] ?? __( 'Deactivation failed.', 'affiliate-wpm' ) ) ); }
			delete_option( self::CREDENTIALS ); delete_option( self::STATE ); wp_clear_scheduled_hook( self::CRON ); AWPM_Updates::clear(); return __( 'License deactivated from this domain.', 'affiliate-wpm' );
		} );
	}
	public static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), '', array( 'response' => 403 ) ); }
		AWPM_Security::admin_request( 'awpm_license' );
	}
	public static function submit() {
		self::guard(); $operation = AWPM_Security::value( $_POST, 'operation' );
		if ( 'activate' === $operation ) { $result = self::activate( wp_unslash( AWPM_Security::value( $_POST, 'license_key' ) ) ); }
		elseif ( 'deactivate' === $operation ) { $result = self::deactivate(); }
		elseif ( 'refresh' === $operation ) { $result = self::refresh(); }
		elseif ( 'updates' === $operation ) { $result = current_user_can( 'update_plugins' ) ? AWPM_Updates::manual_check() : new WP_Error( 'permission', __( 'You cannot check plugin updates.', 'affiliate-wpm' ) ); }
		else { $result = new WP_Error( 'operation', __( 'Unknown license action.', 'affiliate-wpm' ) ); }
		set_transient( 'awpm_license_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => self::safe_message( is_wp_error( $result ) ? $result->get_error_message() : $result ) ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=license' ) ); exit;
	}
	private static function action_form( $operation, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_license"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'awpm_license' ); echo '<button class="button awpm-primary">' . esc_html( $label ) . '</button></form>';
	}
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		self::check_domain(); $state = self::state(); $credentials = self::credentials(); $active = self::active();
		$notice = get_transient( 'awpm_license_notice_' . get_current_user_id() ); if ( is_array( $notice ) ) { delete_transient( 'awpm_license_notice_' . get_current_user_id() ); echo '<p class="awpm-notice">' . esc_html( $notice['message'] ?? '' ) . '</p>'; }
		echo '<section class="awpm-card"><h2>' . AWPM_UI::icon( 'shield' ) . esc_html__( 'License activation', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Activate your Affiliate WPM license to enable the affiliate program, configure Settings and receive updates for this domain.', 'affiliate-wpm' ) . '</p><div class="awpm-license-summary"><span class="awpm-badge awpm-' . ( $active ? 'approved' : 'pending' ) . '">' . esc_html( $active ? __( 'Active', 'affiliate-wpm' ) : __( 'Activation required', 'affiliate-wpm' ) ) . '</span><dl><dt>' . esc_html__( 'Current domain', 'affiliate-wpm' ) . '</dt><dd>' . esc_html( self::domain() ) . '</dd><dt>' . esc_html__( 'Activated domain', 'affiliate-wpm' ) . '</dt><dd>' . esc_html( $state['domain'] ?: '—' ) . '</dd><dt>' . esc_html__( 'License key', 'affiliate-wpm' ) . '</dt><dd>' . ( $credentials['key'] ? esc_html__( 'Stored securely · masked', 'affiliate-wpm' ) : '—' ) . '</dd><dt>' . esc_html__( 'Activation date', 'affiliate-wpm' ) . '</dt><dd>' . esc_html( $state['activated_at'] ?: '—' ) . '</dd></dl></div>';
		if ( $state['last_error'] ) { echo '<p class="awpm-notice">' . esc_html( self::safe_message( $state['last_error'] ) ) . '</p>'; }
		if ( ! $active ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_license"><input type="hidden" name="operation" value="activate">'; wp_nonce_field( 'awpm_license' );
			echo '<div class="awpm-field"><label for="awpm-license-key">' . esc_html__( 'License key', 'affiliate-wpm' ) . '</label><input id="awpm-license-key" type="password" name="license_key" maxlength="512" required autocomplete="off" placeholder="' . esc_attr__( 'Enter your Affiliate WPM license key', 'affiliate-wpm' ) . '"></div><p class="awpm-muted">' . esc_html__( 'If this domain already has an active activation, verification recovers its token without using another activation.', 'affiliate-wpm' ) . '</p><button class="button awpm-primary">' . esc_html__( 'Verify & activate license', 'affiliate-wpm' ) . '</button></form>';
		}
		if ( $credentials['key'] ) { echo '<div class="awpm-actions awpm-license-actions">'; self::action_form( 'refresh', __( 'Refresh license status', 'affiliate-wpm' ) ); if ( self::domain() === $state['domain'] ) { self::action_form( 'deactivate', __( 'Deactivate license', 'affiliate-wpm' ) ); } echo '</div>'; }
		echo '</section><section class="awpm-card"><h2>' . AWPM_UI::icon( 'settings' ) . esc_html__( 'Plugin updates', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . sprintf( esc_html__( 'Installed version: %s', 'affiliate-wpm' ), esc_html( AWPM_VERSION ) ) . '</p><p>' . esc_html( $active ? __( 'WordPress will check for licensed updates automatically. Available versions appear under Plugins and Dashboard → Updates.', 'affiliate-wpm' ) : __( 'Activate your license to enable update checks.', 'affiliate-wpm' ) ) . '</p>';
		if ( $active && current_user_can( 'update_plugins' ) ) { self::action_form( 'updates', __( 'Check for updates', 'affiliate-wpm' ) ); }
		echo '</section>';
	}
}
