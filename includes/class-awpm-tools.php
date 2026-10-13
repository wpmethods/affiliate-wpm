<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Tools {
	const MAX_BACKUP_SIZE = 1048576;
	public static function init() { add_action( 'admin_post_awpm_tools', array( __CLASS__, 'submit' ) ); }
	public static function guard( $operation ) {
		AWPM_Security::admin_request( 'awpm_tools' );
		if ( 'csv' !== $operation && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only administrators can manage settings backups.', 'affiliate-wpm' ), '', array( 'response' => 403 ) ); }

	}
	public static function backup() {
		return array( 'format' => 'affiliate-wpm-settings', 'schema_version' => 1, 'plugin_version' => AWPM_VERSION, 'created_at' => gmdate( 'c' ), 'settings' => array( 'program' => AWPM_Settings::stored(), 'sharing' => AWPM_Sharing::get(), 'emails' => AWPM_Emails::get(), 'appearance' => AWPM_Design::get() ) );
	}
	public static function decode_backup( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'backup', __( 'Choose a settings JSON backup smaller than 1 MB.', 'affiliate-wpm' ) ); }
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $json ), true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || 'affiliate-wpm-settings' !== ( $data['format'] ?? '' ) || 1 !== ( $data['schema_version'] ?? null ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) || count( $data['settings'] ) !== 4 ) { return new WP_Error( 'backup', __( 'This is not a supported Affiliate WPM settings backup.', 'affiliate-wpm' ) ); }
		foreach ( array( 'program', 'sharing', 'emails', 'appearance' ) as $key ) { if ( ! isset( $data['settings'][ $key ] ) || ! is_array( $data['settings'][ $key ] ) || empty( $data['settings'][ $key ] ) ) { return new WP_Error( 'backup', __( 'The backup is incomplete. No settings were changed.', 'affiliate-wpm' ) ); } }
		return array( 'awpm_settings' => AWPM_Settings::clean( $data['settings']['program'] ), 'awpm_sharing' => AWPM_Sharing::clean( $data['settings']['sharing'] ), 'awpm_emails' => AWPM_Emails::clean( $data['settings']['emails'] ), 'awpm_design' => AWPM_Design::clean( $data['settings']['appearance'] ) );
	}
	public static function defaults() {
		$emails = array( 'admin_email' => get_option( 'admin_email' ), 'from_email' => get_option( 'admin_email' ), 'from_name' => get_bloginfo( 'name' ), 'templates' => array() );
		foreach ( AWPM_Emails::definitions() as $key => $template ) { $emails['templates'][ $key ] = array( 'enabled' => 'yes', 'subject' => $template['subject'], 'body' => $template['body'] ); }
		return array( 'awpm_settings' => AWPM_Settings::defaults(), 'awpm_sharing' => AWPM_Sharing::defaults(), 'awpm_emails' => AWPM_Emails::clean( $emails ), 'awpm_design' => AWPM_Design::defaults() );
	}
	private static function apply( $settings ) { foreach ( $settings as $key => $value ) { update_option( $key, $value, false ); } }
	private static function upload() {
		$file = isset( $_FILES['backup'] ) && is_array( $_FILES['backup'] ) ? $_FILES['backup'] : array();
		$path = AWPM_Security::value( $file, 'tmp_name' );
		if ( '0' !== AWPM_Security::value( $file, 'error', '-1' ) || ! $path || ! is_uploaded_file( $path ) || filesize( $path ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'upload', __( 'Upload a valid JSON settings backup, maximum 1 MB.', 'affiliate-wpm' ) ); }
		return self::decode_backup( file_get_contents( $path ) );
	}
	public static function submit() {
		$operation = AWPM_Security::value( $_POST, 'operation' ); self::guard( $operation );
		if ( 'backup' === $operation ) { self::download_headers( 'settings', 'json', 'application/json' ); echo wp_json_encode( self::backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); exit; }
		if ( 'csv' === $operation ) { do_action( 'awpm_export_data_request' ); wp_die( esc_html__( 'CSV export is available in the separate Affiliate WPM Pro add-on.', 'affiliate-wpm' ), '', array( 'response' => 403 ) ); }
		if ( 'restore' === $operation ) { $result = self::upload(); if ( ! is_wp_error( $result ) ) { self::apply( $result ); $result = __( 'Settings restored successfully.', 'affiliate-wpm' ); } }
		elseif ( 'reset' === $operation ) {
			if ( 'RESET' !== AWPM_Security::value( $_POST, 'confirmation' ) ) { $result = new WP_Error( 'confirm', __( 'Type RESET to confirm resetting plugin settings.', 'affiliate-wpm' ) ); }
			else { self::apply( self::defaults() ); $result = __( 'Plugin settings reset to defaults. Your license and financial records were preserved.', 'affiliate-wpm' ); }
		} else { $result = new WP_Error( 'operation', __( 'Unknown tools action.', 'affiliate-wpm' ) ); }
		set_transient( 'awpm_tools_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $result ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=tools' ) ); exit;
	}


	private static function download_headers( $name, $extension, $mime ) {
		while ( ob_get_level() ) { if ( ! ob_end_clean() ) { break; } }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: ' . $mime . '; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="affiliate-wpm-' . $name . '-' . gmdate( 'Y-m-d' ) . '.' . $extension . '"' );
	}






	private static function form( $operation, $upload = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="awpm_tools"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'awpm_tools' );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$notice = get_transient( 'awpm_tools_notice_' . get_current_user_id() ); if ( is_array( $notice ) ) { delete_transient( 'awpm_tools_notice_' . get_current_user_id() ); AWPM_UI::toast( $notice['message'] ?? '', ! empty( $notice['error'] ) ); }
		if ( current_user_can( 'manage_options' ) ) {
			$disabled = '';
			echo '<div class="awpm-form-grid"><section class="awpm-card"><h2>' . wp_kses( AWPM_UI::icon( 'tools' ), AWPM_UI::allowed_html() ) . esc_html__( 'Back up settings', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Download program, sharing, email and appearance settings as a JSON backup.', 'affiliate-wpm' ) . '</p>'; self::form( 'backup' ); echo '<button class="button awpm-primary">' . esc_html__( 'Download settings backup', 'affiliate-wpm' ) . '</button></form></section>';
			echo '<section class="awpm-card"><h2>' . esc_html__( 'Restore settings', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Upload a settings backup to replace those four settings groups. Maximum size: 1 MB.', 'affiliate-wpm' ) . '</p>'; self::form( 'restore', true ); echo '<div class="awpm-tools-action-row"><div class="awpm-field"><label for="awpm-backup-file">' . esc_html__( 'Settings backup (.json)', 'affiliate-wpm' ) . '</label><input type="file" id="awpm-backup-file" name="backup" accept=".json,application/json" required' . esc_attr( $disabled ) . '></div><button class="button awpm-primary"' . esc_attr( $disabled ) . '>' . esc_html__( 'Restore settings', 'affiliate-wpm' ) . '</button></div></form></section></div>';
			echo '<section class="awpm-card"><h2>' . esc_html__( 'Reset plugin settings', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Restore program, sharing, email and appearance defaults. Your license, affiliate accounts, per-product commission rules and financial history are kept. Download a backup first.', 'affiliate-wpm' ) . '</p>'; self::form( 'reset' ); echo '<div class="awpm-tools-action-row"><div class="awpm-field"><label for="awpm-reset-confirmation">' . esc_html__( 'Type RESET to confirm', 'affiliate-wpm' ) . '</label><input type="text" id="awpm-reset-confirmation" name="confirmation" placeholder="RESET" pattern="RESET" maxlength="5" required autocomplete="off"' . esc_attr( $disabled ) . '></div><button class="button awpm-primary"' . esc_attr( $disabled ) . '>' . esc_html__( 'Reset settings', 'affiliate-wpm' ) . '</button></div></form>';
			if ( $disabled ) { echo '<p class="awpm-muted">' . esc_html__( 'Restore and reset are available in Free.', 'affiliate-wpm' ) . '</p>'; } echo '</section>';
		}
		if ( AWPM_License::active() && has_action( 'awpm_export_data_panel' ) ) { do_action( 'awpm_export_data_panel' ); } else { AWPM_License::premium_card( __( 'Export data', 'affiliate-wpm' ), __( 'CSV exports of affiliates, commissions, withdrawals, customers and referral clicks are provided by the separate Pro add-on.', 'affiliate-wpm' ) ); }
	}
}
