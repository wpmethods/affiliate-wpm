<?php
defined( 'ABSPATH' ) || exit;

/** Optional, isolated payout providers. No checkout gateway credentials are reused. */
final class AWPM_Gateways {
	const OPTION = 'awpm_payment_gateways';
	const HOOK = 'awpm_process_gateway_payout';
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process' ) );
		add_action( 'template_redirect', array( __CLASS__, 'oauth_return' ), 2 );
		AWPM_Stripe::init();
	}
	public static function defaults() { return array( 'paypal_enabled' => 'no', 'paypal_mode' => 'sandbox', 'paypal_currency' => 'USD', 'stripe_enabled' => 'no', 'stripe_mode' => 'test', 'credentials' => '' ); }
	public static function config() { return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() ); }
	public static function crypt( $value, $decrypt = false ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) { return false; }
		$key = hash( 'sha256', wp_salt( 'auth' ) . ':awpm-payment-credentials', true );
		if ( $decrypt ) {
			$raw = base64_decode( $value, true ); if ( false === $raw || strlen( $raw ) < 29 ) { return false; }
			return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'affiliate-wpm-payments' );
		}
		$iv = random_bytes( 12 ); $tag = ''; $encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'affiliate-wpm-payments' );
		return false === $encrypted ? false : base64_encode( $iv . $tag . $encrypted );
	}
	private static function secrets( $config = null ) { $config = $config ?: self::config(); $raw = $config['credentials'] ? self::crypt( $config['credentials'], true ) : false; return $raw ? (array) json_decode( $raw, true ) : array(); }
	public static function clean( $input ) {
		if ( ! is_array( $input ) ) { return new WP_Error( 'configuration', __( 'Invalid payment settings.', 'affiliate-wpm' ) ); }
		$old = self::config(); $secrets = self::secrets( $old ); $new = self::defaults();
		foreach ( array( 'paypal_enabled', 'stripe_enabled' ) as $key ) { $new[ $key ] = 'yes' === AWPM_Security::value( $input, $key ) ? 'yes' : 'no'; }
		$new['paypal_mode'] = 'live' === AWPM_Security::value( $input, 'paypal_mode' ) ? 'live' : 'sandbox'; $new['stripe_mode'] = 'live' === AWPM_Security::value( $input, 'stripe_mode' ) ? 'live' : 'test';
		$currency = strtoupper( AWPM_Security::value( $input, 'paypal_currency', 'USD' ) ); $new['paypal_currency'] = in_array( $currency, self::paypal_currencies(), true ) ? $currency : 'USD';
		foreach ( array( 'paypal' => array( 'id', 'secret' ), 'stripe' => array( 'key' ) ) as $provider => $suffixes ) {
			$mode = $new[ $provider . '_mode' ];
			foreach ( $suffixes as $suffix ) {
				$shared = $provider . '_' . $suffix;
				if ( ! array_key_exists( $shared, $input ) ) { continue; }
				$value = trim( AWPM_Security::value( $input, $shared ) );
				if ( '' !== $value && AWPM_Security::value( $input, $provider . '_credential_mode', $mode ) !== $mode ) { return new WP_Error( 'configuration', __( 'The credential environment changed. Save the environment first, then enter its credentials.', 'affiliate-wpm' ) ); }
				$input[ $provider . '_' . $mode . '_' . $suffix ] = $value;
			}
		}
		foreach ( array( 'paypal_sandbox_id', 'paypal_sandbox_secret', 'paypal_live_id', 'paypal_live_secret', 'stripe_test_key', 'stripe_live_key', 'stripe_test_client', 'stripe_live_client' ) as $key ) {
			$value = trim( AWPM_Security::value( $input, $key ) ); if ( '' === $value ) { continue; }
			if ( strlen( $value ) > 512 || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $value ) ) { return new WP_Error( 'configuration', __( 'Enter valid provider credentials without spaces.', 'affiliate-wpm' ) ); }
			if ( 0 === strpos( $key, 'stripe_' ) ) {
				$prefix = false !== strpos( $key, '_client' ) ? 'ca_' : ( false !== strpos( $key, '_test_' ) ? 'sk_test_' : 'sk_live_' );
				if ( 0 !== strpos( $value, $prefix ) ) {
					if ( 'ca_' === $prefix ) {
						return new WP_Error( 'configuration', __( 'Stripe Connect OAuth Client ID must start with ca_. Find it in Stripe Dashboard > Settings > Connect > OAuth settings. A pk_ Publishable key is not a Connect Client ID.', 'affiliate-wpm' ) );
					}
					return new WP_Error( 'configuration', 'sk_test_' === $prefix
						? __( 'Stripe Sandbox / Test Secret key must start with sk_test_. Use the platform secret API key from Stripe Dashboard > Developers > API keys, not a pk_ Publishable key. For an sk_live_ key, select Live first.', 'affiliate-wpm' )
						: __( 'Stripe Live Secret key must start with sk_live_. Use the platform secret API key from Stripe Dashboard > Developers > API keys, not a pk_ Publishable key. For an sk_test_ key, select Sandbox / Test first.', 'affiliate-wpm' ) );
				}
			}
			$secrets[ $key ] = $value;
		}
		$new['credentials'] = $secrets ? self::crypt( wp_json_encode( $secrets ) ) : '';
		if ( false === $new['credentials'] ) { return new WP_Error( 'encryption', __( 'OpenSSL AES-GCM is required to store payout credentials.', 'affiliate-wpm' ) ); }
		foreach ( array( 'paypal', 'stripe' ) as $provider ) { if ( 'yes' === $new[ $provider . '_enabled' ] && ! self::ready( $provider, $new ) ) { return new WP_Error( 'configuration', __( 'Enter credentials for the selected provider mode before enabling it.', 'affiliate-wpm' ) ); } }
		return $new;
	}
	public static function context( $provider, $config = null ) {
		$config = $config ?: self::config(); $secrets = self::secrets( $config ); $mode = $config[ $provider . '_mode' ];
		$id = $secrets[ $provider . '_' . $mode . ( 'paypal' === $provider ? '_id' : '_client' ) ] ?? ''; $key = $secrets[ $provider . '_' . $mode . ( 'paypal' === $provider ? '_secret' : '_key' ) ] ?? '';
		return array( 'mode' => $mode, 'id' => $id, 'key' => $key, 'fingerprint' => hash( 'sha256', $provider . ':' . $mode . ':' . $id . ':' . $key ) );
	}
	public static function ready( $provider, $config = null ) { $config = $config ?: self::config(); $c = self::context( $provider, $config ); return 'yes' === $config[ $provider . '_enabled' ] && $c['key'] && ( 'stripe' === $provider || $c['id'] ); }
	public static function methods() {
		$methods = array(); foreach ( array( 'paypal' => 'PayPal', 'stripe' => 'Stripe Connect' ) as $provider => $label ) {
			if ( ! self::ready( $provider ) ) { continue; } $c = self::context( $provider ); $test = 'live' !== $c['mode'];
			$methods[] = array( 'id' => 'awpm_' . $provider, 'provider' => $provider, 'name' => $label . ( $test ? ' (Sandbox/Test)' : '' ), 'instructions' => 'paypal' === $provider ? __( 'Enter your PayPal account email address only.', 'affiliate-wpm' ) : __( 'Connect your own Stripe account below. Payment is credited to its Stripe balance; bank payouts follow your Stripe settings.', 'affiliate-wpm' ), 'active' => 'yes' );
		} return $methods;
	}
	public static function paypal_currencies() { return array( 'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD' ); }
	public static function decimals( $provider, $currency ) {
		if ( 'stripe' === $provider && in_array( $currency, array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' ), true ) ) { return 3; }
		$zero = 'paypal' === $provider ? array( 'HUF', 'JPY', 'TWD' ) : array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF', 'ISK' );
		return in_array( $currency, $zero, true ) ? 0 : 2;
	}
	private static function http( $url, $method, $headers, $body = null ) {
		$args = array( 'method' => $method, 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'headers' => $headers ); if ( null !== $body ) { $args['body'] = $body; }
		$response = wp_remote_request( $url, $args ); if ( is_wp_error( $response ) ) { return new WP_Error( 'gateway_uncertain', __( 'Provider connection failed. Funds stay reserved until the payment status is known.', 'affiliate-wpm' ) ); }
		$code = wp_remote_retrieve_response_code( $response ); $data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$data = is_array( $data ) ? $data : array(); $data['_awpm_http_status'] = $code; $reference = wp_remote_retrieve_header( $response, 'request-id' ); $data['_awpm_request_id'] = is_string( $reference ) ? $reference : '';
			if ( ( $data['error']['type'] ?? '' ) === 'idempotency_error' ) { return new WP_Error( 'gateway_uncertain', __( 'Provider request identity needs review. The original request is retained.', 'affiliate-wpm' ), $data ); }
			return new WP_Error( $code >= 400 && $code < 500 && ! in_array( $code, array( 408, 409, 429 ), true ) ? 'gateway_rejected' : 'gateway_uncertain', sprintf( __( 'Provider returned HTTP %d. Check the account, balance, country and currency support.', 'affiliate-wpm' ), $code ), is_array( $data ) ? $data : array() ); }
		return is_array( $data ) ? $data : new WP_Error( 'gateway_uncertain', __( 'Invalid provider response. Payment status needs checking.', 'affiliate-wpm' ) );
	}
	public static function stripe( $context, $method, $path, $body = null, $key = '' ) {
		$headers = array( 'Authorization' => 'Bearer ' . $context['key'], 'Stripe-Version' => '2024-06-20' ); if ( $key ) { $headers['Idempotency-Key'] = $key; }
		return self::http( 'https://api.stripe.com/v1/' . $path, $method, $headers, $body );
	}
	private static function paypal( $context, $method, $path, $body = null ) {
		$base = 'live' === $context['mode'] ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
		$auth = self::http( $base . '/v1/oauth2/token', 'POST', array( 'Authorization' => 'Basic ' . base64_encode( $context['id'] . ':' . $context['key'] ), 'Content-Type' => 'application/x-www-form-urlencoded' ), 'grant_type=client_credentials' );
		if ( is_wp_error( $auth ) || empty( $auth['access_token'] ) || ! is_string( $auth['access_token'] ) ) { return new WP_Error( 'gateway_uncertain', __( 'PayPal authentication failed. Check the selected mode credentials.', 'affiliate-wpm' ) ); }
		return self::http( $base . $path, $method, array( 'Authorization' => 'Bearer ' . $auth['access_token'], 'Content-Type' => 'application/json' ), null === $body ? null : wp_json_encode( $body ) );
	}
	public static function prepare( $provider, $uid, $details ) {
		if ( ! self::ready( $provider ) ) { return new WP_Error( 'provider', __( 'This payment provider is not configured.', 'affiliate-wpm' ) ); }
		$c = self::context( $provider ); $job = array( 'provider' => $provider, 'mode' => $c['mode'], 'fingerprint' => $c['fingerprint'], 'key' => wp_generate_uuid4(), 'remote_id' => '', 'started' => 0, 'attempts' => 0 );
		if ( 'paypal' === $provider ) {
			$email = trim( $details ); if ( ! is_email( $email ) || sanitize_email( $email ) !== $email ) { return new WP_Error( 'email', __( 'Enter a valid PayPal account email address.', 'affiliate-wpm' ) ); }
			$job['recipient'] = $email; $job['currency'] = self::config()['paypal_currency'];
		} else {
			$connection = get_user_meta( $uid, '_awpm_stripe_' . $c['mode'], true );
			if ( ! is_array( $connection ) || empty( $connection['id'] ) || ! hash_equals( $c['fingerprint'], $connection['fingerprint'] ?? '' ) || ! preg_match( '/^acct_[a-zA-Z0-9]+$/', $connection['id'] ) ) { return new WP_Error( 'connect', __( 'Connect your Stripe account before requesting a Stripe withdrawal.', 'affiliate-wpm' ) ); }
			$account = self::stripe( $c, 'GET', 'accounts/' . $connection['id'] );
			if ( is_wp_error( $account ) ) { return $account; }
			if ( ( $account['id'] ?? '' ) !== $connection['id'] || 'active' !== ( $account['capabilities']['transfers'] ?? '' ) || empty( $account['payouts_enabled'] ) ) { return new WP_Error( 'connect', __( 'Complete Stripe account verification and enable payouts first.', 'affiliate-wpm' ) ); }
			$job['recipient'] = $connection['id']; $job['currency'] = strtoupper( $account['default_currency'] ?? '' );
			if ( ! isset( get_woocommerce_currencies()[ $job['currency'] ] ) ) { return new WP_Error( 'currency', __( 'Stripe returned an unsupported account currency.', 'affiliate-wpm' ) ); }
		} return $job;
	}
	public static function quote( $job, $amount, $currency ) {
		$fx = AWPM_Currency::rate( $currency, $job['currency'] ); if ( is_wp_error( $fx ) ) { return $fx; }
		$precision = self::decimals( $job['provider'], $job['currency'] ); $converted = round( $amount * $fx['rate'], $precision );
		if ( ! is_finite( $converted ) || $converted <= 0 || $converted > 999999999 ) { return new WP_Error( 'currency', __( 'Invalid payout amount after currency conversion. Review exchange rates.', 'affiliate-wpm' ) ); }
		$job['amount'] = number_format( $converted, $precision, '.', '' ); $job['source_amount'] = (string) $amount; $job['source_currency'] = $currency; $job['fx'] = $fx;
		$minor = round( $converted * pow( 10, 'stripe' === $job['provider'] && in_array( $job['currency'], array( 'ISK', 'UGX' ), true ) ? 2 : $precision ) );
		if ( ! is_finite( $minor ) || $minor > PHP_INT_MAX || $minor < 1 ) { return new WP_Error( 'currency', __( 'The payout amount is outside the supported API integer range.', 'affiliate-wpm' ) ); }
		$job['minor'] = (int) $minor; return $job;
	}
	public static function job( $id ) { wp_cache_delete( 'awpm_gateway_job_' . absint( $id ), 'options' ); return (array) get_option( 'awpm_gateway_job_' . absint( $id ), array() ); }
	public static function store_job( $id, $job ) { update_option( 'awpm_gateway_job_' . absint( $id ), $job, false ); if ( self::job( $id ) !== $job ) { throw new RuntimeException( 'Payment state could not be saved.' ); } }
	private static function schedule( $id, $delay = 60 ) {
		$args = array( (int) $id ); if ( function_exists( 'as_schedule_single_action' ) ) { if ( ! as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => $args, 'group' => 'affiliate-wpm', 'status' => 'pending', 'per_page' => 1 ), 'ids' ) ) { as_schedule_single_action( time() + $delay, self::HOOK, $args, 'affiliate-wpm', false ); } }
		elseif ( ! wp_next_scheduled( self::HOOK, $args ) ) { wp_schedule_single_event( time() + $delay, self::HOOK, $args ); }
	}
	public static function start( $id ) {
		global $wpdb; if ( ! current_user_can( 'manage_woocommerce' ) || ! AWPM_License::active() ) { return new WP_Error( 'access', __( 'An authorized store manager and active license are required.', 'affiliate-wpm' ) ); }
		$table = AWPM_Store::table( 'payouts' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ); if ( ! $row ) { return new WP_Error( 'missing', __( 'Withdrawal not found.', 'affiliate-wpm' ) ); }
		$result = AWPM_Store::locked( $row->affiliate_id, function () use ( $wpdb, $table, $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ); $job = self::job( $id );
			if ( ! $row || 'approved' !== $row->status || empty( $job['provider'] ) ) { return new WP_Error( 'transition', __( 'Approve this automatic withdrawal before sending it.', 'affiliate-wpm' ) ); }
			$c = self::context( $job['provider'] ); if ( ! self::ready( $job['provider'] ) || ! hash_equals( $job['fingerprint'], $c['fingerprint'] ) ) { return new WP_Error( 'configuration', __( 'The payout mode or credentials changed. Restore the original configuration or reject this unsent request.', 'affiliate-wpm' ) ); }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,amount,paid_amount FROM ' . AWPM_Store::table( 'commissions' ) . ' WHERE payout_id=%d', $id ) ); $sum = 0; $job['allocations'] = array();
			foreach ( $rows as $commission ) { $delta = (float) $commission->amount - (float) $commission->paid_amount; $sum += $delta; $job['allocations'][ $commission->id ] = $delta; }
			if ( abs( $sum - (float) $row->amount ) > 0.000001 ) { return new WP_Error( 'changed', __( 'The reserved balance changed. Reject this unsent request and review commissions.', 'affiliate-wpm' ) ); }
			$job['started'] = time(); self::store_job( $id, $job ); AWPM_Store::must( $wpdb->update( $table, array( 'status' => 'processing', 'note' => __( 'Payment queued. Waiting for provider confirmation.', 'affiliate-wpm' ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ) ); return true;
		} ); if ( true === $result ) { self::schedule( $id, 1 ); } return $result;
	}
	private static function finish( $row, $job, $success, $note ) {
		global $wpdb; $table = AWPM_Store::table( 'commissions' ); $test = 'live' !== $job['mode'];
		if ( $success && ! $test ) {
			foreach ( $job['allocations'] as $id => $delta ) { $updated = AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $table SET paid_amount=paid_amount+%f,payout_id=0,status='paid',updated_at=%s WHERE id=%d AND payout_id=%d", $delta, current_time( 'mysql', true ), $id, $row->id ) ) ); if ( 1 !== $updated ) { throw new RuntimeException( 'Reserved commission changed during settlement.' ); } }
		} else { AWPM_Store::must( $wpdb->query( $wpdb->prepare( "UPDATE $table SET payout_id=0,status=IF(paid_amount>0,'paid','available'),updated_at=%s WHERE payout_id=%d", current_time( 'mysql', true ), $row->id ) ) ); }
		$status = $success ? ( $test ? 'tested' : 'completed' ) : 'failed';
		if ( $test && $success ) { $note = __( 'Sandbox/Test successful. No real money sent; commissions remain available.', 'affiliate-wpm' ); }
		AWPM_Store::must( $wpdb->update( AWPM_Store::table( 'payouts' ), array( 'status' => $status, 'note' => $note, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) ) );
		$job['finished'] = time(); $job['result'] = $status; self::store_job( $row->id, $job ); $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AWPM_Store::table( 'payouts' ) . ' WHERE id=%d', $row->id ) ); AWPM_Emails::payout( 'payout_affiliate', $row ); AWPM_Emails::payout( 'payout_admin', $row );
	}
	/** Used only after a signed webhook and an authenticated transfer lookup match the frozen job. */
	public static function finish_reversed( $row, $job, $full ) { self::finish( $row, $job, ! $full, $full ? __( 'Stripe confirmed the transfer was fully reversed before settlement. Earnings remain available.', 'affiliate-wpm' ) : __( 'Stripe transfer confirmed with a partial reversal.', 'affiliate-wpm' ) ); }
	public static function verify_paypal() {
		if ( ! self::ready( 'paypal' ) ) { return true; }
		$c = self::context( 'paypal' ); $base = 'live' === $c['mode'] ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
		$auth = self::http( $base . '/v1/oauth2/token', 'POST', array( 'Authorization' => 'Basic ' . base64_encode( $c['id'] . ':' . $c['key'] ), 'Content-Type' => 'application/x-www-form-urlencoded' ), 'grant_type=client_credentials' );
		return ! is_wp_error( $auth ) && ! empty( $auth['access_token'] ) ? true : new WP_Error( 'configuration', __( 'Settings saved, but PayPal authentication failed. Check the Client ID and Secret for the selected environment. Live payouts also require PayPal Payouts access.', 'affiliate-wpm' ) );
	}
	public static function process( $id ) {
		global $wpdb; $id = absint( $id ); $table = AWPM_Store::table( 'payouts' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ); if ( ! $row || 'processing' !== $row->status ) { return; }
		$result = AWPM_Store::locked( $row->affiliate_id, function () use ( $wpdb, $table, $id ) {
			$operation = function () use ( $wpdb, $table, $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ); $job = self::job( $id ); if ( ! $row || 'processing' !== $row->status || empty( $job['provider'] ) ) { return; }
			$c = self::context( $job['provider'] ); if ( ! hash_equals( $job['fingerprint'], $c['fingerprint'] ) ) { return new WP_Error( 'configuration', __( 'Restore the original payment mode and credentials to check this payment.', 'affiliate-wpm' ) ); }
			if ( empty( $job['remote_id'] ) && $job['started'] && time() - $job['started'] > 23 * HOUR_IN_SECONDS ) { return new WP_Error( 'review', __( 'Uncertain payment exceeded its retry window. Verify it in the provider dashboard; funds remain reserved.', 'affiliate-wpm' ) ); }
			$job['started'] = $job['started'] ?: time(); $job['attempts']++; self::store_job( $id, $job );
			if ( 'stripe' === $job['provider'] ) {
				if ( ! $job['remote_id'] ) {
					$response = self::stripe( $c, 'POST', 'transfers', array( 'amount' => $job['minor'], 'currency' => strtolower( $job['currency'] ), 'destination' => $job['recipient'], 'metadata' => array( 'awpm_payout' => (string) $id ), 'description' => 'Affiliate WPM withdrawal #' . $id ), 'awpm-' . $job['key'] );
					if ( is_wp_error( $response ) ) { if ( 'gateway_rejected' === $response->get_error_code() && 1 === $job['attempts'] ) { self::finish( $row, $job, false, $response->get_error_message() ); return; } return $response; }
					if ( ! preg_match( '/^tr_[a-zA-Z0-9]+$/', $response['id'] ?? '' ) ) { return new WP_Error( 'review', __( 'Stripe returned no valid transfer reference. Funds remain reserved.', 'affiliate-wpm' ) ); }
					$job['remote_id'] = $response['id']; self::store_job( $id, $job );
				}
				$response = self::stripe( $c, 'GET', 'transfers/' . $job['remote_id'] ); if ( is_wp_error( $response ) ) { return $response; }
				if ( ( $response['id'] ?? '' ) !== $job['remote_id'] || ( $response['destination'] ?? '' ) !== $job['recipient'] || (int) ( $response['amount'] ?? -1 ) !== $job['minor'] || strtoupper( $response['currency'] ?? '' ) !== $job['currency'] || ! array_key_exists( 'livemode', $response ) || (bool) $response['livemode'] !== ( 'live' === $job['mode'] ) || ( $response['metadata']['awpm_payout'] ?? '' ) !== (string) $id || ! empty( $response['reversed'] ) || (int) ( $response['amount_reversed'] ?? 0 ) ) { return new WP_Error( 'review', __( 'Stripe confirmation does not match this withdrawal. Funds remain reserved.', 'affiliate-wpm' ) ); }
				self::finish( $row, $job, true, __( 'Confirmed transfer to the affiliate Stripe balance. Bank payout follows their Stripe settings.', 'affiliate-wpm' ) ); return;
			}
			if ( ! $job['remote_id'] ) {
				$response = self::paypal( $c, 'POST', '/v1/payments/payouts', array( 'sender_batch_header' => array( 'sender_batch_id' => $job['key'], 'email_subject' => 'Affiliate earnings payout' ), 'items' => array( array( 'recipient_type' => 'EMAIL', 'amount' => array( 'value' => $job['amount'], 'currency' => $job['currency'] ), 'receiver' => $job['recipient'], 'sender_item_id' => (string) $id ) ) ) );
				if ( is_wp_error( $response ) ) {
					$data = $response->get_error_data(); $duplicate = false;
					foreach ( (array) ( $data['links'] ?? array() ) as $link ) { if ( preg_match( '~/v1/payments/payouts/([a-zA-Z0-9]+)(?:\?|$)~', $link['href'] ?? '', $m ) ) { $job['remote_id'] = $m[1]; $duplicate = true; break; } }
					if ( ! $duplicate ) { if ( 'gateway_rejected' === $response->get_error_code() && 1 === $job['attempts'] ) { self::finish( $row, $job, false, $response->get_error_message() ); return; } return $response; }
				} else { $job['remote_id'] = $response['batch_header']['payout_batch_id'] ?? ''; }
				if ( ! preg_match( '/^[a-zA-Z0-9]+$/', $job['remote_id'] ) ) { return new WP_Error( 'review', __( 'PayPal returned no valid batch reference. Funds remain reserved.', 'affiliate-wpm' ) ); } self::store_job( $id, $job );
			}
			$response = self::paypal( $c, 'GET', '/v1/payments/payouts/' . $job['remote_id'] . '?page_size=1' ); if ( is_wp_error( $response ) ) { return $response; }
			if ( ( $response['batch_header']['payout_batch_id'] ?? '' ) !== $job['remote_id'] ) { return new WP_Error( 'review', __( 'PayPal batch reference mismatch. Funds remain reserved.', 'affiliate-wpm' ) ); }
			foreach ( (array) ( $response['items'] ?? array() ) as $item ) {
				$payment = $item['payout_item'] ?? array(); if ( (string) ( $payment['sender_item_id'] ?? '' ) !== (string) $id || strtolower( $payment['receiver'] ?? '' ) !== strtolower( $job['recipient'] ) || strtoupper( $payment['amount']['currency'] ?? '' ) !== $job['currency'] || abs( (float) ( $payment['amount']['value'] ?? -1 ) - (float) $job['amount'] ) > 0.000001 ) { return new WP_Error( 'review', __( 'PayPal item confirmation mismatch. Funds remain reserved.', 'affiliate-wpm' ) ); }
				$status = $item['transaction_status'] ?? ''; $job['item_id'] = $item['payout_item_id'] ?? ''; self::store_job( $id, $job );
				if ( 'SUCCESS' === $status ) { self::finish( $row, $job, true, __( 'PayPal confirmed the recipient payment.', 'affiliate-wpm' ) ); return; }
				if ( in_array( $status, array( 'FAILED', 'BLOCKED', 'DENIED', 'CANCELED', 'RETURNED', 'REFUNDED' ), true ) ) { self::finish( $row, $job, false, __( 'PayPal confirmed this payout did not complete or was returned.', 'affiliate-wpm' ) ); return; }
			}
			if ( 'DENIED' === ( $response['batch_header']['batch_status'] ?? '' ) ) { self::finish( $row, $job, false, __( 'PayPal denied this payout batch.', 'affiliate-wpm' ) ); return; }
			return new WP_Error( 'pending', __( 'Provider payment is pending or unclaimed. Funds remain reserved.', 'affiliate-wpm' ) );
			};
			$outcome = $operation();
			// Commit durable provider references even when confirmation is still pending.
			return is_wp_error( $outcome ) ? array( 'error' => $outcome ) : $outcome;
		} );
		if ( is_array( $result ) && isset( $result['error'] ) ) { $result = $result['error']; }
		if ( is_wp_error( $result ) ) {
			AWPM_Store::locked( $row->affiliate_id, function () use ( $wpdb, $table, $id, $result ) { $wpdb->query( $wpdb->prepare( "UPDATE $table SET note=%s,updated_at=%s WHERE id=%d AND status='processing'", $result->get_error_message(), current_time( 'mysql', true ), $id ) ); } );
			if ( ! in_array( $result->get_error_code(), array( 'configuration', 'review' ), true ) ) { self::schedule( $id, 300 ); }
		} return $result;
	}
	public static function connect( $country = '' ) { return AWPM_Stripe::connect( $country ); }
	public static function oauth_return() { AWPM_Stripe::returned(); }
	public static function connection_ui( $uid ) { AWPM_Stripe::ui( $uid ); }
	public static function summary( $id ) {
		$job = self::job( $id ); if ( empty( $job['provider'] ) ) { return; }
		echo '<small class="awpm-note">' . esc_html( ( 'live' === $job['mode'] ? 'Live' : 'Sandbox/Test' ) . ' · ' . $job['currency'] . ' ' . $job['amount'] . ' · 1 ' . $job['source_currency'] . ' = ' . $job['fx']['rate'] . ' ' . $job['currency'] ) . '</small>';
		if ( ! empty( $job['remote_id'] ) && current_user_can( 'manage_woocommerce' ) ) { echo '<small class="awpm-note">' . esc_html( 'Provider reference: ' . $job['remote_id'] ) . '</small>'; }
	}
	public static function fields() {
		if ( ! current_user_can( 'manage_options' ) ) { echo '<p class="awpm-muted">' . esc_html__( 'Only administrators can configure automatic payout credentials.', 'affiliate-wpm' ) . '</p>'; return; }
		$c = self::config(); $secrets = self::secrets( $c ); echo '<div class="awpm-gateway-settings"><h3>' . esc_html__( 'Automatic payouts', 'affiliate-wpm' ) . '</h3><p class="awpm-muted">' . esc_html__( 'Manual methods remain available for Bangladesh and other countries. PayPal Payouts requires business approval. Stripe Connect pays a connected Stripe balance; eligibility depends on account country and currency. Merchant funds must cover payouts and provider fees. API credentials are encrypted and excluded from settings backups.', 'affiliate-wpm' ) . '</p>';
		foreach ( array( 'paypal' => 'PayPal Payouts', 'stripe' => 'Stripe Connect' ) as $provider => $label ) {
			echo '<section class="awpm-gateway-card" data-awpm-provider="' . esc_attr( $provider ) . '"><h4>' . esc_html( $label ) . '</h4><div class="awpm-form-grid"><div><label class="awpm-toggle"><input type="checkbox" name="awpm_gateway[' . esc_attr( $provider ) . '_enabled]" value="yes"' . checked( $c[ $provider . '_enabled' ], 'yes', false ) . '> ' . esc_html__( 'Enable automatic payouts', 'affiliate-wpm' ) . '</label></div><div class="awpm-field"><label>' . esc_html__( 'Environment', 'affiliate-wpm' ) . '<select name="awpm_gateway[' . esc_attr( $provider ) . '_mode]"><option value="' . ( 'paypal' === $provider ? 'sandbox' : 'test' ) . '"' . selected( $c[ $provider . '_mode' ], 'paypal' === $provider ? 'sandbox' : 'test', false ) . '>Sandbox / Test</option><option value="live"' . selected( $c[ $provider . '_mode' ], 'live', false ) . '>Live</option></select></label></div>';
			if ( 'paypal' === $provider ) { echo '<div class="awpm-field awpm-gateway-currency"><label>' . esc_html__( 'PayPal payout currency', 'affiliate-wpm' ) . '<select name="awpm_gateway[paypal_currency]">'; foreach ( self::paypal_currencies() as $currency ) { echo '<option' . selected( $c['paypal_currency'], $currency, false ) . '>' . esc_html( $currency ) . '</option>'; } echo '</select></label><p class="awpm-muted">' . esc_html__( 'WooCommerce remains the commission currency. A saved exchange rate converts this withdrawal when needed.', 'affiliate-wpm' ) . '</p></div>'; }
			echo '</div>';
			$mode = $c[ $provider . '_mode' ];
			echo '<div class="awpm-gateway-credentials"><h5 data-awpm-credential-title>' . esc_html( 'live' === $mode ? __( 'Live credentials', 'affiliate-wpm' ) : __( 'Sandbox / Test credentials', 'affiliate-wpm' ) ) . '</h5><input type="hidden" data-awpm-credential-mode name="awpm_gateway[' . esc_attr( $provider ) . '_credential_mode]" value="' . esc_attr( $mode ) . '"><div class="awpm-form-grid">';
			foreach ( 'paypal' === $provider ? array( 'id' => __( 'Client ID', 'affiliate-wpm' ), 'secret' => __( 'Client Secret', 'affiliate-wpm' ) ) : array( 'key' => __( 'Secret key', 'affiliate-wpm' ) ) as $suffix => $title ) {
				$key = $provider . '_' . $suffix; $test_mode = 'paypal' === $provider ? 'sandbox' : 'test';
				$help = 'stripe' === $provider ? ( 'client' === $suffix
					? __( 'Starts with ca_. Get the Connect OAuth Client ID from Stripe Settings > Connect > OAuth settings. The pk_ Publishable key does not belong here.', 'affiliate-wpm' )
					: __( 'Use sk_test_ for Sandbox / Test or sk_live_ for Live. Get the platform Secret key from Stripe Developers > API keys.', 'affiliate-wpm' ) ) : '';
				echo '<div class="awpm-field"><label for="awpm-gateway-' . esc_attr( $key ) . '">' . esc_html( $title ) . '</label><input id="awpm-gateway-' . esc_attr( $key ) . '" data-awpm-credential data-saved-test="' . ( empty( $secrets[ $provider . '_' . $test_mode . '_' . $suffix ] ) ? 'no' : 'yes' ) . '" data-saved-live="' . ( empty( $secrets[ $provider . '_live_' . $suffix ] ) ? 'no' : 'yes' ) . '" data-empty-placeholder="' . esc_attr__( 'Not configured', 'affiliate-wpm' ) . '" data-saved-placeholder="' . esc_attr__( 'Saved - leave blank to keep', 'affiliate-wpm' ) . '" type="password" name="awpm_gateway[' . esc_attr( $key ) . ']" value="" autocomplete="new-password" maxlength="512" placeholder="' . esc_attr( empty( $secrets[ $provider . '_' . $mode . '_' . $suffix ] ) ? __( 'Not configured', 'affiliate-wpm' ) : __( 'Saved - leave blank to keep', 'affiliate-wpm' ) ) . '"' . ( $help ? ' aria-describedby="awpm-help-' . esc_attr( $key ) . '"' : '' ) . '>' . ( $help ? '<p id="awpm-help-' . esc_attr( $key ) . '" class="awpm-muted">' . esc_html( $help ) . '</p>' : '' ) . '</div>';
			}
			echo '</div><p class="awpm-muted">' . esc_html__( 'These fields save credentials for the selected environment. Blank fields keep its saved credentials; the other environment is preserved.', 'affiliate-wpm' ) . '</p></div>';
			if ( 'stripe' === $provider ) { AWPM_Stripe::fields( $mode ); } else { echo '<p class="awpm-muted">' . esc_html__( 'Get the Client ID and Secret from PayPal Developer Dashboard > Apps & Credentials for this environment. Live payouts require a verified Business account, Payouts access and sufficient PayPal balance. Affiliates only enter their PayPal email; Sandbox requires a Sandbox recipient account.', 'affiliate-wpm' ) . '</p>'; }
			echo '<p class="awpm-muted">' . esc_html__( 'Sandbox/Test never settles real commission balances. Live sends require administrator approval and an explicit Send payment action. Do not switch credentials while a payment is processing.', 'affiliate-wpm' ) . '</p></section>';
		} echo '</div>';
	}
}
