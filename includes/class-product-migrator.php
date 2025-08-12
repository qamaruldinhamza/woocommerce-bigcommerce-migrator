<?php
/**
 * Product Migrator Class
 */
class WC_BC_Product_Migrator {

	private $bc_api;
	private $category_map = array();
	private $product_option_map = array();
	private $option_value_map = array();

	// Define attributes that should be custom fields, not options
	private $non_variant_attributes = array('metal', 'metal-style', 'stone-type', 'cameo', 'handmade', 'non-amber-gemstone');

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->load_mappings();
	}

	/**
	 * Load all mappings at once for better performance
	 */
	private function load_mappings() {
		$this->category_map = get_option('wc_bc_category_mapping', array());
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
				throw new Exception($result['error'] . ' - ' . json_encode($result));
			}

			$bc_product_id = $result['data']['id'];

			// Update mapping
			WC_BC_Database::update_mapping($wc_product_id, null, array(
				'bc_product_id' => $bc_product_id,
				'status' => 'success',
				'message' => 'Product migrated successfully',
			));

			// Create options and variations for variable products
			if ($product->is_type('variable')) {
				$this->create_product_options($product, $bc_product_id);
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
	 * Create product options after product is created
	 */
	private function create_product_options($product, $bc_product_id) {
		if (!$product->is_type('variable')) {
			return true;
		}

		$attributes = $product->get_variation_attributes();
		$option_ids = array();

		foreach ($attributes as $attribute_name => $values) {
			$clean_name = str_replace('pa_', '', $attribute_name);

			// Skip non-variant attributes
			if (in_array($clean_name, $this->non_variant_attributes)) {
				continue;
			}

			// Get WooCommerce attribute data
			$wc_attribute = wc_get_attribute(wc_attribute_taxonomy_id_by_name('pa_' . $clean_name));
			$display_name = $wc_attribute ? $wc_attribute->name : ucfirst(str_replace('_', ' ', $clean_name));

			// Determine option type
			$option_type = $this->determine_option_type_by_name($clean_name);

			// Create option for this product
			$option_data = array(
				'product_id' => $bc_product_id,
				'display_name' => $display_name,
				'type' => $option_type,
				'sort_order' => 0
			);

			$response = $this->bc_api->create_product_option($bc_product_id, $option_data);

			if (isset($response['data']['id'])) {
				$option_id = $response['data']['id'];
				$option_ids[$clean_name] = $option_id;

				// Create option values
				$this->create_option_values_for_product($clean_name, $option_id, $values, $product);
			}
		}

		// Store the option mapping for this product
		$this->product_option_map[$product->get_id()] = $option_ids;

		return $option_ids;
	}

	/**
	 * Determine option type based on attribute name
	 */
	private function determine_option_type_by_name($attribute_name) {
		$attribute_name = strtolower($attribute_name);

		// Color attributes should use swatch
		if (strpos($attribute_name, 'color') !== false || strpos($attribute_name, 'colour') !== false) {
			return 'swatch';
		}

		// Size attributes should use rectangles
		if (strpos($attribute_name, 'size') !== false) {
			return 'rectangles';
		}

		// Cut should use rectangles
		if ($attribute_name === 'cut') {
			return 'rectangles';
		}

		// Default to dropdown
		return 'dropdown';
	}

	/**
	 * Create option values for a product option
	 */
	private function create_option_values_for_product($attribute_name, $option_id, $values, $product) {
		$taxonomy = 'pa_' . $attribute_name;
		$value_map = array();

		// Get all variation values actually used by this product
		$used_values = array();
		if ($product->is_type('variable')) {
			$variations = $product->get_available_variations();
			foreach ($variations as $variation_data) {
				$variation = wc_get_product($variation_data['variation_id']);
				if ($variation) {
					$var_attributes = $variation->get_variation_attributes();
					foreach ($var_attributes as $var_attr_name => $var_attr_value) {
						if (str_replace('attribute_', '', $var_attr_name) === $taxonomy) {
							$used_values[] = $var_attr_value;
						}
					}
				}
			}
			$used_values = array_unique($used_values);
		}

		foreach ($values as $value_slug) {
			// Only create values that are actually used in variations
			if (!empty($used_values) && !in_array($value_slug, $used_values)) {
				continue;
			}

			$term = get_term_by('slug', $value_slug, $taxonomy);
			if (!$term) continue;

			$value_data = array(
				'label' => $term->name,
				'sort_order' => (int) ($term->term_order ?: 0),
				'is_default' => false
			);

			// Add color data if it's a color attribute
			if (strpos($attribute_name, 'color') !== false || $attribute_name === 'multi-color') {
				$color = $this->get_color_value($term);
				if ($color) {
					$value_data['value_data'] = array(
						'colors' => array($color)
					);
				}
			}

			$response = $this->bc_api->create_product_option_value($option_id, $value_data);

			if (isset($response['data']['id'])) {
				$value_map[$value_slug] = $response['data']['id'];
				// Store value mapping for this product
				$this->option_value_map[$taxonomy . '_' . $term->term_id . '_' . $product->get_id()] = $response['data']['id'];
			}
		}

		return $value_map;
	}

	/**
	 * Get color hex value for color swatches
	 */
	private function get_color_value($term) {
		// Extended color mapping for jewelry
		$color_map = array(
			'antique' => '#D2691E',
			'black' => '#000000',
			'blue' => '#0000FF',
			'butterscotch' => '#E3A857',
			'cherry' => '#DE3163',
			'citrine' => '#E4D00A',
			'cognac' => '#9F381D',
			'green' => '#008000',
			'multi-color' => '#FF00FF', // Use a default for multi
			'natural' => '#F5DEB3',
			'purple' => '#800080',
			'white' => '#FFFFFF',
			'gold' => '#FFD700',
			'silver' => '#C0C0C0',
			'rose-gold' => '#B76E79',
			'copper' => '#B87333',
			'brass' => '#B5651D',
			'bronze' => '#CD7F32',
			'platinum' => '#E5E4E2',
			'pearl' => '#F0EAD6',
			'amber' => '#FFBF00',
			'amethyst' => '#9966CC',
			'aqua' => '#00FFFF',
			'turquoise' => '#40E0D0',
			'sapphire' => '#0F52BA',
			'emerald' => '#50C878',
			'ruby' => '#E0115F',
			'opal' => '#A8C3BC',
			'onyx' => '#353839',
			'topaz' => '#FFC87C',
			'garnet' => '#733635',
			'coral' => '#FF7F50',
			'jade' => '#00A86B',
			'lapis' => '#26619C',
			'malachite' => '#0BDA51',
			'moonstone' => '#F0F8FF',
			'obsidian' => '#3B3C36',
			'peridot' => '#B0BF1A',
			'quartz' => '#F9E6E6',
			'tourmaline' => '#FF1493'
		);

		$term_name = strtolower($term->name);
		$term_slug = strtolower($term->slug);

		// First try exact match
		if (isset($color_map[$term_name])) {
			return $color_map[$term_name];
		}
		if (isset($color_map[$term_slug])) {
			return $color_map[$term_slug];
		}

		// Then try partial match
		foreach ($color_map as $color_name => $hex) {
			if (strpos($term_slug, $color_name) !== false || strpos($term_name, $color_name) !== false) {
				return $hex;
			}
		}

		// Check for meta data from color swatch plugins
		$color = get_term_meta($term->term_id, 'product_attribute_color', true);
		if (!$color) {
			$color = get_term_meta($term->term_id, 'pa_color', true);
		}

		return $color ?: '#CCCCCC'; // Default gray if no match
	}

	/**
	 * Prepare attributes for all products as custom fields
	 */
	private function prepare_product_attributes_as_custom_fields($product) {
		$attribute_fields = array();
		$attributes = $product->get_attributes();

		foreach ($attributes as $attribute) {
			$attribute_name = $attribute->get_name();
			$clean_name = str_replace('pa_', '', $attribute_name);

			// Include non-variant attributes and simple product attributes
			if (in_array($clean_name, $this->non_variant_attributes) ||
			    ($product->is_type('simple') && !$attribute->get_variation()) ||
			    (!$product->is_type('variable') && !$attribute->get_variation())) {

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
					// Use proper attribute label
					$label = wc_attribute_label($attribute->get_name());

					$attribute_fields[] = array(
						'name' => $label,
						'value' => implode(', ', $values)
					);
				}
			}
		}

		return $attribute_fields;
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

		// Add all product attributes as custom fields
		$attribute_fields = $this->prepare_product_attributes_as_custom_fields($product);
		if (!empty($attribute_fields)) {
			$custom_fields = array_merge($custom_fields, $attribute_fields);
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

	private function migrate_variations($product, $bc_product_id) {
		$variations = $product->get_available_variations();
		$success_count = 0;
		$error_count = 0;

		// Get the product-specific option mapping
		$product_options = isset($this->product_option_map[$product->get_id()])
			? $this->product_option_map[$product->get_id()]
			: array();

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

			// Prepare option values using product-specific options
			$option_values = $this->prepare_variant_option_values($variation, $product_options);
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
				if (isset($result['errors'])) {
					$error_msg .= ' - ' . json_encode($result['errors']);
				}
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

	private function prepare_variant_option_values($variation, $product_options) {
		$option_values = array();
		$attributes = $variation->get_variation_attributes();
		$parent_product_id = $variation->get_parent_id();

		foreach ($attributes as $attribute_name => $attribute_value) {
			// Skip if no value set
			if (empty($attribute_value)) {
				continue;
			}

			// Clean attribute name
			$taxonomy = str_replace('attribute_', '', $attribute_name);
			$clean_name = str_replace('pa_', '', $taxonomy);

			// Skip non-variant attributes
			if (in_array($clean_name, $this->non_variant_attributes)) {
				continue;
			}

			// Get option ID from product-specific mapping
			$option_id = isset($product_options[$clean_name]) ? (int) $product_options[$clean_name] : null;

			if ($option_id) {
				// Get the term object to get proper label
				$term = get_term_by('slug', $attribute_value, $taxonomy);
				$label = $term ? $term->name : $attribute_value;

				// Check if we have a mapped option value ID from this product
				$option_value_id = isset($this->option_value_map[$taxonomy . '_' . ($term ? $term->term_id : 0) . '_' . $parent_product_id])
					? $this->option_value_map[$taxonomy . '_' . ($term ? $term->term_id : 0) . '_' . $parent_product_id]
					: null;

				if ($option_value_id) {
					$option_values[] = array(
						'option_id' => $option_id,
						'id' => (int) $option_value_id
					);
				} else {
					// Fallback to label if no ID mapping
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