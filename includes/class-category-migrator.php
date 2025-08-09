<?php
/**
 * Category Migrator Class
 * Handles hierarchical category migration from WooCommerce to BigCommerce
 */

class WC_BC_Category_Migrator {

	private $bc_api;
	private $category_map = array();
	private $processed_categories = array();
	private $existing_bc_categories = array();

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->load_existing_mapping();
	}

	/**
	 * Load existing category mapping
	 */
	private function load_existing_mapping() {
		$this->category_map = get_option('wc_bc_category_mapping', array());
	}

	/**
	 * Pre-sync all BigCommerce categories to local cache
	 */
	public function pre_sync_categories() {
		$bc_categories = array();
		$page = 1;
		$limit = 250;

		// Fetch all BC categories
		do {
			$response = $this->bc_api->get_categories_paginated($page, $limit);
			if (isset($response['data']) && is_array($response['data'])) {
				$bc_categories = array_merge($bc_categories, $response['data']);
				$page++;
			} else {
				break;
			}
		} while (count($response['data']) == $limit);

		// Index by name and parent for quick lookup
		$indexed_categories = array();
		foreach ($bc_categories as $category) {
			$key = $this->get_category_key($category['name'], $category['parent_id']);
			$indexed_categories[$key] = $category;
		}

		// Store in transient for quick access
		set_transient('wc_bc_existing_categories', $indexed_categories, HOUR_IN_SECONDS);
		$this->existing_bc_categories = $indexed_categories;

		return array(
			'success' => true,
			'total' => count($bc_categories),
			'message' => 'Pre-synced ' . count($bc_categories) . ' BigCommerce categories'
		);
	}

	/**
	 * Generate unique key for category lookup
	 */
	private function get_category_key($name, $parent_id) {
		return md5($name . '|' . $parent_id);
	}

	/**
	 * Migrate all WooCommerce categories to BigCommerce
	 */
	public function migrate_all_categories() {
		// Pre-sync existing categories first
		$this->pre_sync_categories();

		// Get all WooCommerce product categories
		$args = array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'orderby' => 'parent',
			'order' => 'ASC'
		);

		$categories = get_terms($args);

		if (is_wp_error($categories)) {
			return array('error' => 'Failed to fetch categories');
		}

		// Build hierarchy
		$hierarchy = $this->build_category_hierarchy($categories);

		// Migrate categories level by level
		$results = $this->migrate_category_hierarchy($hierarchy);

		// Save mapping
		update_option('wc_bc_category_mapping', $this->category_map);

		return $results;
	}

	/**
	 * Build category hierarchy
	 */
	private function build_category_hierarchy($categories, $parent_id = 0) {
		$hierarchy = array();

		foreach ($categories as $category) {
			if ($category->parent == $parent_id) {
				$children = $this->build_category_hierarchy($categories, $category->term_id);
				$hierarchy[] = array(
					'category' => $category,
					'children' => $children
				);
			}
		}

		return $hierarchy;
	}

	/**
	 * Migrate category hierarchy recursively
	 */
	private function migrate_category_hierarchy($hierarchy, $parent_bc_id = 0) {
		$results = array(
			'success' => 0,
			'error' => 0,
			'skipped' => 0,
			'messages' => array()
		);

		foreach ($hierarchy as $item) {
			$category = $item['category'];

			// Skip if already processed
			if (isset($this->processed_categories[$category->term_id])) {
				$bc_category_id = $this->processed_categories[$category->term_id];

				// Process children with existing parent
				if (!empty($item['children'])) {
					$child_results = $this->migrate_category_hierarchy($item['children'], $bc_category_id);
					$results['success'] += $child_results['success'];
					$results['error'] += $child_results['error'];
					$results['skipped'] += $child_results['skipped'];
					$results['messages'] = array_merge($results['messages'], $child_results['messages']);
				}
				continue;
			}

			// Check if already mapped
			if (isset($this->category_map[$category->term_id])) {
				$bc_category_id = $this->category_map[$category->term_id];
				$this->processed_categories[$category->term_id] = $bc_category_id;
				$results['skipped']++;
				$results['messages'][] = "Category already mapped: {$category->name}";

				// Process children
				if (!empty($item['children'])) {
					$child_results = $this->migrate_category_hierarchy($item['children'], $bc_category_id);
					$results['success'] += $child_results['success'];
					$results['error'] += $child_results['error'];
					$results['skipped'] += $child_results['skipped'];
					$results['messages'] = array_merge($results['messages'], $child_results['messages']);
				}
				continue;
			}

			// Check if category already exists in BigCommerce
			$existing = $this->check_existing_category($category->name, $parent_bc_id);
			if ($existing) {
				$bc_category_id = $existing['id'];
				$this->category_map[$category->term_id] = $bc_category_id;
				$this->processed_categories[$category->term_id] = $bc_category_id;
				$results['skipped']++;
				$results['messages'][] = "Using existing BigCommerce category: {$category->name}";

				// Process children
				if (!empty($item['children'])) {
					$child_results = $this->migrate_category_hierarchy($item['children'], $bc_category_id);
					$results['success'] += $child_results['success'];
					$results['error'] += $child_results['error'];
					$results['skipped'] += $child_results['skipped'];
					$results['messages'] = array_merge($results['messages'], $child_results['messages']);
				}
				continue;
			}

			// Prepare category data for creation
			$category_data = array(
				'name' => $category->name,
				'parent_id' => $parent_bc_id,
				'description' => $category->description,
				'is_visible' => true,
				'sort_order' => $category->term_order ?: 0
			);

			// Add SEO data if available
			$seo_data = $this->get_category_seo_data($category->term_id);
			if ($seo_data) {
				$category_data = array_merge($category_data, $seo_data);
			}

			// Add custom URL
			$category_data['custom_url'] = array(
				'url' => '/' . $category->slug . '/',
				'is_customized' => true
			);

			// Create category in BigCommerce
			$response = $this->bc_api->create_category($category_data);

			if (isset($response['data']['id'])) {
				$bc_category_id = $response['data']['id'];
				$this->category_map[$category->term_id] = $bc_category_id;
				$this->processed_categories[$category->term_id] = $bc_category_id;

				$results['success']++;
				$results['messages'][] = "Successfully created category: {$category->name}";

				// Migrate children
				if (!empty($item['children'])) {
					$child_results = $this->migrate_category_hierarchy($item['children'], $bc_category_id);
					$results['success'] += $child_results['success'];
					$results['error'] += $child_results['error'];
					$results['skipped'] += $child_results['skipped'];
					$results['messages'] = array_merge($results['messages'], $child_results['messages']);
				}
			} else {
				$results['error']++;
				$error_msg = isset($response['error']) ? $response['error'] : 'Unknown error';
				$results['messages'][] = "Failed to create category {$category->name}: {$error_msg}";
			}
		}

		return $results;
	}

	/**
	 * Check if category exists in BigCommerce
	 */
	private function check_existing_category($name, $parent_id = 0) {
		// First check cached categories
		if (empty($this->existing_bc_categories)) {
			$this->existing_bc_categories = get_transient('wc_bc_existing_categories') ?: array();
		}

		$key = $this->get_category_key($name, $parent_id);
		if (isset($this->existing_bc_categories[$key])) {
			return $this->existing_bc_categories[$key];
		}

		// If not in cache, check via API
		$response = $this->bc_api->get_categories_filtered($name, $parent_id);

		if (isset($response['data']) && is_array($response['data'])) {
			foreach ($response['data'] as $category) {
				// Exact name match and same parent
				if ($category['name'] === $name && $category['parent_id'] == $parent_id) {
					// Add to cache
					$this->existing_bc_categories[$key] = $category;
					return $category;
				}
			}
		}

		return false;
	}

	/**
	 * Get SEO data for category (Yoast/RankMath compatible)
	 */
	private function get_category_seo_data($term_id) {
		$seo_data = array();

		// Try Yoast first
		$yoast_title = get_term_meta($term_id, '_yoast_wpseo_title', true);
		$yoast_desc = get_term_meta($term_id, '_yoast_wpseo_metadesc', true);

		if ($yoast_title || $yoast_desc) {
			$seo_data['page_title'] = $yoast_title ?: '';
			$seo_data['meta_description'] = $yoast_desc ?: '';
		} else {
			// Try RankMath
			$rm_title = get_term_meta($term_id, 'rank_math_title', true);
			$rm_desc = get_term_meta($term_id, 'rank_math_description', true);

			if ($rm_title || $rm_desc) {
				$seo_data['page_title'] = $rm_title ?: '';
				$seo_data['meta_description'] = $rm_desc ?: '';
			}
		}

		return $seo_data;
	}

	/**
	 * Get mapping for a specific WC category
	 */
	public function get_bc_category_id($wc_category_id) {
		if (empty($this->category_map)) {
			$this->category_map = get_option('wc_bc_category_mapping', array());
		}

		return isset($this->category_map[$wc_category_id]) ? $this->category_map[$wc_category_id] : null;
	}

	/**
	 * Map multiple WC categories to BC
	 */
	public function map_product_categories($wc_category_ids) {
		$bc_category_ids = array();

		foreach ($wc_category_ids as $wc_id) {
			$bc_id = $this->get_bc_category_id($wc_id);
			if ($bc_id) {
				$bc_category_ids[] = $bc_id;
			}
		}

		return $bc_category_ids;
	}

	/**
	 * Clear all mappings and cache
	 */
	public function reset_mappings() {
		delete_option('wc_bc_category_mapping');
		delete_transient('wc_bc_existing_categories');
		$this->category_map = array();
		$this->processed_categories = array();
		$this->existing_bc_categories = array();

		return array('success' => true, 'message' => 'Category mappings reset');
	}
}