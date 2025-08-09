<?php
/**
 * Product Migrator Class
 */
class WC_BC_Product_Migrator {

	private $bc_api;
	private $category_map = array();
	private $option_map = array();
	private $attribute_map = array();

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->load_mappings();
	}

	/**
	 * Load all mappings at once for better performance
	 */
	private function load_mappings() {
		$this->category_map = get_option('wc_bc_category_mapping', array());
		$this->option_map = get_option('wc_bc_option_mapping', array());
		$this->attribute_map = get_option('wc_bc_attribute_mapping', array());
	}

	public function migrate_product($wc_product_id) {
		$product = wc_get_product($wc_product_id);

		if (!$product) {
			return array('error' => 'Product not found');
		}

		try {
			// Prepare product data
			$product_data = $this->prepare_product_data($product);

			// Create product in BigCommerce
			$result = $this->bc_api->create_product($product_data);

			if (isset($result['error'])) {
				throw new Exception($result['error']);
			}

			$bc_product_id = $result['data']['id'];

			// Update mapping
			WC_BC_Database::update_mapping($wc_product_id, null, array(
				'bc_product_id' => $bc_product_id,
				'status' => 'success',
				'message' => 'Product migrated successfully',
			));

			// Handle variations if it's a variable product
			if ($product->is_type('variable')) {
				$this->migrate_variations($product, $bc_product_id);
			}

			// Apply B2B pricing rules after product creation
			$this->apply_b2b_pricing($wc_product_id, $bc_product_id);

			return array('success' => true, 'bc_product_id' => $bc_product_id);

		} catch (Exception $e) {
			WC_BC_Database::update_mapping($wc_product_id, null, array(
				'status' => 'error',
				'message' => $e->getMessage(),
			));

			return array('error' => $e->getMessage());
		}
	}

	private function prepare_product_data($product) {
		$data = array(
			'name' => $product->get_name(),
			'type' => 'physical',
			'sku' => $product->get_sku(),
			'description' => $product->get_description(),
			'weight' => (float) $product->get_weight(),
			'price' => (float) $product->get_regular_price(),
			'sale_price' => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
			'retail_price' => (float) $product->get_regular_price(),
			'inventory_tracking' => $product->get_manage_stock() ? 'product' : 'none',
			'inventory_level' => $product->get_stock_quantity() ?: 0,
			'is_visible' => $product->get_catalog_visibility() !== 'hidden',
			'categories' => $this->map_categories($product),
			'meta_fields' => $this->prepare_meta_fields($product),
			'custom_fields' => $this->prepare_custom_fields($product),
		);

		// Add images
		$images = $this->prepare_images($product);
		if (!empty($images)) {
			$data['images'] = $images;
		}

		// For simple products with attributes, add them as modifier options
		if ($product->is_type('simple')) {
			$modifiers = $this->prepare_simple_product_modifiers($product);
			if (!empty($modifiers)) {
				$data['modifiers'] = $modifiers;
			}
		}

		// Add related products data to custom fields
		$related_products = $this->prepare_related_products($product);
		if ($related_products) {
			$data['custom_fields'] = array_merge($data['custom_fields'], $related_products);
		}

		return $data;
	}

	private function prepare_images($product) {
		$images = array();

		// Main image
		$main_image_id = $product->get_image_id();
		if ($main_image_id) {
			$images[] = array(
				'is_thumbnail' => true,
				'sort_order' => 0,
				'image_url' => wp_get_attachment_url($main_image_id),
			);
		}

		// Gallery images
		$gallery_ids = $product->get_gallery_image_ids();
		$sort_order = 1;
		foreach ($gallery_ids as $image_id) {
			$images[] = array(
				'is_thumbnail' => false,
				'sort_order' => $sort_order++,
				'image_url' => wp_get_attachment_url($image_id),
			);
		}

		return $images;
	}

	private function map_categories($product) {
		$wc_categories = $product->get_category_ids();
		$bc_categories = array();

		foreach ($wc_categories as $cat_id) {
			if (isset($this->category_map[$cat_id])) {
				$bc_categories[] = (int) $this->category_map[$cat_id];
			}
		}

		return $bc_categories;
	}

	private function prepare_meta_fields($product) {
		$meta_fields = array();

		// Add any WooCommerce meta data that should be preserved
		// Example: Add product-specific SEO data
		$seo_title = get_post_meta($product->get_id(), '_yoast_wpseo_title', true);
		if ($seo_title) {
			$meta_fields[] = array(
				'permission_set' => 'read',
				'namespace' => 'seo',
				'key' => 'title',
				'value' => $seo_title
			);
		}

		return $meta_fields;
	}

	private function prepare_custom_fields($product) {
		$custom_fields = array();

		// Add WooCommerce product ID for reference
		$custom_fields[] = array(
			'name' => 'wc_product_id',
			'value' => (string) $product->get_id()
		);

		// Add product tags as custom field
		$tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
		if (!empty($tags)) {
			$custom_fields[] = array(
				'name' => 'product_tags',
				'value' => implode(', ', $tags)
			);
		}

		// Add any other custom meta fields from WooCommerce
		// Skip attributes as they're handled separately

		return $custom_fields;
	}

	/**
	 * Prepare modifiers for simple products with attributes
	 */
	private function prepare_simple_product_modifiers($product) {
		$modifiers = array();
		$attributes = $product->get_attributes();

		foreach ($attributes as $attribute) {
			// Skip variation attributes
			if ($attribute->get_variation()) {
				continue;
			}

			$modifier = array(
				'type' => 'text',
				'required' => false,
				'display_name' => $attribute->get_name(),
				'config' => array(
					'default_value' => implode(', ', $attribute->get_options()),
					'text_min_length' => 0,
					'text_max_length' => 255
				)
			);

			$modifiers[] = $modifier;
		}

		return $modifiers;
	}

	private function migrate_variations($product, $bc_product_id) {
		$variations = $product->get_available_variations();

		foreach ($variations as $variation_data) {
			$variation = wc_get_product($variation_data['variation_id']);
			if (!$variation) continue;

			$variant_data = array(
				'sku' => $variation->get_sku(),
				'price' => (float) $variation->get_regular_price(),
				'sale_price' => $variation->get_sale_price() ? (float) $variation->get_sale_price() : null,
				'retail_price' => (float) $variation->get_regular_price(),
				'weight' => (float) $variation->get_weight(),
				'inventory_level' => $variation->get_stock_quantity() ?: 0,
				'option_values' => $this->prepare_option_values($variation),
			);

			$result = $this->bc_api->create_product_variant($bc_product_id, $variant_data);

			if (isset($result['data']['id'])) {
				WC_BC_Database::update_mapping(
					$product->get_id(),
					$variation->get_id(),
					array(
						'bc_product_id' => $bc_product_id,
						'bc_variation_id' => $result['data']['id'],
						'status' => 'success',
						'message' => 'Variation migrated successfully',
					)
				);
			} else {
				$error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
				WC_BC_Database::update_mapping(
					$product->get_id(),
					$variation->get_id(),
					array(
						'bc_product_id' => $bc_product_id,
						'status' => 'error',
						'message' => 'Failed to migrate variation: ' . $error_msg,
					)
				);
			}
		}
	}

	private function prepare_option_values($variation) {
		$option_values = array();
		$attributes = $variation->get_variation_attributes();

		foreach ($attributes as $attribute_name => $attribute_value) {
			// Clean attribute name
			$clean_name = str_replace('attribute_pa_', '', $attribute_name);
			$clean_name = str_replace('attribute_', '', $clean_name);

			// Map to BigCommerce option ID if available
			if (isset($this->option_map[$clean_name])) {
				$option_values[] = array(
					'option_id' => (int) $this->option_map[$clean_name],
					'label' => $attribute_value,
				);
			} else {
				// Fallback to display name
				$option_values[] = array(
					'option_display_name' => ucfirst(str_replace('_', ' ', $clean_name)),
					'label' => $attribute_value,
				);
			}
		}

		return $option_values;
	}

	/**
	 * Apply B2B pricing rules (all products require login to see price)
	 */
	private function apply_b2b_pricing($wc_product_id, $bc_product_id) {
		// Since all products require login to see price,
		// this should be handled via BigCommerce customer groups and price lists
		// The B2B handler class should have already set up the price lists

		$b2b_handler = new WC_BC_B2B_Handler();
		$b2b_handler->apply_b2b_pricing($wc_product_id, $bc_product_id);
	}

	/**
	 * Prepare related products (cross-sells and up-sells)
	 */
	private function prepare_related_products($product) {
		$related_fields = array();

		// Get cross-sell products
		$cross_sells = $product->get_cross_sell_ids();
		if (!empty($cross_sells)) {
			$cross_sell_skus = array();
			foreach ($cross_sells as $product_id) {
				try {
					$cross_sell_product = wc_get_product($product_id);
					if ($cross_sell_product && $cross_sell_product->get_sku()) {
						$cross_sell_skus[] = $cross_sell_product->get_sku();
					}
				} catch (Exception $e) {
					// Skip if product doesn't exist
					continue;
				}
			}

			if (!empty($cross_sell_skus)) {
				$related_fields[] = array(
					'name' => 'cross_sell_products',
					'value' => implode(',', $cross_sell_skus)
				);
			}
		}

		// Get up-sell products
		$upsells = $product->get_upsell_ids();
		if (!empty($upsells)) {
			$upsell_skus = array();
			foreach ($upsells as $product_id) {
				try {
					$upsell_product = wc_get_product($product_id);
					if ($upsell_product && $upsell_product->get_sku()) {
						$upsell_skus[] = $upsell_product->get_sku();
					}
				} catch (Exception $e) {
					// Skip if product doesn't exist
					continue;
				}
			}

			if (!empty($upsell_skus)) {
				$related_fields[] = array(
					'name' => 'upsell_products',
					'value' => implode(',', $upsell_skus)
				);
			}
		}

		return $related_fields;
	}

	/**
	 * Get BigCommerce product ID from WooCommerce product ID
	 */
	private function get_bc_product_id($wc_product_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		return $wpdb->get_var($wpdb->prepare(
			"SELECT bc_product_id FROM $table_name WHERE wc_product_id = %d AND wc_variation_id IS NULL",
			$wc_product_id
		));
	}
}