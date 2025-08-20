<?php
/**
 * Product Verification Class
 * Verifies that migrated products still exist in BigCommerce
 */
class WC_BC_Product_Verification {

	private $bc_api;
	private $verification_table;

	// Table structure constants
	const VERIFICATION_TABLE_NAME = 'wc_bc_product_verification';
	const VERIFICATION_COLUMNS = array(
		'id' => 'bigint(20) NOT NULL AUTO_INCREMENT',
		'wc_product_id' => 'bigint(20) NOT NULL',
		'bc_product_id' => 'bigint(20) NOT NULL',
		'verification_status' => "varchar(20) NOT NULL DEFAULT 'pending'",
		'verification_message' => 'text',
		'last_verified' => 'datetime',
		'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
		'updated_at' => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
	);

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->verification_table = $this->get_verification_table_name();
		$this->ensure_verification_table_exists();
	}

	/**
	 * Get the verification table name with WordPress prefix
	 */
	private function get_verification_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::VERIFICATION_TABLE_NAME;
	}

	/**
	 * Check if verification table exists, create if not
	 */
	private function ensure_verification_table_exists() {
		global $wpdb;

		$table_name = $this->verification_table;

		// Check if table exists
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

		if (!$table_exists) {
			$this->create_verification_table();
			error_log("Created verification table: $table_name");
		} else {
			error_log("Verification table already exists: $table_name");
		}
	}

	/**
	 * Create the verification table
	 */
	private function create_verification_table() {
		global $wpdb;

		$table_name = $this->verification_table;
		$charset_collate = $wpdb->get_charset_collate();

		// Build column definitions
		$columns = array();
		foreach (self::VERIFICATION_COLUMNS as $column_name => $column_definition) {
			$columns[] = "`$column_name` $column_definition";
		}

		$sql = "CREATE TABLE $table_name (
            " . implode(",\n            ", $columns) . ",
            PRIMARY KEY (id),
            UNIQUE KEY unique_wc_product (wc_product_id),
            KEY idx_bc_product_id (bc_product_id),
            KEY idx_verification_status (verification_status),
            KEY idx_last_verified (last_verified)
        ) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		// Verify table was created
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
		if (!$table_exists) {
			throw new Exception("Failed to create verification table: $table_name");
		}
	}

	/**
	 * Populate verification table with migrated products
	 */
	public function populate_verification_table() {
		global $wpdb;

		$migrator_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;
		$verification_table = $this->verification_table;

		// Get all successfully migrated products (not variations)
		$migrated_products = $wpdb->get_results(
			"SELECT wc_product_id, bc_product_id 
             FROM $migrator_table 
             WHERE wc_variation_id IS NULL 
             AND bc_product_id IS NOT NULL 
             AND status = 'success'"
		);

		$inserted = 0;
		$skipped = 0;

		foreach ($migrated_products as $product) {
			// Check if already exists in verification table
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $verification_table WHERE wc_product_id = %d",
				$product->wc_product_id
			));

			if (!$exists) {
				$result = $wpdb->insert(
					$verification_table,
					array(
						'wc_product_id' => $product->wc_product_id,
						'bc_product_id' => $product->bc_product_id,
						'verification_status' => 'pending'
					),
					array('%d', '%d', '%s')
				);

				if ($result) {
					$inserted++;
				} else {
					error_log("Failed to insert verification record for WC Product: {$product->wc_product_id}");
				}
			} else {
				$skipped++;
			}
		}

		return array(
			'success' => true,
			'total_migrated' => count($migrated_products),
			'inserted' => $inserted,
			'skipped' => $skipped
		);
	}

	/**
	 * Verify products in batch
	 */
	public function verify_products_batch($batch_size = 50) {
		global $wpdb;

		// Get pending verification records
		$pending_products = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->verification_table} 
             WHERE verification_status = 'pending' 
             ORDER BY id ASC 
             LIMIT %d",
			$batch_size
		));

		if (empty($pending_products)) {
			return array(
				'success' => true,
				'message' => 'No pending products to verify',
				'verified' => 0,
				'failed' => 0
			);
		}

		$verified = 0;
		$failed = 0;

		foreach ($pending_products as $product_record) {
			$result = $this->verify_single_product($product_record);

			if ($result['verified']) {
				$verified++;
			} else {
				$failed++;
			}

			// Add small delay to avoid API rate limits
			usleep(200000); // 0.2 seconds
		}

		return array(
			'success' => true,
			'processed' => count($pending_products),
			'verified' => $verified,
			'failed' => $failed,
			'remaining' => $this->get_pending_count()
		);
	}

	/**
	 * Verify a single product (verification only)
	 */
	private function verify_single_product($product_record) {
		global $wpdb;

		try {
			// Check if product exists in BigCommerce
			$bc_product = $this->bc_api->get_product($product_record->bc_product_id);

			if (isset($bc_product['data']['id']) && $bc_product['data']['id'] == $product_record->bc_product_id) {
				// Product exists and matches
				$update_result = $wpdb->update(
					$this->verification_table,
					array(
						'verification_status' => 'verified',
						'verification_message' => 'Product successfully verified in BigCommerce',
						'last_verified' => current_time('mysql')
					),
					array('id' => $product_record->id),
					array('%s', '%s', '%s'),
					array('%d')
				);

				if ($update_result) {
					error_log("Verified product: WC ID {$product_record->wc_product_id}, BC ID {$product_record->bc_product_id}");
					return array('verified' => true, 'message' => 'Product verified successfully');
				} else {
					throw new Exception('Failed to update verification status in database');
				}

			} else {
				throw new Exception('Product not found in BigCommerce or ID mismatch');
			}

		} catch (Exception $e) {
			// Product verification failed
			$error_message = $e->getMessage();

			$wpdb->update(
				$this->verification_table,
				array(
					'verification_status' => 'failed',
					'verification_message' => $error_message,
					'last_verified' => current_time('mysql')
				),
				array('id' => $product_record->id),
				array('%s', '%s', '%s'),
				array('%d')
			);

			error_log("Failed to verify product: WC ID {$product_record->wc_product_id}, BC ID {$product_record->bc_product_id}, Error: {$error_message}");

			return array('verified' => false, 'message' => $error_message);
		}
	}

	/**
	 * Get verification statistics
	 */
	public function get_verification_stats() {
		global $wpdb;

		$stats = $wpdb->get_row(
			"SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN verification_status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM {$this->verification_table}",
			ARRAY_A
		);

		return $stats;
	}

	/**
	 * Get count of pending verifications
	 */
	private function get_pending_count() {
		global $wpdb;

		return $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->verification_table} WHERE verification_status = 'pending'"
		);
	}

	/**
	 * Get failed verifications for investigation
	 */
	public function get_failed_verifications($limit = 50) {
		global $wpdb;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->verification_table} 
             WHERE verification_status = 'failed' 
             ORDER BY last_verified DESC 
             LIMIT %d",
			$limit
		));
	}

	/**
	 * Re-verify failed products
	 */
	public function retry_failed_verifications($batch_size = 20) {
		global $wpdb;

		// Reset failed products to pending for retry
		$wpdb->update(
			$this->verification_table,
			array(
				'verification_status' => 'pending',
				'verification_message' => 'Retrying verification'
			),
			array('verification_status' => 'failed'),
			array('%s', '%s'),
			array('%s')
		);

		// Now verify the batch
		return $this->verify_products_batch($batch_size);
	}

	/**
	 * Clean up old verification records (optional)
	 */
	public function cleanup_old_records($days_old = 30) {
		global $wpdb;

		$cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

		$deleted = $wpdb->query($wpdb->prepare(
			"DELETE FROM {$this->verification_table} 
             WHERE verification_status = 'failed' 
             AND last_verified < %s",
			$cutoff_date
		));

		return array(
			'success' => true,
			'deleted_records' => $deleted,
			'cutoff_date' => $cutoff_date
		);
	}

	/**
	 * Get verification table status
	 */
	public function get_table_status() {
		global $wpdb;

		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->verification_table}'") === $this->verification_table;

		if (!$table_exists) {
			return array(
				'exists' => false,
				'message' => 'Verification table does not exist'
			);
		}

		$stats = $this->get_verification_stats();

		return array(
			'exists' => true,
			'table_name' => $this->verification_table,
			'stats' => $stats,
			'message' => "Verification table exists with {$stats['total']} records"
		);
	}

	/**
	 * Verify and update product weights
	 */
	public function verify_and_update_weights($batch_size = 20) {
		global $wpdb;

		// Get pending products from verification table to verify AND update weights
		$pending_products = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->verification_table} 
         WHERE verification_status = 'pending' 
         ORDER BY id ASC 
         LIMIT %d",
			$batch_size
		));

		if (empty($pending_products)) {
			return array(
				'success' => true,
				'message' => 'No pending products to verify and update',
				'processed' => 0,
				'updated' => 0,
				'failed' => 0,
				'remaining' => 0
			);
		}

		$updated = 0;
		$failed = 0;
		$messages = array();

		foreach ($pending_products as $product_record) {
			$result = $this->update_single_product_weight($product_record);

			if ($result['updated']) {
				$messages [] = $result['message'];
				$updated++;
			} else {
				$failed++;
			}

			// Add delay to avoid API rate limits
			usleep(300000); // 0.3 seconds
		}

		return array(
			'success' => true,
			'processed' => count($pending_products),
			'updated' => $updated,
			'failed' => $failed,
			'messages' => $messages,
			'remaining' => $this->get_pending_count()
		);
	}

	/**
	 * Update weight for a single product (verify + fix weight)
	 */
	private function update_single_product_weight($product_record) {
		global $wpdb;

		try {
			// First verify the product exists
			$bc_product = $this->bc_api->get_product($product_record->bc_product_id);

			// Check for API errors first
			if (isset($bc_product['error'])) {
				$error_details = isset($bc_product['details']) ? json_encode($bc_product['details']) : 'No details';
				throw new Exception("BigCommerce API Error: {$bc_product['error']}. Details: {$error_details}");
			}

			// Log the full API response for debugging

			if (!isset($bc_product['data'])) {
				throw new Exception('Invalid API response structure - no data key found');
			}

			if (!isset($bc_product['data']['id'])) {
				throw new Exception('No product ID in response data');
			}

			if ($bc_product['data']['id'] != $product_record->bc_product_id) {
				throw new Exception("Product ID mismatch. Expected: {$product_record->bc_product_id}, Got: {$bc_product['data']['id']}");
			}

			// Get WooCommerce product
			$wc_product = wc_get_product($product_record->wc_product_id);
			if (!$wc_product) {
				throw new Exception('WooCommerce product not found');
			}

			// Get and fix the weight
			$original_weight = $wc_product->get_weight();
			$weight_data = $this->fix_and_prepare_weight($original_weight);

			// Prepare update data
			$update_data = array(
				'weight' => (float) $weight_data['corrected_weight_grams']
			);

			// Prepare custom fields for weight range
			if (!empty($weight_data['weight_range'])) {
				$update_data['custom_fields'] = array(
					array(
						'name' => 'weight_range_grams',
						'value' => $weight_data['weight_range']
					)
				);
			}

			$clean_product_id = (int) $product_record->bc_product_id;

			// Update product in BigCommerce
			$result = $this->bc_api->update_product($clean_product_id, $update_data);

			// Check for update errors
			if (isset($result['error'])) {
				$error_details = isset($result['details']) ? json_encode($result['details']) : 'No details';
				throw new Exception("Failed to update product: {$result['error']}. Details: {$error_details}");
			}

			if (isset($result['data']['id'])) {
				// Update verification status with weight fix message
				$verification_message = 'Product verified and weight fixed';
				if (!empty($weight_data['original_weight']) && $weight_data['original_weight'] !== (string)$weight_data['corrected_weight_grams']) {
					$verification_message .= ' (Weight: ' . $weight_data['original_weight'] . ' → ' . $weight_data['corrected_weight_grams'] . 'g';
					if (!empty($weight_data['weight_range'])) {
						$verification_message .= ', Range: ' . $weight_data['weight_range'];
					}
					$verification_message .= ')';
				}

				$wpdb->update(
					$this->verification_table,
					array(
						'verification_status' => 'verified',
						'verification_message' => $verification_message,
						'last_verified' => current_time('mysql')
					),
					array('id' => $product_record->id),
					array('%s', '%s', '%s'),
					array('%d')
				);

				return array('updated' => true, 'message' => 'Product verified and weight updated successfully');
			} else {
				throw new Exception('No product ID returned in update response: ' . json_encode($result));
			}

		} catch (Exception $e) {
			$error_message = $e->getMessage();

			// Update verification status as failed
			$wpdb->update(
				$this->verification_table,
				array(
					'verification_status' => 'failed',
					'verification_message' => 'Verification and weight update failed: ' . $error_message,
					'last_verified' => current_time('mysql')
				),
				array('id' => $product_record->id),
				array('%s', '%s', '%s'),
				array('%d')
			);

			return array('updated' => false, 'message' => $error_message);
		}
	}

	/**
	 * Fix weight and prepare weight data with intelligent range correction
	 */
	private function fix_and_prepare_weight($weight_string) {
		if (empty($weight_string)) {
			return array(
				'corrected_weight_grams' => 0,
				'weight_range' => '',
				'original_weight' => ''
			);
		}

		// Convert to string if it's not
		$weight_string = (string) $weight_string;
		$original_weight = $weight_string;
		$weight_range = '';
		$corrected_weight_grams = 0;

		// Check if it contains a range (dash or hyphen)
		if (strpos($weight_string, '-') !== false || strpos($weight_string, '–') !== false) {
			// Split by various dash types
			$parts = preg_split('/[-–—]/', $weight_string);

			if (count($parts) == 2) {
				$value1 = $this->parse_weight_value(trim($parts[0]));
				$value2 = $this->parse_weight_value(trim($parts[1]));

				// Fix the reversed range issue intelligently
				if ($value1 > $value2) {
					// Calculate how many decimal places we need to move
					$divisor = 1;
					$temp_value1 = $value1;

					// Keep dividing by 10 until value1 becomes less than or equal to value2
					while ($temp_value1 > $value2 && $divisor <= 10000) { // Max divisor 10000 for safety
						$divisor *= 10;
						$temp_value1 = $value1 / $divisor;
					}

					// Apply the correction if we found a valid divisor
					if ($temp_value1 <= $value2 && $divisor > 1) {
						$original_value1 = $value1;
						$value1 = $temp_value1;
						error_log("Fixed weight range: {$original_weight} -> {$value1}-{$value2} (original {$original_value1} divided by {$divisor})");
					}
				}

				// Create corrected weight range
				$min_weight = min($value1, $value2);
				$max_weight = max($value1, $value2);
				$weight_range = $min_weight . '-' . $max_weight . ' grams';

				// Use the maximum value as the main weight
				$corrected_weight_grams = $max_weight;
			} else {
				// Single value
				$corrected_weight_grams = $this->parse_weight_value($weight_string);
			}
		} else {
			// Single value
			$corrected_weight_grams = $this->parse_weight_value($weight_string);
		}

		return array(
			'corrected_weight_grams' => round($corrected_weight_grams, 2),
			'weight_range' => $weight_range,
			'original_weight' => $original_weight
		);
	}

	/**
	 * Parse weight value from string (same as migrator)
	 */
	private function parse_weight_value($value) {
		// Remove any non-numeric characters except decimal point
		$value = preg_replace('/[^0-9.]/', '', $value);
		return (float) $value;
	}
}