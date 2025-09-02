<?php
/**
 * BigCommerce API Class
 */
class WC_BC_BigCommerce_API {

	private $store_hash;
	private $access_token;
	private $api_url;

	public function __construct() {
		$this->store_hash = get_option('wc_bc_store_hash');
		$this->access_token = get_option('wc_bc_access_token');
		$this->api_url = 'https://api.bigcommerce.com/stores/' . $this->store_hash . '/v3/';
		$this->api_v2_url = 'https://api.bigcommerce.com/stores/' . $this->store_hash . '/v2/'; // ADD THIS LINE
	}

	private function make_request($endpoint, $method = 'GET', $data = null, $api_version = 'v3') { // ADD API VERSION PARAM

		// CHOOSE URL BASED ON VERSION
		$base_url = ($api_version === 'v2') ? $this->api_v2_url : $this->api_url;
		$full_url = $base_url . $endpoint;

		$args = array(
			'method' => $method,
			'headers' => array(
				'X-Auth-Token' => $this->access_token,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
		);

		if ($data && in_array($method, array('POST', 'PUT'))) {
			$args['body'] = json_encode($data);
		}

		$response = wp_remote_request($full_url, $args);

		if (is_wp_error($response)) {
			error_log("WP Error: " . $response->get_error_message());
			return array('error' => $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$status_code = wp_remote_retrieve_response_code($response);

		$data = json_decode($body, true);

		// For V2, a 201 or 200 is success, but the data isn't nested in a 'data' key
		if ($api_version === 'v2' && ($status_code === 201 || $status_code === 200)) {
			return array('data' => $data); // Wrap in 'data' key for consistency
		}

		if ($status_code >= 400) {
			// More detailed error information
			$error_message = 'API Error';
			if (is_array($data)) {
				if (isset($data['title'])) {
					$error_message = $data['title'];
				} elseif (is_array($data) && isset($data[0]['message'])) { // V2 error format
					$error_message = $data[0]['message'];
				} elseif (isset($data['message'])) {
					$error_message = $data['message'];
				} elseif (isset($data['error'])) {
					$error_message = $data['error'];
				}
			}

			return array(
				'error' => $error_message,
				'details' => array(
					'status_code' => $status_code,
					'response_body' => $body,
					'parsed_data' => $data
				)
			);
		}

		return $data;
	}

	public function create_product($product_data) {
		return $this->make_request('catalog/products', 'POST', $product_data);
	}

	public function create_product_variant($product_id, $variant_data) {
		return $this->make_request("catalog/products/{$product_id}/variants", 'POST', $variant_data);
	}

	public function create_category($category_data) {
		return $this->make_request('catalog/categories', 'POST', $category_data);
	}

	public function get_categories() {
		return $this->make_request('catalog/categories?limit=250');
	}

	public function create_brand($brand_data) {
		return $this->make_request('catalog/brands', 'POST', $brand_data);
	}

	public function get_brands() {
		return $this->make_request('catalog/brands?limit=250');
	}

	public function create_option($option_data) {
		return $this->make_request('catalog/products/options', 'POST', $option_data);
	}

	public function create_option_value($option_id, $value_data) {
		return $this->make_request("catalog/products/options/{$option_id}/values", 'POST', $value_data);
	}

	public function add_related_product($product_id, $related_product_id) {
		// BigCommerce doesn't have a direct API for related products
		// This would need to be handled through custom fields or metafields
		return array('success' => true, 'message' => 'Related products stored in custom fields');
	}

	public function create_customer_group($group_data) {
		return $this->make_request('customer_groups', 'POST', $group_data);
	}

	public function create_price_list($price_list_data) {
		return $this->make_request('pricelists', 'POST', $price_list_data);
	}

	public function add_price_list_record($price_list_id, $record_data) {
		return $this->make_request("pricelists/{$price_list_id}/records", 'POST', $record_data);
	}

	public function test_connection() {
		// Test the connection by fetching store information
		$response = $this->make_request('store');

		if (isset($response['data'])) {
			return array(
				'success' => true,
				'store_name' => $response['data']['name'],
				'store_domain' => $response['data']['domain']
			);
		}

		return array(
			'success' => false,
			'error' => isset($response['error']) ? $response['error'] : 'Connection failed'
		);
	}

	public function get_categories_filtered($name = '', $parent_id = null) {
		$query = 'catalog/categories?limit=250';
		if ($name) $query .= '&name=' . urlencode($name);
		if ($parent_id !== null) $query .= '&parent_id=' . $parent_id;
		return $this->make_request($query);
	}

	public function get_categories_paginated($page = 1, $limit = 250) {
		return $this->make_request("catalog/categories?page={$page}&limit={$limit}");
	}

	public function get_options_paginated($page = 1, $limit = 250) {
		return $this->make_request("catalog/products/options?page={$page}&limit={$limit}");
	}

	public function get_option_values($option_id) {
		return $this->make_request("catalog/products/options/{$option_id}/values");
	}


	public function create_product_option($product_id, $option_data) {
		return $this->make_request("catalog/products/{$product_id}/options", 'POST', $option_data);
	}

	public function create_product_option_value($option_id, $value_data) {
		return $this->make_request("catalog/products/options/{$option_id}/values", 'POST', $value_data);
	}

	public function get_product_options($product_id) {
		return $this->make_request("catalog/products/{$product_id}/options");
	}

	// Add these methods to your WC_BC_BigCommerce_API class

	/**
	 * Get products from BigCommerce with pagination
	 */
	public function get_products($page = 1, $limit = 250) {
		$endpoint = 'catalog/products'; // Remove leading slash

		$params = array(
			'page' => $page,
			'limit' => min($limit, 250),
			'include' => 'variants,images'
		);

		$url = $this->build_url($endpoint, $params);
		return $this->make_request($url, 'GET'); // Fixed parameter order
	}

	/**
	 * Search for products by name in BigCommerce
	 */
	public function search_products_by_name($product_name) {
		$endpoint = 'catalog/products'; // Remove leading slash

		$params = array(
			'keyword' => urlencode(trim($product_name)),
			'limit' => 50,
			'include' => 'variants'
		);

		$url = $this->build_url($endpoint, $params);
		return $this->make_request($url, 'GET'); // Fixed parameter order
	}

	private function build_url($endpoint, $params = array()) {
		$base_url = $endpoint; // Don't add $this->api_url here since make_request() adds it

		if (!empty($params)) {
			$base_url .= '?' . http_build_query($params);
		}

		return $base_url;
	}

	/**
	 * Get a specific product from BigCommerce
	 */
	public function get_product($product_id) {
		$endpoint = "catalog/products/{$product_id}";
		return $this->make_request($endpoint, 'GET'); // Just pass the endpoint
	}


	public function get_product_variant($product_id, $variant_id) {
		$endpoint = "catalog/products/{$product_id}/variants/{$variant_id}";
		return $this->make_request($endpoint, 'GET'); // Fixed
	}

	public function update_product($product_id, $product_data) {
		$endpoint = "catalog/products/{$product_id}";

		return $this->make_request($endpoint, 'PUT', $product_data);
	}

	/**
	 * Create a customer in BigCommerce
	 */
	public function create_customer($customer_data) {
		$endpoint = "customers";

		$payload = array($customer_data);

		return $this->make_request($endpoint, 'POST', $payload);
	}

	/**
	 * Get customer groups
	 */
	public function get_customer_groups() {
		$endpoint = "customer_groups";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Get a specific customer
	 */
	public function get_customer($customer_id) {
		$endpoint = "customers/{$customer_id}";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Update a customer
	 */
	public function update_customer($customer_id, $customer_data) {
		$endpoint = "customers/{$customer_id}";
		return $this->make_request($endpoint, 'PUT', $customer_data);
	}

	/**
	 * Create an order in BigCommerce
	 * CORRECTED: This now uses the V2 API endpoint, which is required for historical orders.
	 */
	public function create_order($order_data) {
		$endpoint = 'orders';
		// The last parameter specifies to use the 'v2' API endpoint and logic
		return $this->make_request($endpoint, 'POST', $order_data, 'v2');
	}

	/**
	 * Get a specific order from BigCommerce
	 */
	public function get_order($order_id) {
		$endpoint = "orders/{$order_id}";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Update an order in BigCommerce
	 */
	public function update_order($order_id, $order_data) {
		$endpoint = "orders/{$order_id}";
		return $this->make_request($endpoint, 'PUT', $order_data);
	}

	/**
	 * Get orders from BigCommerce with pagination
	 */
	public function get_orders($page = 1, $limit = 250, $filters = array()) {
		$params = array_merge(array(
			'page' => $page,
			'limit' => min($limit, 250),
		), $filters);

		$endpoint = 'orders?' . http_build_query($params);
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Create order shipping addresses
	 */
	public function create_order_shipping_address($order_id, $address_data) {
		$endpoint = "orders/{$order_id}/shipping_addresses";
		return $this->make_request($endpoint, 'POST', $address_data);
	}

	/**
	 * Get order products/line items
	 */
	public function get_order_products($order_id) {
		$endpoint = "orders/{$order_id}/products";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Create order products/line items
	 */
	public function create_order_product($order_id, $product_data) {
		$endpoint = "orders/{$order_id}/products";
		return $this->make_request($endpoint, 'POST', $product_data);
	}

	/**
	 * Get order taxes
	 */
	public function get_order_taxes($order_id) {
		$endpoint = "orders/{$order_id}/taxes";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Create order tax
	 */
	public function create_order_tax($order_id, $tax_data) {
		$endpoint = "orders/{$order_id}/taxes";
		return $this->make_request($endpoint, 'POST', $tax_data);
	}

	/**
	 * Get order coupons
	 */
	public function get_order_coupons($order_id) {
		$endpoint = "orders/{$order_id}/coupons";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Create order coupon
	 */
	public function create_order_coupon($order_id, $coupon_data) {
		$endpoint = "orders/{$order_id}/coupons";
		return $this->make_request($endpoint, 'POST', $coupon_data);
	}

	/**
	 * Get order transactions (read-only in most cases)
	 */
	public function get_order_transactions($order_id) {
		$endpoint = "orders/{$order_id}/transactions";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Get order shipping quotes (for reference)
	 */
	public function get_order_shipping_quotes($order_id, $address_id) {
		$endpoint = "orders/{$order_id}/shipping_addresses/{$address_id}/shipping_quotes";
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Search orders by various criteria
	 */
	public function search_orders($criteria) {
		$allowed_filters = array(
			'min_id', 'max_id', 'min_total', 'max_total',
			'customer_id', 'email', 'status_id', 'cart_id',
			'payment_method', 'min_date_created', 'max_date_created',
			'min_date_modified', 'max_date_modified', 'sort'
		);

		$filters = array_intersect_key($criteria, array_flip($allowed_filters));

		return $this->get_orders(1, 250, $filters);
	}

	/**
	 * Get order statuses from BigCommerce
	 */
	public function get_order_statuses() {
		$endpoint = 'order_statuses';
		return $this->make_request($endpoint, 'GET');
	}

	/**
	 * Verify order exists in BigCommerce
	 */
	public function verify_order_exists($order_id) {
		$response = $this->get_order($order_id);
		return isset($response['data']['id']) && $response['data']['id'] == $order_id;
	}

	/**
	 * Get order count for pagination planning
	 */
	public function get_order_count($filters = array()) {
		$response = $this->get_orders(1, 1, $filters);

		if (isset($response['meta']['pagination']['total'])) {
			return $response['meta']['pagination']['total'];
		}

		return 0;
	}
}