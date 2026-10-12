<?php
defined( 'ABSPATH' ) || exit;

/** Licensed updates use the same metadata protocol as Click Shop. */
final class AWPM_Updates {
	private static $checker;
	public static function endpoint() { return defined( 'AWPM_UPDATE_URL' ) ? AWPM_UPDATE_URL : 'https://wpmethods.com/updates-affiliate-wpm/'; }
	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'build' ), 30 );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'protect_cache' ), 100 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'protect_cache' ), 100 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'protect_download' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'download_args' ), 100, 2 );
	}
	private static function trusted_url( $url ) {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false; $host = wp_parse_url( self::endpoint(), PHP_URL_HOST );
		return $parts && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && strtolower( $parts['host'] ) === strtolower( (string) $host ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && ( ! isset( $parts['port'] ) || 443 === $parts['port'] ) && false === strpos( $url, "\r" ) && false === strpos( $url, "\n" );
	}
	private static function headers() {
		$credentials = AWPM_License::credentials();
		return array( 'X-WPM-License-Key' => $credentials['key'], 'X-WPM-Activation-Token' => $credentials['token'], 'X-WPM-Domain' => AWPM_License::domain(), 'X-WPM-Product' => 'affiliate-wpm' );
	}
	/** Match the WP Methods update-server protocol used by Abandonment Recovery. */
	public static function query_args( $args ) {
		$credentials = AWPM_License::credentials();
		$args['license_key'] = $credentials['key'];
		$args['domain'] = AWPM_License::domain();
		return $args;
	}
	public static function request_args( $args ) {
		$args['headers'] = array_merge( isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(), self::headers() );
		$args['timeout'] = 15; $args['redirection'] = 0; $args['sslverify'] = true; $args['reject_unsafe_urls'] = true; $args['limit_response_size'] = 1048576;
		return $args;
	}
	public static function build() {
		if ( self::$checker ) { return self::$checker; }
		if ( ! AWPM_License::active() || ! self::trusted_url( self::endpoint() ) ) { return null; }
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory' ) ) { require_once AWPM_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php'; }
		self::$checker = \YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker( add_query_arg( array( 'action' => 'get_metadata', 'slug' => 'affiliate-wpm' ), self::endpoint() ), AWPM_FILE, 'affiliate-wpm' );
		self::$checker->addQueryArgFilter( array( __CLASS__, 'query_args' ) );
		self::$checker->addHttpRequestArgFilter( array( __CLASS__, 'request_args' ) );
		self::$checker->addResultFilter( array( __CLASS__, 'validate_metadata' ) );
		self::$checker->addFilter( 'pre_inject_update', array( __CLASS__, 'validate_metadata' ) );
		self::$checker->addFilter( 'pre_inject_info', array( __CLASS__, 'validate_metadata' ) );
		return self::$checker;
	}
	public static function validate_metadata( $info, $response = null ) {
		if ( null !== $response ) {
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return null; }
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || 'affiliate-wpm' !== ( $data['slug'] ?? '' ) ) { return null; }
		}
		if ( ! AWPM_License::active() || ! is_object( $info ) || 'affiliate-wpm' !== ( $info->slug ?? '' ) || ! preg_match( '/^\d+(?:\.\d+){1,3}(?:[-.][a-zA-Z0-9.-]+)?$/', (string) ( $info->version ?? '' ) ) || ! self::trusted_url( $info->download_url ?? '' ) ) { return null; }
		if ( ! self::valid_package_auth( $info->download_url ) ) { return null; }
		if ( isset( $info->sections ) && is_array( $info->sections ) ) { $info->sections = array_map( 'wp_kses_post', array_filter( $info->sections, 'is_string' ) ); }
		$info->translations = array();
		return $info;
	}
	public static function protect_cache( $transient ) {
		$file = plugin_basename( AWPM_FILE );
		if ( is_object( $transient ) && isset( $transient->response[ $file ] ) ) {
			$update = $transient->response[ $file ];
			if ( ! AWPM_License::active() || ! is_object( $update ) || 'affiliate-wpm' !== ( $update->slug ?? '' ) || ! self::trusted_url( $update->package ?? '' ) ) { unset( $transient->response[ $file ] ); }
		}
		return $transient;
	}
	/** A server-generated key is permitted only in its designated query field. */
	private static function valid_package_auth( $url ) {
		if ( ! self::trusted_url( $url ) ) { return false; }
		$query = array(); parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$credentials = AWPM_License::credentials();
		if ( isset( $query['license_key'] ) && ( ! is_string( $query['license_key'] ) || ! hash_equals( $credentials['key'], $query['license_key'] ) ) ) { return false; }
		if ( isset( $query['domain'] ) && ( ! is_string( $query['domain'] ) || AWPM_License::domain() !== $query['domain'] ) ) { return false; }
		$without_key = remove_query_arg( 'license_key', $url );
		foreach ( $credentials as $secret ) { if ( $secret && ( false !== strpos( $without_key, $secret ) || false !== strpos( $without_key, rawurlencode( $secret ) ) ) ) { return false; } }
		return true;
	}
	private static function authenticated_package( $package ) {
		return add_query_arg( self::query_args( array() ), $package );
	}
	private static function package_matches( $package ) {
		$state = get_site_option( 'external_updates-affiliate-wpm' );
		return is_object( $state ) && isset( $state->update ) && is_object( $state->update ) && 'affiliate-wpm' === ( $state->update->slug ?? '' ) && self::valid_package_auth( $package ) && self::valid_package_auth( $state->update->download_url ?? '' ) && remove_query_arg( array( 'license_key', 'domain' ), $package ) === remove_query_arg( array( 'license_key', 'domain' ), $state->update->download_url );
	}
	public static function protect_download( $reply, $package, $upgrader, $extra ) {
		if ( ( $extra['plugin'] ?? '' ) !== plugin_basename( AWPM_FILE ) ) { return $reply; }
		if ( ! AWPM_License::active() || ! self::package_matches( $package ) ) { return new WP_Error( 'awpm_license', __( 'Verify your Affiliate WPM license and check for updates before downloading this update.', 'affiliate-wpm' ) ); }
		if ( false !== $reply ) { return $reply; }
		// The metadata package URL can omit credentials. Add them only when downloading
		// the verified package, so this server can authenticate it through GET parameters.
		if ( ! function_exists( 'download_url' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$result = download_url( self::authenticated_package( $package ), 300, true );
		if ( is_wp_error( $result ) ) { return new WP_Error( 'awpm_update_download', __( 'Affiliate WPM could not download the update. Verify your license and try again.', 'affiliate-wpm' ) ); }
		return $result;
	}
	public static function download_args( $args, $url ) {
		if ( AWPM_License::active() && self::package_matches( $url ) ) { $args = self::request_args( $args ); unset( $args['limit_response_size'] ); }
		return $args;
	}
	public static function clear() {
		if ( self::$checker ) { self::$checker->resetUpdateState(); self::$checker->removeHooks(); self::$checker = null; }
		delete_site_option( 'external_updates-affiliate-wpm' );
		wp_clear_scheduled_hook( 'puc_cron_check_updates-affiliate-wpm' );
		$updates = get_site_transient( 'update_plugins' ); $file = plugin_basename( AWPM_FILE );
		if ( is_object( $updates ) ) { unset( $updates->response[ $file ], $updates->no_update[ $file ] ); set_site_transient( 'update_plugins', $updates ); }
	}
	public static function manual_check() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) { return new WP_Error( 'permission', __( 'Access denied.', 'affiliate-wpm' ) ); }
		$result = AWPM_License::refresh(); if ( is_wp_error( $result ) ) { return $result; }
		$checker = self::build(); if ( ! $checker ) { return new WP_Error( 'inactive', __( 'Activate your license to check for updates.', 'affiliate-wpm' ) ); }
		$update = $checker->checkForUpdates();
		$state = $checker->getUpdateState();
		if ( ! $state || ! $state->getLastCheck() || ! self::validate_metadata( $state->getUpdate() ) || $checker->getLastRequestApiErrors() ) { return new WP_Error( 'metadata', __( 'Update metadata could not be verified. Please try again.', 'affiliate-wpm' ) ); }
		return $update && version_compare( $update->version, AWPM_VERSION, '>' ) ? sprintf( __( 'Version %s is available. Open Plugins or Dashboard → Updates to install it.', 'affiliate-wpm' ), $update->version ) : __( 'Update check completed. No newer version is available.', 'affiliate-wpm' );
	}
}
