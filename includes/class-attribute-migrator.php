<?php
/**
 * Attribute and Brand Migrator Class
 * Handles migration of WooCommerce attributes to BigCommerce options and brands
 */

class WC_BC_Attribute_Migrator {
    
    private $bc_api;
    private $option_map = array();
    private $brand_map = array();
    
    public function __construct() {
        $this->bc_api = new WC_BC_BigCommerce_API();
    }
    
    /**
     * Migrate all WooCommerce attributes to BigCommerce
     */
    public function migrate_all_attributes() {
        $results = array(
            'options' => array('success' => 0, 'error' => 0),
            'brands' => array('success' => 0, 'error' => 0),
            'messages' => array()
        );
        
        // Get all WooCommerce attributes
        $attributes = wc_get_attribute_taxonomies();
        
        foreach ($attributes as $attribute) {
            // Special handling for specific attributes that should become brands
            if (in_array($attribute->attribute_name, array('metal', 'metal-style', 'stone-type'))) {
                // These could be product properties rather than variants
                continue;
            }
            
            // Create option in BigCommerce
            $option_data = array(
                'name' => $attribute->attribute_label,
                'display_name' => $attribute->attribute_label,
                'type' => 'swatch', // or 'radio_buttons', 'rectangles', etc.
                'sort_order' => $attribute->attribute_orderby == 'menu_order' ? 0 : 1
            );
            
            $response = $this->bc_api->create_option($option_data);
            
            if (isset($response['data']['id'])) {
                $bc_option_id = $response['data']['id'];
                $this->option_map[$attribute->attribute_id] = $bc_option_id;
                
                // Migrate option values (terms)
                $this->migrate_attribute_terms($attribute, $bc_option_id);
                
                $results['options']['success']++;
                $results['messages'][] = "Migrated attribute: {$attribute->attribute_label}";
            } else {
                $results['options']['error']++;
                $results['messages'][] = "Failed to migrate attribute: {$attribute->attribute_label}";
            }
        }
        
        // Migrate brands separately
        $brand_results = $this->migrate_brands();
        $results['brands'] = $brand_results['brands'];
        $results['messages'] = array_merge($results['messages'], $brand_results['messages']);
        
        // Save mappings
        update_option('wc_bc_option_mapping', $this->option_map);
        update_option('wc_bc_brand_mapping', $this->brand_map);
        
        return $results;
    }
    
    /**
     * Migrate attribute terms as option values
     */
    private function migrate_attribute_terms($attribute, $bc_option_id) {
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ));
        
        foreach ($terms as $term) {
            $value_data = array(
                'label' => $term->name,
                'sort_order' => $term->term_order,
                'is_default' => false
            );
            
            // Add color/pattern data for visual swatches if available
            if ($attribute->attribute_name == 'color') {
                $color_value = $this->get_color_value($term);
                if ($color_value) {
                    $value_data['value_data'] = array(
                        'colors' => array($color_value)
                    );
                }
            }
            
            $this->bc_api->create_option_value($bc_option_id, $value_data);
        }
    }
    
    /**
     * Get color hex value for color swatches
     */
    private function get_color_value($term) {
        // Check if using a color swatch plugin
        $color = get_term_meta($term->term_id, 'product_attribute_color', true);
        
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
                'brown' => '#A52A2A'
            );
            
            $term_slug = strtolower($term->slug);
            if (isset($color_map[$term_slug])) {
                return $color_map[$term_slug];
            }
        }
        
        return $color;
    }
    
    /**
     * Migrate brands from various sources
     */
    private function migrate_brands() {
        $results = array(
            'brands' => array('success' => 0, 'error' => 0),
            'messages' => array()
        );
        
        // Check for Perfect Brands plugin
        if (taxonomy_exists('pwb-brand')) {
            $brands = get_terms(array(
                'taxonomy' => 'pwb-brand',
                'hide_empty' => false
            ));
        }
        // Check for WooCommerce Brands plugin
        elseif (taxonomy_exists('product_brand')) {
            $brands = get_terms(array(
                'taxonomy' => 'product_brand',
                'hide_empty' => false
            ));
        }
        // Check for brand attribute
        else {
            $brands = get_terms(array(
                'taxonomy' => 'pa_brand',
                'hide_empty' => false
            ));
        }
        
        if (!is_wp_error($brands) && !empty($brands)) {
            foreach ($brands as $brand) {
                $brand_data = array(
                    'name' => $brand->name,
                    'page_title' => $brand->name,
                    'meta_description' => $brand->description,
                    'custom_url' => array(
                        'url' => '/brands/' . $brand->slug . '/',
                        'is_customized' => true
                    )
                );
                
                // Add brand image if available
                $image_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
                if ($image_id) {
                    $brand_data['image_url'] = wp_get_attachment_url($image_id);
                }
                
                $response = $this->bc_api->create_brand($brand_data);
                
                if (isset($response['data']['id'])) {
                    $this->brand_map[$brand->term_id] = $response['data']['id'];
                    $results['brands']['success']++;
                    $results['messages'][] = "Migrated brand: {$brand->name}";
                } else {
                    $results['brands']['error']++;
                    $results['messages'][] = "Failed to migrate brand: {$brand->name}";
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Get BigCommerce option ID for WooCommerce attribute
     */
    public function get_bc_option_id($wc_attribute_id) {
        if (empty($this->option_map)) {
            $this->option_map = get_option('wc_bc_option_mapping', array());
        }
        
        return isset($this->option_map[$wc_attribute_id]) ? $this->option_map[$wc_attribute_id] : null;
    }
    
    /**
     * Get BigCommerce brand ID for WooCommerce brand term
     */
    public function get_bc_brand_id($wc_brand_term_id) {
        if (empty($this->brand_map)) {
            $this->brand_map = get_option('wc_bc_brand_mapping', array());
        }
        
        return isset($this->brand_map[$wc_brand_term_id]) ? $this->brand_map[$wc_brand_term_id] : null;
    }
}