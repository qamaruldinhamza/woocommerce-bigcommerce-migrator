<?php
/**
 * Category Migrator Class
 * Handles hierarchical category migration from WooCommerce to BigCommerce
 */

class WC_BC_Category_Migrator {
    
    private $bc_api;
    private $category_map = array();
    private $processed_categories = array();
    
    public function __construct() {
        $this->bc_api = new WC_BC_BigCommerce_API();
    }
    
    /**
     * Migrate all WooCommerce categories to BigCommerce
     */
    public function migrate_all_categories() {
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
            'messages' => array()
        );
        
        foreach ($hierarchy as $item) {
            $category = $item['category'];
            
            // Skip if already processed
            if (isset($this->processed_categories[$category->term_id])) {
                continue;
            }
            
            // Prepare category data
            $category_data = array(
                'name' => $category->name,
                'parent_id' => $parent_bc_id,
                'description' => $category->description,
                'is_visible' => true,
                'sort_order' => $category->term_order
            );
            
            // Add SEO data if available
            $seo_data = $this->get_category_seo_data($category->term_id);
            if ($seo_data) {
                $category_data = array_merge($category_data, $seo_data);
            }
            
            // Add custom URL if exists
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
                $results['messages'][] = "Successfully migrated category: {$category->name}";
                
                // Migrate children
                if (!empty($item['children'])) {
                    $child_results = $this->migrate_category_hierarchy($item['children'], $bc_category_id);
                    $results['success'] += $child_results['success'];
                    $results['error'] += $child_results['error'];
                    $results['messages'] = array_merge($results['messages'], $child_results['messages']);
                }
            } else {
                $results['error']++;
                $error_msg = isset($response['error']) ? $response['error'] : 'Unknown error';
                $results['messages'][] = "Failed to migrate category {$category->name}: {$error_msg}";
            }
        }
        
        return $results;
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
}