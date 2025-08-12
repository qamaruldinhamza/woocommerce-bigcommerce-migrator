<?php
/**
 * Product Migrator Class
 */
class WC_BC_Product_Migrator {

	private $bc_api;
	private $category_map = array();
	private $option_map = array();
	private $option_value_map = array();
	private $attribute_migrator;

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->attribute_migrator = new WC_BC_Attribute_Migrator();
		$this->load_mappings();
	}

	/**
	 * Load all mappings at once for better performance
	 */
	private function load_mappings() {
		$this->category_map = get_option('wc_bc_category_mapping', array());
		$this->option_map = get_option('wc_bc_option_mapping', array());
		$this->option_value_map = get_option('wc_bc_option_value_mapping', array());
	}

	public function migrate_product($wc_product_id) {
		$product = wc_get_product($wc_product_id);

		if (!$product) {
			return array('error' => 'Product not found');
		}

		try {
			// For variable products, first ensure options are created
			if ($product->is_type('variable')) {
				$this->ensure_product_options($product);
			}

			// Prepare product data
			$product_data = $this->prepare_product_data($product);

			// Create product in BigCommerce
			$result = $this->bc_api->create_product($product_data);

			if (isset($result['error'])) {
				throw new Exception($result['error'] . ' - ' . json_encode($result));
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

	/**
	 * Ensure product options exist in BigCommerce before creating variants
	 */
	private function ensure_product_options($product) {
		$attributes = $product->get_variation_attributes();

		foreach ($attributes as $attribute_name => $values) {
			// Clean attribute name
			$clean_name = str_replace('pa_', '', $attribute_name);

			// Check if option already mapped
			if (!isset($this->option_map[$clean_name])) {
				// Get the attribute object
				$attribute = wc_get_attribute(wc_attribute_taxonomy_id_by_name($attribute_name));
				if ($attribute) {
					// Create option if not exists
					$option_data = array(
						'name' => $attribute->name,
						'display_name' => $attribute->name,
						'type' => 'dropdown'
					);

					$response = $this->bc_api->create_option($option_data);
					if (isset($response['data']['id'])) {
						$this->option_map[$clean_name] = $response['data']['id'];
						update_option('wc_bc_option_mapping', $this->option_map);
					}
				}
			}
		}
	}

	private function prepare_product_data($product) {
		$data = array(
			'name' => $product->get_name(),
			'type' => 'physical',
			'sku' => $product->get_sku() ?: 'SKU-' . $product->get_id(),
			'description' => $product->get_description(),
			'weight' => (float) ($product->get_weight() ?: 0),
			'price' => (float) ($product->get_regular_price() ?: 0),
			'sale_price' => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
			'retail_price' => (float) ($product->get_regular_price() ?: 0),
			'inventory_tracking' => $product->get_manage_stock() ? 'product' : 'none',
			'inventory_level' => (int) ($product->get_stock_quantity() ?: 0),
			'is_visible' => $product->get_catalog_visibility() !== 'hidden',
			'categories' => $this->map_categories($product),
			'custom_fields' => $this->prepare_custom_fields($product),
		);

		// Add SEO fields
		$seo_fields = $this->prepare_seo_fields($product);
		if (!empty($seo_fields)) {
			$data = array_merge($data, $seo_fields);
		}

		// Add images
		$images = $this->prepare_images($product);
		if (!empty($images)) {
			$data['images'] = $images;
		}

		// For simple products with attributes, add them as custom fields
		if ($product->is_type('simple')) {
			$simple_attributes = $this->prepare_simple_product_attributes($product);
			if (!empty($simple_attributes)) {
				$data['custom_fields'] = array_merge($data['custom_fields'], $simple_attributes);
			}
		}

		// For variable products, add option assignments
		if ($product->is_type('variable')) {
			$options = $this->prepare_product_options($product);
			if (!empty($options)) {
				$data['options'] = $options;
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
			$image_url = wp_get_attachment_url($main_image_id);
			if ($image_url) {
				$images[] = array(
					'is_thumbnail' => true,
					'sort_order' => 0,
					'image_url' => $image_url,
				);
			}
		}

		// Gallery images
		$gallery_ids = $product->get_gallery_image_ids();
		$sort_order = 1;
		foreach ($gallery_ids as $image_id) {
			$image_url = wp_get_attachment_url($image_id);
			if ($image_url) {
				$images[] = array(
					'is_thumbnail' => false,
					'sort_order' => $sort_order++,
					'image_url' => $image_url,
				);
			}
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

	private function prepare_seo_fields($product) {
		$seo_fields = array();

		// Yoast SEO
		$yoast_title = get_post_meta($product->get_id(), '_yoast_wpseo_title', true);
		$yoast_desc = get_post_meta($product->get_id(), '_yoast_wpseo_metadesc', true);

		if ($yoast_title) {
			$seo_fields['page_title'] = $yoast_title;
		}
		if ($yoast_desc) {
			$seo_fields['meta_description'] = $yoast_desc;
		}

		// RankMath SEO
		if (empty($seo_fields)) {
			$rm_title = get_post_meta($product->get_id(), 'rank_math_title', true);
			$rm_desc = get_post_meta($product->get_id(), 'rank_math_description', true);

			if ($rm_title) {
				$seo_fields['page_title'] = $rm_title;
			}
			if ($rm_desc) {
				$seo_fields['meta_description'] = $rm_desc;
			}
		}

		// Custom URL
		$seo_fields['custom_url'] = array(
			'url' => '/' . $product->get_slug() . '/',
			'is_customized' => true
		);

		return $seo_fields;
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

		// Add short description if exists
		$short_description = $product->get_short_description();
		if ($short_description) {
			$custom_fields[] = array(
				'name' => 'short_description',
				'value' => strip_tags($short_description)
			);
		}

		return $custom_fields;
	}

	/**
	 * Prepare attributes for simple products as custom fields
	 */
	private function prepare_simple_product_attributes($product) {
		$attribute_fields = array();
		$attributes = $product->get_attributes();

		foreach ($attributes as $attribute) {
			// Skip variation attributes
			if ($attribute->get_variation()) {
				continue;
			}

			$options = $attribute->get_options();
			$values = array();

			// Get term names if it's a taxonomy
			if ($attribute->is_taxonomy()) {
				$terms = get_terms(array(
					'taxonomy' => $attribute->get_name(),
					'include' => $options,
					'hide_empty' => false,
				));
				if (!is_wp_error($terms)) {
					foreach ($terms as $term) {
						$values[] = $term->name;
					}
				}
			} else {
				$values = $options;
			}

			if (!empty($values)) {
				$attribute_fields[] = array(
					'name' => wc_attribute_label($attribute->get_name()),
					'value' => implode(', ', $values)
				);
			}
		}

		return $attribute_fields;
	}

	/**
	 * Prepare product options for variable products
	 */
	private function prepare_product_options($product) {
		$options = array();
		$attributes = $product->get_variation_attributes();

		foreach ($attributes as $attribute_name => $values) {
			$clean_name = str_replace('pa_', '', $attribute_name);

			if (isset($this->option_map[$clean_name])) {
				$options[] = (int) $this->option_map[$clean_name];
			}
		}

		return $options;
	}

	private function migrate_variations($product, $bc_product_id) {
		$variations = $product->get_available_variations();
		$success_count = 0;
		$error_count = 0;

		foreach ($variations as $variation_data) {
			$variation = wc_get_product($variation_data['variation_id']);
			if (!$variation) continue;

			// Prepare variant data
			$variant_data = array(
				'sku' => $variation->get_sku() ?: 'VAR-' . $variation->get_id(),
				'price' => (float) ($variation->get_regular_price() ?: $product->get_regular_price()),
				'sale_price' => $variation->get_sale_price() ? (float) $variation->get_sale_price() : null,
				'retail_price' => (float) ($variation->get_regular_price() ?: $product->get_regular_price()),
				'weight' => (float) ($variation->get_weight() ?: $product->get_weight() ?: 0),
				'inventory_level' => (int) ($variation->get_stock_quantity() ?: 0),
				'inventory_tracking' => $variation->get_manage_stock() ? 'variant' : 'none',
			);

			// Prepare option values
			$option_values = $this->prepare_variant_option_values($variation);
			if (!empty($option_values)) {
				$variant_data['option_values'] = $option_values;
			}

			// Add variant image if different from parent
			$variant_image_id = $variation->get_image_id();
			if ($variant_image_id && $variant_image_id != $product->get_image_id()) {
				$image_url = wp_get_attachment_url($variant_image_id);
				if ($image_url) {
					$variant_data['image_url'] = $image_url;
				}
			}

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
				$success_count++;
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
				$error_count++;
			}
		}

		return array('success' => $success_count, 'errors' => $error_count);
	}

	private function prepare_variant_option_values($variation) {
		$option_values = array();
		$attributes = $variation->get_variation_attributes();

		foreach ($attributes as $attribute_name => $attribute_value) {
			// Skip if no value set
			if (empty($attribute_value)) {
				continue;
			}

			// Clean attribute name
			$taxonomy = str_replace('attribute_', '', $attribute_name);
			$clean_name = str_replace('pa_', '', $taxonomy);

			// Get option ID
			$option_id = null;
			if (isset($this->option_map[$clean_name])) {
				$option_id = (int) $this->option_map[$clean_name];
			}

			if ($option_id) {
				// Get the term object to get proper label
				$term = get_term_by('slug', $attribute_value, $taxonomy);
				$label = $term ? $term->name : $attribute_value;

				// Check if we have a mapped option value ID
				$option_value_id = $this->attribute_migrator->get_bc_option_value_id($taxonomy, $term ? $term->term_id : 0);

				if ($option_value_id) {
					$option_values[] = array(
						'option_id' => $option_id,
						'id' => (int) $option_value_id
					);
				} else {
					$option_values[] = array(
						'option_id' => $option_id,
						'label' => $label
					);
				}
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
		$b2b_handler = new WC_BC_B2B_Handler();
		return $b2b_handler->apply_b2b_pricing($wc_product_id, $bc_product_id);
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