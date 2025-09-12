<?php
/**
 * Product Migrator Class
 */
class WC_BC_Product_Migrator {

	private $bc_api;
	private $category_map = array();
	private $product_option_map = array();
	private $option_value_map = array();

	// Cache properties for duplicate name checking
	private $bc_product_names_cache = array();
	private $bc_product_skus_cache = array();
	private $cache_loaded = false;

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

	/**
	 * Enhanced migrate product method with final validation
	 */
	public function migrate_product($wc_product_id) {
		$product = wc_get_product($wc_product_id);

		if (!$product) {
			return array('error' => 'Product not found');
		}

		try {
			// Check if product is already migrated
			$existing_bc_id = $this->get_bc_product_id($wc_product_id);
			if ($existing_bc_id) {
				// Product already exists, update mapping status and proceed with variations
				WC_BC_Database::update_mapping($wc_product_id, null, array(
					'bc_product_id' => $existing_bc_id,
					'status' => 'success',
					'message' => 'Product already migrated',
				));

				// ADD THIS LINE HERE:
				$this->check_and_update_existing_variations($wc_product_id);

				// Handle variations if it's a variable product
				if ($product->is_type('variable')) {
					$this->create_product_options($product, $existing_bc_id);
					$this->migrate_variations($product, $existing_bc_id);
				}

				return array('success' => true, 'bc_product_id' => $existing_bc_id, 'already_exists' => true);
			}

			// Prepare product data
			$product_data = $this->prepare_product_data($product);

			// Ensure unique product name
			$original_name = $product_data['name'];
			$product_data['name'] = $this->ensure_unique_product_name(
				$product_data['name'],
				$wc_product_id,
				$product_data['sku']
			);

			// Final validation of all custom fields
			if (isset($product_data['custom_fields'])) {
				$validated_fields = array();
				foreach ($product_data['custom_fields'] as $field) {
					if (!empty($field['value']) && strlen($field['value']) <= 250 && strlen($field['value']) >= 1) {
						$validated_fields[] = $field;
					} else {
						error_log("Skipping invalid custom field for product {$wc_product_id}: " . json_encode($field));
					}
				}
				$product_data['custom_fields'] = $validated_fields;
			}

			// Log the custom fields count for monitoring
			$custom_field_count = isset($product_data['custom_fields']) ? count($product_data['custom_fields']) : 0;
			if ($custom_field_count > 10) {
				error_log("Product {$wc_product_id} has {$custom_field_count} custom fields - may need optimization");
			}

			// Create product in BigCommerce
			$result = $this->bc_api->create_product($product_data);

			// Better error handling
			if (is_wp_error($result)) {
				throw new Exception('BigCommerce API error: ' . $result->get_error_message());
			}

			if (isset($result['error'])) {
				// Handle specific duplicate name error with retry
				if (strpos($result['error'], 'duplicate') !== false && strpos($result['error'], 'name') !== false) {
					error_log("Duplicate name detected for product {$wc_product_id}, generating new unique name");

					if (rand(0, 1) === 0){
						$original_name .= ' ('.$product_data['sku'].')';
					}else{
						if (rand(0, 1) === 0) {
							// Add 1–5 dots
							$original_name .= ' ' . str_repeat('_', rand(1, 5));
						} else {
							// Add 1–5 dashes
							$original_name .= ' ' . str_repeat('-.', rand(1, 5));
						}
					}

					// Generate a more unique name and retry
					$product_data['name'] = $this->ensure_unique_product_name(
						$original_name,
						$wc_product_id,
						$product_data['sku']
					);

					// Retry the creation
					$result = $this->bc_api->create_product($product_data);

					if (isset($result['error'])) {
						$error_details = isset($result['details']) ? ' - Details: ' . json_encode($result['details']) : '';
						throw new Exception($result['error'] . $error_details);
					}
				} else {
					$error_details = isset($result['details']) ? ' - Details: ' . json_encode($result['details']) : '';
					throw new Exception($result['error'] . $error_details);
				}
			}

			if (!isset($result['data']['id'])) {
				throw new Exception('Unexpected response from BigCommerce: ' . json_encode($result));
			}

			$bc_product_id = $result['data']['id'];

			// Update mapping
			$update_data = array(
				'bc_product_id' => $bc_product_id,
				'status' => 'success',
				'message' => 'Product migrated successfully',
			);

			// Add note if name was changed
			if ($product_data['name'] !== $original_name) {
				$update_data['message'] .= ' (Name changed from "' . $original_name . '" to "' . $product_data['name'] . '")';
			}

			WC_BC_Database::update_mapping($wc_product_id, null, $update_data);

			// Create options and variations for variable products
			if ($product->is_type('variable')) {
				$this->create_product_options($product, $bc_product_id);
				$this->migrate_variations($product, $bc_product_id);
			}

			// Apply B2B pricing rules after product creation
			$this->apply_b2b_pricing($wc_product_id, $bc_product_id);

			return array(
				'success' => true,
				'bc_product_id' => $bc_product_id,
				'name_changed' => $product_data['name'] !== $original_name,
				'final_name' => $product_data['name']
			);

		} catch (Exception $e) {
			$error_message = $e->getMessage();

			// Log detailed error for debugging
			error_log("Migration error for product {$wc_product_id}: {$error_message}");

			WC_BC_Database::update_mapping($wc_product_id, null, array(
				'status' => 'error',
				'message' => $error_message,
			));

			return array('error' => $error_message);
		}
	}


