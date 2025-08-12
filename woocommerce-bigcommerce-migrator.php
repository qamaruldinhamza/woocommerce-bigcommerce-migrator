<?php
/**
 * Plugin Name: WooCommerce to BigCommerce Migrator
 * Plugin URI: https://amberman.com
 * Description: Migrate WooCommerce products to BigCommerce with batch processing
 * Version: 1.0.1
 * Author: Qamar Ul Din Hamza
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('WC_BC_MIGRATOR_VERSION', '1.0.1');
define('WC_BC_MIGRATOR_PATH', plugin_dir_path(__FILE__));
define('WC_BC_MIGRATOR_URL', plugin_dir_url(__FILE__));
define('WC_BC_MIGRATOR_TABLE', 'wc_bc_product_mapping');


/// Require All Classes
require_once WC_BC_MIGRATOR_PATH . "includes/WC_BC_Migrator.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-database.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-bigcommerce-api.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-product-migrator.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-batch-processor.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-rest-api.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-category-migrator.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-attribute-migrator.php";
require_once WC_BC_MIGRATOR_PATH . "includes/class-b2b-handler.php";


// Initialize the plugin
add_action('plugins_loaded', function() {
	WC_BC_Migrator::get_instance();
});


add_action('admin_enqueue_scripts', "enqueue_admin_scripts");

function enqueue_admin_scripts($hook) {
	if ('toplevel_page_wc-bc-migrator' !== $hook) {
		//return;
	}

	// Resolve asset locations: support either assets/js|css/ or root-level files
	$js_rel  = file_exists(WC_BC_MIGRATOR_PATH . 'assets/js/admin.js') ? 'assets/js/admin.js' : 'admin.js';
	$css_rel = file_exists(WC_BC_MIGRATOR_PATH . 'assets/css/admin.css') ? 'assets/css/admin.css' : 'admin.css';

	$js_path  = WC_BC_MIGRATOR_PATH . $js_rel;
	$css_path = WC_BC_MIGRATOR_PATH . $css_rel;

	$js_url  = WC_BC_MIGRATOR_URL . $js_rel;
	$css_url = WC_BC_MIGRATOR_URL . $css_rel;

	// Use filemtime for cache-busting if constant not defined
	$js_ver  = defined('WC_BC_MIGRATOR_VERSION') ? WC_BC_MIGRATOR_VERSION : (file_exists($js_path) ? filemtime($js_path) : false);
	$css_ver = defined('WC_BC_MIGRATOR_VERSION') ? WC_BC_MIGRATOR_VERSION : (file_exists($css_path) ? filemtime($css_path) : false);

	wp_enqueue_script(
		'wc-bc-migrator-admin',
		$js_url,
		array('jquery', 'wp-api'),
		$js_ver,
		true
	);

	wp_localize_script('wc-bc-migrator-admin', 'wcBcMigrator', array(
		'apiUrl' => trailingslashit( rest_url('wc-bc-migrator/v1') ),
		'nonce'  => wp_create_nonce('wp_rest'),
	));

	wp_enqueue_style(
		'wc-bc-migrator-admin',
		$css_url,
		array(),
		$css_ver
	);
}