<?php
defined( 'ABSPATH' ) || exit;

final class AWPM_Design {
	public static function defaults() { return array( 'accent' => '#0066ff', 'ink' => '#172b38', 'muted' => '#627380', 'border' => '#dfe7e8', 'surface' => '#ffffff', 'highlight' => '#eef5ff', 'radius' => 12, 'css' => '' ); }
	public static function get() { return wp_parse_args( get_option( 'awpm_design', array() ), self::defaults() ); }
	public static function init() { add_action( 'admin_post_awpm_save_design', array( __CLASS__, 'save' ) ); }
	public static function clean( $input ) {
		$input = is_array( $input ) ? $input : array();
		$defaults = self::defaults(); $clean = $defaults;
		foreach ( array( 'accent', 'ink', 'muted', 'border', 'surface', 'highlight' ) as $key ) { $clean[ $key ] = sanitize_hex_color( AWPM_Security::value( $input, $key ) ) ?: $defaults[ $key ]; }
		$clean['radius'] = min( 24, max( 0, (int) AWPM_Security::value( $input, 'radius', '12' ) ) );
		$clean['css'] = current_user_can( 'unfiltered_html' ) ? substr( wp_strip_all_tags( AWPM_Security::value( $input, 'css' ) ), 0, 20000 ) : self::get()['css'];
		return $clean;
	}
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Access denied.', 'affiliate-wpm' ), 403 ); }
		AWPM_Security::admin_request( 'awpm_design' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AWPM_Security::admin_request() (or Tools guard()) enforces POST, capability and action-specific nonce before changes. The dedicated clean() schema sanitizes each permitted setting using typed, text, HTML, email, URL or credential validation; blanket text sanitization would corrupt structured settings.
		update_option( 'awpm_design', self::clean( isset( $_POST['awpm_design'] ) && is_array( $_POST['awpm_design'] ) ? wp_unslash( $_POST['awpm_design'] ) : array() ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=affiliate-wpm&tab=customization&saved=1' ) ); exit;
	}
	public static function enqueue( $handle ) {
		$design = self::get(); $css = '.awpm{';
		foreach ( array( 'accent', 'ink', 'muted', 'border', 'surface', 'highlight' ) as $key ) { $css .= '--awpm-' . $key . ':' . ( sanitize_hex_color( $design[ $key ] ) ?: self::defaults()[ $key ] ) . ';'; }
		$css .= '--awpm-radius:' . min( 24, max( 0, (int) $design['radius'] ) ) . 'px;}';
		$css .= '.awpm .awpm-card,.awpm .awpm-metric{background:var(--awpm-surface);border-radius:var(--awpm-radius)}.awpm .button-primary{background:var(--awpm-accent);border-color:var(--awpm-accent);color:#fff}';
		wp_add_inline_style( $handle, $css . "\n" . wp_strip_all_tags( $design['css'] ) );
	}
	public static function render() {
		$design = self::get();
		echo '<form class="awpm-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="awpm_save_design">'; wp_nonce_field( 'awpm_design' );
		echo '<section class="awpm-card"><h2>' . esc_html__( 'Dashboard appearance', 'affiliate-wpm' ) . '</h2><p>' . esc_html__( 'These colors apply to the admin panel, Affiliate Area, and product sharing section.', 'affiliate-wpm' ) . '</p><div class="awpm-form-grid">';
		foreach ( array( 'accent' => 'Accent / buttons', 'ink' => 'Text', 'muted' => 'Secondary text', 'border' => 'Borders', 'surface' => 'Card background', 'highlight' => 'Highlighted metric background' ) as $key => $label ) {
			echo '<div class="awpm-field"><label for="awpm-design-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="awpm-design-' . esc_attr( $key ) . '" type="color" name="awpm_design[' . esc_attr( $key ) . ']" value="' . esc_attr( $design[ $key ] ) . '"></div>';
		}
		echo '<div class="awpm-field"><label for="awpm-radius">' . esc_html__( 'Card corner radius (px)', 'affiliate-wpm' ) . '</label><input id="awpm-radius" type="number" min="0" max="24" name="awpm_design[radius]" value="' . esc_attr( $design['radius'] ) . '"></div></div><div class="awpm-design-preview"><div><small>' . esc_html__( 'Your dashboard preview', 'affiliate-wpm' ) . '</small><strong>' . esc_html__( 'Available earnings', 'affiliate-wpm' ) . '</strong></div><span class="awpm-design-sample">' . esc_html__( 'Your accent color', 'affiliate-wpm' ) . '</span></div></section><section class="awpm-card"><h2>' . esc_html__( 'Custom CSS', 'affiliate-wpm' ) . '</h2><p class="awpm-muted">' . esc_html__( 'Scope selectors with .awpm to target plugin elements. CSS loads on the plugin admin panel, Affiliate Area, and enabled product sharing pages. Editing CSS requires the WordPress unfiltered_html capability.', 'affiliate-wpm' ) . '</p><label class="screen-reader-text" for="awpm-css">' . esc_html__( 'Custom CSS', 'affiliate-wpm' ) . '</label><textarea id="awpm-css" class="awpm-template-body awpm-code" name="awpm_design[css]" rows="12" maxlength="20000"' . disabled( ! current_user_can( 'unfiltered_html' ), true, false ) . '>' . esc_textarea( $design['css'] ) . '</textarea></section>';
		AWPM_UI::footer( __( 'Save customization', 'affiliate-wpm' ) ); echo '</form>';
	}
}