	/**
	 * Create product options after product is created
	 */
	public function create_product_options($product, $bc_product_id) {
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
	 * Prepare attributes for all products as custom fields with smart splitting
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
					if (!is_wp_error($terms) && !empty($terms)) {
						foreach ($terms as $term) {
							$values[] = $term->name;
						}
					}
				} else {
					$values = is_array($options) ? $options : array();
				}

				if (!empty($values)) {
					// Use proper attribute label
					$label = wc_attribute_label($attribute->get_name());
					$value_string = implode(', ', $values);

					// Create chunked fields for this attribute
					if (!empty(trim($value_string))) {
						$attr_fields = $this->create_chunked_custom_fields($label, $value_string);
						$attribute_fields = array_merge($attribute_fields, $attr_fields);
					}
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

	/**
	 * Enhanced prepare_custom_fields with better validation and prefixed names
	 */
	private function prepare_custom_fields($product) {
		$custom_fields = array();

		// Add WooCommerce product ID for reference with prefix
		$custom_fields[] = array(
			'name' => '__wc_product_id',
			'value' => (string) $product->get_id()
		);

		// Add product tags as custom field with prefix and smart splitting
		$tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
		if (!empty($tags) && is_array($tags) && !is_wp_error($tags)) {
			$tags_string = implode(', ', $tags);
			$tag_fields = $this->create_chunked_custom_fields('__product_tags', $tags_string);
			$custom_fields = array_merge($custom_fields, $tag_fields);
		}

		// Add short description with prefix and smart splitting
		$short_description = $product->get_short_description();
		if ($short_description) {
			$clean_description = strip_tags($short_description);
			if (!empty(trim($clean_description))) {
				$desc_fields = $this->create_chunked_custom_fields('__short_description', $clean_description);
				$custom_fields = array_merge($custom_fields, $desc_fields);
			}
		}

		// Add all product attributes as custom fields with smart splitting
		$attribute_fields = $this->prepare_product_attributes_as_custom_fields($product);
		$custom_fields = array_merge($custom_fields, $attribute_fields);

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
	 * Prepare related products (cross-sells and up-sells) with length validation
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
					continue;
				}
			}

