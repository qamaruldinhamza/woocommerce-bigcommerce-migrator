<?php
if (!defined('ABSPATH')) exit;
/**
 * Database Class
 */
class WC_BC_Database {

	public static function create_tables() {
		global $wpdb;

		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wc_product_id bigint(20) NOT NULL,
            wc_variation_id bigint(20) DEFAULT NULL,
            bc_product_id bigint(20) DEFAULT NULL,
            bc_variation_id bigint(20) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY wc_product_id (wc_product_id),
            KEY wc_variation_id (wc_variation_id),
            KEY status (status)
        ) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	public static function insert_mapping($data) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->insert($table_name, $data);
	}

	public static function update_mapping($wc_product_id, $wc_variation_id, $data) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		$where = array('wc_product_id' => $wc_product_id);
		if ($wc_variation_id) {
			$where['wc_variation_id'] = $wc_variation_id;
		}

		return $wpdb->update($table_name, $data, $where);
	}

	public static function get_pending_products($limit = 10) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE status = 'pending' LIMIT %d",
			$limit
		));
	}

	public static function get_error_products($limit = 10) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE status = 'error' LIMIT %d",
			$limit
		));
	}

	public static function get_migration_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM $table_name GROUP BY status"
		);
	}
}