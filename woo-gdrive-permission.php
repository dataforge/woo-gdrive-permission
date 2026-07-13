<?php
/**
 * Plugin Name: Woo GDrive Permission
 * Plugin URI:  https://github.com/dataforge/woo-gdrive-permission
 * Description: Per-recipient entitlement system with OTP verification for granting GDrive viewer access on WooCommerce purchases.
 * Version: 3.4.91
 * Author: DataForge
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 * License: GPL-2.0-or-later
 * Update URI:  https://github.com/dataforge/woo-gdrive-permission
 */

defined( 'ABSPATH' ) || exit;

define( 'WGDP_VERSION',          '3.4.91' );
define( 'WGDP_PLUGIN_FILE',      __FILE__ );
define( 'WGDP_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
define( 'WGDP_PLUGIN_PATH',      plugin_dir_path( __FILE__ ) );
define( 'WGDP_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );

require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-updater.php';
WGDP_Updater::init();

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Register Block Checkout integration.
 */
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
		return;
	}
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-blocks-integration.php';
	add_action( 'woocommerce_blocks_checkout_block_registration', function ( $integration_registry ) {
		$integration_registry->register( new WGDP_Blocks_Integration() );
	} );
} );

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Woo GDrive Permission</strong> requires Woo to be installed and active.</p></div>';
		} );
		return;
	}

	// Core classes (order matters for dependencies).
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-db.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-google-auth.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-google-drive.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-entitlements.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-otp.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-notification-email.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-product-meta.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-release-gate.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-order-handler.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-classic-checkout.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-claim-page.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-self-service.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-cron.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-access-manager-table.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-entitlements-list.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-dashboard-widget.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-shortcodes.php';
	require_once WGDP_PLUGIN_PATH . 'includes/class-wgdp-admin.php';

	// DB upgrade check.
	WGDP_DB::maybe_upgrade();

	// Instantiate singletons.
	WGDP_Google_Auth::instance();
	WGDP_Google_Drive::instance();
	WGDP_Entitlements::instance();
	WGDP_OTP::instance();
	WGDP_Product_Meta::instance();
	WGDP_Release_Gate::instance();
	WGDP_Order_Handler::instance();
	WGDP_Classic_Checkout::instance();
	WGDP_Claim_Page::instance();
	WGDP_Self_Service::instance();
	WGDP_Cron::instance();
	WGDP_Cron::schedule();
	WGDP_Entitlements_List::instance();
	WGDP_Dashboard_Widget::instance();
	WGDP_Shortcodes::instance();
	WGDP_Admin::instance();
} );

/**
 * Activation: create DB table, schedule cron, create pages.
 */
register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgdp-db.php';
	WGDP_DB::install();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgdp-cron.php';
	WGDP_Cron::schedule();

	// Create front-end pages (self-healing check on init also handles this).
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgdp-self-service.php';
	WGDP_Self_Service::ensure_page_exists();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgdp-claim-page.php';
	WGDP_Claim_Page::ensure_page_exists();
} );

/**
 * Deactivation: clear cron, clean up.
 */
register_deactivation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wgdp-cron.php';
	WGDP_Cron::unschedule();

	delete_option( 'wgdp_setup_notice_dismissed' );
} );

/**
 * Add custom cron schedule.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['every_20_minutes'] = array(
		'interval' => 1200,
		'display'  => __( 'Every 20 Minutes', 'woo-gdrive-permission' ),
	);
	return $schedules;
} );

/**
 * Add "Settings" link on Plugins page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=wgdp' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
	return $links;
} );
