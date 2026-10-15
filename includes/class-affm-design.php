<?php
defined( 'ABSPATH' ) || exit;

final class AFFM_Design {
	public static function defaults() { return array( 'accent' => '#0066ff', 'ink' => '#172b38', 'muted' => '#627380', 'border' => '#dfe7e8', 'surface' => '#ffffff', 'highlight' => '#eef5ff', 'radius' => 12, 'css' => '' ); }
	public static function get() { return wp_parse_args( get_option( 'affm_design', array() ), self::defaults() ); }
	public static function init() { add_action( 'admin_post_affm_save_design', array( __CLASS__, 'save' ) ); }
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults(); $clean = $defaults;
		foreach ( array( 'accent', 'ink', 'muted', 'border', 'surface', 'highlight' ) as $key ) { $clean[ $key ] = sanitize_hex_color( AFFM_Security::value( $input, $key ) ) ?: $defaults[ $key ]; }
		$clean['radius'] = min( 24, max( 0, (int) AFFM_Security::value( $input, 'radius', '12' ) ) );
		$clean['css'] = current_user_can( 'unfiltered_html' ) ? substr( wp_strip_all_tags( AFFM_Security::value( $input, 'css' ) ), 0, 20000 ) : self::get()['css'];
		return $clean;
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affmint' ), 403 ); }
		AFFM_Security::admin_request( 'affm_design' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AFFM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes. The dedicated clean() schema sanitizes each permitted setting using typed, text, HTML, email, URL or credential validation; blanket text sanitization would corrupt structured settings.
		update_option( 'affm_design', self::clean( isset( $_POST['affm_design'] ) && is_array( $_POST['affm_design'] ) ? wp_unslash( $_POST['affm_design'] ) : array() ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=affmint&tab=customization&saved=1' ) ); exit;
	}
	public static function enqueue( $handle ) {
		$design = self::get(); $css = '.affm{';
		foreach ( array( 'accent', 'ink', 'muted', 'border', 'surface', 'highlight' ) as $key ) { $css .= '--affm-' . $key . ':' . ( sanitize_hex_color( $design[ $key ] ) ?: self::defaults()[ $key ] ) . ';'; }
		$css .= '--affm-radius:' . min( 24, max( 0, (int) $design['radius'] ) ) . 'px;}';
		$css .= '.affm .affm-card,.affm .affm-metric{background:var(--affm-surface);border-radius:var(--affm-radius)}.affm .button-primary{background:var(--affm-accent);border-color:var(--affm-accent);color:#fff}';
		wp_add_inline_style( $handle, $css . "\n" . wp_strip_all_tags( $design['css'] ) );
	}
	public static function render() {
		$design = self::get();
		echo '<form class="affm-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="affm_save_design">'; wp_nonce_field( 'affm_design' );
		echo '<section class="affm-card"><h2>' . esc_html__( 'Dashboard appearance', 'affmint' ) . '</h2><p>' . esc_html__( 'These colors apply to the admin panel, Affiliate Area, and product sharing section.', 'affmint' ) . '</p><div class="affm-form-grid">';
		foreach ( array( 'accent' => 'Accent / buttons', 'ink' => 'Text', 'muted' => 'Secondary text', 'border' => 'Borders', 'surface' => 'Card background', 'highlight' => 'Highlighted metric background' ) as $key => $label ) {
			echo '<div class="affm-field"><label for="affm-design-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="affm-design-' . esc_attr( $key ) . '" type="color" name="affm_design[' . esc_attr( $key ) . ']" value="' . esc_attr( $design[ $key ] ) . '"></div>';
		}
		echo '<div class="affm-field"><label for="affm-radius">' . esc_html__( 'Card corner radius (px)', 'affmint' ) . '</label><input id="affm-radius" type="number" min="0" max="24" name="affm_design[radius]" value="' . esc_attr( $design['radius'] ) . '"></div></div><div class="affm-design-preview"><div><small>' . esc_html__( 'Your dashboard preview', 'affmint' ) . '</small><strong>' . esc_html__( 'Available earnings', 'affmint' ) . '</strong></div><span class="affm-design-sample">' . esc_html__( 'Your accent color', 'affmint' ) . '</span></div></section><section class="affm-card"><h2>' . esc_html__( 'Custom CSS', 'affmint' ) . '</h2><p class="affm-muted">' . esc_html__( 'Scope selectors with .affm to target plugin elements. CSS loads on the plugin admin panel, Affiliate Area, and enabled product sharing pages. Editing CSS requires the WordPress unfiltered_html capability.', 'affmint' ) . '</p><label class="screen-reader-text" for="affm-css">' . esc_html__( 'Custom CSS', 'affmint' ) . '</label><textarea id="affm-css" class="affm-template-body affm-code" name="affm_design[css]" rows="12" maxlength="20000"' . disabled( ! current_user_can( 'unfiltered_html' ), true, false ) . '>' . esc_textarea( $design['css'] ) . '</textarea></section>';
		AFFM_UI::footer( __( 'Save customization', 'affmint' ) ); echo '</form>';
	}
}
