<?php
/**
 * Product Migrator Class
 */
class WC_BC_Product_Migrator {

	private $bc_api;
	private $category_map = array();
	private $brand_map = array();

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->load_category_mapping();
		$this->load_brand_mapping();
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
			'brand_id' => $this->map_brand($product),
			'meta_fields' => $this->prepare_meta_fields($product),
			'custom_fields' => $this->prepare_custom_fields($product),
		);

		// Add images
		$images = $this->prepare_images($product);
		if (!empty($images)) {
			$data['images'] = $images;
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
				$bc_categories[] = $this->category_map[$cat_id];
			}
		}

		return $bc_categories;
	}

	private function map_brand($product) {
		// You can customize this based on how brands are stored in WooCommerce
		$brand = $product->get_attribute('brand');
		if ($brand && isset($this->brand_map[$brand])) {
			return $this->brand_map[$brand];
		}
		return null;
	}

	private function prepare_meta_fields($product) {
		// Prepare meta fields based on your requirements
		return array();
	}

	private function prepare_custom_fields($product) {
		$custom_fields = array();
		$attributes = $product->get_attributes();

		foreach ($attributes as $attribute) {
			if (!$attribute->get_variation()) {
				$custom_fields[] = array(
					'name' => $attribute->get_name(),
					'value' => implode(', ', $attribute->get_options()),
				);
			}
		}

		return $custom_fields;
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
			}
		}
	}

	private function prepare_option_values($variation) {
		$option_values = array();
		$attributes = $variation->get_variation_attributes();

		foreach ($attributes as $attribute_name => $attribute_value) {
			// You'll need to map these to BigCommerce option IDs
			// This is a simplified version
			$option_values[] = array(
				'option_display_name' => str_replace('attribute_', '', $attribute_name),
				'label' => $attribute_value,
			);
		}

		return $option_values;
	}

	private function load_category_mapping() {
		// Load or create category mapping
		$this->category_map = get_option('wc_bc_category_mapping', array());
	}

	private function load_brand_mapping() {
		// Load or create brand mapping
		$this->brand_map = get_option('wc_bc_brand_mapping', array());
	}
}