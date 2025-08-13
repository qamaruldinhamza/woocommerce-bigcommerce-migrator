<?php

/**
 * Batch Processor Class
 */
class WC_BC_Batch_Processor {

	public function prepare_products() {
		global $wpdb;

		// Get all WooCommerce products
		$args = array(
			'post_type' => 'product',
			'posts_per_page' => -1,
			'post_status' => 'publish',
			'fields' => 'ids',
		);

		$products = get_posts($args);

		$inserted = 0;
		$skipped = 0;

		foreach ($products as $product_id) {
			$product = wc_get_product($product_id);

			if ($product->is_type('variable')) {
				// Insert parent product
				$existing = $this->check_existing_mapping($product_id, null);
				if (!$existing) {
					WC_BC_Database::insert_mapping(array(
						'wc_product_id' => $product_id,
						'wc_variation_id' => null,
						'status' => 'pending',
					));
					$inserted++;
				} else {
					$skipped++;
				}

				// Insert variations
				$variations = $product->get_children();
				foreach ($variations as $variation_id) {
					$existing = $this->check_existing_mapping($product_id, $variation_id);
					if (!$existing) {
						WC_BC_Database::insert_mapping(array(
							'wc_product_id' => $product_id,
							'wc_variation_id' => $variation_id,
							'status' => 'pending',
						));
						$inserted++;
					} else {
						$skipped++;
					}
				}
			} else {
				// Simple product
				$existing = $this->check_existing_mapping($product_id, null);
				if (!$existing) {
					WC_BC_Database::insert_mapping(array(
						'wc_product_id' => $product_id,
						'wc_variation_id' => null,
						'status' => 'pending',
					));
					$inserted++;
				} else {
					$skipped++;
				}
			}
		}

		return array(
			'success' => true,
			'inserted' => $inserted,
			'skipped' => $skipped,
			'total_products' => count($products),
		);
	}

	private function check_existing_mapping($product_id, $variation_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		$query = $wpdb->prepare(
			"SELECT id FROM $table_name WHERE wc_product_id = %d AND wc_variation_id %s",
			$product_id,
			$variation_id ? "= $variation_id" : "IS NULL"
		);

		return $wpdb->get_var($query);
	}

	public function process_batch($batch_size = 10) {
		// Get pending parent products only (variations are handled within the migrate_product method)
		$pending_products = WC_BC_Database::get_pending_parent_products($batch_size);

		if (empty($pending_products)) {
			return array(
				'success' => true,
				'message' => 'No pending products to migrate',
				'processed' => 0,
			);
		}

		$migrator = new WC_BC_Product_Migrator();
		$processed = 0;
		$errors = 0;

		foreach ($pending_products as $mapping) {
			// Migrate the product (this handles both simple and variable products with their variations)
			$result = $migrator->migrate_product($mapping->wc_product_id);

			if (isset($result['error'])) {
				error_log("Migration error for product {$mapping->wc_product_id}: " . $result['error']);
				$errors++;
			} else {
				$processed++;
				error_log("Successfully migrated product {$mapping->wc_product_id} to BC ID: " . $result['bc_product_id']);
			}

			// Add a small delay to avoid API rate limits
			usleep(500000); // 0.5 seconds
		}

		return array(
			'success' => true,
			'processed' => $processed,
			'errors' => $errors,
			'remaining' => $this->get_remaining_count(),
		);
	}

	public function retry_errors($batch_size = 10) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		// Reset error products to pending (parent products only)
		$error_products = WC_BC_Database::get_error_parent_products($batch_size);

		foreach ($error_products as $product) {
			$wpdb->update(
				$table_name,
				array('status' => 'pending', 'message' => 'Retrying migration'),
				array('id' => $product->id)
			);
		}

		// Process the batch
		return $this->process_batch($batch_size);
	}

	private function get_remaining_count() {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND wc_variation_id IS NULL"
		);
	}

	/**
	 * Get migration status summary
	 */
	public function get_migration_status() {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		$status = array(
			'total_products' => $wpdb->get_var("SELECT COUNT(DISTINCT wc_product_id) FROM $table_name WHERE wc_variation_id IS NULL"),
			'pending_products' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND wc_variation_id IS NULL"),
			'success_products' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'success' AND wc_variation_id IS NULL"),
			'error_products' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'error' AND wc_variation_id IS NULL"),
			'total_variations' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE wc_variation_id IS NOT NULL"),
			'pending_variations' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND wc_variation_id IS NOT NULL"),
			'success_variations' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'success' AND wc_variation_id IS NOT NULL"),
			'error_variations' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'error' AND wc_variation_id IS NOT NULL"),
		);

		return $status;
	}

	/**
	 * Get recent errors for debugging
	 */
	public function get_recent_errors($limit = 10) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name WHERE status = 'error' ORDER BY updated_at DESC LIMIT %d",
			$limit
		));
	}
}