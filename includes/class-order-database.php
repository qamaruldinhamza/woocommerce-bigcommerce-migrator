<?php
/**
 * Order Migration Database Class
 */
class WC_BC_Order_Database {

	const ORDER_MIGRATION_TABLE = 'wc_bc_order_migration';

	public static function create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		// Check if table already exists
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
			return true;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			wc_order_id bigint(20) NOT NULL,
			bc_order_id bigint(20) DEFAULT NULL,
			wc_customer_id bigint(20) DEFAULT NULL,
			bc_customer_id bigint(20) DEFAULT NULL,
			order_status varchar(50) NOT NULL,
			order_total decimal(10,2) NOT NULL DEFAULT 0.00,
			order_date datetime NOT NULL,
			payment_method varchar(100) DEFAULT NULL,
			payment_method_title varchar(200) DEFAULT NULL,
			migration_status varchar(20) NOT NULL DEFAULT 'pending',
			migration_message text,
			migration_data longtext COMMENT 'JSON data for debugging',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY unique_wc_order (wc_order_id),
			KEY idx_wc_customer_id (wc_customer_id),
			KEY idx_bc_customer_id (bc_customer_id),
			KEY idx_migration_status (migration_status),
			KEY idx_order_status (order_status),
			KEY idx_order_date (order_date),
			KEY idx_bc_order_id (bc_order_id)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
	}

	public static function insert_order_mapping($data) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		// First check if this wc_order_id already exists
		$existing = $wpdb->get_var(
			$wpdb->prepare("SELECT id FROM $table_name WHERE wc_order_id = %d", $data['wc_order_id'])
		);

		if ($existing) {
			// Optionally: update instead of inserting a duplicate
			/*return $wpdb->update(
				$table_name,
				array(
					'bc_order_id' => isset($data['bc_order_id']) ? $data['bc_order_id'] : null,
					'wc_customer_id' => isset($data['wc_customer_id']) ? $data['wc_customer_id'] : null,
					'bc_customer_id' => isset($data['bc_customer_id']) ? $data['bc_customer_id'] : null,
					'order_status' => $data['order_status'],
					'order_total' => $data['order_total'],
					'order_date' => $data['order_date'],
					'payment_method' => isset($data['payment_method']) ? $data['payment_method'] : null,
					'payment_method_title' => isset($data['payment_method_title']) ? $data['payment_method_title'] : null,
					'migration_status' => $data['migration_status'],
					'migration_message' => isset($data['migration_message']) ? $data['migration_message'] : null,
					'migration_data' => isset($data['migration_data']) ? $data['migration_data'] : null
				),
				array('wc_order_id' => $data['wc_order_id']),
				array('%d','%d','%d','%d','%s','%f','%s','%s','%s','%s','%s','%s'),
				array('%d')
			);*/

			return $existing;
		}

		// No existing row, proceed with insert
		$defaults = array(
			'migration_status' => 'pending',
			'order_total' => 0.00,
			'order_date' => current_time('mysql')
		);
		$data = wp_parse_args($data, $defaults);

		$insert_data = array(
			'wc_order_id' => $data['wc_order_id'],
			'bc_order_id' => isset($data['bc_order_id']) ? $data['bc_order_id'] : null,
			'wc_customer_id' => isset($data['wc_customer_id']) ? $data['wc_customer_id'] : null,
			'bc_customer_id' => isset($data['bc_customer_id']) ? $data['bc_customer_id'] : null,
			'order_status' => $data['order_status'],
			'order_total' => $data['order_total'],
			'order_date' => $data['order_date'],
			'payment_method' => isset($data['payment_method']) ? $data['payment_method'] : null,
			'payment_method_title' => isset($data['payment_method_title']) ? $data['payment_method_title'] : null,
			'migration_status' => $data['migration_status'],
			'migration_message' => isset($data['migration_message']) ? $data['migration_message'] : null,
			'migration_data' => isset($data['migration_data']) ? $data['migration_data'] : null
		);

		$format = array('%d','%d','%d','%d','%s','%f','%s','%s','%s','%s','%s','%s');

		$result = $wpdb->insert($table_name, $insert_data, $format);

		if ($result === false) {
			error_log("Order mapping insert failed: " . $wpdb->last_error);
		}

		return $result;
	}

	public static function update_order_mapping($wc_order_id, $data) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		// Dynamic format array based on the actual data being updated
		$format = array();
		foreach ($data as $key => $value) {
			switch ($key) {
				case 'wc_order_id':
				case 'bc_order_id':
				case 'wc_customer_id':
				case 'bc_customer_id':
					$format[] = '%d';
					break;
				case 'order_total':
					$format[] = '%f';
					break;
				case 'order_date':
				case 'order_status':
				case 'payment_method':
				case 'payment_method_title':
				case 'migration_status':
				case 'migration_message':
				case 'migration_data':
					$format[] = '%s';
					break;
				default:
					$format[] = '%s';
					break;
			}
		}

		return $wpdb->update(
			$table_name,
			$data,
			array('wc_order_id' => $wc_order_id),
			$format,
			array('%d')
		);
	}

	public static function get_pending_orders($limit = 50) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE migration_status = 'pending' ORDER BY order_date ASC LIMIT %d",
			$limit
		));
	}

	public static function get_order_migration_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		$stats = $wpdb->get_results(
			"SELECT migration_status as status, COUNT(*) as count 
			 FROM $table_name 
			 GROUP BY migration_status"
		);

		// Also get additional useful stats
		$additional_stats = $wpdb->get_row(
			"SELECT 
				COUNT(*) as total_orders,
				SUM(order_total) as total_value,
				MIN(order_date) as earliest_order,
				MAX(order_date) as latest_order
			 FROM $table_name"
		);

		return array(
			'status_breakdown' => $stats,
			'summary' => $additional_stats
		);
	}

	public static function get_order_by_wc_id($wc_order_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $table_name WHERE wc_order_id = %d",
			$wc_order_id
		));
	}

	public static function get_error_orders($limit = 50) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE migration_status = 'error' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
	}

	public static function get_orders_by_date_range($start_date, $end_date, $limit = 100) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name 
			 WHERE order_date BETWEEN %s AND %s 
			 ORDER BY order_date ASC 
			 LIMIT %d",
			$start_date,
			$end_date,
			$limit
		));
	}

	public static function get_orders_by_status($status, $limit = 100) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE order_status = %s ORDER BY order_date DESC LIMIT %d",
			$status,
			$limit
		));
	}

	public static function get_remaining_orders_count() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_name WHERE migration_status = 'pending'"
		);
	}

	public static function reset_order_migration() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		return $wpdb->query("TRUNCATE TABLE $table_name");
	}

	/**
	 * Get orders that have dependencies (customers/products) available in BigCommerce
	 */
	public static function get_ready_orders($limit = 50) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;
		$customer_table = $wpdb->prefix . WC_BC_Customer_Database::CUSTOMER_MIGRATION_TABLE;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		// Get orders that either have no customer (guest orders) or have migrated customers
		$sql = "SELECT o.* FROM $table_name o
				LEFT JOIN $customer_table c ON o.wc_customer_id = c.wp_user_id AND c.migration_status = 'success'
				WHERE o.migration_status = 'pending' 
				AND (o.wc_customer_id IS NULL OR c.bc_customer_id IS NOT NULL)
				ORDER BY o.order_date ASC 
				LIMIT %d";

		return $wpdb->get_results($wpdb->prepare($sql, $limit));
	}

	/**
	 * Check if an order's products are all migrated
	 */
	public static function check_order_products_migrated($wc_order_id) {
		$order = wc_get_order($wc_order_id);
		if (!$order) {
			return false;
		}

		global $wpdb;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		foreach ($order->get_items() as $item) {
			$product_id = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			// Check if product is migrated
			if ($variation_id) {
				// Check variation
				$migrated = $wpdb->get_var($wpdb->prepare(
					"SELECT bc_variation_id FROM $product_table 
					 WHERE wc_product_id = %d AND wc_variation_id = %d AND status = 'success'",
					$product_id,
					$variation_id
				));
			} else {
				// Check simple product
				$migrated = $wpdb->get_var($wpdb->prepare(
					"SELECT bc_product_id FROM $product_table 
					 WHERE wc_product_id = %d AND wc_variation_id IS NULL AND status = 'success'",
					$product_id
				));
			}

			if (!$migrated) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get order statistics for dashboard
	 */
	public static function get_dashboard_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ORDER_MIGRATION_TABLE;

		$stats = $wpdb->get_row("
			SELECT 
				COUNT(*) as total,
				SUM(CASE WHEN migration_status = 'pending' THEN 1 ELSE 0 END) as pending,
				SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END) as success,
				SUM(CASE WHEN migration_status = 'error' THEN 1 ELSE 0 END) as error,
				SUM(CASE WHEN migration_status = 'skipped' THEN 1 ELSE 0 END) as skipped,
				SUM(order_total) as total_value,
				AVG(order_total) as average_value
			FROM $table_name
		", ARRAY_A);

		return $stats;
	}
}