<?php
defined( 'ABSPATH' ) || exit;

/** Secret-key setup and authenticated Stripe-hosted affiliate onboarding. */
final class AWPM_Stripe {
	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'affiliate-wpm/v1', '/stripe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'webhook' ), 'permission_callback' => '__return_true' ) );
		} );
	}
	private static function put_meta( $uid, $key, $value ) { update_user_meta( $uid, $key, $value ); if ( get_user_meta( $uid, $key, true ) !== $value ) { throw new RuntimeException( 'Stripe account state could not be saved.' ); } }
	private static function option( $mode ) { return 'awpm_stripe_setup_' . ( 'live' === $mode ? 'live' : 'test' ); }
	public static function setup_state( $mode ) {
		$stored = get_option( self::option( $mode ), '' );
		$raw = is_string( $stored ) && $stored ? AWPM_Gateways::crypt( $stored, true ) : false;
		return $raw ? (array) json_decode( $raw, true ) : array();
	}
	private static function save_state( $mode, $state ) {
		$encrypted = AWPM_Gateways::crypt( wp_json_encode( $state ) );
		if ( false === $encrypted ) { return new WP_Error( 'encryption', __( 'Could not encrypt Stripe setup.', 'affiliate-wpm' ) ); }
		update_option( self::option( $mode ), $encrypted, false );
		return true;
	}
	public static function endpoint() { return rest_url( 'affiliate-wpm/v1/stripe' ); }
	public static function setup( $input = array() ) {
		$c = AWPM_Gateways::context( 'stripe' );
		if ( ! AWPM_Gateways::ready( 'stripe' ) ) { return true; }
		$platform = AWPM_Gateways::stripe( $c, 'GET', 'account' );
		if ( is_wp_error( $platform ) || ! preg_match( '/^acct_[a-zA-Z0-9]+$/', $platform['id'] ?? '' ) ) {
			return new WP_Error( 'configuration', __( 'Stripe settings were saved, but the account could not be verified. Check the selected environment and Secret key, then save again.', 'affiliate-wpm' ) );
		}
		$state = self::setup_state( $c['mode'] );
		if ( ( $state['platform_id'] ?? '' ) !== $platform['id'] ) { $state = array(); }
		$state['platform_id'] = $platform['id']; $state['country'] = strtoupper( $platform['country'] ?? '' ); $state['fingerprint'] = $c['fingerprint']; $state['checked'] = time();
		$url = self::endpoint(); $errors = array();
		foreach ( array( 'platform' => array( 'transfer.reversed' ), 'connect' => array( 'account.updated' ) ) as $kind => $events ) {
			$manual = trim( AWPM_Security::value( $input, 'stripe_' . $kind . '_webhook_secret' ) );
			if ( $manual ) {
				if ( ! preg_match( '/^whsec_[a-zA-Z0-9]+$/', $manual ) || strlen( $manual ) > 512 || AWPM_Security::value( $input, 'stripe_credential_mode', $c['mode'] ) !== $c['mode'] ) { $errors[] = __( 'Use a whsec_ signing secret from the selected Stripe environment.', 'affiliate-wpm' ); continue; }
				$state[ $kind ] = array( 'secret' => $manual, 'url' => $url, 'manual' => true );
			}
			$saved = $state[ $kind ] ?? array();
			if ( ! empty( $saved['secret'] ) && ( $saved['url'] ?? '' ) === $url ) { continue; }
			// A local WordPress site cannot receive Stripe internet callbacks. CLI forwarding or a public URL is needed.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! $host || preg_match( '/(?:\.local|\.test|\.localhost)$/i', $host ) || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				$errors[] = __( 'Stripe account verified. Automatic webhook setup needs a public HTTPS site. For local tests, forward Stripe events to the endpoint with Stripe CLI and enter its signing secret in Advanced webhook setup.', 'affiliate-wpm' ); continue;
			}
			// Persist one request identity so a lost API response does not create duplicate destinations.
			$pending = $state[ 'pending_' . $kind ] ?? array();
			if ( ( $pending['url'] ?? '' ) !== $url ) { $pending = array( 'url' => $url, 'key' => wp_generate_uuid4(), 'started' => time() ); }
			if ( time() - $pending['started'] > 23 * HOUR_IN_SECONDS ) { $errors[] = __( 'Webhook setup needs review in Stripe Dashboard. Add the endpoint manually and enter its signing secret below.', 'affiliate-wpm' ); continue; }
			$state[ 'pending_' . $kind ] = $pending;
			$result = self::save_state( $c['mode'], $state ); if ( is_wp_error( $result ) ) { return $result; }
			$created = AWPM_Gateways::stripe( $c, 'POST', 'webhook_endpoints', array( 'url' => $url, 'enabled_events' => $events, 'connect' => 'connect' === $kind ? 'true' : 'false', 'api_version' => '2024-06-20', 'description' => 'Affiliate WPM ' . $kind ), 'awpm-webhook-' . $pending['key'] );
			if ( is_wp_error( $created ) || ! preg_match( '/^whsec_[a-zA-Z0-9]+$/', $created['secret'] ?? '' ) || ( $created['url'] ?? '' ) !== $url || ! array_key_exists( 'livemode', $created ) || (bool) $created['livemode'] !== ( 'live' === $c['mode'] ) ) {
				$errors[] = __( 'Stripe account verified, but webhook setup needs attention. Save again to retry, or use Advanced webhook setup.', 'affiliate-wpm' ); continue;
			}
			$state[ $kind ] = array( 'secret' => $created['secret'], 'id' => $created['id'], 'url' => $url ); unset( $state[ 'pending_' . $kind ] );
		}
		$result = self::save_state( $c['mode'], $state ); if ( is_wp_error( $result ) ) { return $result; }
		return $errors ? new WP_Error( 'webhook_setup', implode( ' ', array_unique( $errors ) ) ) : true;
	}
	private static function status_key( $id, $c ) { return 'awpm_stripe_status_' . md5( $id . $c['fingerprint'] ); }
	public static function account( $id, $c, $fresh = false ) {
		$key = self::status_key( $id, $c ); $account = $fresh ? false : get_transient( $key );
		if ( false === $account ) {
			$account = AWPM_Gateways::stripe( $c, 'GET', 'accounts/' . $id );
			if ( is_wp_error( $account ) ) { return $account; }
			if ( ( $account['id'] ?? '' ) !== $id ) { return new WP_Error( 'connect', __( 'Stripe account verification did not match.', 'affiliate-wpm' ) ); }
			// Cache only payout readiness, not identity documents or banking information.
			$account = array( 'id' => $id, 'country' => $account['country'] ?? '', 'default_currency' => $account['default_currency'] ?? '', 'capabilities' => array( 'transfers' => $account['capabilities']['transfers'] ?? '' ), 'payouts_enabled' => ! empty( $account['payouts_enabled'] ), 'details_submitted' => ! empty( $account['details_submitted'] ) );
			set_transient( $key, $account, 120 );
		}
		return $account;
	}
	/** Keep only safe error identifiers. Raw Stripe messages can contain submitted personal data. */
	private static function provider_error( $error, $stage, $country ) {
		$data = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
		$detail = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$code = substr( sanitize_key( AWPM_Security::value( $detail, 'code', AWPM_Security::value( $detail, 'type', 'provider_response' ) ) ), 0, 80 );
		$param = substr( preg_replace( '/[^a-zA-Z0-9_\[\]]/', '', AWPM_Security::value( $detail, 'param' ) ), 0, 80 );
		$raw = strtolower( AWPM_Security::value( $detail, 'message' ) );
		$message = __( 'Stripe could not start account setup. Ask the store administrator to check the Stripe setup error in Affiliate WPM settings.', 'affiliate-wpm' );
		if ( is_wp_error( $error ) && 'gateway_uncertain' === $error->get_error_code() ) { $message = __( 'Stripe connection could not be confirmed. Try again later; your existing setup request is retained to prevent duplicate accounts.', 'affiliate-wpm' ); }
		elseif ( 'country' === $param || strpos( $code, 'country' ) !== false || strpos( $raw, 'country' ) !== false || strpos( $raw, 'countries' ) !== false ) { $message = __( 'Stripe cannot onboard this country with the store current platform configuration. Ask the store administrator to check Stripe Connect country eligibility, or choose another available payout method.', 'affiliate-wpm' ); }
		elseif ( strpos( $raw, 'connect' ) !== false && ( strpos( $raw, 'sign' ) !== false || strpos( $raw, 'platform' ) !== false || strpos( $raw, 'enable' ) !== false ) ) { $message = __( 'The store Stripe account needs its Connect platform setup completed. Ask the administrator to complete Stripe Dashboard > Connect > Setup guide in the selected Test or Live environment.', 'affiliate-wpm' ); }
		elseif ( 'invalid_api_key' === $code || '401' === (string) ( $data['_awpm_http_status'] ?? '' ) ) { $message = __( 'The store Stripe credentials could not be authenticated. Ask the administrator to check the Secret key for the selected Test or Live environment.', 'affiliate-wpm' ); }
		elseif ( strpos( $code, 'permission' ) !== false || '403' === (string) ( $data['_awpm_http_status'] ?? '' ) ) { $message = __( 'Stripe did not permit this account setup. Ask the administrator to review the platform permissions and Connect setup.', 'affiliate-wpm' ); }
		elseif ( strpos( $param, 'capabilities' ) === 0 ) { $message = __( 'Stripe rejected the requested account capabilities. Ask the store administrator to check the platform Connect onboarding configuration.', 'affiliate-wpm' ); }
		$mode = AWPM_Gateways::context( 'stripe' )['mode'];
		$reference = AWPM_Security::value( $data, '_awpm_request_id' ); if ( ! preg_match( '/^req_[a-zA-Z0-9]+$/', $reference ) ) { $reference = ''; }
		update_option( 'awpm_stripe_last_error_' . $mode, array( 'code' => $code, 'param' => $param, 'stage' => $stage, 'country' => $country, 'reference' => $reference, 'message' => $message, 'at' => time() ), false );
		return new WP_Error( 'connect', $message );
	}
	public static function connect( $country = '' ) {
		$uid = get_current_user_id();
		if ( ! $uid || ! AWPM_Settings::program_enabled() || ! AWPM_Tracking::active( $uid ) || ! AWPM_Gateways::ready( 'stripe' ) ) { return new WP_Error( 'connect', __( 'Stripe connection is currently unavailable.', 'affiliate-wpm' ) ); }
		$c = AWPM_Gateways::context( 'stripe' ); $base = wc_get_account_endpoint_url( 'affiliate-wpm' );
		if ( 'live' === $c['mode'] && 'https' !== wp_parse_url( $base, PHP_URL_SCHEME ) ) { return new WP_Error( 'https', __( 'Live Stripe connections require an HTTPS site.', 'affiliate-wpm' ) ); }
		$country = strtoupper( $country );
		$connection = get_user_meta( $uid, '_awpm_stripe_' . $c['mode'], true );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || ! isset( WC()->countries->get_countries()[ $country ] ) ) {
			$country = is_array( $connection ) ? ( $connection['country'] ?? '' ) : '';
			if ( ! $country ) { $country = is_array( $connection ) && ! empty( $connection['id'] ) ? strtoupper( self::setup_state( $c['mode'] )['country'] ?? '' ) : ''; }
			if ( ! $country ) { return new WP_Error( 'country', __( 'Choose your country before connecting Stripe.', 'affiliate-wpm' ) ); }
		}
		update_user_meta( $uid, '_awpm_stripe_country_' . $c['mode'], $country );
		$platform = AWPM_Gateways::stripe( $c, 'GET', 'account' );
		if ( is_wp_error( $platform ) ) { return self::provider_error( $platform, 'platform', $country ); }
		if ( ! preg_match( '/^acct_[a-zA-Z0-9]+$/', $platform['id'] ?? '' ) ) { return new WP_Error( 'connect', __( 'Stripe platform could not be verified. Ask the store administrator to check its Secret key and Connect setup.', 'affiliate-wpm' ) ); }
		$site = hash( 'sha256', home_url( '/' ) );
		$result = AWPM_Store::locked( $uid, function () use ( $uid, $c, $country, $platform, $site ) {
			$meta = '_awpm_stripe_' . $c['mode']; $saved = get_user_meta( $uid, $meta, true );
			$valid = is_array( $saved ) && preg_match( '/^acct_[a-zA-Z0-9]+$/', $saved['id'] ?? '' ) && ( hash_equals( $c['fingerprint'], $saved['fingerprint'] ?? '' ) || ( $saved['platform'] ?? '' ) === $platform['id'] );
			if ( ! $valid && is_array( $saved ) && preg_match( '/^acct_[a-zA-Z0-9]+$/', $saved['id'] ?? '' ) ) {
				// Rotation of a platform key must not silently replace an established affiliate account.
				$existing = AWPM_Gateways::stripe( $c, 'GET', 'accounts/' . $saved['id'] );
				if ( ! is_wp_error( $existing ) && ( $existing['id'] ?? '' ) === $saved['id'] ) { $valid = true; $saved['platform'] = $platform['id']; }
				elseif ( is_wp_error( $existing ) && 'gateway_uncertain' === $existing->get_error_code() ) { return array( 'error' => new WP_Error( 'connect', __( 'Your existing Stripe connection could not be checked. Try again later; no replacement account was created.', 'affiliate-wpm' ) ) ); }
			}
			if ( ! $valid ) {
				$pending_key = '_awpm_stripe_create_' . $c['mode']; $pending = get_user_meta( $uid, $pending_key, true );
				if ( ! is_array( $pending ) || ( $pending['platform'] ?? '' ) !== $platform['id'] ) { $pending = array( 'platform' => $platform['id'], 'country' => $country, 'key' => wp_generate_uuid4(), 'started' => time(), 'email' => get_userdata( $uid )->user_email, 'request_version' => 2 ); }
				if ( time() - $pending['started'] > 23 * HOUR_IN_SECONDS ) { return array( 'error' => new WP_Error( 'connect', __( 'A previous Stripe account setup needs administrator review before retrying. No duplicate account was created.', 'affiliate-wpm' ) ) ); }
				$pending['attempts'] = ( $pending['attempts'] ?? 0 ) + 1; self::put_meta( $uid, $pending_key, $pending );
				$request = array( 'type' => 'standard', 'country' => $pending['country'], 'email' => $pending['email'], 'metadata' => array( 'awpm_user' => (string) $uid, 'awpm_site' => $site ) );
				// Stripe automatically requests country-specific Standard capabilities during onboarding.
				// Preserve the original payload for an older uncertain request's idempotency identity.
				if ( ( $pending['request_version'] ?? 1 ) < 2 ) { $request['capabilities'] = array( 'transfers' => array( 'requested' => 'true' ) ); }
				$account = AWPM_Gateways::stripe( $c, 'POST', 'accounts', $request, 'awpm-account-' . $pending['key'] );
				if ( is_wp_error( $account ) || ! preg_match( '/^acct_[a-zA-Z0-9]+$/', $account['id'] ?? '' ) ) {
					// Commit the pending identity on uncertainty, so a retry cannot duplicate the account.
					if ( is_wp_error( $account ) && 'gateway_rejected' === $account->get_error_code() && 1 === $pending['attempts'] ) { delete_user_meta( $uid, $pending_key ); }
					return array( 'error' => self::provider_error( $account, 'account', $pending['country'] ) );
				}
				$saved = array( 'id' => $account['id'], 'country' => $pending['country'], 'platform' => $platform['id'], 'fingerprint' => $c['fingerprint'], 'hosted' => true, 'created' => time() ); delete_user_meta( $uid, $pending_key );
			}
			$saved['fingerprint'] = $c['fingerprint']; self::put_meta( $uid, $meta, $saved ); return $saved;
		} );
		if ( is_array( $result ) && isset( $result['error'] ) ) { return $result['error']; } if ( is_wp_error( $result ) ) { return $result; }
		if ( empty( $result['hosted'] ) ) { return new WP_Error( 'connect', __( 'Your existing Stripe OAuth connection is retained. It can still receive payouts; manage verification in your Stripe Dashboard.', 'affiliate-wpm' ) ); }
		$state = bin2hex( random_bytes( 32 ) );
		$session = array( 'state' => hash( 'sha256', $state ), 'expires' => time() + 2 * HOUR_IN_SECONDS, 'mode' => $c['mode'], 'fingerprint' => $c['fingerprint'], 'id' => $result['id'], 'country' => $result['country'] ); update_user_meta( $uid, '_awpm_stripe_onboarding', $session );
		$url = add_query_arg( array( 'awpm_stripe_return' => 1, 'state' => $state ), $base );
		$link = AWPM_Gateways::stripe( $c, 'POST', 'account_links', array( 'account' => $result['id'], 'type' => 'account_onboarding', 'return_url' => $url, 'refresh_url' => add_query_arg( 'awpm_stripe_refresh', 1, $url ) ) );
		if ( is_wp_error( $link ) ) { return self::provider_error( $link, 'onboarding', $result['country'] ); }
		delete_option( 'awpm_stripe_last_error_' . $c['mode'] );
		if ( 'https' !== wp_parse_url( $link['url'] ?? '', PHP_URL_SCHEME ) || 'connect.stripe.com' !== wp_parse_url( $link['url'] ?? '', PHP_URL_HOST ) ) { return new WP_Error( 'connect', __( 'Stripe onboarding could not open. Your account is retained; try again to resume setup.', 'affiliate-wpm' ) ); }
		wp_redirect( $link['url'], 303 ); exit;
	}
	public static function returned() {
		if ( ! isset( $_GET['awpm_stripe_return'] ) || ! is_account_page() || ! is_wc_endpoint_url( 'affiliate-wpm' ) || ! is_user_logged_in() ) { return; }
		$uid = get_current_user_id(); $saved = get_user_meta( $uid, '_awpm_stripe_onboarding', true ); $state = AWPM_Security::value( $_GET, 'state' ); $c = AWPM_Gateways::context( 'stripe' );
		$ok = is_array( $saved ) && $state && ( $saved['expires'] ?? 0 ) >= time() && hash_equals( $saved['state'] ?? '', hash( 'sha256', $state ) ) && hash_equals( $c['fingerprint'], $saved['fingerprint'] ?? '' ) && AWPM_Settings::program_enabled() && AWPM_Tracking::active( $uid );
		if ( ! $ok ) { wc_add_notice( __( 'Stripe setup session expired or did not match. Connect again from your Affiliate Area.', 'affiliate-wpm' ), 'error' ); }
		elseif ( isset( $_GET['awpm_stripe_refresh'] ) ) { $result = self::connect( $saved['country'] ); if ( is_wp_error( $result ) ) { wc_add_notice( $result->get_error_message(), 'error' ); } }
		else {
			delete_user_meta( $uid, '_awpm_stripe_onboarding' );
			$connection = get_user_meta( $uid, '_awpm_stripe_' . $c['mode'], true );
			$account = ( $connection['id'] ?? '' ) === $saved['id'] ? self::account( $saved['id'], $c, true ) : new WP_Error( 'connect' );
			$ready = ! is_wp_error( $account ) && 'active' === ( $account['capabilities']['transfers'] ?? '' ) && ! empty( $account['payouts_enabled'] );
			wc_add_notice( $ready ? __( 'Stripe account connected and ready to receive payouts.', 'affiliate-wpm' ) : __( 'Stripe setup is still incomplete or being verified. Resume setup before requesting a Stripe withdrawal.', 'affiliate-wpm' ), $ready ? 'success' : 'notice' );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'affiliate-wpm' ) ); exit;
	}
	public static function ui( $uid ) {
		if ( ! AWPM_Gateways::ready( 'stripe' ) || ! AWPM_Settings::program_enabled() || ! AWPM_Tracking::active( $uid ) ) { return; }
		$c = AWPM_Gateways::context( 'stripe' ); $connection = get_user_meta( $uid, '_awpm_stripe_' . $c['mode'], true ); $connected = is_array( $connection ) && hash_equals( $c['fingerprint'], $connection['fingerprint'] ?? '' ) && preg_match( '/^acct_[a-zA-Z0-9]+$/', $connection['id'] ?? '' );
		$account = $connected ? self::account( $connection['id'], $c ) : null;
		$ready = is_array( $account ) && 'active' === ( $account['capabilities']['transfers'] ?? '' ) && ! empty( $account['payouts_enabled'] );
		echo '<div class="awpm-stripe-connect"><h4>' . esc_html__( 'Get paid with Stripe', 'affiliate-wpm' ) . '</h4><p class="awpm-muted">' . esc_html( $ready ? __( 'Stripe connected. Ready to receive payouts to your Stripe balance.', 'affiliate-wpm' ) : ( $connected ? __( 'Complete or review your Stripe setup before withdrawing.', 'affiliate-wpm' ) : __( 'Choose your country and complete Stripe secure onboarding. Availability depends on the store platform and Stripe eligibility.', 'affiliate-wpm' ) ) ) . '</p><form method="post">';
		wp_nonce_field( 'awpm_account' ); echo '<input type="hidden" name="awpm_action" value="stripe_connect">';
		if ( ! $connected ) { echo '<label for="awpm-stripe-country">' . esc_html__( 'Your country', 'affiliate-wpm' ) . '</label><select id="awpm-stripe-country" name="stripe_country" required><option value="">' . esc_html__( 'Choose your country', 'affiliate-wpm' ) . '</option>'; foreach ( WC()->countries->get_countries() as $code => $name ) { echo '<option value="' . esc_attr( $code ) . '"' . selected( get_user_meta( $uid, '_awpm_stripe_country_' . $c['mode'], true ), $code, false ) . '>' . esc_html( $name ) . '</option>'; } echo '</select>'; }
		if ( ! $connected || ! empty( $connection['hosted'] ) ) { echo '<button class="button awpm-primary" type="submit">' . esc_html( $connected ? __( 'Review / resume Stripe setup', 'affiliate-wpm' ) : __( 'Connect with Stripe', 'affiliate-wpm' ) ) . '</button>'; }
		echo '</form></div>';
	}
	public static function fields( $mode ) {
		$state = self::setup_state( $mode ); $c = AWPM_Gateways::context( 'stripe' ); $verified = ( $state['fingerprint'] ?? '' ) === $c['fingerprint'];
		$connected = $verified && ! empty( $state['platform']['secret'] ) && ! empty( $state['connect']['secret'] ) && ( $state['platform']['url'] ?? '' ) === self::endpoint() && ( $state['connect']['url'] ?? '' ) === self::endpoint();
		echo '<p class="awpm-muted">' . esc_html__( 'Enable Stripe Connect and complete your platform profile. Affiliates use Stripe-hosted onboarding; no OAuth Client ID or Publishable key is needed. Your Stripe balance must cover payouts.', 'affiliate-wpm' ) . '</p><p class="awpm-muted">' . esc_html( $connected ? __( 'Webhooks connected for the saved environment.', 'affiliate-wpm' ) : ( $verified ? __( 'Account verified. Webhook setup needs attention.', 'affiliate-wpm' ) : __( 'Save your Secret key to verify the account and set up webhooks.', 'affiliate-wpm' ) ) ) . '</p>';
		$error = (array) get_option( 'awpm_stripe_last_error_' . $mode, array() );
		if ( $error && current_user_can( 'manage_options' ) ) {
			echo '<div class="awpm-notice"><strong>' . esc_html__( 'Last Stripe setup error', 'affiliate-wpm' ) . '</strong><p>' . esc_html( $error['message'] ?? '' ) . '</p><small>' . esc_html( implode( ' · ', array_filter( array( strtoupper( $mode ), $error['stage'] ?? '', $error['country'] ?? '', $error['code'] ?? '', $error['param'] ?? '', $error['reference'] ?? '' ) ) ) ) . '</small></div>';
		}
		echo '<details><summary>' . esc_html__( 'Advanced webhook setup', 'affiliate-wpm' ) . '</summary><p class="awpm-muted">' . esc_html__( 'Only needed if automatic setup fails or you test locally with Stripe CLI. Create a platform destination for transfer.reversed and a connected-account destination for account.updated. Point both to:', 'affiliate-wpm' ) . ' <code>' . esc_html( self::endpoint() ) . '</code></p><div class="awpm-form-grid">';
		foreach ( array( 'platform' => __( 'Transfer webhook signing secret', 'affiliate-wpm' ), 'connect' => __( 'Account webhook signing secret', 'affiliate-wpm' ) ) as $kind => $label ) {
			echo '<div class="awpm-field"><label>' . esc_html( $label ) . '</label><input type="password" name="awpm_gateway[stripe_' . esc_attr( $kind ) . '_webhook_secret]" value="" autocomplete="new-password" maxlength="512" data-awpm-credential data-saved-test="' . ( empty( self::setup_state( 'test' )[ $kind ]['secret'] ) ? 'no' : 'yes' ) . '" data-saved-live="' . ( empty( self::setup_state( 'live' )[ $kind ]['secret'] ) ? 'no' : 'yes' ) . '" data-empty-placeholder="whsec_..." data-saved-placeholder="' . esc_attr__( 'Saved - leave blank to keep', 'affiliate-wpm' ) . '" placeholder="whsec_..."></div>';
		} echo '</div></details>';
	}
	public static function webhook( $request ) {
		$body = $request->get_body(); $header = $request->get_header( 'stripe-signature' );
		if ( strlen( $body ) > 1048576 || ! preg_match( '/(?:^|,)t=(\d+)(?:,|$)/', $header, $time ) || abs( time() - (int) $time[1] ) > 300 ) { return new WP_Error( 'signature', 'Invalid Stripe signature.', array( 'status' => 400 ) ); }
		preg_match_all( '/(?:^|,)v1=([a-f0-9]{64})(?=,|$)/', $header, $signatures ); $mode = null;
		foreach ( array( 'test', 'live' ) as $candidate ) { $saved = self::setup_state( $candidate ); foreach ( array( 'platform', 'connect' ) as $kind ) { if ( empty( $saved[ $kind ]['secret'] ) ) { continue; } $digest = hash_hmac( 'sha256', $time[1] . '.' . $body, $saved[ $kind ]['secret'] ); foreach ( $signatures[1] as $signature ) { if ( hash_equals( $digest, $signature ) ) { $mode = $candidate; } } } }
		$event = json_decode( $body, true );
		if ( null === $mode || ! is_array( $event ) || ! array_key_exists( 'livemode', $event ) || (bool) $event['livemode'] !== ( 'live' === $mode ) ) { return new WP_Error( 'signature', 'Invalid Stripe signature or environment.', array( 'status' => 400 ) ); }
		$config = AWPM_Gateways::config(); $config['stripe_mode'] = $mode; $c = AWPM_Gateways::context( 'stripe', $config ); $obj = $event['data']['object'] ?? array();
		if ( 'account.updated' === ( $event['type'] ?? '' ) ) { if ( preg_match( '/^acct_[a-zA-Z0-9]+$/', $obj['id'] ?? '' ) ) { delete_transient( self::status_key( $obj['id'], $c ) ); } return new WP_REST_Response( array( 'received' => true ), 200 ); }
		if ( 'transfer.reversed' !== ( $event['type'] ?? '' ) ) { return new WP_REST_Response( array( 'received' => true ), 200 ); }
		$id = absint( $obj['metadata']['awpm_payout'] ?? 0 ); $job = AWPM_Gateways::job( $id );
		if ( ! $id || ( $job['provider'] ?? '' ) !== 'stripe' || ( $job['mode'] ?? '' ) !== $mode || ( $job['remote_id'] ?? '' ) !== ( $obj['id'] ?? '' ) ) { return new WP_REST_Response( array( 'received' => true ), 200 ); }
		if ( ! hash_equals( $job['fingerprint'], $c['fingerprint'] ) ) { return new WP_Error( 'configuration', 'Restore original Stripe credentials to reconcile this payment.', array( 'status' => 503 ) ); }
		$transfer = AWPM_Gateways::stripe( $c, 'GET', 'transfers/' . $job['remote_id'] );
		if ( is_wp_error( $transfer ) ) { return new WP_Error( 'provider', 'Stripe confirmation unavailable. Retry delivery.', array( 'status' => 503 ) ); }
		$result = self::reconcile( $id, $transfer );
		return is_wp_error( $result ) ? new WP_Error( 'reconcile', $result->get_error_message(), array( 'status' => 503 ) ) : new WP_REST_Response( array( 'received' => true ), 200 );
	}
	/** Reconcile cumulative confirmed reversals; repeats and older events cannot restore funds twice. */
	public static function reconcile( $id, $transfer ) {
		global $wpdb; $table = AWPM_Store::table( 'payouts' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
		if ( ! $row ) { return true; }
		return AWPM_Store::locked( $row->affiliate_id, function () use ( $wpdb, $table, $id, $transfer ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ); $job = AWPM_Gateways::job( $id );
			if ( ! $row || ! in_array( $row->status, array( 'completed', 'processing', 'failed', 'tested' ), true ) || ( $job['provider'] ?? '' ) !== 'stripe' ) { return true; }
			if ( ( $transfer['id'] ?? '' ) !== $job['remote_id'] || ( $transfer['destination'] ?? '' ) !== $job['recipient'] || (int) ( $transfer['amount'] ?? -1 ) !== $job['minor'] || strtoupper( $transfer['currency'] ?? '' ) !== $job['currency'] || ! array_key_exists( 'livemode', $transfer ) || (bool) $transfer['livemode'] !== ( 'live' === $job['mode'] ) || (string) ( $transfer['metadata']['awpm_payout'] ?? '' ) !== (string) $id ) { return new WP_Error( 'review', __( 'Stripe reversal did not match this withdrawal.', 'affiliate-wpm' ) ); }
			$reversed = (int) ( $transfer['amount_reversed'] ?? 0 ); $previous = (int) ( $job['reversed_minor'] ?? 0 );
			if ( $reversed <= $previous ) { return true; }
			if ( $reversed > $job['minor'] || $reversed < 0 ) { return new WP_Error( 'review', __( 'Invalid Stripe reversal amount.', 'affiliate-wpm' ) ); }
			if ( 'processing' === $row->status ) {
				// The authenticated transfer is known, even if its first confirmation was lost.
				AWPM_Gateways::finish_reversed( $row, $job, $reversed === $job['minor'] );
				if ( $reversed === $job['minor'] ) { $job = AWPM_Gateways::job( $id ); $job['reversed_minor'] = $reversed; AWPM_Gateways::store_job( $id, $job ); return true; }
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
				$job = AWPM_Gateways::job( $id );
			}
			if ( 'live' === $job['mode'] ) {
				$commissions = AWPM_Store::table( 'commissions' );
				foreach ( $job['allocations'] as $commission_id => $allocation ) {
					$delta = round( $allocation * $reversed / $job['minor'], 6 ) - round( $allocation * $previous / $job['minor'], 6 );
					$updated = AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $commissions SET paid_amount=paid_amount-%f,updated_at=%s WHERE id=%d AND affiliate_id=%d", $delta, current_time( 'mysql', true ), $commission_id, $row->affiliate_id ) ) );
					if ( $delta && 1 !== $updated ) { throw new RuntimeException( 'Missing settled commission.' ); }
					AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $commissions SET status=IF(paid_amount>0,'paid','available') WHERE id=%d AND payout_id=0 AND status IN ('paid','available')", $commission_id ) ) );
				}
			}
			$job['reversed_minor'] = $reversed; $full = $reversed === $job['minor'];
			AWPM_Store::must( $wpdb->update( $table, array( 'status' => $full ? 'failed' : $row->status, 'note' => $full ? __( 'Stripe confirmed the full transfer was reversed. The returned earnings are available again.', 'affiliate-wpm' ) : __( 'Stripe confirmed a partial transfer reversal. Returned earnings were restored proportionally.', 'affiliate-wpm' ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ) );
			AWPM_Gateways::store_job( $id, $job ); return true;
		} );
	}
}