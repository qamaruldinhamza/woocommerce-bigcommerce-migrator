<?php
/**
 * B2B Handler Class
 * Handles B2B-specific features migration
 */

class WC_BC_B2B_Handler {
    
    private $bc_api;
    private $customer_group_map = array();
    private $price_list_map = array();
    
    public function __construct() {
        $this->bc_api = new WC_BC_BigCommerce_API();
    }
    
    /**
     * Setup B2B features in BigCommerce
     */
    public function setup_b2b_features() {
        $results = array(
            'customer_groups' => $this->setup_customer_groups(),
            'price_lists' => $this->setup_price_lists(),
            'messages' => array()
        );
        
        return $results;
    }
    
    /**
     * Setup customer groups based on WordPress roles
     */
    private function setup_customer_groups() {
        $results = array('success' => 0, 'error' => 0);
        
        // Get WordPress roles that should have special pricing
        $b2b_roles = array('wholesale_customer', 'distributor', 'dealer', 'customer');
        
        foreach ($b2b_roles as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            
            $group_data = array(
                'name' => ucfirst(str_replace('_', ' ', $role_name)),
                'is_default' => $role_name === 'customer',
                'category_access' => array(
                    'type' => 'all'
                ),
                'discount_rules' => array()
            );
            
            // Add discount rules if applicable
            $discount = $this->get_role_discount($role_name);
            if ($discount) {
                $group_data['discount_rules'][] = array(
                    'type' => 'all',
                    'method' => 'percent',
                    'amount' => $discount
                );
            }
            
            $response = $this->bc_api->create_customer_group($group_data);
            
            if (isset($response['data']['id'])) {
                $this->customer_group_map[$role_name] = $response['data']['id'];
                $results['success']++;
            } else {
                $results['error']++;
            }
        }
        
        update_option('wc_bc_customer_group_mapping', $this->customer_group_map);
        
        return $results;
    }
    
    /**
     * Setup price lists for B2B pricing
     */
    private function setup_price_lists() {
        $results = array('success' => 0, 'error' => 0);
        
        // Create a "Login Required" price list
        $login_price_list = array(
            'name' => 'Login Required Pricing',
            'active' => true,
            'is_default' => false,
            'customer_groups' => array() // Will be assigned to guest group
        );
        
        $response = $this->bc_api->create_price_list($login_price_list);
        
        if (isset($response['data']['id'])) {
            $this->price_list_map['login_required'] = $response['data']['id'];
            $results['success']++;
        } else {
            $results['error']++;
        }
        
        // Create role-based price lists
        foreach ($this->customer_group_map as $role => $group_id) {
            if ($role === 'customer') continue; // Skip default customer role
            
            $price_list_data = array(
                'name' => ucfirst(str_replace('_', ' ', $role)) . ' Pricing',
                'active' => true,
                'is_default' => false,
                'customer_groups' => array($group_id)
            );
            
            $response = $this->bc_api->create_price_list($price_list_data);
            
            if (isset($response['data']['id'])) {
                $this->price_list_map[$role] = $response['data']['id'];
                $results['success']++;
            } else {
                $results['error']++;
            }
        }
        
        update_option('wc_bc_price_list_mapping', $this->price_list_map);
        
        return $results;
    }
    
    /**
     * Get discount percentage for a role
     */
    private function get_role_discount($role_name) {
        // Check if using a B2B plugin that stores role discounts
        $discount = get_option("b2b_discount_{$role_name}", 0);
        
        if (!$discount) {
            // Default discounts
            $default_discounts = array(
                'wholesale_customer' => 20,
                'distributor' => 30,
                'dealer' => 25
            );
            
            $discount = isset($default_discounts[$role_name]) ? $default_discounts[$role_name] : 0;
        }
        
        return $discount;
    }
    
    /**
     * Apply B2B pricing to a product in BigCommerce
     */
    public function apply_b2b_pricing($wc_product_id, $bc_product_id) {
        $product = wc_get_product($wc_product_id);
        if (!$product) {
            return false;
        }
        
        // Check if product requires login to see price
        $hide_price = get_post_meta($wc_product_id, '_hide_price_until_login', true);
        
        if ($hide_price === 'yes' && isset($this->price_list_map['login_required'])) {
            // Add product to login required price list with hidden price
            $record_data = array(
                'variant_id' => $bc_product_id,
                'price' => null, // This hides the price
                'sale_price' => null,
                'retail_price' => null,
                'map_price' => null,
                'calculated_price' => null,
                'price_range' => array(
                    'minimum' => array(
                        'as_entered' => null,
                        'entered_inclusive' => true,
                        'tax_exclusive' => null,
                        'tax_inclusive' => null
                    ),
                    'maximum' => array(
                        'as_entered' => null,
                        'entered_inclusive' => true,
                        'tax_exclusive' => null,
                        'tax_inclusive' => null
                    )
                )
            );
            
            $this->bc_api->add_price_list_record($this->price_list_map['login_required'], $record_data);
        }
        
        // Apply role-based pricing
        $role_prices = get_post_meta($wc_product_id, '_role_based_prices', true);
        
        if ($role_prices && is_array($role_prices)) {
            foreach ($role_prices as $role => $price) {
                if (isset($this->price_list_map[$role])) {
                    $record_data = array(
                        'variant_id' => $bc_product_id,
                        'price' => (float) $price,
                        'sale_price' => null,
                        'retail_price' => (float) $product->get_regular_price()
                    );
                    
                    $this->bc_api->add_price_list_record($this->price_list_map[$role], $record_data);
                }
            }
        }
        
        return true;
    }
    
