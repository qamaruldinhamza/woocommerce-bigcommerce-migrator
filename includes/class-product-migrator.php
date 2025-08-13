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
		// If already mapped in our table, reuse BC ID and skip creating a duplicate
		$existing_bc_id = $this->get_bc_product_id($wc_product_id);

		// Prepare product data (always needed for variants/options and potential updates)
		$product_data = $this->prepare_product_data($product);

		$bc_product_id = null;

		if ($existing_bc_id) {
			// Treat as already created; proceed to options/variants using the existing BC product
			$bc_product_id = (int) $existing_bc_id;

			// Ensure mapping row is marked success for the parent (idempotent)
			WC_BC_Database::update_mapping($wc_product_id, null, array(
				'bc_product_id' => $bc_product_id,
				'status' => 'success',
				'message' => 'Product already mapped; proceeding with variants/options',
			));
		} else {
			// Create product in BigCommerce
			$result = $this->bc_api->create_product($product_data);

			// Normalize WP_Error or unexpected structures into exceptions
			if (is_wp_error($result)) {
				throw new Exception('BigCommerce API error: ' . $result->get_error_message());
			}

			if (!is_array($result) || !isset($result['data']) || !isset($result['data']['id'])) {
				throw new Exception('Unexpected response from BigCommerce when creating product: ' . json_encode($result));
			}

			$bc_product_id = (int) $result['data']['id'];

			// Update mapping for the parent
			WC_BC_Database::update_mapping($wc_product_id, null, array(
				'bc_product_id' => $bc_product_id,
				'status' => 'success',
				'message' => 'Product migrated successfully',
			));
		}

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
		$option_value_mappings = array();

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

			// Prepare option values for this attribute
			$option_values = array();
			$taxonomy = 'pa_' . $clean_name;

			// Get all variation values actually used by this product
			$used_values = $this->get_used_variation_values($product, $taxonomy);

			foreach ($values as $value_slug) {
				// Only include values that are actually used in variations
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
				if (strpos($clean_name, 'color') !== false || $clean_name === 'multi-color') {
					$color = $this->get_color_value($term);
					if ($color) {
						$value_data['value_data'] = array(
							'colors' => array($color)
						);
					}
				}

				$option_values[] = $value_data;
			}

			// Create option for this product WITH values
			$option_data = array(
				'product_id' => $bc_product_id,
				'display_name' => $display_name,
				'type' => $option_type,
				'sort_order' => 0,
				'option_values' => $option_values // Include values in the option creation
			);

			$response = $this->bc_api->create_product_option($bc_product_id, $option_data);

			if (isset($response['data']['id'])) {
				$option_id = $response['data']['id'];
				$option_ids[$clean_name] = $option_id;

				// Store the option value mappings from the response
				if (isset($response['data']['option_values'])) {
					foreach ($response['data']['option_values'] as $created_value) {
						// Map the label to the value ID for use in variants
						$key = $clean_name . '_' . $created_value['label'];
						$option_value_mappings[$key] = array(
							'id' => $created_value['id'],
							'option_id' => $option_id,
							'option_display_name' => $display_name,
							'label' => $created_value['label']
						);
					}
				}
			}
		}

		// Store the mappings for this product
		$this->product_option_map[$product->get_id()] = $option_ids;
		$this->option_value_map[$product->get_id()] = $option_value_mappings;

		return $option_ids;
	}

