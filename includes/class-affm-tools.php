<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Tools {
	const MAX_BACKUP_SIZE = 1048576;
	public static function init() { add_action( 'admin_post_affm_tools', array( __CLASS__, 'submit' ) ); }
	public static function guard( $operation ) {
		AFFM_Security::admin_request( 'affm_tools' );
		if ( 'csv' !== $operation && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only administrators can manage settings backups.', 'affmint' ), '', array( 'response' => 403 ) ); }

	}
	public static function backup() {
		return array( 'format' => 'affmint-settings', 'schema_version' => 1, 'plugin_version' => AFFM_VERSION, 'created_at' => gmdate( 'c' ), 'settings' => array( 'program' => AFFM_Settings::stored(), 'sharing' => AFFM_Sharing::get(), 'emails' => AFFM_Emails::get(), 'appearance' => AFFM_Design::get() ) );
	}
	public static function decode_backup( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'backup', __( 'Choose a settings JSON backup smaller than 1 MB.', 'affmint' ) ); }
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $json ), true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || 'affmint-settings' !== ( $data['format'] ?? '' ) || 1 !== ( $data['schema_version'] ?? null ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) || count( $data['settings'] ) !== 4 ) { return new WP_Error( 'backup', __( 'This is not a supported AffMint settings backup.', 'affmint' ) ); }
		foreach ( array( 'program', 'sharing', 'emails', 'appearance' ) as $key ) { if ( ! isset( $data['settings'][ $key ] ) || ! is_array( $data['settings'][ $key ] ) || empty( $data['settings'][ $key ] ) ) { return new WP_Error( 'backup', __( 'The backup is incomplete. No settings were changed.', 'affmint' ) ); } }
		return array( 'affm_settings' => AFFM_Settings::clean( $data['settings']['program'] ), 'affm_sharing' => AFFM_Sharing::clean( $data['settings']['sharing'] ), 'affm_emails' => AFFM_Emails::clean( $data['settings']['emails'] ), 'affm_design' => AFFM_Design::clean( $data['settings']['appearance'] ) );
	}
	public static function defaults() {
		$emails = array( 'admin_email' => get_option( 'admin_email' ), 'from_email' => get_option( 'admin_email' ), 'from_name' => get_bloginfo( 'name' ), 'templates' => array() );
		foreach ( AFFM_Emails::definitions() as $key => $template ) { $emails['templates'][ $key ] = array( 'enabled' => 'yes', 'subject' => $template['subject'], 'body' => $template['body'] ); }
		return array( 'affm_settings' => AFFM_Settings::defaults(), 'affm_sharing' => AFFM_Sharing::defaults(), 'affm_emails' => AFFM_Emails::clean( $emails ), 'affm_design' => AFFM_Design::defaults() );
	}
	private static function apply( $settings ) { foreach ( $settings as $key => $value ) { update_option( $key, $value, false ); } }
	private static function upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Private upload helper runs only after guard() verifies POST, capability and affm_tools nonce. Validate the uploaded-file scalar path using is_uploaded_file, size and JSON schema checks; do not alter the OS temporary path.
		$file = isset( $_FILES['backup'] ) && is_array( $_FILES['backup'] ) ? $_FILES['backup'] : array();
		$path = AFFM_Security::value( $file, 'tmp_name' );
		if ( '0' !== AFFM_Security::value( $file, 'error', '-1' ) || ! $path || ! is_uploaded_file( $path ) || filesize( $path ) > self::MAX_BACKUP_SIZE ) { return new WP_Error( 'upload', __( 'Upload a valid JSON settings backup, maximum 1 MB.', 'affmint' ) ); }
		return self::decode_backup( file_get_contents( $path ) );
	}
	public static function submit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
		$operation = AFFM_Security::value( $_POST, 'operation' ); self::guard( $operation );
		if ( 'backup' === $operation ) { self::download_headers( 'settings', 'json', 'application/json' ); echo wp_json_encode( self::backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); exit; }
		if ( 'csv' === $operation ) { do_action( 'affm_export_data_request' ); wp_die( esc_html__( 'CSV export is available in the separate AffMint Pro add-on.', 'affmint' ), '', array( 'response' => 403 ) ); }
		if ( 'restore' === $operation ) { $result = self::upload(); if ( ! is_wp_error( $result ) ) { self::apply( $result ); $result = __( 'Settings restored successfully.', 'affmint' ); } }
		elseif ( 'reset' === $operation ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes.
			if ( 'RESET' !== AFFM_Security::value( $_POST, 'confirmation' ) ) { $result = new WP_Error( 'confirm', __( 'Type RESET to confirm resetting plugin settings.', 'affmint' ) ); }
			else { self::apply( self::defaults() ); $result = __( 'Plugin settings reset to defaults. Your license and financial records were preserved.', 'affmint' ); }
		} else { $result = new WP_Error( 'operation', __( 'Unknown tools action.', 'affmint' ) ); }
		set_transient( 'affm_tools_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $result ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=affmint&tab=tools' ) ); exit;
	}


	private static function download_headers( $name, $extension, $mime ) {
		while ( ob_get_level() ) { if ( ! ob_end_clean() ) { break; } }
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: ' . $mime . '; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="affmint-' . $name . '-' . gmdate( 'Y-m-d' ) . '.' . $extension . '"' );
	}






	private static function form( $operation, $upload = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="affm_tools"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'; wp_nonce_field( 'affm_tools' );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$notice = get_transient( 'affm_tools_notice_' . get_current_user_id() ); if ( is_array( $notice ) ) { delete_transient( 'affm_tools_notice_' . get_current_user_id() ); AFFM_UI::toast( $notice['message'] ?? '', ! empty( $notice['error'] ) ); }
		if ( current_user_can( 'manage_options' ) ) {
			$disabled = '';
			echo '<div class="affm-form-grid"><section class="affm-card"><h2>' . wp_kses( AFFM_UI::icon( 'tools' ), AFFM_UI::allowed_html() ) . esc_html__( 'Back up settings', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Download program, sharing, email and appearance settings as a JSON backup.', 'affmint' ) . '</p>'; self::form( 'backup' ); echo '<button class="button affm-primary">' . esc_html__( 'Download settings backup', 'affmint' ) . '</button></form></section>';
			echo '<section class="affm-card"><h2>' . esc_html__( 'Restore settings', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Upload a settings backup to replace those four settings groups. Maximum size: 1 MB.', 'affmint' ) . '</p>'; self::form( 'restore', true ); echo '<div class="affm-tools-action-row"><div class="affm-field"><label for="affm-backup-file">' . esc_html__( 'Settings backup (.json)', 'affmint' ) . '</label><input type="file" id="affm-backup-file" name="backup" accept=".json,application/json" required' . esc_attr( $disabled ) . '></div><button class="button affm-primary"' . esc_attr( $disabled ) . '>' . esc_html__( 'Restore settings', 'affmint' ) . '</button></div></form></section></div>';
			echo '<section class="affm-card"><h2>' . esc_html__( 'Reset plugin settings', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Restore program, sharing, email and appearance defaults. Your license, affiliate accounts, per-product commission rules and financial history are kept. Download a backup first.', 'affmint' ) . '</p>'; self::form( 'reset' ); echo '<div class="affm-tools-action-row"><div class="affm-field"><label for="affm-reset-confirmation">' . esc_html__( 'Type RESET to confirm', 'affmint' ) . '</label><input type="text" id="affm-reset-confirmation" name="confirmation" placeholder="RESET" pattern="RESET" maxlength="5" required autocomplete="off"' . esc_attr( $disabled ) . '></div><button class="button affm-primary"' . esc_attr( $disabled ) . '>' . esc_html__( 'Reset settings', 'affmint' ) . '</button></div></form>';
			if ( $disabled ) { echo '<p class="affm-muted">' . esc_html__( 'Restore and reset are available in Free.', 'affmint' ) . '</p>'; } echo '</section>';
		}
		if ( AFFM_License::active() && has_action( 'affm_export_data_panel' ) ) { do_action( 'affm_export_data_panel' ); } else { AFFM_License::premium_card( __( 'Export data', 'affmint' ), __( 'CSV exports of affiliates, commissions, withdrawals, customers and referral clicks are provided by the separate Pro add-on.', 'affmint' ) ); }
	}
}
