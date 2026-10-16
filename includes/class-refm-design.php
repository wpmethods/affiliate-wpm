<?php
defined( 'ABSPATH' ) || exit;

final class REFM_Design {
	public static function defaults() { return array( 'accent' => '#0066ff', 'ink' => '#172b38', 'muted' => '#627380', 'border' => '#dfe7e8', 'surface' => '#ffffff', 'highlight' => '#eef5ff', 'radius' => 12, 'css' => '' ); }
	public static function get() { return wp_parse_args( get_option( 'refm_design', array() ), self::defaults() ); }
	public static function init() { add_action( 'admin_post_refm_save_design', array( __CLASS__, 'save' ) ); }
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults(); $clean = $defaults;
		foreach ( array( 'accent', 'ink', 'muted', 'border', 'surface', 'highlight' ) as $key ) { $clean[ $key ] = sanitize_hex_color( REFM_Security::value( $input, $key ) ) ?: $defaults[ $key ]; }
		$clean['radius'] = min( 24, max( 0, (int) REFM_Security::value( $input, 'radius', '12' ) ) );
		$clean['css'] = current_user_can( 'unfiltered_html' ) ? substr( wp_strip_all_tags( REFM_Security::value( $input, 'css' ) ), 0, 20000 ) : self::get()['css'];
		return $clean;
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'refmora' ), '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) { wp_die( esc_html__( 'Use the settings form to make changes.', 'refmora' ), '', array( 'response' => 405 ) ); }
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) { wp_die( esc_html__( 'Your session expired. Refresh and try again.', 'refmora' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'refm_design' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check_admin_referer() and capabilities are checked before input is passed directly to its typed schema sanitizer. The dedicated clean() schema sanitizes each permitted setting using typed, text, HTML, email, URL or credential validation; blanket text sanitization would corrupt structured settings.
		$sanitized = self::clean( isset( $_POST['refm_design'] ) && is_array( $_POST['refm_design'] ) ? wp_unslash( $_POST['refm_design'] ) : array() );
		update_option( 'refm_design', $sanitized, false );
		wp_safe_redirect( admin_url( 'admin.php?page=refmora&tab=customization&saved=1' ) ); exit;
	}
	public static function enqueue( $handle ) {
		$design = self::get(); $css = '.refm{';
		foreach ( array( 'accent', 'ink', 'muted', 'border', 'surface', 'highlight' ) as $key ) { $css .= '--refm-' . $key . ':' . ( sanitize_hex_color( $design[ $key ] ) ?: self::defaults()[ $key ] ) . ';'; }
		$css .= '--refm-radius:' . min( 24, max( 0, (int) $design['radius'] ) ) . 'px;}';
		$css .= '.refm .refm-card,.refm .refm-metric{background:var(--refm-surface);border-radius:var(--refm-radius)}.refm .button-primary{background:var(--refm-accent);border-color:var(--refm-accent);color:#fff}';
		wp_add_inline_style( $handle, $css . "\n" . wp_strip_all_tags( $design['css'] ) );
	}
	public static function render() {
		$design = self::get();
		echo '<form class="refm-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="refm_save_design">'; wp_nonce_field( 'refm_design' );
		echo '<section class="refm-card"><h2>' . esc_html__( 'Dashboard appearance', 'refmora' ) . '</h2><p>' . esc_html__( 'These colors apply to the admin panel, Affiliate Area, and product sharing section.', 'refmora' ) . '</p><div class="refm-form-grid">';
		foreach ( array( 'accent' => 'Accent / buttons', 'ink' => 'Text', 'muted' => 'Secondary text', 'border' => 'Borders', 'surface' => 'Card background', 'highlight' => 'Highlighted metric background' ) as $key => $label ) {
			echo '<div class="refm-field"><label for="refm-design-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="refm-design-' . esc_attr( $key ) . '" type="color" name="refm_design[' . esc_attr( $key ) . ']" value="' . esc_attr( $design[ $key ] ) . '"></div>';
		}
		echo '<div class="refm-field"><label for="refm-radius">' . esc_html__( 'Card corner radius (px)', 'refmora' ) . '</label><input id="refm-radius" type="number" min="0" max="24" name="refm_design[radius]" value="' . esc_attr( $design['radius'] ) . '"></div></div><div class="refm-design-preview"><div><small>' . esc_html__( 'Your dashboard preview', 'refmora' ) . '</small><strong>' . esc_html__( 'Available earnings', 'refmora' ) . '</strong></div><span class="refm-design-sample">' . esc_html__( 'Your accent color', 'refmora' ) . '</span></div></section><section class="refm-card"><h2>' . esc_html__( 'Custom CSS', 'refmora' ) . '</h2><p class="refm-muted">' . esc_html__( 'Scope selectors with .refm to target plugin elements. CSS loads on the plugin admin panel, Affiliate Area, and enabled product sharing pages. Editing CSS requires the WordPress unfiltered_html capability.', 'refmora' ) . '</p><label class="screen-reader-text" for="refm-css">' . esc_html__( 'Custom CSS', 'refmora' ) . '</label><textarea id="refm-css" class="refm-template-body refm-code" name="refm_design[css]" rows="12" maxlength="20000"' . disabled( ! current_user_can( 'unfiltered_html' ), true, false ) . '>' . esc_textarea( $design['css'] ) . '</textarea></section>';
		REFM_UI::footer( __( 'Save customization', 'refmora' ) ); echo '</form>';
	}
}
