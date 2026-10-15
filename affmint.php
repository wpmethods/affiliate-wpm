<?php
/**
 * Plugin Name: AffMint
 * Plugin URI: https://wpmethods.com/product/affiliatewpm-best-affiliate-plugin-for-woocommerce/
 * Description: WooCommerce referral tracking, product commissions and manual affiliate withdrawals. Optional Pro add-on.
 * Version: 1.0.0
 * Author: WP Methods
 * Author URI: https://wpmethods.com/
 * Text Domain: affmint
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 */
defined( 'ABSPATH' ) || exit;
define( 'AFFM_VERSION', '1.0.0' );
define( 'AFFM_FILE', __FILE__ );
define( 'AFFM_PATH', plugin_dir_path( __FILE__ ) );
define( 'AFFM_URL', plugin_dir_url( __FILE__ ) );
require_once AFFM_PATH . 'includes/class-affm-store.php';
require_once AFFM_PATH . 'includes/class-affm-security.php';
require_once AFFM_PATH . 'includes/class-affm-ui.php';
require_once AFFM_PATH . 'includes/class-affm-settings.php';
require_once AFFM_PATH . 'includes/class-affm-currency.php';
require_once AFFM_PATH . 'includes/class-affm-license.php';
require_once AFFM_PATH . 'includes/class-affm-updates.php';
require_once AFFM_PATH . 'includes/class-affm-cache.php';
AFFM_Cache::init();
AFFM_License::init();
AFFM_Updates::init();
add_action( 'admin_post_affm_currency_recovery', array( 'AFFM_Currency', 'recover' ) );
require_once AFFM_PATH . 'includes/class-affm-emails.php';
require_once AFFM_PATH . 'includes/class-affm-affiliates.php';
require_once AFFM_PATH . 'includes/class-affm-design.php';
require_once AFFM_PATH . 'includes/class-affm-tracking.php';
require_once AFFM_PATH . 'includes/class-affm-commissions.php';
require_once AFFM_PATH . 'includes/class-affm-payouts.php';
require_once AFFM_PATH . 'includes/class-affm-gateways.php';
require_once AFFM_PATH . 'includes/class-affm-stripe.php';
require_once AFFM_PATH . 'includes/class-affm-account.php';
require_once AFFM_PATH . 'includes/class-affm-sharing.php';
require_once AFFM_PATH . 'includes/class-affm-admin.php';
require_once AFFM_PATH . 'includes/class-affm-tools.php';
require_once AFFM_PATH . 'includes/class-affm-checkout.php';
register_activation_hook( __FILE__, function () {
	AFFM_Store::install();
	AFFM_Account::endpoint();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( AFFM_License::CRON ); AFFM_Updates::clear(); flush_rewrite_rules(); } );
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AFFM_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AFFM_FILE, true );
	}
} );
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'AffMint requires WooCommerce to be active.', 'affmint' ) . '</p></div>';
		} );
		return;
	}
	if ( get_option( 'affm_db_version' ) !== AFFM_VERSION ) {
		AFFM_Store::install();
	}
	AFFM_Settings::init();
	AFFM_Emails::init();
	AFFM_Design::init();
	AFFM_Tracking::init();
	AFFM_Checkout::init();
	AFFM_Commissions::init();
	AFFM_Gateways::init();
	AFFM_Account::init();
	AFFM_Sharing::init();
	AFFM_Admin::init();
	AFFM_Tools::init();
} );
