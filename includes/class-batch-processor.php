<?php

/**
 * Batch Processor Class
 */
class WC_BC_Batch_Processor {

	public function prepare_products() {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		// Set more conservative limits
		set_time_limit(300); // 5 minutes max
		ini_set('memory_limit', '1024M');

		try {
			$batch_size = 25; // Smaller batch size
			$offset = 5000;
			$inserted = 0;
			$skipped = 0;
			$total_processed = 0;
			$max_batches = 40; // Limit total batches to prevent infinite loops
			$batch_count = 0;

			do {
				// Safety check - prevent infinite processing
				if ($batch_count >= $max_batches) {
					error_log("Preparation stopped after {$max_batches} batches to prevent timeout");
					break;
				}

				$args = array(
					'post_type' => 'product',
					'posts_per_page' => $batch_size,
					'offset' => $offset,
					'post_status' => array('publish', 'draft'),
					'fields' => 'ids',
				);

				$products = get_posts($args);

				if (empty($products)) {
					break;
				}

				foreach ($products as $product_id) {
					try {
						$product = wc_get_product($product_id);

						if (!$product) {
							continue;
						}

						if ($product->is_type('variable')) {
							// Handle parent product
							$parent_exists = $wpdb->get_var($wpdb->prepare(
								"SELECT id FROM $table_name WHERE wc_product_id = %d AND wc_variation_id IS NULL",
								$product_id
							));

							if (!$parent_exists) {
								$result = WC_BC_Database::insert_mapping(array(
									'wc_product_id' => $product_id,
									'wc_variation_id' => null,
									'status' => 'pending',
								));
								if ($result) $inserted++;
							} else {
								$skipped++;
							}

							// Handle variations with limit to prevent memory issues
							$variations = array_slice($product->get_children(), 0, 100); // Limit variations
							foreach ($variations as $variation_id) {
								$variation_exists = $wpdb->get_var($wpdb->prepare(
									"SELECT id FROM $table_name WHERE wc_product_id = %d AND wc_variation_id = %d",
									$product_id,
									$variation_id
								));

								if (!$variation_exists) {
									$result = WC_BC_Database::insert_mapping(array(
										'wc_product_id' => $product_id,
										'wc_variation_id' => $variation_id,
										'status' => 'pending',
									));
									if ($result) $inserted++;
								} else {
									$skipped++;
								}
							}
						} else {
							// Simple product
							$simple_exists = $wpdb->get_var($wpdb->prepare(
								"SELECT id FROM $table_name WHERE wc_product_id = %d AND wc_variation_id IS NULL",
								$product_id
							));

							if (!$simple_exists) {
								$result = WC_BC_Database::insert_mapping(array(
									'wc_product_id' => $product_id,
									'wc_variation_id' => null,
									'status' => 'pending',
								));
								if ($result) $inserted++;
							} else {
								$skipped++;
							}
						}

						unset($product);
						$total_processed++;

					} catch (Exception $e) {
						error_log("Error processing product {$product_id}: " . $e->getMessage());
						continue;
					}
				}

				$offset += $batch_size;
				$batch_count++;

				// Clear caches more frequently
				if ($batch_count % 5 === 0) {
					wp_cache_flush();
					if (function_exists('wp_suspend_cache_addition')) {
						wp_suspend_cache_addition(true);
					}
				}

				// Add delay between batches
				usleep(200000); // 0.2 seconds

				// Check execution time - stop if getting close to limit
				if ((time() - $_SERVER['REQUEST_TIME']) > 280) { // 280 seconds (4min 40sec)
					error_log("Preparation stopped due to time limit approaching");
					break;
				}

			} while (count($products) === $batch_size);

			// Log completion
			error_log("Preparation completed: Inserted: {$inserted}, Skipped: {$skipped}, Processed: {$total_processed}");

			return array(
				'success' => true,
				'inserted' => $inserted,
				'skipped' => $skipped,
				'total_processed' => $total_processed,
				'batches_processed' => $batch_count,
				'message' => "Successfully processed {$total_processed} products in {$batch_count} batches"
			);

		} catch (Exception $e) {
			$error_message = $e->getMessage();
			error_log("Critical error in prepare_products: " . $error_message);

			return array(
				'success' => false,
				'error' => $error_message,
				'inserted' => $inserted ?? 0,
				'skipped' => $skipped ?? 0,
				'total_processed' => $total_processed ?? 0,
			);
		}
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
		// Get products that need processing (either new products or products with pending variations)
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
			$wc_product_id = $mapping->wc_product_id;

			// Check if this is a product with pending variations but already migrated parent
			if ($mapping->bc_product_id && $mapping->status === 'success') {
				// This product exists in BC, just migrate pending variations
				$result = $this->migrate_pending_variations($wc_product_id, $mapping->bc_product_id);
			} else {
				// Migrate the entire product (this handles both simple and variable products)
				$result = $migrator->migrate_product($wc_product_id);
			}

			if (isset($result['error'])) {
				error_log("Migration error for product {$wc_product_id}: " . $result['error']);
				$errors++;
			} else {
				$processed++;
				error_log("Successfully processed product {$wc_product_id}");
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

// Add this new method to WC_BC_Batch_Processor:
	private function migrate_pending_variations($wc_product_id, $bc_product_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		try {
			$product = wc_get_product($wc_product_id);
			if (!$product || !$product->is_type('variable')) {
				return array('error' => 'Product not found or not variable');
			}

			$migrator = new WC_BC_Product_Migrator();

			// Get pending variations for this product
			$pending_variations = $wpdb->get_results($wpdb->prepare(
				"SELECT wc_variation_id FROM $table_name 
             WHERE wc_product_id = %d AND wc_variation_id IS NOT NULL AND status = 'pending'",
				$wc_product_id
			));

			if (empty($pending_variations)) {
				return array('success' => true, 'message' => 'No pending variations found');
			}

			// First ensure product options are created/updated
			$migrator->create_product_options($product, $bc_product_id);

			$success_count = 0;
			$error_count = 0;

			foreach ($pending_variations as $variation_data) {
				$variation_id = $variation_data->wc_variation_id;
				$variation = wc_get_product($variation_id);

				if (!$variation) {
					continue;
				}

				// Use the existing variation migration logic
				$result = $migrator->migrate_single_variation($variation, $product, $bc_product_id);

				if (isset($result['error'])) {
					$error_count++;
					WC_BC_Database::update_mapping(
						$wc_product_id,
						$variation_id,
						array(
							'status' => 'error',
							'message' => $result['error'],
						)
					);
				} else {
					$success_count++;
					WC_BC_Database::update_mapping(
						$wc_product_id,
						$variation_id,
						array(
							'bc_product_id' => $bc_product_id,
							'bc_variation_id' => $result['bc_variation_id'],
							'status' => 'success',
							'message' => 'Variation migrated successfully',
						)
					);
				}
			}

			return array(
				'success' => true,
				'variations_processed' => $success_count + $error_count,
				'variations_success' => $success_count,
				'variations_errors' => $error_count
			);

		} catch (Exception $e) {
			return array('error' => $e->getMessage());
		}
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