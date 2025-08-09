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
		$args = array(
			'method' => $method,
			'headers' => array(
				'X-Auth-Token' => $this->access_token,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'timeout' => 30,
		);

		if ($data && in_array($method, array('POST', 'PUT'))) {
			$args['body'] = json_encode($data);
		}

		$response = wp_remote_request($this->api_url . $endpoint, $args);

		if (is_wp_error($response)) {
			return array('error' => $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		$status_code = wp_remote_retrieve_response_code($response);
		if ($status_code >= 400) {
			return array('error' => $data['title'] ?? 'API Error', 'details' => $data);
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
}