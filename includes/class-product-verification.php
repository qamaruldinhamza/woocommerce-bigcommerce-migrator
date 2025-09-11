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
		'wc_variation_id' => 'bigint(20) NULL',
		'bc_product_id' => 'bigint(20) NOT NULL',
		'bc_variation_id' => 'bigint(20) NULL',
		'verification_status' => "varchar(20) NOT NULL DEFAULT 'pending'",
		'verification_message' => 'text',
		'last_verified' => 'datetime',
		'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
		'updated_at' => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
	);

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->verification_table = $this->get_verification_table_name();
		$this->ensure_verification_table_exists(); // This drops and recreates the table
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
	 * Initialize verification system (drops and recreates table)
	 */
	public function initialize_verification_system() {
		global $wpdb;

		$table_name = $this->verification_table;

		// Drop and recreate table for fresh start
		$drop_result = $wpdb->query("DROP TABLE IF EXISTS $table_name");
		error_log("Dropped verification table result: " . ($drop_result !== false ? 'success' : 'failed'));

		$this->create_verification_table();

		// Verify table was actually created
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
		error_log("Verification table exists after creation: " . ($table_exists ? 'yes' : 'no'));

		return array(
			'success' => $table_exists,
			'message' => $table_exists ? 'Verification system initialized' : 'Failed to create table'
		);
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
        UNIQUE KEY unique_wc_product_variation (wc_product_id, wc_variation_id),
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

		// Debug: Check if verification table exists
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$verification_table'") === $verification_table;
		if (!$table_exists) {
			error_log("Verification table does not exist: $verification_table");
			return array(
				'success' => false,
				'message' => 'Verification table does not exist'
			);
		}

		// Get all successfully migrated products AND variations
		$migrated_items = $wpdb->get_results(
			"SELECT wc_product_id, wc_variation_id, bc_product_id, bc_variation_id 
         FROM $migrator_table 
         WHERE bc_product_id IS NOT NULL 
         AND status = 'success'"
		);

		error_log("Found " . count($migrated_items) . " migrated items to verify");

		$inserted = 0;
		$skipped = 0;
		$errors = 0;

		foreach ($migrated_items as $item) {
			// Check if this record already exists
			$where_clause = "wc_product_id = %d";
			$where_values = array($item->wc_product_id);

			if ($item->wc_variation_id) {
				$where_clause .= " AND wc_variation_id = %d";
				$where_values[] = $item->wc_variation_id;
			} else {
				$where_clause .= " AND wc_variation_id IS NULL";
			}

			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $verification_table WHERE $where_clause",
				$where_values
			));

			if ($exists) {
				$skipped++;
				continue;
			}

			// Prepare insert data
			$insert_data = array(
				'wc_product_id' => $item->wc_product_id,
				'bc_product_id' => $item->bc_product_id,
				'verification_status' => 'pending'
			);

			$format = array('%d', '%d', '%s');

			// Add variation data if it exists
			if ($item->wc_variation_id) {
				$insert_data['wc_variation_id'] = $item->wc_variation_id;
				$format[] = '%d';
			}

			if ($item->bc_variation_id) {
				$insert_data['bc_variation_id'] = $item->bc_variation_id;
				$format[] = '%d';
			}

			$result = $wpdb->insert($verification_table, $insert_data, $format);

			if ($result === false) {
				$errors++;
				error_log("Failed to insert verification record for WC Product: {$item->wc_product_id}, Error: " . $wpdb->last_error);
			} else {
				$inserted++;
			}

			// Log every 1000 insertions
			if (($inserted + $skipped + $errors) % 1000 == 0) {
				error_log("Progress: Inserted: $inserted, Skipped: $skipped, Errors: $errors");
			}
		}

		error_log("Final result: Inserted: $inserted, Skipped: $skipped, Errors: $errors");

		return array(
			'success' => true,
			'total_migrated' => count($migrated_items),
			'inserted' => $inserted,
			'skipped' => $skipped,
			'errors' => $errors
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

	private function verify_single_product($product_record) {
		global $wpdb;

		try {
			// Determine if this is a variation or main product
			$is_variation = !empty($product_record->wc_variation_id);

			if ($is_variation) {
				$result = $this->verify_and_fix_variation($product_record);
			} else {
				$result = $this->verify_and_fix_main_product($product_record);
			}

			// Update verification status
			$wpdb->update(
				$this->verification_table,
				array(
					'verification_status' => $result['success'] ? 'verified' : 'failed',
					'verification_message' => $result['message'],
					'last_verified' => current_time('mysql')
				),
				array('id' => $product_record->id),
				array('%s', '%s', '%s'),
				array('%d')
			);

			return array('verified' => $result['success'], 'message' => $result['message']);

		} catch (Exception $e) {
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

			return array('verified' => false, 'message' => $error_message);
		}
	}

	/**
	 * Verify and fix main product
	 */
	private function verify_and_fix_main_product($product_record) {
		// Get BC product
		$bc_product = $this->bc_api->get_product($product_record->bc_product_id);

		// Check for API errors
		if (isset($bc_product['error'])) {
			return array('success' => false, 'message' => 'Product not found in BigCommerce: ' . $bc_product['error']);
		}

		if (!isset($bc_product['data']['id'])) {
			return array('success' => false, 'message' => 'Product not found in BigCommerce');
		}

		// Get WC product
		$wc_product = wc_get_product($product_record->wc_product_id);
		if (!$wc_product) {
			return array('success' => false, 'message' => 'WooCommerce product not found');
		}

		$fixes = array();
		$fix_messages = array();
		$issues = array();

		// 1. Check and fix pricing
		$this->check_and_fix_pricing($bc_product['data'], $wc_product, $fixes, $fix_messages, $issues);

		// 2. Check and fix inventory
		$this->check_and_fix_inventory($wc_product, $fixes, $fix_messages, $issues);

		// 3. Check and fix basic product data
		$this->check_and_fix_basic_data($bc_product['data'], $wc_product, $fixes, $fix_messages, $issues);

		// 4. Check and fix supplier
		$this->check_and_fix_supplier($bc_product['data'], $product_record->wc_product_id, $fixes, $fix_messages, $issues);

		if (!empty($fixes)) {
			$result = $this->bc_api->update_product($product_record->bc_product_id, $fixes);

			if (isset($result['error'])) {
				return array('success' => false, 'message' => 'Fix failed: ' . $result['error']);
			}
		}

		// Build message
		$message = 'Product verified';
		if (!empty($fix_messages)) {
			$message .= ' and fixed: ' . implode(', ', $fix_messages);
		}
		if (!empty($issues)) {
			$message .= ' | Issues: ' . implode(', ', $issues);
		}

		return array('success' => true, 'message' => $message);
	}

	private function check_and_fix_pricing($bc_data, $wc_product, &$fixes, &$fix_messages, &$issues) {
		$bc_price = $bc_data['price'] ?? 0;
		$wc_price = $wc_product->get_regular_price();

		if (($bc_price == 0 || empty($bc_price)) && $wc_price && $wc_price > 0) {
			$fixes['price'] = (float) $wc_price;
			$fixes['retail_price'] = (float) $wc_price;
			$fix_messages[] = "Fixed price: {$wc_price}";
		} elseif ($bc_price != $wc_price && $wc_price) {
			$issues[] = "price_mismatch(BC:{$bc_price},WC:{$wc_price})";
		}

		// Sale price
		$wc_sale_price = $wc_product->get_sale_price();
		if ($wc_sale_price && $wc_sale_price > 0) {
			$fixes['sale_price'] = (float) $wc_sale_price;
			$fix_messages[] = "Updated sale price: {$wc_sale_price}";
		}
	}

	private function check_and_fix_inventory($wc_product, &$fixes, &$fix_messages, &$issues) {
		if ($wc_product->is_type('variable')) {
			$fixes['inventory_tracking'] = 'variant'; // This is crucial
			$fix_messages[] = "Set inventory tracking to variant level";
		} elseif ($wc_product->get_manage_stock()) {
			$fixes['inventory_tracking'] = 'product';
			$fixes['inventory_level'] = (int) ($wc_product->get_stock_quantity() ?: 0);
			$fix_messages[] = "Updated inventory: " . $fixes['inventory_level'];
		}
	}

	private function check_and_fix_basic_data($bc_data, $wc_product, &$fixes, &$fix_messages, &$issues) {
		// SKU
		$bc_sku = $bc_data['sku'] ?? '';
		$wc_sku = $wc_product->get_sku();

		if ($bc_sku !== $wc_sku && !empty($wc_sku)) {
			$fixes['sku'] = $wc_sku;
			$fix_messages[] = "Fixed SKU: {$bc_sku} → {$wc_sku}";
		} elseif (empty($wc_sku)) {
			$issues[] = "sku_missing";
		}

		// Name
		$bc_name = $bc_data['name'] ?? '';
		$wc_name = $wc_product->get_name();

		if ($bc_name !== $wc_name && !empty($wc_name)) {
			$fixes['name'] = $wc_name;
			$fix_messages[] = "Fixed name";
		}

		// Weight
		$bc_weight = $bc_data['weight'] ?? 0;
		$wc_weight = $wc_product->get_weight();

		if ($wc_weight && (empty($bc_weight) || $bc_weight == 0)) {
			$weight_data = $this->fix_and_prepare_weight($wc_weight);
			$fixes['weight'] = (float) $weight_data['corrected_weight_grams'];
			$fix_messages[] = "Fixed weight: {$wc_weight} → {$weight_data['corrected_weight_grams']}g";
		}

		// Images count check only
		$bc_images = $bc_data['images'] ?? array();
		$wc_image_ids = $wc_product->get_gallery_image_ids();
		$wc_featured_image = $wc_product->get_image_id();
		$total_wc_images = count($wc_image_ids) + ($wc_featured_image ? 1 : 0);

		if (count($bc_images) === 0 && $total_wc_images > 0) {
			$issues[] = "images_missing";
		} elseif (count($bc_images) !== $total_wc_images) {
			$issues[] = "image_count_mismatch";
		}
	}

	private function check_and_fix_supplier($bc_data, $wc_product_id, &$fixes, &$fix_messages, &$issues) {
		$supplier_name = $this->get_supplier_name($wc_product_id);
		$existing_fields = $bc_data['custom_fields'] ?? array();
		$updated_fields = array();
		$supplier_exists = false;
		$needs_update = false;

		foreach ($existing_fields as $field) {
			if ($field['name'] === '__supplier') {
				$supplier_exists = true;
				$current_value = $field['value'] ?? '';
				$new_value = $supplier_name ?: '';

				// Only update if values are different and we have a new value
				if ($current_value !== $new_value && !empty($new_value)) {
					$updated_fields[] = array(
						'id' => $field['id'],
						'name' => '__supplier',
						'value' => $new_value
					);
					$needs_update = true;
					$fix_messages[] = "Updated supplier: {$current_value} → {$new_value}";
				} else {
					$updated_fields[] = $field; // Keep existing
					if (!empty($current_value)) {
						$fix_messages[] = "Supplier field already exists: {$current_value}";
					}
				}
			} else {
				$updated_fields[] = $field; // Keep other fields
			}
		}

		// Only add supplier field if it doesn't exist AND we have a supplier
		if (!$supplier_exists && !empty($supplier_name)) {
			$updated_fields[] = array(
				'name' => '__supplier',
				'value' => $supplier_name
			);
			$needs_update = true;
			$fix_messages[] = "Added supplier field: {$supplier_name}";
		}

		// Only include custom_fields in fixes if we actually need to update them
		if ($needs_update) {
			$fixes['custom_fields'] = $updated_fields;
		}
	}

	/**
	 * Verify and fix variation
	 */
	private function verify_and_fix_variation($product_record) {
		// First check if the variation record is valid
		if (empty($product_record->bc_variation_id)) {
			// Create the missing variation
			return $this->create_missing_variation($product_record);
		}

		// Get BC variant
		$bc_variant = $this->bc_api->get_product_variant($product_record->bc_product_id, $product_record->bc_variation_id);

		// Check for API errors
		if (isset($bc_variant['error'])) {
			return array('success' => false, 'message' => 'Variation not found in BigCommerce: ' . $bc_variant['error']);
		}

		if (!isset($bc_variant['data']['id'])) {
			return array('success' => false, 'message' => 'Variation not found in BigCommerce');
		}

		// Get WC variation
		$wc_variation = wc_get_product($product_record->wc_variation_id);
		if (!$wc_variation) {
			return array('success' => false, 'message' => 'WooCommerce variation not found');
		}

		$fixes = array();
		$fix_messages = array();

		// 1. Fix variation pricing (only if needed)
		$bc_price = $bc_variant['data']['price'] ?? 0;
		$wc_price = $wc_variation->get_regular_price();

		if (($bc_price == 0 || empty($bc_price)) && $wc_price && $wc_price > 0) {
			$fixes['price'] = (float) $wc_price;
			$fix_messages[] = "Fixed price: {$wc_price}";
		}

		// 2. Fix variation inventory (only if different)
		if ($wc_variation->get_manage_stock()) {
			$wc_stock = (int) ($wc_variation->get_stock_quantity() ?: 0);
			$bc_stock = (int) ($bc_variant['data']['inventory_level'] ?? 0);

			if ($bc_stock !== $wc_stock) {
				$fixes['inventory_level'] = $wc_stock;
				$fix_messages[] = "Updated stock: {$bc_stock} → {$wc_stock}";
			}
		}

		// 3. Fix variation SKU (only if different)
		$bc_sku = $bc_variant['data']['sku'] ?? '';
		$wc_sku = $wc_variation->get_sku();

		if ($bc_sku !== $wc_sku && !empty($wc_sku)) {
			$fixes['sku'] = $wc_sku;
			$fix_messages[] = "Fixed SKU: {$bc_sku} → {$wc_sku}";
		}

		// Apply fixes only if needed
		if (!empty($fixes)) {
			$result = $this->bc_api->update_product_variant($product_record->bc_product_id, $product_record->bc_variation_id, $fixes);

			if (isset($result['error'])) {
				return array('success' => false, 'message' => 'Variation fix failed: ' . $result['error']);
			}

			$message = 'Variation verified and fixed: ' . implode(', ', $fix_messages);
		} else {
			$message = 'Variation verified - no fixes needed';
		}

		return array('success' => true, 'message' => $message);
	}

	/**
	 * Create missing variation in BigCommerce
	 */
	private function create_missing_variation($product_record) {
		// Get WC variation
		$wc_variation = wc_get_product($product_record->wc_variation_id);
		if (!$wc_variation) {
			return array('success' => false, 'message' => 'WooCommerce variation not found');
		}

		// Get parent product to access options
		$parent_product = wc_get_product($wc_variation->get_parent_id());
		if (!$parent_product) {
			return array('success' => false, 'message' => 'Parent product not found');
		}

		// Get variation attributes
		$attributes = $wc_variation->get_variation_attributes();
		$option_values = array();

		foreach ($attributes as $attribute_name => $attribute_value) {
			// Skip if no value set
			if (empty($attribute_value)) {
				continue;
			}

			// Clean attribute name
			$taxonomy = str_replace('attribute_', '', $attribute_name);
			$clean_name = str_replace('pa_', '', $taxonomy);

			// Get the term object to get proper label
			$term = get_term_by('slug', $attribute_value, $taxonomy);
			$label = $term ? $term->name : $attribute_value;

			// For creating variations, use the format from your migrator
			$option_values[] = array(
				'option_display_name' => ucfirst(str_replace('-', ' ', $clean_name)),
				'label' => $label
			);
		}

		// Prepare variation data using the same format as your migrator
		$variation_data = array(
			'sku' => $wc_variation->get_sku() ?: 'VAR-' . $wc_variation->get_id(),
			'price' => (float) ($wc_variation->get_regular_price() ?: $parent_product->get_regular_price() ?: 0),
			'option_values' => $option_values
		);

		// Add weight if available (no conversion needed - both use grams)
		$variant_weight = $wc_variation->get_weight() ?: $parent_product->get_weight();
		if ($variant_weight && $variant_weight > 0) {
			$variation_data['weight'] = (float) $variant_weight;
		}

		// Handle variant sale price
		$variant_sale_price = $wc_variation->get_sale_price();
		if ($variant_sale_price !== '' && $variant_sale_price !== null && (float) $variant_sale_price > 0) {
			$variation_data['sale_price'] = (float) $variant_sale_price;
		}

		// Retail price
		$retail_price = $wc_variation->get_regular_price() ?: $parent_product->get_regular_price();
		if ($retail_price > 0) {
			$variation_data['retail_price'] = (float) $retail_price;
		}

		// Set inventory if WC manages stock
		if ($wc_variation->get_manage_stock()) {
			$variation_data['inventory_level'] = (int) ($wc_variation->get_stock_quantity() ?: 0);
			$variation_data['inventory_tracking'] = 'variant';
		}

		// Add variant image if different from parent
		$variant_image_id = $wc_variation->get_image_id();
		if ($variant_image_id && $variant_image_id != $parent_product->get_image_id()) {
			$image_url = wp_get_attachment_url($variant_image_id);
			if ($image_url) {
				$variation_data['image_url'] = $image_url;
			}
		}

		// Create variation in BigCommerce
		$result = $this->bc_api->create_product_variant($product_record->bc_product_id, $variation_data);

		if (isset($result['error'])) {
			return array('success' => false, 'message' => 'Failed to create variation: ' . $result['error']);
		}

		if (isset($result['data']['id'])) {
			// Update the verification record with the new BC variation ID
			global $wpdb;
			$wpdb->update(
				$this->verification_table,
				array('bc_variation_id' => $result['data']['id']),
				array('id' => $product_record->id),
				array('%d'),
				array('%d')
			);

			return array('success' => true, 'message' => 'Created missing variation with ID: ' . $result['data']['id']);
		}

		return array('success' => false, 'message' => 'Failed to create variation - no ID returned');
	}

	/**
	 * Get supplier name (reuse from sync system)
	 */
	private function get_supplier_name($wc_product_id) {
		global $wpdb;

		try {
			$supplier_id = $wpdb->get_var($wpdb->prepare(
				"SELECT supplier_id FROM {$wpdb->prefix}atum_product_data WHERE product_id = %d",
				$wc_product_id
			));

			if (!$supplier_id) {
				return null;
			}

			$supplier_name = $wpdb->get_var($wpdb->prepare(
				"SELECT post_title FROM {$wpdb->prefix}posts WHERE ID = %d AND post_type = 'atum_supplier'",
				$supplier_id
			));

			return $supplier_name ?: null;

		} catch (Exception $e) {
			error_log("Error getting supplier for product {$wc_product_id}: " . $e->getMessage());
			return null;
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

			// Handle custom fields - check if weight_range_grams already exists
			if (!empty($weight_data['weight_range'])) {
				$existing_custom_fields = isset($bc_product['data']['custom_fields']) ? $bc_product['data']['custom_fields'] : array();
				$weight_range_exists = false;
				$updated_custom_fields = array();

				// Check existing custom fields
				foreach ($existing_custom_fields as $field) {
					if ($field['name'] === 'weight_range_grams') {
						$weight_range_exists = true;
						// Update the existing field
						$updated_custom_fields[] = array(
							'id' => $field['id'],
							'name' => 'weight_range_grams',
							'value' => $weight_data['weight_range']
						);
					} else {
						// Keep other existing fields
						$updated_custom_fields[] = $field;
					}
				}

				// If weight_range_grams doesn't exist, add it
				if (!$weight_range_exists) {
					$updated_custom_fields[] = array(
						'name' => 'weight_range_grams',
						'value' => $weight_data['weight_range']
					);
				}

				$update_data['custom_fields'] = $updated_custom_fields;
			}

			// Update product in BigCommerce
			$result = $this->bc_api->update_product($product_record->bc_product_id, $update_data);

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