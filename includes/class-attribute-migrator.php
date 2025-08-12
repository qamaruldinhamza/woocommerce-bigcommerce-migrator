<?php
/**
 * Attribute and Brand Migrator Class
 * Handles migration of WooCommerce attributes to BigCommerce options and brands
 */

class WC_BC_Attribute_Migrator {

	private $bc_api;
	private $option_map = array();
	private $option_value_map = array();
	private $existing_bc_options = array();

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->load_existing_mappings();
	}

	/**
	 * Load existing mappings
	 */
	private function load_existing_mappings() {
		$this->option_map = get_option('wc_bc_option_mapping', array());
		$this->option_value_map = get_option('wc_bc_option_value_mapping', array());
	}

	/**
	 * Pre-sync existing BigCommerce options
	 */
	public function pre_sync_options() {
		$bc_options = array();
		$page = 1;
		$limit = 250;

		// Fetch all BC options
		do {
			$response = $this->bc_api->get_options_paginated($page, $limit);
			if (isset($response['data']) && is_array($response['data'])) {
				$bc_options = array_merge($bc_options, $response['data']);
				$page++;
			} else {
				break;
			}
		} while (count($response['data']) == $limit);

		// Index by name for quick lookup
		foreach ($bc_options as $option) {
			$this->existing_bc_options[$option['display_name']] = $option;
		}

		return array(
			'success' => true,
			'total' => count($bc_options),
			'message' => 'Pre-synced ' . count($bc_options) . ' BigCommerce options'
		);
	}

	/**
	 * Migrate all WooCommerce attributes to BigCommerce
	 */
	public function migrate_all_attributes() {
		$results = array(
			'options' => array('success' => 0, 'error' => 0, 'skipped' => 0),
			'messages' => array()
		);

		// Pre-sync existing options
		$this->pre_sync_options();

		// Get all WooCommerce attributes
		$attributes = wc_get_attribute_taxonomies();

		foreach ($attributes as $attribute) {
			// Skip attributes that should be product properties rather than variants
			if (in_array($attribute->attribute_name, array('metal', 'metal-style', 'stone-type'))) {
				$results['options']['skipped']++;
				$results['messages'][] = "Skipped non-variant attribute: {$attribute->attribute_label}";
				continue;
			}

			// Check if option already exists in BigCommerce
			if (isset($this->existing_bc_options[$attribute->attribute_label])) {
				$bc_option = $this->existing_bc_options[$attribute->attribute_label];
				$bc_option_id = $bc_option['id'];
				$this->option_map[$attribute->attribute_name] = $bc_option_id;

				$results['options']['skipped']++;
				$results['messages'][] = "Using existing BigCommerce option: {$attribute->attribute_label}";

				// Sync option values even for existing options
				$this->migrate_attribute_terms($attribute, $bc_option_id, true);
				continue;
			}

			// Check if already mapped
			if (isset($this->option_map[$attribute->attribute_name])) {
				$results['options']['skipped']++;
				$results['messages'][] = "Option already mapped: {$attribute->attribute_label}";
				continue;
			}

			// Determine option type based on attribute
			$option_type = $this->determine_option_type($attribute);

			// Create option in BigCommerce
			$option_data = array(
				'name' => $attribute->attribute_label,
				'display_name' => $attribute->attribute_label,
				'type' => $option_type,
				'sort_order' => (int) $attribute->attribute_orderby
			);

			$response = $this->bc_api->create_option($option_data);

			if (isset($response['data']['id'])) {
				$bc_option_id = $response['data']['id'];
				// Map by attribute name, not ID
				$this->option_map[$attribute->attribute_name] = $bc_option_id;

				// Migrate option values (terms)
				$value_results = $this->migrate_attribute_terms($attribute, $bc_option_id);

				$results['options']['success']++;
				$results['messages'][] = "Migrated attribute: {$attribute->attribute_label} with {$value_results['success']} values";
			} else {
				$results['options']['error']++;
				$error_msg = isset($response['error']) ? $response['error'] : 'Unknown error';
				$results['messages'][] = "Failed to migrate attribute {$attribute->attribute_label}: {$error_msg}";
			}
		}

		// Save mappings
		update_option('wc_bc_option_mapping', $this->option_map);
		update_option('wc_bc_option_value_mapping', $this->option_value_map);

		return $results;
	}

	/**
	 * Determine the appropriate BigCommerce option type
	 */
	private function determine_option_type($attribute) {
		$attribute_name = strtolower($attribute->attribute_name);

		// Color attributes should use swatch
		if (strpos($attribute_name, 'color') !== false || strpos($attribute_name, 'colour') !== false) {
			return 'swatch';
		}

		// Size attributes should use rectangles
		if (strpos($attribute_name, 'size') !== false) {
			return 'rectangles';
		}

		// Default to dropdown
		return 'dropdown';
	}

	/**
	 * Migrate attribute terms as option values
	 */
	private function migrate_attribute_terms($attribute, $bc_option_id, $check_existing = false) {
		$results = array('success' => 0, 'error' => 0, 'skipped' => 0);

		$taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
		$terms = get_terms(array(
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
			'orderby' => 'menu_order',
			'order' => 'ASC'
		));

		if (is_wp_error($terms)) {
			return $results;
		}

		// Get existing option values if checking
		$existing_values = array();
		if ($check_existing) {
			$existing_response = $this->bc_api->get_option_values($bc_option_id);
			if (isset($existing_response['data'])) {
				foreach ($existing_response['data'] as $value) {
					$existing_values[$value['label']] = $value['id'];
				}
			}
		}

		foreach ($terms as $term) {
			// Check if value already exists
			if (isset($existing_values[$term->name])) {
				$this->option_value_map[$taxonomy . '_' . $term->term_id] = $existing_values[$term->name];
				$results['skipped']++;
				continue;
			}

			$value_data = array(
				'label' => $term->name,
				'sort_order' => (int) $term->term_order,
				'is_default' => false
			);

			// Add color/pattern data for visual swatches if available
			if ($attribute->attribute_name == 'color' || $attribute->attribute_name == 'colour') {
				$color_value = $this->get_color_value($term);
				if ($color_value) {
					$value_data['value_data'] = array(
						'colors' => array($color_value)
					);
				}
			}

			$response = $this->bc_api->create_option_value($bc_option_id, $value_data);

			if (isset($response['data']['id'])) {
				// Store mapping for option values
				$this->option_value_map[$taxonomy . '_' . $term->term_id] = $response['data']['id'];
				$results['success']++;
			} else {
				$results['error']++;
			}
		}

		return $results;
	}

	/**
	 * Get color hex value for color swatches
	 */
	private function get_color_value($term) {
		// Check if using a color swatch plugin
		$color = get_term_meta($term->term_id, 'product_attribute_color', true);
		if (!$color) {
			$color = get_term_meta($term->term_id, 'pa_color', true);
		}

		if (!$color) {
			// Fallback to basic color mapping
			$color_map = array(
				'black' => '#000000',
				'white' => '#FFFFFF',
				'red' => '#FF0000',
				'blue' => '#0000FF',
				'green' => '#008000',
				'yellow' => '#FFFF00',
				'orange' => '#FFA500',
				'purple' => '#800080',
				'pink' => '#FFC0CB',
				'gray' => '#808080',
				'grey' => '#808080',
				'brown' => '#A52A2A',
				'gold' => '#FFD700',
				'silver' => '#C0C0C0',
				'rose-gold' => '#B76E79',
				'copper' => '#B87333',
				'brass' => '#B5651D'
			);

			$term_slug = strtolower($term->slug);
			foreach ($color_map as $color_name => $hex) {
				if (strpos($term_slug, $color_name) !== false) {
					return $hex;
				}
			}
		}

		return $color;
	}

	/**
	 * Get BigCommerce option ID for WooCommerce attribute
	 */
	public function get_bc_option_id($wc_attribute_name) {
		if (empty($this->option_map)) {
			$this->option_map = get_option('wc_bc_option_mapping', array());
		}

		return isset($this->option_map[$wc_attribute_name]) ? $this->option_map[$wc_attribute_name] : null;
	}

	/**
	 * Get BigCommerce option value ID for WooCommerce attribute term
	 */
	public function get_bc_option_value_id($taxonomy, $term_id) {
		if (empty($this->option_value_map)) {
			$this->option_value_map = get_option('wc_bc_option_value_mapping', array());
		}

		$key = $taxonomy . '_' . $term_id;
		return isset($this->option_value_map[$key]) ? $this->option_value_map[$key] : null;
	}

	/**
	 * Reset all mappings
	 */
	public function reset_mappings() {
		delete_option('wc_bc_option_mapping');
		delete_option('wc_bc_option_value_mapping');
		$this->option_map = array();
		$this->option_value_map = array();
		$this->existing_bc_options = array();

		return array('success' => true, 'message' => 'Attribute mappings reset');
	}
}