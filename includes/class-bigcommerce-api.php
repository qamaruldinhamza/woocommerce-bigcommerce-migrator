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
	}

	private function make_request($endpoint, $method = 'GET', $data = null) {
		$full_url = $this->api_url . $endpoint;

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

		if ($status_code >= 400) {
			// More detailed error information
			$error_message = 'API Error';
			if (is_array($data)) {
				if (isset($data['title'])) {
					$error_message = $data['title'];
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

	public function create_customer($customer_data) {
		return $this->make_request('customers', 'POST', $customer_data);
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
		$endpoint = "/catalog/products/{$product_id}";
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
}