    /**
     * Migrate customer accounts with their groups
     */
    public function migrate_b2b_customers($limit = 50) {
        $results = array('success' => 0, 'error' => 0, 'messages' => array());
        
        // Get B2B customers
        $args = array(
            'role__in' => array('wholesale_customer', 'distributor', 'dealer'),
            'number' => $limit
        );
        
        $users = get_users($args);
        
        foreach ($users as $user) {
            $customer_data = array(
                'email' => $user->user_email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'company' => get_user_meta($user->ID, 'billing_company', true),
                'phone' => get_user_meta($user->ID, 'billing_phone', true),
                'customer_group_id' => $this->get_customer_group_for_user($user),
                'notes' => 'Migrated from WooCommerce. Original role: ' . implode(', ', $user->roles),
                'tax_exempt_category' => $this->is_tax_exempt($user) ? 'wholesale' : ''
            );
            
            // Add addresses
            $addresses = $this->prepare_customer_addresses($user);
            if (!empty($addresses)) {
                $customer_data['addresses'] = $addresses;
            }
            
            $response = $this->bc_api->create_customer($customer_data);
            
            if (isset($response['data']['id'])) {
                $results['success']++;
                update_user_meta($user->ID, 'bc_customer_id', $response['data']['id']);
                $results['messages'][] = "Migrated customer: {$user->user_email}";
            } else {
                $results['error']++;
                $results['messages'][] = "Failed to migrate customer: {$user->user_email}";
            }
        }
        
        return $results;
    }
    
    /**
     * Get appropriate customer group for user
     */
    private function get_customer_group_for_user($user) {
        foreach ($user->roles as $role) {
            if (isset($this->customer_group_map[$role])) {
                return $this->customer_group_map[$role];
            }
        }
        
        return 0; // Default group
    }
    
    /**
     * Check if user is tax exempt
     */
    private function is_tax_exempt($user) {
        // Check user meta for tax exempt status
        $tax_exempt = get_user_meta($user->ID, 'tax_exempt', true);
        
        // Also check if wholesale customer (often tax exempt)
        if (in_array('wholesale_customer', $user->roles)) {
            return true;
        }
        
        return $tax_exempt === 'yes';
    }
    
    /**
     * Prepare customer addresses
     */
    private function prepare_customer_addresses($user) {
        $addresses = array();
        
        // Billing address
        $billing_address = array(
            'first_name' => get_user_meta($user->ID, 'billing_first_name', true),
            'last_name' => get_user_meta($user->ID, 'billing_last_name', true),
            'company' => get_user_meta($user->ID, 'billing_company', true),
            'street_1' => get_user_meta($user->ID, 'billing_address_1', true),
            'street_2' => get_user_meta($user->ID, 'billing_address_2', true),
            'city' => get_user_meta($user->ID, 'billing_city', true),
            'state' => get_user_meta($user->ID, 'billing_state', true),
            'zip' => get_user_meta($user->ID, 'billing_postcode', true),
            'country' => get_user_meta($user->ID, 'billing_country', true),
            'phone' => get_user_meta($user->ID, 'billing_phone', true),
            'address_type' => 'billing'
        );
        
        if (!empty($billing_address['street_1'])) {
            $addresses[] = $billing_address;
        }
        
        // Shipping address
        $shipping_address = array(
            'first_name' => get_user_meta($user->ID, 'shipping_first_name', true),
            'last_name' => get_user_meta($user->ID, 'shipping_last_name', true),
            'company' => get_user_meta($user->ID, 'shipping_company', true),
            'street_1' => get_user_meta($user->ID, 'shipping_address_1', true),
            'street_2' => get_user_meta($user->ID, 'shipping_address_2', true),
            'city' => get_user_meta($user->ID, 'shipping_city', true),
            'state' => get_user_meta($user->ID, 'shipping_state', true),
            'zip' => get_user_meta($user->ID, 'shipping_postcode', true),
            'country' => get_user_meta($user->ID, 'shipping_country', true),
            'address_type' => 'shipping'
        );
        
        if (!empty($shipping_address['street_1'])) {
            $addresses[] = $shipping_address;
        }
        
        return $addresses;
    }
}