			if (!empty($cross_sell_skus)) {
				$cross_sells_string = implode(',', $cross_sell_skus);
				$cross_sell_fields = $this->create_chunked_custom_fields('__cross_sell_products', $cross_sells_string, true);
				$related_fields = array_merge($related_fields, $cross_sell_fields);
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
					continue;
				}
			}

			if (!empty($upsell_skus)) {
				$upsells_string = implode(',', $upsell_skus);
				$upsell_fields = $this->create_chunked_custom_fields('__upsell_products', $upsells_string, true);
				$related_fields = array_merge($related_fields, $upsell_fields);
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
			"SELECT bc_product_id FROM $table_name WHERE wc_product_id = %d AND wc_variation_id IS NULL AND bc_product_id IS NOT NULL",
			$wc_product_id
		));
	}

	/**
	 * Check if variation already exists in BigCommerce and update status
	 */
	private function check_and_update_existing_variations($wc_product_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		// Get all pending items (products and variations) with bc_product_id or bc_variation_id
		$pending_items = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table_name 
         WHERE wc_product_id = %d 
         AND (bc_product_id IS NOT NULL OR bc_variation_id IS NOT NULL)
         AND status = 'pending'",
			$wc_product_id
		));

		foreach ($pending_items as $item_mapping) {
			try {
				if ($item_mapping->wc_variation_id === NULL && $item_mapping->bc_product_id) {
					// This is a main product, check if it exists
					$bc_product = $this->bc_api->get_product($item_mapping->bc_product_id);

					if (isset($bc_product['data']['id'])) {
						// Product exists, update status to success
						WC_BC_Database::update_mapping(
							$item_mapping->wc_product_id,
							null,
							array(
								'status' => 'success',
								'message' => 'Product already migrated and verified in BigCommerce',
							)
						);

						error_log("Updated existing product status for WC Product {$wc_product_id}, BC Product {$item_mapping->bc_product_id}");
					}
				} elseif ($item_mapping->wc_variation_id && $item_mapping->bc_variation_id && $item_mapping->bc_product_id) {
					// This is a variation, check if it exists
					$bc_variation = $this->bc_api->get_product_variant(
						$item_mapping->bc_product_id,
						$item_mapping->bc_variation_id
					);

					if (isset($bc_variation['data']['id'])) {
						// Variation exists, update status to success
						WC_BC_Database::update_mapping(
							$item_mapping->wc_product_id,
							$item_mapping->wc_variation_id,
							array(
								'status' => 'success',
								'message' => 'Variation already migrated and verified in BigCommerce',
							)
						);

						error_log("Updated existing variation status for WC Product {$wc_product_id}, Variation {$item_mapping->wc_variation_id}, BC Variation {$item_mapping->bc_variation_id}");
					}
				}
			} catch (Exception $e) {
				// If item doesn't exist or API error, leave status as pending
				error_log("Could not verify item Product ID: {$item_mapping->bc_product_id}, Variation ID: {$item_mapping->bc_variation_id}: " . $e->getMessage());
			}
		}
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


	/**
	 * Smart text splitting that respects word boundaries and punctuation
	 */
	private function smart_split_text($text, $max_length = 250) {
		$chunks = array();
		$remaining_text = trim($text);

		// If text is already within limit, return as single chunk
		if (strlen($remaining_text) <= $max_length) {
			return array($remaining_text);
		}

		while (strlen($remaining_text) > $max_length) {
			$chunk = substr($remaining_text, 0, $max_length);

			// Find the last space, comma, period, or semicolon within the limit
			$break_positions = array();
			$break_positions[] = strrpos($chunk, ' ');
			$break_positions[] = strrpos($chunk, '.');
			$break_positions[] = strrpos($chunk, ',');
			$break_positions[] = strrpos($chunk, ';');
			$break_positions[] = strrpos($chunk, '!');
			$break_positions[] = strrpos($chunk, '?');

			// Remove false values and find the maximum position
			$break_positions = array_filter($break_positions, function($pos) { return $pos !== false; });

			if (!empty($break_positions)) {
				$break_pos = max($break_positions);
				// Make sure we don't break at position 0
				if ($break_pos > 0) {
					$chunk = substr($remaining_text, 0, $break_pos);
					$remaining_text = ltrim(substr($remaining_text, $break_pos));
				} else {
					// No good break point found, force break at max length
					$chunk = substr($remaining_text, 0, $max_length);
					$remaining_text = substr($remaining_text, $max_length);
				}
			} else {
				// No good break point found, force break at max length
				$chunk = substr($remaining_text, 0, $max_length);
				$remaining_text = substr($remaining_text, $max_length);
			}

			$chunk = trim($chunk);
			if (!empty($chunk)) {
				$chunks[] = $chunk;
			}
		}

		// Add the remaining text if any
		$remaining_text = trim($remaining_text);
		if (!empty($remaining_text)) {
			$chunks[] = $remaining_text;
		}

		return $chunks;
	}

	/**
	 * Smart SKU splitting that keeps complete SKUs together
	 */
	private function smart_split_skus($skus_array, $max_length = 250) {
		$chunks = array();
		$current_chunk = '';

		// Ensure we have an array and clean the SKUs
		if (!is_array($skus_array)) {
			return $chunks;
		}

		foreach ($skus_array as $sku) {
			$sku = trim($sku);

			// Skip empty SKUs
			if (empty($sku)) {
				continue;
			}

			$test_chunk = empty($current_chunk) ? $sku : $current_chunk . ',' . $sku;

			if (strlen($test_chunk) <= $max_length) {
				$current_chunk = $test_chunk;
			} else {
				// Current chunk would exceed limit, save it and start new chunk
				if (!empty($current_chunk)) {
					$chunks[] = $current_chunk;
				}

				// If a single SKU is longer than max_length, we need to handle it
				if (strlen($sku) > $max_length) {
					// This shouldn't happen with normal SKUs, but handle it gracefully
					error_log("SKU too long for custom field: " . $sku);
					$sku = substr($sku, 0, $max_length - 3) . '...';
				}

				$current_chunk = $sku;
			}
		}

		// Add the last chunk if not empty
		if (!empty($current_chunk)) {
			$chunks[] = $current_chunk;
		}

		return $chunks;
	}

	/**
	 * Create multiple custom fields for long content
	 */
	private function create_chunked_custom_fields($base_name, $content, $is_sku_list = false) {
		$fields = array();

		if (empty($content)) {
			return $fields;
		}

		$content = trim($content);

		if ($is_sku_list) {
			// Handle SKU lists (comma-separated)
			$skus = array_map('trim', explode(',', $content));
			// Remove empty SKUs
			$skus = array_filter($skus, function($sku) { return !empty($sku); });
			$chunks = $this->smart_split_skus($skus);
		} else {
			// Handle text content
			$chunks = $this->smart_split_text($content);
		}

		// Create fields for each chunk
		foreach ($chunks as $index => $chunk) {
			$chunk = trim($chunk);
			if (!empty($chunk) && strlen($chunk) <= 250) {
				$field_name = $index === 0 ? $base_name : $base_name . '_' . ($index + 1);
				$fields[] = array(
					'name' => $field_name,
					'value' => $chunk
				);
			}
		}

		return $fields;
	}

	/**
	 * Generate elegant unique product name using SKU and subtle visual elements
	 */
	private function ensure_unique_product_name($product_name, $product_id, $sku = '') {
		$original_name = $product_name;
		$attempt = 0;
		$max_attempts = 20;

		// Clean the SKU for display (remove special characters, keep alphanumeric and dashes)
		$clean_sku = '';
		if (!empty($sku)) {
			$clean_sku = preg_replace('/[^a-zA-Z0-9\-]/', '', $sku);
		}

		while ($attempt < $max_attempts) {
			// Check if current name exists
			if (!$this->product_name_exists_in_bc($product_name)) {
				// Update our cache with the new name
				$this->bc_product_names_cache[strtolower(trim($product_name))] = 'pending_' . $product_id;

				if ($attempt > 0) {
					error_log("Generated unique name for product {$product_id}: '{$original_name}' -> '{$product_name}'");
				}
				return $product_name;
			}

			$attempt++;

			// Elegant naming strategies - customer-friendly
			if ($attempt == 1 && !empty($clean_sku)) {
				// Strategy 1: Add SKU in parentheses (most professional)
				$product_name = $original_name . ' (' . $clean_sku . ')';

			} elseif ($attempt == 2 && !empty($clean_sku)) {
				// Strategy 2: Add SKU with dash (clean separation)
				$product_name = $original_name . ' - ' . $clean_sku;

			} elseif ($attempt == 3) {
				// Strategy 3: Add single dot (very subtle)
				$product_name = $original_name . '.';

			} elseif ($attempt == 4) {
				// Strategy 4: Add double dots (still subtle)
				$product_name = $original_name . '..';

			} elseif ($attempt == 5) {
				// Strategy 5: Add triple dots (ellipsis style)
				$product_name = $original_name . '...';

			} elseif ($attempt == 6 && !empty($clean_sku)) {
				// Strategy 6: SKU at the beginning with dash
				$product_name = $clean_sku . ' - ' . $original_name;

			} elseif ($attempt == 7 && !empty($clean_sku)) {
				// Strategy 7: SKU with single dot
				$product_name = $original_name . ' (' . $clean_sku . ').';

			} elseif ($attempt == 8) {
				// Strategy 8: Add space and single dot
				$product_name = $original_name . ' .';

			} elseif ($attempt == 9) {
				// Strategy 9: Add space and double dots
				$product_name = $original_name . ' ..';

			} elseif ($attempt == 10) {
				// Strategy 10: Add space and triple dots
				$product_name = $original_name . ' ...';

			} elseif ($attempt == 11 && !empty($clean_sku)) {
				// Strategy 11: SKU in brackets
				$product_name = $original_name . ' [' . $clean_sku . ']';

			} elseif ($attempt == 12 && !empty($clean_sku)) {
				// Strategy 12: SKU with dots
				$product_name = $original_name . ' ' . $clean_sku . '.';

			} elseif ($attempt == 13 && !empty($clean_sku)) {
				// Strategy 13: SKU with double dots
				$product_name = $original_name . ' ' . $clean_sku . '..';

			} elseif ($attempt == 14) {
				// Strategy 14: Four dots (getting more unique)
				$product_name = $original_name . '....';

			} elseif ($attempt == 15) {
				// Strategy 15: Five dots
				$product_name = $original_name . '.....';

			} elseif ($attempt == 16 && !empty($clean_sku)) {
				// Strategy 16: Reverse order with dots
				$product_name = $clean_sku . ' ' . $original_name . '.';

			} elseif ($attempt == 17) {
				// Strategy 17: Mix of spaces and dots
				$product_name = $original_name . ' . .';

			} elseif ($attempt == 18) {
				// Strategy 18: Different dot pattern
				$product_name = $original_name . ' .. .';

			} else {
				// Strategy 19-20: Add a very short, clean suffix
				$clean_suffix = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2);
				$product_name = $original_name . ' ' . $clean_suffix;
			}

			// Ensure name doesn't exceed BigCommerce limit (250 characters)
			if (strlen($product_name) > 250) {
				if (!empty($clean_sku) && strlen($clean_sku) < 50) {
					// Truncate original name and add SKU
					$available_length = 250 - strlen(' (' . $clean_sku . ')');
					$truncated_original = substr($original_name, 0, $available_length);
					$product_name = $truncated_original . ' (' . $clean_sku . ')';
				} else {
					// Just truncate and add dots
					$product_name = substr($original_name, 0, 247) . '...';
				}
			}
		}

		// Final fallback - use a clean 2-letter suffix
		$clean_suffix = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2);
		$max_base_length = 250 - 3; // 3 for " XX"
		$truncated_base = substr($original_name, 0, $max_base_length);
		$final_name = $truncated_base . ' ' . $clean_suffix;

		error_log("Generated fallback unique name for product {$product_id}: {$final_name}");

		// Update cache with final name
		$this->bc_product_names_cache[strtolower(trim($final_name))] = 'pending_' . $product_id;

		return $final_name;
	}


	/**
	 * Load existing BigCommerce product names and SKUs into cache
	 */
	private function load_bc_products_cache() {
		if ($this->cache_loaded) {
			return;
		}

		try {
			$page = 1;
			$limit = 250; // Max allowed by BigCommerce

			do {
				$result = $this->bc_api->get_products($page, $limit);

				if (isset($result['data']) && is_array($result['data'])) {
					foreach ($result['data'] as $product) {
						if (isset($product['name'])) {
							$this->bc_product_names_cache[strtolower(trim($product['name']))] = $product['id'];
						}
						if (isset($product['sku']) && !empty($product['sku'])) {
							$this->bc_product_skus_cache[strtolower(trim($product['sku']))] = $product['id'];
						}
					}
				}

				$page++;

				// Check if there are more pages
				$has_more = isset($result['meta']['pagination']['links']['next']);

			} while ($has_more);

			$this->cache_loaded = true;
			error_log("Loaded " . count($this->bc_product_names_cache) . " product names and " . count($this->bc_product_skus_cache) . " SKUs into cache");

		} catch (Exception $e) {
			error_log("Failed to load BigCommerce products cache: " . $e->getMessage());
			// Continue without cache - will use API calls instead
		}
	}

	/**
	 * Enhanced product name existence check with cache
	 */
	private function product_name_exists_in_bc($product_name) {
		// Load cache if not already loaded
		$this->load_bc_products_cache();

		$name_key = strtolower(trim($product_name));

		// Check cache first
		if (isset($this->bc_product_names_cache[$name_key])) {
			return true;
		}

		// If not in cache and cache is loaded, it doesn't exist
		if ($this->cache_loaded) {
			return false;
		}

		// Fallback to API search if cache failed to load
		try {
			$search_result = $this->bc_api->search_products_by_name($product_name);

			if (isset($search_result['data']) && !empty($search_result['data'])) {
				foreach ($search_result['data'] as $bc_product) {
					if (isset($bc_product['name']) && strtolower(trim($bc_product['name'])) === $name_key) {
						return true;
					}
				}
			}

			return false;

		} catch (Exception $e) {
			error_log("Error checking product name existence: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Process a batch of products to update their custom field names.
	 */
	public function update_custom_fields_batch($batch_size = 20) {
		global $wpdb;
		$migrator_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		// Get products that are migrated but not yet updated
		$products_to_update = $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT wc_product_id, bc_product_id 
         FROM $migrator_table 
         WHERE status = 'success' 
         AND wc_variation_id IS NULL
         AND (message NOT LIKE '%%Custom fields updated with new weight%%' OR message IS NULL)
         LIMIT %d",
			$batch_size
		));

		if (empty($products_to_update)) {
			return array('success' => true, 'message' => 'No products found requiring custom field updates.', 'processed' => 0);
		}

		$processed = 0;
		$updated_count = 0;
		$failed_count = 0;
		$deleted_count =0;
		$responses = array();

		foreach ($products_to_update as $product) {
			try {
				$bc_product_id = $product->bc_product_id;
				$wc_product_id = $product->wc_product_id;

				$custom_fields_response = $this->bc_api->get_product_custom_fields($bc_product_id);

				if (isset($custom_fields_response['data'])) {
					$fields_to_update = array(
						'wc_product_id',
						'product_tags',
						'short_description',
						'upsell_products',
						'cross_sell_products',
						'weight_range_grams'
					);

					// Handle weight range fields specially - check for duplicates
					$weight_range_fields = array();
					$other_fields = array();

					foreach ($custom_fields_response['data'] as $field) {
						$base_name = ltrim($field['name'], '_');

						if ($base_name === 'weight_range_grams' || $field['name'] === '__weight_range_grams') {
							$weight_range_fields[] = $field;
						} else {
							$other_fields[] = $field;
						}
					}

					// Process weight range fields
					if (!empty($weight_range_fields)) {
						$preferred_field = null;
						$fields_to_delete = array();

						// Find the preferred field (prioritize __weight_range_grams)
						foreach ($weight_range_fields as $field) {
							if ($field['name'] === '__weight_range_grams') {
								$preferred_field = $field;
							} elseif (!$preferred_field && ltrim($field['name'], '_') === 'weight_range_grams') {
								$preferred_field = $field;
							} else {
								$fields_to_delete[] = $field;
							}
						}

						// Update the preferred field
						if ($preferred_field) {
							// Convert "grams" to "g" in the value
							$new_value = str_replace(' grams', ' g', $preferred_field['value']);

							$update_data = array(
								'name' => 'Weight Range',
								'value' => $new_value
							);

							$responses[$bc_product_id][] = $this->bc_api->update_product_custom_field(
								$bc_product_id,
								$preferred_field['id'],
								$update_data
							);
							usleep(300000);
						}

						// Delete duplicate weight range fields
						foreach ($fields_to_delete as $field) {
							$delete_result = $this->bc_api->delete_product_custom_field($bc_product_id, $field['id']);
							if (isset($delete_result['error'])) {
								error_log("Failed to delete duplicate weight range field: " . $delete_result['error']);
							}else{
								$deleted_count++;
							}
							usleep(300000);
						}
					}

					// Process other fields
					foreach ($other_fields as $field) {
						$base_name = ltrim($field['name'], '_');

						if (in_array($base_name, $fields_to_update) && substr($field['name'], 0, 2) !== '__') {
							$new_name = '__' . $base_name;

							// Update the custom field
							$responses[$bc_product_id][] = $this->bc_api->update_product_custom_field(
								$bc_product_id,
								$field['id'],
								array('name' => $new_name)
							);
							usleep(300000);
						}
					}
				}

				// Mark as updated
				WC_BC_Database::update_mapping($wc_product_id, null, array(
					'message' => 'Product migrated successfully (Custom fields updated with new weight)'
				));
				$updated_count++;

			} catch (Exception $e) {
				WC_BC_Database::update_mapping($wc_product_id, null, array(
					'message' => 'Failed to update custom fields: ' . $e->getMessage()
				));
				$failed_count++;
			}
			$processed++;
		}

		$remaining_query = "SELECT COUNT(DISTINCT wc_product_id) FROM $migrator_table WHERE status = 'success' AND wc_variation_id IS NULL AND (message NOT LIKE '%%Custom fields updated with new weight%%' OR message IS NULL)";
		$remaining = $wpdb->get_var($remaining_query);

		return array(
			'success' => true,
			'processed' => $processed,
			'updated' => $updated_count,
			'failed' => $failed_count,
			'deleted' => $deleted_count,
			'remaining' => $remaining
		);
	}



	// Add this method to WC_BC_Product_Migrator class:
	public function migrate_single_variation($variation, $parent_product, $bc_product_id) {
		try {
			// Prepare variant data (reuse existing logic from migrate_variations method)
			$variant_weight = $this->convert_and_fix_weight($variation->get_weight() ?: $parent_product->get_weight());

			$variant_data = array(
				'sku' => $variation->get_sku() ?: 'VAR-' . $variation->get_id(),
				'option_values' => array(),
				'price' => (float) ($variation->get_regular_price() ?: $parent_product->get_regular_price() ?: 0),
			);

			if ($variant_weight > 0) {
				$variant_data['weight'] = (float) $variant_weight;
			}

			// Handle variant sale price
			$variant_sale_price = $variation->get_sale_price();
			if ($variant_sale_price !== '' && $variant_sale_price !== null && (float) $variant_sale_price > 0) {
				$variant_data['sale_price'] = (float) $variant_sale_price;
			}

			// Get product options for this parent product
			$product_options = isset($this->product_option_map[$parent_product->get_id()])
				? $this->product_option_map[$parent_product->get_id()]
				: array();

			// Prepare option values
			$option_values = $this->prepare_variant_option_values($variation, $product_options);
			if (!empty($option_values)) {
				$variant_data['option_values'] = $option_values;
			} else {
				throw new Exception('No option values found for variant');
			}

			// Create variant in BigCommerce
			$result = $this->bc_api->create_product_variant($bc_product_id, $variant_data);

			if (isset($result['data']['id'])) {
				return array('success' => true, 'bc_variation_id' => $result['data']['id']);
			} else {
				$error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
				throw new Exception($error_msg);
			}

		} catch (Exception $e) {
			return array('error' => $e->getMessage());
		}
	}

}