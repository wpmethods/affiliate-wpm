<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Emails {
	private static $queue = array();
	public static function definitions() {
		return array(
			'joined_admin' => array( 'label' => 'Admin: new affiliate', 'audience' => 'admin', 'subject' => '[{site_name}] New affiliate: {affiliate_name}', 'body' => "An affiliate has joined.\nName: {affiliate_name}\nStatus: {status}\nReview: {admin_url}" ),
			'joined_affiliate' => array( 'label' => 'Affiliate: join confirmation', 'audience' => 'affiliate', 'subject' => 'Your {site_name} affiliate account', 'body' => "Hello {affiliate_name},\nYour affiliate account status is {status}. You can follow your application and access available program features from your dashboard.\nDashboard: {dashboard_url}" ),
			'approved_affiliate' => array( 'label' => 'Affiliate: application approved', 'audience' => 'affiliate', 'subject' => 'Your affiliate application is approved', 'body' => "Hello {affiliate_name},\nYour affiliate application is approved. You can now share your referral link.\nDashboard: {dashboard_url}" ),
			'rejected_affiliate' => array( 'label' => 'Affiliate: application rejected', 'audience' => 'affiliate', 'subject' => 'Your affiliate application update', 'body' => "Hello {affiliate_name},\nYour affiliate application was rejected. Contact the store if you have questions.\nDashboard: {dashboard_url}" ),
			'withdrawal_admin' => array( 'label' => 'Admin: new withdrawal', 'audience' => 'admin', 'subject' => '[{site_name}] Withdrawal #{payout_id}', 'body' => "{affiliate_name} requested {amount} via {method}.\nRequest: #{payout_id}\nReview: {admin_url}" ),
			'withdrawal_affiliate' => array( 'label' => 'Affiliate: withdrawal requested', 'audience' => 'affiliate', 'subject' => 'Your withdrawal #{payout_id} was received', 'body' => "Hello {affiliate_name},\nWe received your withdrawal request for {amount} via {method}.\nStatus: {status}\nDashboard: {dashboard_url}" ),
			'payout_affiliate' => array( 'label' => 'Affiliate: withdrawal status changed', 'audience' => 'affiliate', 'subject' => 'Withdrawal #{payout_id}: {status}', 'body' => "Hello {affiliate_name},\nYour withdrawal #{payout_id} for {amount} is now {status}.\nNote: {note}\nDashboard: {dashboard_url}" ),
			'payout_admin' => array( 'label' => 'Admin: withdrawal status changed', 'audience' => 'admin', 'subject' => '[{site_name}] Withdrawal #{payout_id}: {status}', 'body' => "Withdrawal #{payout_id} for {affiliate_name} ({amount}) is now {status}.\nNote: {note}\nReview: {admin_url}" ),
			'commission_affiliate' => array( 'label' => 'Affiliate: commission available', 'audience' => 'affiliate', 'subject' => 'New affiliate earnings: {amount}', 'body' => "Hello {affiliate_name},\nYour commission for order #{order_id} is now available: {amount}.\nDashboard: {dashboard_url}" ),
		);
	}
	public static function get() {
		$saved = get_option( 'affm_emails', array() );
		$config = array( 'admin_email' => get_option( 'admin_email' ), 'from_email' => get_option( 'admin_email' ), 'from_name' => get_bloginfo( 'name' ), 'templates' => array() );
		$config = wp_parse_args( $saved, $config );
		foreach ( self::definitions() as $key => $definition ) { $config['templates'][ $key ] = wp_parse_args( $saved['templates'][ $key ] ?? array(), array( 'enabled' => 'yes', 'subject' => $definition['subject'], 'body' => $definition['body'] ) ); }
		return $config;
	}
	public static function init() { add_action( 'admin_post_affm_save_emails', array( __CLASS__, 'save' ) ); }
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$config = array( 'admin_email' => '', 'from_email' => '', 'from_name' => substr( sanitize_text_field( AFFM_Security::value( $input, 'from_name', get_bloginfo( 'name' ) ) ), 0, 120 ), 'templates' => array() );
		foreach ( array( 'admin_email', 'from_email' ) as $key ) { $email = sanitize_email( AFFM_Security::value( $input, $key ) ); $config[ $key ] = is_email( $email ) ? $email : get_option( 'admin_email' ); }
		foreach ( self::definitions() as $key => $definition ) {
			$row = AFFM_Security::rows( AFFM_Security::rows( $input, 'templates' ), $key );
			$config['templates'][ $key ] = array( 'enabled' => 'yes' === AFFM_Security::value( $row, 'enabled' ) ? 'yes' : 'no', 'subject' => substr( sanitize_text_field( AFFM_Security::value( $row, 'subject', $definition['subject'] ) ), 0, 200 ), 'body' => substr( sanitize_textarea_field( AFFM_Security::value( $row, 'body', $definition['body'] ) ), 0, 10000 ) );
		}
		return $config;
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affmint' ), 403 ); }
		AFFM_Security::admin_request( 'affm_emails' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes. The dedicated clean() schema sanitizes each permitted setting using typed, text, HTML, email, URL or credential validation; blanket text sanitization would corrupt structured settings.
		update_option( 'affm_emails', self::clean( isset( $_POST['affm_emails'] ) && is_array( $_POST['affm_emails'] ) ? wp_unslash( $_POST['affm_emails'] ) : array() ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=affmint&tab=emails&saved=1' ) ); exit;
	}
	public static function queue( $event, $id, $context = array() ) { self::$queue[] = array( $event, $id, $context ); }
	public static function finish( $committed ) {
		$events = self::$queue; self::$queue = array();
		if ( $committed ) { foreach ( $events as $event ) { self::send( $event[0], $event[1], $event[2] ); } }
	}
	public static function send( $event, $id, $context = array() ) {
		$definitions = self::definitions(); $user = get_userdata( $id );
		if ( ! isset( $definitions[ $event ] ) || ! $user ) { return false; }
		$config = self::get(); $template = $config['templates'][ $event ];
		if ( 'yes' !== $template['enabled'] ) { return false; }
		$recipient = 'admin' === $definitions[ $event ]['audience'] ? $config['admin_email'] : $user->user_email;
		if ( ! is_email( $recipient ) ) { return false; }
		$values = array( 'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'affiliate_name' => $user->first_name ?: $user->display_name, 'status' => '', 'amount' => '', 'method' => '', 'note' => '', 'payout_id' => '', 'order_id' => '', 'dashboard_url' => wc_get_account_endpoint_url( 'affmint' ), 'admin_url' => admin_url( 'admin.php?page=affmint&tab=' . ( strpos( $event, 'joined' ) === 0 ? 'affiliates' : 'payouts' ) ) );
		$values = array_merge( $values, array_intersect_key( $context, $values ) );
		$replace = array();
		foreach ( $values as $key => $value ) { $replace[ '{' . $key . '}' ] = wp_strip_all_tags( (string) $value ); }
		$subject = sanitize_text_field( strtr( $template['subject'], $replace ) );
		$body = strtr( $template['body'], $replace );
		$from_name = str_replace( array( '<', '>', '"', "\r", "\n" ), '', $config['from_name'] );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8', 'From: ' . $from_name . ' <' . sanitize_email( $config['from_email'] ) . '>' );
		try { return wp_mail( $recipient, $subject, $body, $headers ); }
		catch ( Throwable $error ) { return false; }
	}
	public static function payout( $event, $row ) {
		self::queue( $event, $row->affiliate_id, array( 'payout_id' => $row->id, 'amount' => $row->currency . ' ' . wc_format_decimal( $row->amount, AFFM_Currency::precision( $row->currency ) ), 'method' => $row->method, 'status' => $row->status, 'note' => $row->note ) );
	}
	public static function render() {
		$config = self::get();
		echo '<form class="affm-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="affm_save_emails">'; wp_nonce_field( 'affm_emails' );
		echo '<section class="affm-card"><h2>' . esc_html__( 'Email delivery', 'affmint' ) . '</h2><p>' . esc_html__( 'Messages use WordPress wp_mail. Configure your site mail service or SMTP plugin for reliable delivery. Templates are plain text. Payment destination details are never included.', 'affmint' ) . '</p><div class="affm-form-grid">';
		foreach ( array( 'admin_email' => 'Admin notification email', 'from_email' => 'Sender email', 'from_name' => 'Sender name' ) as $key => $label ) {
			echo '<div class="affm-field"><label for="affm-email-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="affm-email-' . esc_attr( $key ) . '" type="' . ( 'from_name' === $key ? 'text' : 'email' ) . '" name="affm_emails[' . esc_attr( $key ) . ']" value="' . esc_attr( $config[ $key ] ) . '" required></div>';
		}
		echo '</div></section><p class="affm-muted">' . esc_html__( 'Placeholders: {site_name}, {affiliate_name}, {status}, {amount}, {method}, {note}, {payout_id}, {order_id}, {dashboard_url}, {admin_url}. Event-specific values are empty for other events.', 'affmint' ) . '</p>';
		foreach ( self::definitions() as $key => $definition ) {
			$row = $config['templates'][ $key ]; $prefix = 'affm_emails[templates][' . $key . ']';
			echo '<details class="affm-card affm-email-template"' . ( 'joined_admin' === $key ? ' open' : '' ) . '><summary>' . wp_kses( AFFM_UI::icon( 'emails' ), AFFM_UI::allowed_html() ) . esc_html( $definition['label'] ) . '</summary><div class="affm-template-editor"><label class="affm-toggle"><input type="checkbox" name="' . esc_attr( $prefix . '[enabled]' ) . '" value="yes"' . checked( $row['enabled'], 'yes', false ) . '> ' . esc_html__( 'Enable this email', 'affmint' ) . '</label><div class="affm-field"><label for="affm-subject-' . esc_attr( $key ) . '">' . esc_html__( 'Subject', 'affmint' ) . '</label><input id="affm-subject-' . esc_attr( $key ) . '" name="' . esc_attr( $prefix . '[subject]' ) . '" value="' . esc_attr( $row['subject'] ) . '" maxlength="200"></div><label for="affm-body-' . esc_attr( $key ) . '">' . esc_html__( 'Message', 'affmint' ) . '</label><textarea class="affm-template-body" id="affm-body-' . esc_attr( $key ) . '" name="' . esc_attr( $prefix . '[body]' ) . '" rows="6" maxlength="10000">' . esc_textarea( $row['body'] ) . '</textarea></div></details>';
		}
		AFFM_UI::footer( __( 'Save email templates', 'affmint' ) ); echo '</form>';
	}
}
