<?php
/**
 * Plugin Name: Affiliate WPM
 * Plugin URI: https://wpmethods.com/
 * Description: Lightweight WooCommerce affiliate tracking, product commissions, and manual withdrawals.
 * Version: 1.0.0
 * Author: WP Methods
 * Author URI: https://wpmethods.com/
 * Text Domain: affiliate-wpm
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 */
defined( 'ABSPATH' ) || exit;
define( 'AWPM_VERSION', '1.0.0' );
define( 'AWPM_FILE', __FILE__ );
define( 'AWPM_PATH', plugin_dir_path( __FILE__ ) );
define( 'AWPM_URL', plugin_dir_url( __FILE__ ) );
require_once AWPM_PATH . 'includes/class-awpm-store.php';
require_once AWPM_PATH . 'includes/class-awpm-security.php';
require_once AWPM_PATH . 'includes/class-awpm-ui.php';
require_once AWPM_PATH . 'includes/class-awpm-settings.php';
require_once AWPM_PATH . 'includes/class-awpm-currency.php';
require_once AWPM_PATH . 'includes/class-awpm-license.php';
require_once AWPM_PATH . 'includes/class-awpm-updates.php';
AWPM_License::init();
AWPM_Updates::init();
add_action( 'admin_post_awpm_currency_recovery', array( 'AWPM_Currency', 'recover' ) );
require_once AWPM_PATH . 'includes/class-awpm-emails.php';
require_once AWPM_PATH . 'includes/class-awpm-affiliates.php';
require_once AWPM_PATH . 'includes/class-awpm-design.php';
require_once AWPM_PATH . 'includes/class-awpm-tracking.php';
require_once AWPM_PATH . 'includes/class-awpm-commissions.php';
require_once AWPM_PATH . 'includes/class-awpm-payouts.php';
require_once AWPM_PATH . 'includes/class-awpm-account.php';
require_once AWPM_PATH . 'includes/class-awpm-sharing.php';
require_once AWPM_PATH . 'includes/class-awpm-admin.php';
require_once AWPM_PATH . 'includes/class-awpm-tools.php';
register_activation_hook( __FILE__, function () {
	AWPM_Store::install();
	AWPM_Account::endpoint();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( AWPM_License::CRON ); AWPM_Updates::clear(); flush_rewrite_rules(); } );
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AWPM_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AWPM_FILE, true );
	}
} );
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Affiliate WPM requires WooCommerce to be active.', 'affiliate-wpm' ) . '</p></div>';
		} );
		return;
	}
	if ( get_option( 'awpm_db_version' ) !== AWPM_VERSION ) {
		AWPM_Store::install();
	}
	AWPM_Settings::init();
	AWPM_Emails::init();
	AWPM_Design::init();
	AWPM_Tracking::init();
	AWPM_Commissions::init();
	AWPM_Account::init();
	AWPM_Sharing::init();
	AWPM_Admin::init();
	AWPM_Tools::init();
} );
