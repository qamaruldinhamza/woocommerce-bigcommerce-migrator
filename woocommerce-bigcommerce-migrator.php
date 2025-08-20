<?php
/**
 * Plugin Name: WooCommerce to BigCommerce Migrator
 * Plugin URI: https://amberman.com
 * Description: Migrate WooCommerce products to BigCommerce with batch processing
 * Version: 1.1.2
 * Author: Qamar Ul Din Hamza
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('WC_BC_MIGRATOR_VERSION', '1.1.2');
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

