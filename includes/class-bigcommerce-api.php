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
}