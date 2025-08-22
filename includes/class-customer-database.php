<?php
/**
 * Customer Migration Database Class
 */
class WC_BC_Customer_Database {

	const CUSTOMER_MIGRATION_TABLE = 'wc_bc_customer_migration';

	public static function create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		// Check if table already exists
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
			return true; // Table already exists
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
           id bigint(20) NOT NULL AUTO_INCREMENT,
           wp_user_id bigint(20) NOT NULL,
           bc_customer_id bigint(20) DEFAULT NULL,
           customer_email varchar(255) NOT NULL,
           customer_type varchar(50) NOT NULL DEFAULT 'customer',
           bc_customer_group_id int(11) DEFAULT NULL,
           migration_status varchar(20) NOT NULL DEFAULT 'pending',
           migration_message text,
           created_at datetime DEFAULT CURRENT_TIMESTAMP,
           updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (id),
           UNIQUE KEY unique_wp_user (wp_user_id),
           KEY idx_customer_email (customer_email),
           KEY idx_customer_type (customer_type),
           KEY idx_migration_status (migration_status),
           KEY idx_bc_customer_id (bc_customer_id)
       ) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
	}

	public static function insert_customer_mapping($data) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		$defaults = array(
			'migration_status' => 'pending',
			'customer_type' => 'customer'
		);

		$data = wp_parse_args($data, $defaults);

		// Prepare the data in the correct order matching the format array
		$insert_data = array(
			'wp_user_id' => $data['wp_user_id'],
			'bc_customer_id' => isset($data['bc_customer_id']) ? $data['bc_customer_id'] : null,
			'customer_email' => $data['customer_email'],
			'customer_type' => $data['customer_type'],
			'bc_customer_group_id' => isset($data['bc_customer_group_id']) ? $data['bc_customer_group_id'] : null,
			'migration_status' => $data['migration_status'],
			'migration_message' => isset($data['migration_message']) ? $data['migration_message'] : null
		);

		// Format array must match the data order exactly
		$format = array(
			'%d', // wp_user_id
			'%d', // bc_customer_id
			'%s', // customer_email
			'%s', // customer_type
			'%d', // bc_customer_group_id
			'%s', // migration_status
			'%s'  // migration_message
		);

		return $wpdb->insert($table_name, $insert_data, $format);
	}

	public static function update_customer_mapping($wp_user_id, $data) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		return $wpdb->update(
			$table_name,
			$data,
			array('wp_user_id' => $wp_user_id),
			array('%d', '%s', '%d', '%s', '%s'),
			array('%d')
		);
	}

	public static function get_pending_customers($limit = 50) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE migration_status = 'pending' ORDER BY id ASC LIMIT %d",
			$limit
		));
	}

	public static function get_customer_migration_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		return $wpdb->get_results(
			"SELECT migration_status as status, COUNT(*) as count 
             FROM $table_name 
             GROUP BY migration_status"
		);
	}

	public static function get_customer_by_wp_id($wp_user_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $table_name WHERE wp_user_id = %d",
			$wp_user_id
		));
	}

	public static function get_error_customers($limit = 50) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::CUSTOMER_MIGRATION_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE migration_status = 'error' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
	}
}