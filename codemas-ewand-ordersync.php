<?php
/**
 * Plugin Name:       Codemas Ewand OrderSync
 * Plugin URI:        https://buddhima.netlify.app/
 * Description:       Sends paid WooCommerce orders to the Ewand ERP as Production Order JSON (styles, fabrics, sizes, embellishments).
 * Version:           1.0
 * Author:            Buddhima Vilochana
 * Author URI:        https://buddhima.netlify.app/
 * Requires Plugins:  woocommerce
 * Requires PHP:      7.4
 * Text Domain:       codemas-ewand-ordersync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CODEMAS_EWAND_VERSION', '1.0' );
define( 'CODEMAS_EWAND_FILE', __FILE__ );
define( 'CODEMAS_EWAND_DIR', plugin_dir_path( __FILE__ ) );

/* Declare HPOS (custom order tables) compatibility */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once CODEMAS_EWAND_DIR . 'includes/class-settings.php';
	require_once CODEMAS_EWAND_DIR . 'includes/class-product-fields.php';
	require_once CODEMAS_EWAND_DIR . 'includes/class-payload.php';
	require_once CODEMAS_EWAND_DIR . 'includes/class-sender.php';

	Codemas_Ewand_Settings::init();
	Codemas_Ewand_Product_Fields::init();
	Codemas_Ewand_Sender::init();
} );

/* Settings link on the Plugins screen */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=codemas-ewand-ordersync' ) ) . '">' . esc_html__( 'Settings', 'codemas-ewand-ordersync' ) . '</a>' );
	return $links;
} );
