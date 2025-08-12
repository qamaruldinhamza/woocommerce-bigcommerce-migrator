<?php
/**
 * Main Plugin Class
 */
class WC_BC_Migrator {

	private static $instance = null;

	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		// Activation/Deactivation hooks
		register_activation_hook(__FILE__, array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		// Admin hooks
		add_action('admin_menu', array($this, 'add_admin_menu'));


		// REST API hooks
		add_action('rest_api_init', array($this, 'register_rest_routes'));

		// Include required files
		$this->include_files();
	}

	private function include_files() {
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-database.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-bigcommerce-api.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-product-migrator.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-rest-api.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-batch-processor.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-category-migrator.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-attribute-migrator.php';
		require_once WC_BC_MIGRATOR_PATH . 'includes/class-b2b-handler.php';
	}

	public function activate() {
		WC_BC_Database::create_tables();
		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	public function add_admin_menu() {
		add_menu_page(
			'WC to BC Migrator',
			'WC to BC Migrator',
			'manage_options',
			'wc-bc-migrator',
			array($this, 'admin_page'),
			'dashicons-migrate',
			30
		);
	}

	public function admin_page() {
		include WC_BC_MIGRATOR_PATH . 'admin/admin-page.php';
	}


	public function register_rest_routes() {
		$rest_api = new WC_BC_REST_API();
		$rest_api->register_routes();
	}
}