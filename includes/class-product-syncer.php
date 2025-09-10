<?php
/**
 * Product Data Syncer Class
 * Syncs quantity, price, and supplier data for migrated products
 */
class WC_BC_Product_Syncer {

	private $bc_api;

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
	}

	public function sync_batch($batch_size = 20) {
		global $wpdb;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		// Get migrated products that need syncing
		$products_to_sync = $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT wc_product_id, bc_product_id 
             FROM $product_table 
             WHERE status = 'success' 
             AND wc_variation_id IS NULL
             AND bc_product_id IS NOT NULL
             AND (message NOT LIKE '%%Data synced%%' OR message IS NULL)
             LIMIT %d",
			$batch_size
		));

		if (empty($products_to_sync)) {
			return array(
				'success' => true,
				'message' => 'No products found requiring sync.',
				'processed' => 0,
				'updated' => 0,
				'failed' => 0,
				'remaining' => 0
			);
		}

		$processed = 0;
		$updated = 0;
		$failed = 0;

		foreach ($products_to_sync as $product_mapping) {
			try {
				$result = $this->sync_single_product($product_mapping->wc_product_id, $product_mapping->bc_product_id);

				if ($result['success']) {
					$updated++;
					// Mark as synced
					WC_BC_Database::update_mapping($product_mapping->wc_product_id, null, array(
						'message' => 'Product migrated successfully (Data synced)'
					));
				} else {
					$failed++;
				}

				$processed++;
				usleep(300000); // 0.3 second delay

			} catch (Exception $e) {
				$failed++;
				$processed++;
				error_log("Sync error for product {$product_mapping->wc_product_id}: " . $e->getMessage());
			}
		}

		// Get remaining count
		$remaining = $wpdb->get_var(
			"SELECT COUNT(DISTINCT wc_product_id) 
             FROM $product_table 
             WHERE status = 'success' 
             AND wc_variation_id IS NULL 
             AND bc_product_id IS NOT NULL
             AND (message NOT LIKE '%%Data synced%%' OR message IS NULL)"
		);

		$total_products = $wpdb->get_var(
			"SELECT COUNT(DISTINCT wc_product_id) 
             FROM $product_table 
             WHERE status = 'success' 
             AND wc_variation_id IS NULL 
             AND bc_product_id IS NOT NULL"
		);

		return array(
			'success' => true,
			'processed' => $processed,
			'updated' => $updated,
			'failed' => $failed,
			'remaining' => $remaining,
			'total_products' => $total_products
		);
	}

	private function sync_single_product($wc_product_id, $bc_product_id) {
		$wc_product = wc_get_product($wc_product_id);
		if (!$wc_product) {
			return array('success' => false, 'error' => 'WooCommerce product not found');
		}

		try {
			// Prepare sync data
			$sync_data = array();

			// 1. Update inventory/quantity
			if ($wc_product->get_manage_stock()) {
				$sync_data['inventory_level'] = (int) ($wc_product->get_stock_quantity() ?: 0);
				$sync_data['inventory_tracking'] = 'product';
			}

			// 2. Update pricing
			$regular_price = $wc_product->get_regular_price();
			if ($regular_price) {
				$sync_data['price'] = (float) $regular_price;
				$sync_data['retail_price'] = (float) $regular_price;
			}

			$sale_price = $wc_product->get_sale_price();
			if ($sale_price !== '' && $sale_price !== null && (float) $sale_price > 0) {
				$sync_data['sale_price'] = (float) $sale_price;
			}

			// 3. Get supplier data and update custom fields
			$supplier_name = $this->get_supplier_name($wc_product_id);

			if ($supplier_name || !empty($sync_data)) {
				// Get current custom fields
				$custom_fields_response = $this->bc_api->get_product_custom_fields($bc_product_id);
				$existing_fields = isset($custom_fields_response['data']) ? $custom_fields_response['data'] : array();

				$updated_fields = array();
				$supplier_field_exists = false;

				// Preserve existing fields and update supplier if needed
				foreach ($existing_fields as $field) {
					if ($field['name'] === '__supplier') {
						$supplier_field_exists = true;
						if ($supplier_name && $supplier_name !== $field['value']) {
							// Update supplier
							$updated_fields[] = array(
								'id' => $field['id'],
								'name' => '__supplier',
								'value' => $supplier_name
							);
						} else {
							// Keep existing
							$updated_fields[] = $field;
						}
					} else {
						// Keep other fields
						$updated_fields[] = $field;
					}
				}

				// Add supplier field if it doesn't exist
				if (!$supplier_field_exists && $supplier_name) {
					$updated_fields[] = array(
						'name' => '__supplier',
						'value' => $supplier_name
					);
				}

				// Add custom fields to sync data if updated
				if (!empty($updated_fields)) {
					$sync_data['custom_fields'] = $updated_fields;
				}
			}

			if (empty($sync_data)) {
				return array('success' => true, 'message' => 'No data to sync');
			}

			// Update product in BigCommerce
			$result = $this->bc_api->update_product($bc_product_id, $sync_data);

			if (isset($result['error'])) {
				return array('success' => false, 'error' => $result['error']);
			}

			return array('success' => true, 'synced_data' => array_keys($sync_data));

		} catch (Exception $e) {
			return array('success' => false, 'error' => $e->getMessage());
		}
	}

	private function get_supplier_name($wc_product_id) {
		global $wpdb;

		try {
			// Get supplier_id from ATUM plugin table
			$supplier_id = $wpdb->get_var($wpdb->prepare(
				"SELECT supplier_id FROM {$wpdb->prefix}atum_product_data WHERE product_id = %d",
				$wc_product_id
			));

			if (!$supplier_id) {
				return null;
			}

			// Get supplier name from posts table
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
}