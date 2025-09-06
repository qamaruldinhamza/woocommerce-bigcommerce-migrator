<?php
/**
 * REST API Class
 */
class WC_BC_REST_API {

	public function register_routes() {
		register_rest_route('wc-bc-migrator/v1', '/migrate/batch', array(
			'methods' => 'POST',
			'callback' => array($this, 'process_batch'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/prepare', array(
			'methods' => 'POST',
			'callback' => array($this, 'prepare_migration'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/stats', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_stats'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/retry-errors', array(
			'methods' => 'POST',
			'callback' => array($this, 'retry_errors'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/categories', array(
			'methods' => 'POST',
			'callback' => array($this, 'migrate_categories'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/attributes', array(
			'methods' => 'POST',
			'callback' => array($this, 'migrate_attributes'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/migrate/b2b-setup', array(
			'methods' => 'POST',
			'callback' => array($this, 'setup_b2b'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/test-connection', array(
			'methods' => 'POST',
			'callback' => array($this, 'test_connection'),
			'permission_callback' => array($this, 'check_permission'),
		));

		// Add these after your existing routes:

		register_rest_route('wc-bc-migrator/v1', '/verification/init', array(
			'methods' => 'POST',
			'callback' => array($this, 'init_verification'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/verification/populate', array(
			'methods' => 'POST',
			'callback' => array($this, 'populate_verification'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/verification/verify', array(
			'methods' => 'POST',
			'callback' => array($this, 'verify_batch'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'batch_size' => array(
					'required' => false,
					'default' => 50,
					'validate_callback' => function($param) {
						return is_numeric($param) && $param > 0 && $param <= 200;
					},
					'sanitize_callback' => 'absint',
				),
			),
		));

		register_rest_route('wc-bc-migrator/v1', '/verification/stats', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_verification_stats'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/verification/retry', array(
			'methods' => 'POST',
			'callback' => array($this, 'retry_verification'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'batch_size' => array(
					'required' => false,
					'default' => 20,
					'sanitize_callback' => 'absint',
				),
			),
		));

		register_rest_route('wc-bc-migrator/v1', '/verification/failed', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_failed_verifications'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/verification/update-weights', array(
			'methods' => 'POST',
			'callback' => array($this, 'update_weights'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'batch_size' => array(
					'required' => false,
					'default' => 20,
					'sanitize_callback' => 'absint',
				),
			),
		));


		// Customer migration endpoints
		register_rest_route('wc-bc-migrator/v1', '/customers/prepare', array(
			'methods' => 'POST',
			'callback' => array($this, 'prepare_customers'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/customers/migrate', array(
			'methods' => 'POST',
			'callback' => array($this, 'migrate_customers_batch'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'batch_size' => array(
					'required' => false,
					'default' => 10,
					'sanitize_callback' => 'absint',
				),
			),
		));

		register_rest_route('wc-bc-migrator/v1', '/customers/stats', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_customer_stats'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/orders/prepare', array(
			'methods' => 'POST',
			'callback' => array($this, 'prepare_orders'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'date_from' => array(
					'required' => false,
					'validate_callback' => function($param) {
						return empty($param) || strtotime($param) !== false;
					},
				),
				'date_to' => array(
					'required' => false,
					'validate_callback' => function($param) {
						return empty($param) || strtotime($param) !== false;
					},
				),
				'status_filter' => array(
					'required' => false,
					'validate_callback' => function($param) {
						return empty($param) || is_array($param);
					},
				),
			),
		));

		register_rest_route('wc-bc-migrator/v1', '/orders/migrate', array(
			'methods' => 'POST',
			'callback' => array($this, 'migrate_orders_batch'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'batch_size' => array(
					'required' => false,
					'default' => 10,
					'sanitize_callback' => 'absint',
				),
			),
		));

		register_rest_route('wc-bc-migrator/v1', '/orders/stats', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_order_stats'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/orders/retry', array(
			'methods' => 'POST',
			'callback' => array($this, 'retry_order_errors'),
			'permission_callback' => array($this, 'check_permission'),
			'args' => array(
				'batch_size' => array(
					'required' => false,
					'default' => 10,
					'sanitize_callback' => 'absint',
				),
			),
		));

		register_rest_route('wc-bc-migrator/v1', '/orders/validate', array(
			'methods' => 'POST',
			'callback' => array($this, 'validate_order_dependencies'),
			'permission_callback' => array($this, 'check_permission'),
		));

		register_rest_route('wc-bc-migrator/v1', '/orders/failed', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_failed_orders'),
			'permission_callback' => array($this, 'check_permission'),
		));


		add_action('wp_ajax_wc_bc_update_custom_fields_batch', function() {
			check_ajax_referer('wc_bc_migrator_nonce', 'nonce');
			$migrator = new WC_BC_Product_Migrator();
			$batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;
			$result = $migrator->update_custom_fields_batch($batch_size);
			if (isset($result['error'])) {
				wp_send_json_error(array('message' => $result['error']));
			} else {
				wp_send_json_success($result);
			}
		});

	}

	public function check_permission() {
		return current_user_can('manage_options');
	}

	public function prepare_migration() {
		$batch_processor = new WC_BC_Batch_Processor();
		$result = $batch_processor->prepare_products();

		return new WP_REST_Response($result, 200);
	}

	public function process_batch($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		$batch_processor = new WC_BC_Batch_Processor();
		$result = $batch_processor->process_batch($batch_size);

		return new WP_REST_Response($result, 200);
	}

	public function get_stats() {
		$stats = WC_BC_Database::get_migration_stats();

		$formatted_stats = array(
			'total' => 0,
			'pending' => 0,
			'success' => 0,
			'error' => 0,
		);

		foreach ($stats as $stat) {
			$formatted_stats[$stat->status] = (int) $stat->count;
			$formatted_stats['total'] += (int) $stat->count;
		}

		return new WP_REST_Response($formatted_stats, 200);
	}

	public function retry_errors($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		$batch_processor = new WC_BC_Batch_Processor();
		$result = $batch_processor->retry_errors($batch_size);

		return new WP_REST_Response($result, 200);
	}

	public function migrate_categories() {
		$category_migrator = new WC_BC_Category_Migrator();
		$result = $category_migrator->migrate_all_categories();

		return new WP_REST_Response($result, 200);
	}

	public function migrate_attributes() {
		$attribute_migrator = new WC_BC_Attribute_Migrator();
		$result = $attribute_migrator->migrate_all_attributes();

		return new WP_REST_Response($result, 200);
	}

	public function setup_b2b() {
		$b2b_handler = new WC_BC_B2B_Handler();
		$result = $b2b_handler->setup_b2b_features();

		return new WP_REST_Response($result, 200);
	}

	public function test_connection() {
		$bc_api = new WC_BC_BigCommerce_API();
		$result = $bc_api->test_connection();

		return new WP_REST_Response($result, 200);
	}

	public function init_verification() {
		try {
			$verifier = new WC_BC_Product_Verification();
			$status = $verifier->get_table_status();

			return new WP_REST_Response(array(
				'success' => true,
				'message' => 'Verification system initialized',
				'table_status' => $status
			), 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function populate_verification() {
		try {
			$verifier = new WC_BC_Product_Verification();
			$result = $verifier->populate_verification_table();

			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function verify_batch($request) {
		$batch_size = $request->get_param('batch_size') ?: 50;

		try {
			$verifier = new WC_BC_Product_Verification();
			$result = $verifier->verify_products_batch($batch_size);

			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function get_verification_stats() {
		try {
			$verifier = new WC_BC_Product_Verification();
			$stats = $verifier->get_verification_stats();

			return new WP_REST_Response(array(
				'success' => true,
				'stats' => $stats
			), 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function retry_verification($request) {
		$batch_size = $request->get_param('batch_size') ?: 20;

		try {
			$verifier = new WC_BC_Product_Verification();
			$result = $verifier->retry_failed_verifications($batch_size);

			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function get_failed_verifications() {
		try {
			$verifier = new WC_BC_Product_Verification();
			$failed = $verifier->get_failed_verifications(50);

			return new WP_REST_Response(array(
				'success' => true,
				'failed_verifications' => $failed
			), 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function update_weights($request) {
		$batch_size = $request->get_param('batch_size') ?: 20;

		try {
			$verifier = new WC_BC_Product_Verification();
			$result = $verifier->verify_and_update_weights($batch_size);

			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}


	// Callback methods
	public function prepare_customers() {
		try {
			$migrator = new WC_BC_Customer_Migrator();
			$result = $migrator->prepare_customers();
			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function migrate_customers_batch($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		try {
			$migrator = new WC_BC_Customer_Migrator();
			$result = $migrator->process_batch($batch_size);
			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	public function get_customer_stats() {
		try {
			$stats = WC_BC_Customer_Database::get_customer_migration_stats();

			$formatted_stats = array(
				'total' => 0,
				'pending' => 0,
				'success' => 0,
				'error' => 0,
			);

			foreach ($stats as $stat) {
				$formatted_stats[$stat->status] = (int) $stat->count;
				$formatted_stats['total'] += (int) $stat->count;
			}

			return new WP_REST_Response(array(
				'success' => true,
				'stats' => $formatted_stats
			), 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	// Complete callback methods:

	/**
	 * Prepare orders for migration
	 */
	public function prepare_orders($request) {
		try {
			// Clear any existing output
			if (ob_get_level()) {
				ob_end_clean();
			}

			$processor = new WC_BC_Order_Processor();
			$result = $processor->prepare_orders();

			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			error_log("Order preparation error: " . $e->getMessage());

			return new WP_REST_Response(array(
				'success' => false,
				'message' => 'Memory or timeout error during preparation. Try smaller batches.'
			), 500);
		}
	}


	/**
	 * Migrate orders batch
	 */
	public function migrate_orders_batch($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		try {
			$processor = new WC_BC_Order_Processor();
			$result = $processor->process_batch($batch_size);
			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	/**
	 * Get order migration statistics
	 */
	public function get_order_stats() {
		try {
			// Use the efficient dashboard stats function directly
			$stats = WC_BC_Order_Database::get_dashboard_stats();

			$formatted_stats = array(
				'total'   => (int) ($stats['total'] ?? 0),
				'pending' => (int) ($stats['pending'] ?? 0),
				'success' => (int) ($stats['success'] ?? 0),
				'error'   => (int) ($stats['error'] ?? 0),
				'skipped' => (int) ($stats['skipped'] ?? 0)
			);

			return new WP_REST_Response(array(
				'success' => true,
				'stats'   => $formatted_stats
			), 200);

		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	/**
	 * Retry failed order migrations
	 */
	public function retry_order_errors($request) {
		$batch_size = $request->get_param('batch_size') ?: 10;

		try {
			$processor = new WC_BC_Order_Processor();
			$result = $processor->retry_failed_orders($batch_size);
			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	/**
	 * Validate order dependencies before migration
	 */
	public function validate_order_dependencies() {
		try {
			$validator = new WC_BC_Order_Validator();
			$result = $validator->validate_dependencies();
			return new WP_REST_Response($result, 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}

	/**
	 * Get failed order migrations for debugging
	 */
	public function get_failed_orders() {
		try {
			$failed_orders = WC_BC_Order_Database::get_error_orders(50);

			// Enhance with additional data
			$enhanced_orders = array();
			foreach ($failed_orders as $order) {
				$wc_order = wc_get_order($order->wc_order_id);
				$enhanced_orders[] = array(
					'wc_order_id' => $order->wc_order_id,
					'order_status' => $order->order_status,
					'order_total' => $order->order_total,
					'order_date' => $order->order_date,
					'payment_method' => $order->payment_method,
					'migration_message' => $order->migration_message,
					'customer_name' => $wc_order ? $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() : 'Unknown',
					'updated_at' => $order->updated_at
				);
			}

			return new WP_REST_Response(array(
				'success' => true,
				'failed_orders' => $enhanced_orders
			), 200);
		} catch (Exception $e) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $e->getMessage()
			), 500);
		}
	}
}