// Helper method to get used variation values
	private function get_used_variation_values($product, $taxonomy) {
		$used_values = array();
		$variations = $product->get_available_variations();

		foreach ($variations as $variation_data) {
			$variation = wc_get_product($variation_data['variation_id']);
			if ($variation) {
				$var_attributes = $variation->get_variation_attributes();
				foreach ($var_attributes as $var_attr_name => $var_attr_value) {
					if (str_replace('attribute_', '', $var_attr_name) === $taxonomy && !empty($var_attr_value)) {
						$used_values[] = $var_attr_value;
					}
				}
			}
		}

		return array_unique($used_values);
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

		// No need to create values separately - they're included in the option creation
		// Just map the values we'll use
		/*foreach ($values as $value_slug) {
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

			$response = $this->bc_api->create_option_value($option_id, $value_data);
			if (isset($response['data']['id'])) {
				$value_map[$value_slug] = $response['data']['id'];
				// Store value mapping for this product
				$this->option_value_map[$taxonomy . '_' . $term->term_id . '_' . $product->get_id()] = $response['data']['id'];
			}
		}*/

		foreach ($values as $value_slug) {
			if (!empty($used_values) && !in_array($value_slug, $used_values)) {
				continue;
			}

			$term = get_term_by('slug', $value_slug, $taxonomy);
			if ($term) {
				$value_map[$value_slug] = $term->name;
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
		// Get and fix weight
		$weight = $this->convert_and_fix_weight($product->get_weight());

		$data = array(
			'name' => $product->get_name(),
			'type' => 'physical',
			'sku' => $product->get_sku() ?: 'SKU-' . $product->get_id(),
			'description' => $product->get_description(),
			'weight' => (float) $weight, // Now in ounces
			'price' => (float) ($product->get_regular_price() ?: 0),
			'retail_price' => (float) ($product->get_regular_price() ?: 0),
			'inventory_tracking' => $product->get_manage_stock() ? 'product' : 'none',
			'inventory_level' => (int) ($product->get_stock_quantity() ?: 0),
			'is_visible' => $product->get_catalog_visibility() !== 'hidden',
			'categories' => $this->map_categories($product),
			'custom_fields' => $this->prepare_custom_fields($product),
		);

		// Handle sale price properly
		$sale_price = $product->get_sale_price();
		if ($sale_price !== '' && $sale_price !== null && (float) $sale_price > 0) {
			$data['sale_price'] = (float) $sale_price;
		}


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

			$variant_weight = $this->convert_and_fix_weight($variation->get_weight() ?: $product->get_weight());

			// Prepare variant data
			$variant_data = array(
				'sku' => $variation->get_sku() ?: 'VAR-' . $variation->get_id(),
				'option_values' => array(), // Initialize as empty array
				'price' => (float) ($variation->get_regular_price() ?: $product->get_regular_price() ?: 0),
			);

			// Add optional fields only if they have values
			if ($variant_weight > 0) {
				$variant_data['weight'] = (float) $variant_weight;
			}

			// Handle variant sale price properly
			$variant_sale_price = $variation->get_sale_price();
			if ($variant_sale_price !== '' && $variant_sale_price !== null && (float) $variant_sale_price > 0) {
				$variant_data['sale_price'] = (float) $variant_sale_price;
			}

			// Retail price
			$retail_price = $variation->get_regular_price() ?: $product->get_regular_price();
			if ($retail_price > 0) {
				$variant_data['retail_price'] = (float) $retail_price;
			}

			// Inventory
			if ($variation->get_manage_stock()) {
				$variant_data['inventory_level'] = (int) ($variation->get_stock_quantity() ?: 0);
				$variant_data['inventory_tracking'] = 'variant';
			}

			// Prepare option values - MUST have values
			$option_values = $this->prepare_variant_option_values($variation, $product_options);
			if (!empty($option_values)) {
				$variant_data['option_values'] = $option_values;
			} else {
				// Skip this variant if no option values
				$error_msg = 'No option values found for variant';
				WC_BC_Database::update_mapping(
					$product->get_id(),
					$variation->get_id(),
					array(
						'bc_product_id' => $bc_product_id,
						'status' => 'error',
						'message' => $error_msg,
					)
				);
				$error_count++;
				continue;
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

		// Get the option value mappings for this product
		$option_value_mappings = isset($this->option_value_map[$parent_product_id])
			? $this->option_value_map[$parent_product_id]
			: array();

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

			// Get the term object to get proper label
			$term = get_term_by('slug', $attribute_value, $taxonomy);
			$label = $term ? $term->name : $attribute_value;

			// Look up the option value mapping
			$mapping_key = $clean_name . '_' . $label;

			if (isset($option_value_mappings[$mapping_key])) {
				$mapping = $option_value_mappings[$mapping_key];

				// Use the exact structure from BigCommerce docs
				$option_values[] = array(
					'option_display_name' => $mapping['option_display_name'],
					'label' => $mapping['label'],
					'id' => (int) $mapping['id'],
					'option_id' => (int) $mapping['option_id']
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


	/**
	 * Convert weight from grams to ounces and fix formatting issues
	 */
	private function convert_and_fix_weight($weight_string) {
		if (empty($weight_string)) {
			return 0;
		}

		// Convert to string if it's not
		$weight_string = (string) $weight_string;

		// Check if it contains a range (dash or hyphen)
		if (strpos($weight_string, '-') !== false || strpos($weight_string, '–') !== false) {
			// Split by various dash types
			$parts = preg_split('/[-–—]/', $weight_string);

			if (count($parts) == 2) {
				$value1 = $this->parse_weight_value(trim($parts[0]));
				$value2 = $this->parse_weight_value(trim($parts[1]));

				// Fix the reversed range issue (29-3.5 should be 2.9-3.5)
				if ($value1 > $value2 && $value1 > 10 && $value2 < 10) {
					// Likely a typo - convert 29 to 2.9
					$value1 = $value1 / 10;
				}

				// Return the maximum value
				$weight_grams = max($value1, $value2);
			} else {
				// Single value
				$weight_grams = $this->parse_weight_value($weight_string);
			}
		} else {
			// Single value
			$weight_grams = $this->parse_weight_value($weight_string);
		}

		// Convert grams to ounces (1 gram = 0.035274 ounces)
		$weight_ounces = $weight_grams * 0.035274;

		// Round to 2 decimal places
		return round($weight_ounces, 2);
	}

	/**
	 * Parse weight value from string
	 */
	private function parse_weight_value($value) {
		// Remove any non-numeric characters except decimal point
		$value = preg_replace('/[^0-9.]/', '', $value);
		return (float) $value;
	}
}