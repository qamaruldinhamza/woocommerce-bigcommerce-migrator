<?php
/**
 * Customer Migrator Class
 */
class WC_BC_Customer_Migrator {

	private $bc_api;
	private $customer_group_mapping;
	private $wholesale_custom_fields;

	// Define customer group mappings (you'll need to update these with actual BC group IDs)
	const CUSTOMER_GROUP_MAPPING = array(
		'customer' => 1, // Default customer group ID in BigCommerce
		'wholesale_customer' => 2, // Also goes to customer group
		'subscriber' => 3, // Wholesale customer group ID in BigCommerce
	);

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->customer_group_mapping = self::CUSTOMER_GROUP_MAPPING;
		$this->load_wholesale_custom_fields();
	}

	/**
	 * Load wholesale custom fields configuration
	 */
	private function load_wholesale_custom_fields() {
		$this->wholesale_custom_fields = get_option('wwlc_option_registration_form_custom_fields', array());
	}

	/**
	 * Prepare customers for migration
	 */
	public function prepare_customers() {
		// Get all users with customer-related roles
		if (!WC_BC_Customer_Database::create_table()) {
			return array(
				'success' => false,
				'message' => 'Failed to create customer migration table'
			);
		}

		$user_query = new WP_User_Query(array(
			'role__in' => array('customer', 'wholesale_customer', 'subscriber'),
			'number' => -1,
			'fields' => 'all'
		));

		$users = $user_query->get_results();
		$inserted = 0;
		$skipped = 0;

		foreach ($users as $user) {
			// Check if already exists
			$existing = WC_BC_Customer_Database::get_customer_by_wp_id($user->ID);

			if (!$existing) {
				// Determine customer type (prioritize wholesale)
				$customer_type = 'customer';
				$user_roles = $user->roles;

				if (in_array('wholesale_customer', $user_roles)) {
					$customer_type = 'wholesale_customer';
				} elseif (in_array('subscriber', $user_roles)) {
					$customer_type = 'customer'; // Subscribers go to regular customer group
				}

				$result = WC_BC_Customer_Database::insert_customer_mapping(array(
					'wp_user_id' => $user->ID,
					'customer_email' => $user->user_email,
					'customer_type' => $customer_type,
					'bc_customer_group_id' => $this->customer_group_mapping[$customer_type],
					'migration_status' => 'pending'
				));

				if ($result) {
					$inserted++;
				} else {
					$skipped++;
				}
			} else {
				$skipped++;
			}
		}

		return array(
			'success' => true,
			'total_users' => count($users),
			'inserted' => $inserted,
			'skipped' => $skipped
		);
	}

	/**
	 * Migrate a single customer
	 */
	public function migrate_customer($wp_user_id) {
		$user = get_user_by('ID', $wp_user_id);

		if (!$user) {
			return array('error' => 'User not found');
		}

		try {
			// Check if already migrated
			$existing_mapping = WC_BC_Customer_Database::get_customer_by_wp_id($wp_user_id);
			if ($existing_mapping && $existing_mapping->bc_customer_id) {
				return array(
					'success' => true,
					'bc_customer_id' => $existing_mapping->bc_customer_id,
					'already_exists' => true
				);
			}

			// Prepare customer data
			$customer_data = $this->prepare_customer_data($user);

			// Create customer in BigCommerce
			$result = $this->bc_api->create_customer($customer_data);

			if (isset($result['error'])) {
				throw new Exception(json_encode(array(
					'response' => $result,
					'payload' => $customer_data
				)));
			}

			// Fix: BigCommerce returns data in array format with 'data' key
			if (!isset($result['data']) || !is_array($result['data']) || empty($result['data'])) {
				throw new Exception('Invalid response from BigCommerce API: ' . json_encode($result));
			}

			// Get the first customer from the data array
			$customer_response = $result['data'][0];

			if (!isset($customer_response['id'])) {
				throw new Exception('No customer ID returned from BigCommerce');
			}

			$bc_customer_id = $customer_response['id'];

			// Update mapping
			WC_BC_Customer_Database::update_customer_mapping($wp_user_id, array(
				'bc_customer_id' => $bc_customer_id,
				'migration_status' => 'success',
				'migration_message' => 'Customer migrated successfully'
			));

			return array(
				'success' => true,
				'bc_customer_id' => $bc_customer_id,
				'customer_data' => $customer_response // Optional: return full customer data for debugging
			);

		} catch (Exception $e) {
			WC_BC_Customer_Database::update_customer_mapping($wp_user_id, array(
				'migration_status' => 'error',
				'migration_message' => $e->getMessage()
			));

			return array('error' => json_decode($e->getMessage()));
		}
	}

	/**
	 * Prepare customer data for BigCommerce
	 */
	private function prepare_customer_data($user) {
		$customer_type = $this->get_customer_type($user);

		// Base customer data
		$customer_data = array(
			'email' => $user->user_email,
			'first_name' => $user->first_name ?: '',
			'last_name' => $user->last_name ?: '',
			'customer_group_id' => $this->customer_group_mapping[$customer_type],
			'notes' => 'Migrated from WooCommerce. Original role: ' . implode(', ', $user->roles), // Add notes
			'accepts_product_review_abandoned_cart_emails' => true, // Optional: enable email marketing
			'trigger_account_created_notification' => false, // Don't send welcome email during migration
			'origin_channel_id' => 1, // Usually 1 for main storefront
			'channel_ids' => array(1) // Array of channel IDs
		);

		if (empty($customer_data['email'])) {
			throw new Exception('Customer email is required');
		}

		if (!isset($this->customer_group_mapping[$customer_type])) {
			throw new Exception('Invalid customer type: ' . $customer_type);
		}

		// Get wholesale/billing data
		$wholesale_data = $this->get_wholesale_data($user->ID);
		$billing_data = $this->get_billing_data($user->ID);
		$shipping_data = $this->get_shipping_data($user->ID);

		// Set phone, country, and company from wholesale data or billing data
		if (!empty($wholesale_data['phone'])) {
			$customer_data['phone'] = $wholesale_data['phone'];
		} elseif (!empty($billing_data['billing_phone'])) {
			$customer_data['phone'] = $billing_data['billing_phone'];
		}

		if (!empty($wholesale_data['company_name'])) {
			$customer_data['company'] = $wholesale_data['company_name'];
		} elseif (!empty($billing_data['billing_company'])) {
			$customer_data['company'] = $billing_data['billing_company'];
		}

		// Add tax exempt status for B2B customers
		if ($this->is_tax_exempt($user)) {
			$customer_data['tax_exempt_category'] = 'wholesale';
		}

		// Prepare addresses
		$addresses = $this->prepare_customer_addresses($wholesale_data, $billing_data, $shipping_data);
		if (!empty($addresses)) {
			$customer_data['addresses'] = $addresses;
		}

		// Prepare custom fields (form fields)
		$form_fields = $this->prepare_form_fields($wholesale_data);
		if (!empty($form_fields)) {
			$customer_data['form_fields'] = $form_fields;
		}

		// Force password reset (since we're not migrating passwords)
		$customer_data['authentication'] = array(
			'force_password_reset' => true
		);

		return $customer_data;
	}

	/**
	 * Check if user is tax exempt
	 */
	private function is_tax_exempt($user) {
		// Check user meta for tax exempt status
		$tax_exempt = get_user_meta($user->ID, 'tax_exempt', true);

		if ($tax_exempt === 'yes' || $tax_exempt === '1' || $tax_exempt === true) {
			return true;
		}

		// Check for wholesale plugin tax exempt meta
		$wholesale_tax_exempt = get_user_meta($user->ID, 'wwlc_tax_exempt', true);
		if ($wholesale_tax_exempt === 'yes' || $wholesale_tax_exempt === '1') {
			return true;
		}

		// Check if wholesale customer (often tax exempt by default)
		if (in_array('wholesale_customer', $user->roles)) {
			return true;
		}

		// Check if distributor (usually tax exempt)
		if (in_array('distributor', $user->roles)) {
			return true;
		}

		// Check for other common tax exempt meta keys
		$other_tax_exempt_keys = array(
			'billing_tax_exempt',
			'is_tax_exempt',
			'tax_exemption',
			'vat_exempt'
		);

		foreach ($other_tax_exempt_keys as $key) {
			$value = get_user_meta($user->ID, $key, true);
			if ($value === 'yes' || $value === '1' || $value === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get customer type based on user roles
	 */
	private function get_customer_type($user) {
		if (in_array('wholesale_customer', $user->roles)) {
			return 'wholesale_customer';
		}
		return 'customer';
	}

	/**
	 * Get wholesale customer data
	 */
	private function get_wholesale_data($user_id) {
		$wholesale_data = array();

		// Map ALL wholesale fields with correct meta keys
		$wholesale_fields_map = array(
			// Basic contact info
			'phone' => 'wwlc_phone',
			'country' => 'wwlc_country',
			'address_1' => 'wwlc_address',
			'address_line_2' => 'wwlc_address_2',
			'city' => 'wwlc_city',
			'state' => 'wwlc_state',
			'postcode' => 'wwlc_postcode',

			// Company info
			'company_name' => 'wwlc_company_name',

			// Custom wholesale fields (using exact keys from your data)
			'position_title' => 'wwlc_cf_co_position_title',
			'primary_business' => 'wwlc_cf_primary_business',
			'business_id_type' => 'wwlc_cf_business_id_type',
			'business_id_number' => 'wwlc_cf_business_id_num',
			'company_website' => 'wwlc_cf_co_website',

			// Basic user fields
			'first_name' => 'first_name',
			'last_name' => 'last_name',
		);

		foreach ($wholesale_fields_map as $key => $meta_key) {
			$value = get_user_meta($user_id, $meta_key, true);
			if (!empty($value)) {
				$wholesale_data[$key] = $value;
			}
		}

		// Also get the custom fields dynamically from the plugin configuration
		if (!empty($this->wholesale_custom_fields)) {
			foreach ($this->wholesale_custom_fields as $field_id => $field_config) {
				if (isset($field_config['field_name']) && isset($field_config['enabled']) && $field_config['enabled'] === '1') {
					$value = get_user_meta($user_id, $field_id, true);
					if (!empty($value)) {
						// Store with a clean key based on field name
						$clean_key = sanitize_key($field_config['field_name']);
						$wholesale_data[$clean_key] = $value;

						// Also store the original field name for form fields
						$wholesale_data['field_names'][$clean_key] = $field_config['field_name'];
					}
				}
			}
		}

		return $wholesale_data;
	}

	/**
	 * Get billing data
	 */
	private function get_billing_data($user_id) {
		$billing_fields = array(
			'billing_first_name', 'billing_last_name', 'billing_company',
			'billing_address_1', 'billing_address_2', 'billing_city',
			'billing_state', 'billing_postcode', 'billing_country',
			'billing_email', 'billing_phone'
		);

		$billing_data = array();
		foreach ($billing_fields as $field) {
			$value = get_user_meta($user_id, $field, true);
			if (!empty($value)) {
				$billing_data[$field] = $value;
			}
		}

		return $billing_data;
	}

	/**
	 * Get shipping data
	 */
	private function get_shipping_data($user_id) {
		$shipping_fields = array(
			'shipping_first_name', 'shipping_last_name', 'shipping_company',
			'shipping_address_1', 'shipping_address_2', 'shipping_city',
			'shipping_state', 'shipping_postcode', 'shipping_country'
		);

		$shipping_data = array();
		foreach ($shipping_fields as $field) {
			$value = get_user_meta($user_id, $field, true);
			if (!empty($value)) {
				$shipping_data[$field] = $value;
			}
		}

		return $shipping_data;
	}

	/**
	 * Prepare customer addresses for BigCommerce
	 * CORRECTED: Sends the raw state code instead of the full state name.
	 */
	private function prepare_customer_addresses($wholesale_data, $billing_data, $shipping_data) {
		$addresses = array();

		// Primary address from wholesale data or billing data
		if (!empty($wholesale_data['address_1'])) {
			$country_code = WC_BC_Location_Mapper::get_country_code($wholesale_data['country'] ?? '');
			$primary_address = array(
				'first_name' => $wholesale_data['first_name'] ?? '',
				'last_name' => $wholesale_data['last_name'] ?? '',
				'company' => $wholesale_data['company_name'] ?? '',
				'address1' => $wholesale_data['address_1'],
				'address2' => $wholesale_data['address_line_2'] ?? '',
				'city' => $wholesale_data['city'] ?? '',
				'state_or_province' => $wholesale_data['state'] ?? '', // THIS IS THE FIX
				'postal_code' => $wholesale_data['postcode'] ?? '',
				'country_code' => $country_code,
				'phone' => $wholesale_data['phone'] ?? '',
				'address_type' => 'residential'
			);
		} elseif (!empty($billing_data['billing_address_1'])) {
			$country_code = WC_BC_Location_Mapper::get_country_code($billing_data['billing_country'] ?? '');
			$primary_address = array(
				'first_name' => $billing_data['billing_first_name'] ?? '',
				'last_name' => $billing_data['billing_last_name'] ?? '',
				'company' => $billing_data['billing_company'] ?? '',
				'address1' => $billing_data['billing_address_1'],
				'address2' => $billing_data['billing_address_2'] ?? '',
				'city' => $billing_data['billing_city'] ?? '',
				'state_or_province' => $billing_data['billing_state'] ?? '', // THIS IS THE FIX
				'postal_code' => $billing_data['billing_postcode'] ?? '',
				'country_code' => $country_code,
				'phone' => $billing_data['billing_phone'] ?? '',
				'address_type' => 'residential'
			);
		}

		if (!empty($primary_address['address1'])) {
			$addresses[] = $primary_address;
		}

		// Handle shipping address similarly...
		if (!empty($shipping_data['shipping_address_1']) &&
		    $shipping_data['shipping_address_1'] !== ($billing_data['billing_address_1'] ?? '')) {

			$country_code = WC_BC_Location_Mapper::get_country_code($shipping_data['shipping_country'] ?? '');
			$shipping_address = array(
				'first_name' => $shipping_data['shipping_first_name'] ?? '',
				'last_name' => $shipping_data['shipping_last_name'] ?? '',
				'company' => $shipping_data['shipping_company'] ?? '',
				'address1' => $shipping_data['shipping_address_1'],
				'address2' => $shipping_data['shipping_address_2'] ?? '',
				'city' => $shipping_data['shipping_city'] ?? '',
				'state_or_province' => $shipping_data['shipping_state'] ?? '', // THIS IS THE FIX
				'postal_code' => $shipping_data['shipping_postcode'] ?? '',
				'country_code' => $country_code,
				'address_type' => 'residential'
			);

			if (!empty($shipping_address['address1'])) {
				$addresses[] = $shipping_address;
			}
		}

		return $addresses;
	}

	/**
	 * Prepare form fields (custom fields) for BigCommerce
	 */
	private function prepare_form_fields($wholesale_data) {
		$form_fields = array();

		// Primary form fields mapping using exact field names
		$field_mapping = array(
			'Position/Title in Company' => $wholesale_data['position_title'] ?? '',
			'Primary Business' => $wholesale_data['primary_business'] ?? '',
			'Business ID Type' => $wholesale_data['business_id_type'] ?? '',
			'Business ID Number' => $wholesale_data['business_id_number'] ?? '0',
			'Company Website' => $wholesale_data['company_website'] ?? '',
		);

		// Add dynamic fields if they exist
		if (isset($wholesale_data['field_names'])) {
			foreach ($wholesale_data['field_names'] as $clean_key => $original_name) {
				if (!empty($wholesale_data[$clean_key])) {
					$field_mapping[$original_name] = $wholesale_data[$clean_key];
				}
			}
		}

		// Convert to BigCommerce format
		foreach ($field_mapping as $field_name => $value) {
			if (!empty($value) && !empty($field_name)) {
				$form_fields[] = array(
					'name' => $field_name,
					'value' => (string) $value
				);
			}
		}

		return $form_fields;
	}

	/**
	 * Convert country name to country code
	 */
	/**
	 * Convert country name to country code using Location Mapper
	 */
	private function get_country_code($country) {
		return WC_BC_Location_Mapper::get_country_code($country);
	}

	/**
	 * Process customer migration batch
	 */
	public function process_batch($batch_size = 10) {
		$pending_customers = WC_BC_Customer_Database::get_pending_customers($batch_size);

		if (empty($pending_customers)) {
			return array(
				'success' => true,
				'message' => 'No pending customers to migrate',
				'processed' => 0,
				'errors' => 0
			);
		}

		$processed = 0;
		$errors = 0;
		$results = array();

		foreach ($pending_customers as $customer_mapping) {
			$result = $this->migrate_customer($customer_mapping->wp_user_id);
			$results[$customer_mapping->wp_user_id] = $result;

			if (isset($result['error'])) {
				$errors++;
			} else {
				$processed++;
			}

			// Add delay to avoid API rate limits
			usleep(500000); // 0.5 seconds
		}

		return array(
			'success' => true,
			'processed' => $processed,
			'errors' => $errors,
			'results' => $results,
			'remaining' => count(WC_BC_Customer_Database::get_pending_customers(1))
		);
	}
}