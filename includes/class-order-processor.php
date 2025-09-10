<?php
/**
 * Order Migration Processor
 * Handles the preparation and batch processing of WooCommerce orders for migration
 */
class WC_BC_Order_Processor {

	private $bc_api;
	private $status_mapper;

	public function __construct() {
		$this->bc_api        = new WC_BC_BigCommerce_API();
		$this->status_mapper = new WC_BC_Order_Status_Mapper();
	}

	public function prepare_orders( $date_from = null, $date_to = null, $status_filter = null ) {
		set_time_limit( 300 );
		if ( ! WC_BC_Order_Database::create_table() ) {
			return array( 'success' => false, 'message' => 'Failed to create order migration table' );
		}
		$batch_size = 500;
		$offset     = 0;
		$inserted   = 0;
		$skipped    = 0;
		$errors     = 0;
		do {
			$args = array(
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'limit'   => $batch_size,
				'offset'  => $offset,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC'
			);
			$order_ids = wc_get_orders( $args );
			if ( empty( $order_ids ) ) {
				break;
			}
			foreach ( $order_ids as $order_id ) {
				if ( WC_BC_Order_Database::get_order_by_wc_id( $order_id ) ) {
					$skipped ++;
					continue;
				}
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					$errors ++;
					continue;
				}
				$order_data = $this->extract_order_basic_data( $order );
				if ( WC_BC_Order_Database::insert_order_mapping( $order_data ) ) {
					$inserted ++;
				} else {
					$errors ++;
					error_log( "Failed to prepare order {$order_id} for migration" );
				}
				unset( $order );
			}
			$offset += $batch_size;
			wp_cache_flush();
		} while ( count( $order_ids ) === $batch_size );

		return array(
			'success'         => true,
			'total_processed' => $inserted + $skipped + $errors,
			'inserted'        => $inserted,
			'skipped'         => $skipped,
			'errors'          => $errors
		);
	}

	private function extract_order_basic_data( $order ) {
		$customer_id    = $order->get_customer_id();
		$bc_customer_id = null;
		if ( $customer_id ) {
			$customer_mapping = WC_BC_Customer_Database::get_customer_by_wp_id( $customer_id );
			if ( $customer_mapping && $customer_mapping->bc_customer_id ) {
				$bc_customer_id = $customer_mapping->bc_customer_id;
			}
		}

		return array(
			'wc_order_id'          => $order->get_id(),
			'wc_customer_id'       => $customer_id ?: null,
			'bc_customer_id'       => $bc_customer_id,
			'order_status'         => $order->get_status(),
			'order_total'          => $order->get_total(),
			'order_date'           => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'migration_status'     => 'pending'
		);
	}

	public function process_batch( $batch_size = 10 ) {
		$pending_orders = WC_BC_Order_Database::get_pending_orders( $batch_size );
		if ( empty( $pending_orders ) ) {
			return array( 'success' => true, 'message' => 'No pending orders to migrate', 'processed' => 0, 'errors' => 0 );
		}
		$processed = 0;
		$errors    = 0;
		$results   = array();
		foreach ( $pending_orders as $order_mapping ) {
			$result                                  = $this->migrate_single_order( $order_mapping->wc_order_id );
			$results[ $order_mapping->wc_order_id ] = $result;
			if ( isset( $result['error'] ) ) {
				$errors ++;
			} else {
				$processed ++;
			}
			usleep( 500000 );
		}

		return array(
			'success'   => true,
			'processed' => $processed,
			'errors'    => $errors,
			'results'   => $results,
			'remaining' => WC_BC_Order_Database::get_remaining_orders_count()
		);
	}

	public function migrate_single_order( $wc_order_id ) {
		$wc_order = wc_get_order( $wc_order_id );
		if ( ! $wc_order ) {
			return array( 'error' => 'WooCommerce order not found' );
		}

		$order_data = null; // Define here to make it available in the catch block

		try {
			$existing_mapping = WC_BC_Order_Database::get_order_by_wc_id( $wc_order_id );
			if ( $existing_mapping && $existing_mapping->bc_order_id ) {
				WC_BC_Order_Database::update_order_mapping( $wc_order_id, [
					'migration_status'  => 'success',
					'migration_message' => 'Order already migrated.'
				] );

				return array(
					'success'        => true,
					'bc_order_id'    => $existing_mapping->bc_order_id,
					'already_exists' => true
				);
			}

			// Prepare the data that will be sent to the API
			$order_data = $this->prepare_order_data( $wc_order );

			if ( empty( $order_data['products'] ) ) {
				throw new Exception( 'Could not process any line items for this order. Check product mapping and data.' );
			}

			$result = $this->bc_api->create_order( $order_data );

			// Check for an API error in the response
			if ( isset( $result['error'] ) ) {
				// If there's an error, throw an exception that includes the API response
				throw new Exception( json_encode( $result['error'] ) );
			}

			if ( ! isset( $result['data']['id'] ) ) {
				throw new Exception( 'No order ID returned from BigCommerce: ' . json_encode( $result ) );
			}

			$bc_order_id = $result['data']['id'];

			WC_BC_Order_Database::update_order_mapping( $wc_order_id, array(
				'bc_order_id'       => $bc_order_id,
				'migration_status'  => 'success',
				'migration_message' => 'Order migrated successfully'
			) );

			return array( 'success' => true, 'bc_order_id' => $bc_order_id );

		} catch ( Exception $e ) {

			// This 'catch' block now handles all errors
			$error_message = $e->getMessage();

			// Attempt to decode the message. If it's our API error, it will be JSON.
			$decoded_error = json_decode($error_message, true);

			// Prepare the final error response for the API
			$final_error_response = [
				'api_error' => (json_last_error() === JSON_ERROR_NONE) ? $decoded_error : $error_message,
				'sent_payload' => $order_data
			];

			// Log the full error with payload for server-side debugging
			error_log( "Order migration error for order {$wc_order_id}: " . json_encode($final_error_response) );

			// Update the database with the full error details
			WC_BC_Order_Database::update_order_mapping( $wc_order_id, array(
				'migration_status'  => 'error',
				'migration_message' => json_encode($final_error_response) // Store the full context
			) );

			// Return the structured error and payload
			return array( 'error' => $final_error_response );
		}
	}

	private function prepare_order_data( $wc_order ) {
		$bc_customer_id = 0;
		$customer_id    = $wc_order->get_customer_id();
		if ( $customer_id ) {
			$customer_mapping = WC_BC_Customer_Database::get_customer_by_wp_id( $customer_id );
			if ( $customer_mapping && $customer_mapping->bc_customer_id ) {
				$bc_customer_id = (int) $customer_mapping->bc_customer_id;
			}
		}
		$staff_notes  = 'Migrated from WooCommerce. WC Order ID: ' . $wc_order->get_id();
		$coupon_codes = $wc_order->get_coupon_codes();
		if ( ! empty( $coupon_codes ) ) {
			$staff_notes .= ' | Coupon(s) Used: ' . implode( ', ', $coupon_codes );
		}

		return array(
			'customer_id'             => $bc_customer_id,
			'status_id'               => WC_BC_Order_Status_Mapper::map_status( $wc_order->get_status() ),
			'date_created'            => $wc_order->get_date_created()->format( 'r' ),
			'billing_address'         => $this->prepare_v2_billing_address( $wc_order ),
			'shipping_addresses'      => $this->prepare_v2_shipping_addresses( $wc_order ),
			'products'                => $this->prepare_v2_order_products( $wc_order ),
			'subtotal_ex_tax'         => (float) $wc_order->get_subtotal(),
			'subtotal_inc_tax'        => (float) ( $wc_order->get_subtotal() + ( $wc_order->get_total_tax() - $wc_order->get_shipping_tax() ) ),
			'total_ex_tax'            => (float) ( $wc_order->get_total() - $wc_order->get_total_tax() ),
			'total_inc_tax'           => (float) $wc_order->get_total(),
			'shipping_cost_ex_tax'    => (float) $wc_order->get_shipping_total(),
			'shipping_cost_inc_tax'   => (float) ( $wc_order->get_shipping_total() + $wc_order->get_shipping_tax() ),
			'payment_method'          => $wc_order->get_payment_method_title(),
			'staff_notes'             => $staff_notes,
			'customer_message'        => $wc_order->get_customer_note(),
			'discount_amount'         => (float) $wc_order->get_discount_total(),
			'external_source'         => 'M-MIG',
		);
	}

	private function prepare_v2_billing_address($wc_order) {
		$country_code = $wc_order->get_billing_country();
		$country_name = WC_BC_Location_Mapper::get_bc_country_name($country_code);

		// Enhanced validation and fallback
		if (empty($country_name)) {
			error_log("Invalid or unrecognized billing country code '{$country_code}' for Order #" . $wc_order->get_id());
			// Try to get a fallback country name
			$countries = WC()->countries->get_countries();
			$fallback_name = $countries[$country_code] ?? null;

			if ($fallback_name) {
				$country_name = $fallback_name;
				error_log("Using fallback country name '{$country_name}' for code '{$country_code}'");
			} else {
				throw new Exception("Invalid or unrecognized billing country code '{$country_code}' for Order #" . $wc_order->get_id());
			}
		}

		$raw_state = $wc_order->get_billing_state();
		$raw_zip = $wc_order->get_billing_postcode();
		$cleaned_address = $this->clean_state_and_zip($raw_state, $raw_zip, $country_code);

		$address = array(
			'first_name'   => $wc_order->get_billing_first_name(),
			'last_name'    => $wc_order->get_billing_last_name(),
			'company'      => $wc_order->get_billing_company(),
			'street_1'     => $wc_order->get_billing_address_1(),
			'street_2'     => $wc_order->get_billing_address_2(),
			'city'         => $wc_order->get_billing_city(),
			'state'        => $cleaned_address['state'],
			'country'      => $country_name,
			'country_iso2' => $country_code,
			'phone'        => $wc_order->get_billing_phone(),
			'email'        => $wc_order->get_billing_email(),
		);

		// Only add ZIP if it's not empty
		if (!empty($cleaned_address['zip'])) {
			$address['zip'] = $cleaned_address['zip'];
		}

		return $address;
	}

	private function prepare_v2_shipping_addresses($wc_order) {
		if (!$wc_order->get_shipping_address_1()) {
			return array();
		}

		$shipping_methods = $wc_order->get_shipping_methods();
		$shipping_method_name = !empty($shipping_methods) ? reset($shipping_methods)->get_method_title() : 'Migrated Shipping';
		$country_code = $wc_order->get_shipping_country();
		$country_name = WC_BC_Location_Mapper::get_bc_country_name($country_code);

		// Enhanced validation and fallback
		if (empty($country_name)) {
			error_log("Invalid or unrecognized shipping country code '{$country_code}' for Order #" . $wc_order->get_id());
			// Try to get a fallback country name
			$countries = WC()->countries->get_countries();
			$fallback_name = $countries[$country_code] ?? null;

			if ($fallback_name) {
				$country_name = $fallback_name;
				error_log("Using fallback country name '{$country_name}' for code '{$country_code}'");
			} else {
				throw new Exception("Invalid or unrecognized shipping country code '{$country_code}' for Order #" . $wc_order->get_id());
			}
		}

		$raw_state = $wc_order->get_shipping_state();
		$raw_zip = $wc_order->get_shipping_postcode();
		$cleaned_address = $this->clean_state_and_zip($raw_state, $raw_zip, $country_code);

		$shipping_address = array(
			'first_name'      => $wc_order->get_shipping_first_name(),
			'last_name'       => $wc_order->get_shipping_last_name(),
			'company'         => $wc_order->get_shipping_company(),
			'street_1'        => $wc_order->get_shipping_address_1(),
			'street_2'        => $wc_order->get_shipping_address_2(),
			'city'            => $wc_order->get_shipping_city(),
			'state'           => $cleaned_address['state'],
			'country'         => $country_name,
			'country_iso2'    => $country_code,
			'shipping_method' => $shipping_method_name,
		);

		// Only add ZIP if it's not empty
		if (!empty($cleaned_address['zip'])) {
			$shipping_address['zip'] = $cleaned_address['zip'];
		}

		return array($shipping_address);
	}

	// Add this new method to WC_BC_Order_Processor
	private function clean_state_and_zip($state, $zip, $country_code) {
		$cleaned_state = trim($state);
		$cleaned_zip = trim($zip);

		// Get WooCommerce locale information for this country
		$address_format = WC_BC_Location_Mapper::get_address_format($country_code);

		// Handle cases where state got mixed into ZIP field (US specific)
		if (empty($cleaned_state) && !empty($cleaned_zip) && $country_code === 'US') {
			if (preg_match('/^([A-Z]{2})\s+(.+)$/', $cleaned_zip, $matches)) {
				$potential_state = $matches[1];
				$potential_zip = $matches[2];

				// Validate using WooCommerce state data
				$us_states = WC()->countries->get_states('US');
				if (isset($us_states[$potential_state])) {
					$cleaned_state = $potential_state;
					$cleaned_zip = $potential_zip;
					error_log("Fixed mixed state/zip: extracted state '{$potential_state}' from zip field");
				}
			}
		}

		// Handle missing postal codes based on WooCommerce locale requirements
		if (empty($cleaned_zip)) {
			if (!$address_format['postcode_required']) {
				error_log("Postal code not required for country '{$country_code}', keeping empty");
			} else {
				$cleaned_zip = $this->get_default_postcode($country_code);
				error_log("Set default postal code '{$cleaned_zip}' for country '{$country_code}'");
			}
		}

		// Handle missing states using WooCommerce state data
		if (empty($cleaned_state) && $address_format['state_required']) {
			if ($country_code === 'US' && !empty($cleaned_zip)) {
				$guessed_state = $this->guess_us_state_from_zip($cleaned_zip);
				if ($guessed_state) {
					// Validate the guessed state exists in WooCommerce
					$us_states = WC()->countries->get_states('US');
					if (isset($us_states[$guessed_state])) {
						$cleaned_state = $guessed_state;
						error_log("Guessed and validated US state '{$guessed_state}' from ZIP '{$cleaned_zip}'");
					}
				}
			}

			// If still no state and it's required, use a default
			if (empty($cleaned_state)) {
				$cleaned_state = 'N/A';
				error_log("Set default state 'N/A' for country '{$country_code}' with missing required state");
			}
		}

		return array(
			'state' => $cleaned_state,
			'zip' => $cleaned_zip
		);
	}

// Helper method for country-specific default postal codes
	private function get_default_postcode($country_code) {
		$defaults = array(
			'US' => '00000',
			'CA' => 'A0A 0A0',
			'GB' => 'SW1A 1AA',
			'AU' => '0000',
			'DE' => '00000',
			'FR' => '00000',
			'IT' => '00000',
			'ES' => '00000',
			'NL' => '0000 AA',
			'BE' => '0000',
			'CH' => '0000',
			'AT' => '0000',
			'SE' => '000 00',
			'NO' => '0000',
			'DK' => '0000',
			'FI' => '00000',
			'JP' => '000-0000',
			'KR' => '00000',
			'CN' => '000000',
			'IN' => '000000',
			'BR' => '00000-000',
			'MX' => '00000',
			'RU' => '000000',
			'ZA' => '0000',
			'PL' => '00-000',
			'CZ' => '000 00',
			'HU' => '0000',
			'PT' => '0000-000',
			'GR' => '000 00',
			'TR' => '00000',
			'IL' => '0000000',
			'AE' => '00000',
			'SA' => '00000',
			'EG' => '00000',
			'NG' => '000000',
			'KE' => '00000',
			'GH' => 'GA-000-0000',
			'MA' => '00000',
			'TN' => '0000',
			'DZ' => '00000',
			'LY' => '00000'
		);

		return isset($defaults[$country_code]) ? $defaults[$country_code] : '00000';
	}

// Add this helper method for US state guessing
	private function guess_us_state_from_zip($zip) {
		if (empty($zip) || !preg_match('/^\d{5}/', $zip)) {
			return null;
		}

		$zip_prefix = substr($zip, 0, 3);
		$zip_num = intval($zip_prefix);

		// Basic ZIP code to state mapping for common ranges
		$zip_ranges = array(
			array('min' => 100, 'max' => 149, 'state' => 'NY'),
			array('min' => 200, 'max' => 299, 'state' => 'DC'),
			array('min' => 300, 'max' => 399, 'state' => 'GA'),
			array('min' => 600, 'max' => 699, 'state' => 'IL'),
			array('min' => 700, 'max' => 799, 'state' => 'TX'),
			array('min' => 800, 'max' => 899, 'state' => 'CO'),
			array('min' => 900, 'max' => 999, 'state' => 'CA'),
		);

		foreach ($zip_ranges as $range) {
			if ($zip_num >= $range['min'] && $zip_num <= $range['max']) {
				return $range['state'];
			}
		}

		return null;
	}


	/**
	 * Prepare V2 order products with robust fallbacks.
	 */
	private function prepare_v2_order_products($wc_order) {
		$products = array();
		foreach ($wc_order->get_items() as $item) {
			$line_item = $this->prepare_single_line_item($item);
			if ($line_item) {
				$products[] = $line_item;
			}
		}
		return $products;
	}

	/**
	 * This is the master function for a single line item.
	 * It checks for deleted products FIRST, then attempts a full mapping,
	 * and falls back to a custom product if any part of the mapping fails.
	 */
	private function prepare_single_line_item($item) {
		$wc_product_object = $item->get_product();

		// SCENARIO 1: The product has been deleted from WooCommerce.
		// Immediately create a custom product using historical data from the order item itself.
		if (!is_object($wc_product_object)) {
			error_log("Order #" . $item->get_order_id() . ": WC Product ID #" . $item->get_product_id() . " is deleted. Creating as custom product.");
			return $this->create_fallback_product_payload($item);
		}

		// SCENARIO 2: The product exists. Attempt to map it.
		$mapped_product_payload = $this->get_mapped_product_payload($item, $wc_product_object);

		if ($mapped_product_payload) {
			return $mapped_product_payload; // Success!
		}

		// SCENARIO 3: Mapping failed for a reason (no mapping, option mismatch, etc.).
		// The helper functions will have logged the specific reason. Fall back to a custom product.
		return $this->create_fallback_product_payload($item);
	}

	private function get_mapped_product_payload($item, $wc_product_object) {
		global $wpdb;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;

		$product_id = $item->get_product_id();
		$variation_id = $item->get_variation_id();
		$target_unit_price = $item->get_quantity() > 0 ? ($item->get_total() / $item->get_quantity()) : 0;

		$is_simple_in_wc = $variation_id == 0;

		$query = $wpdb->prepare("SELECT bc_product_id, wc_variation_id FROM $product_table WHERE wc_product_id = %d AND status = 'success'", $product_id);
		$mappings = $wpdb->get_results($query);

		if (empty($mappings)) {
			error_log("Order #" . $item->get_order_id() . ": No mapping found for WC Product #" . $product_id . ". Falling back.");
			return null;
		}

		if ($is_simple_in_wc && count($mappings) > 1) {
			error_log("Order #" . $item->get_order_id() . ": WC Product #{$product_id} was simple but is now variable in BC. Falling back.");
			return null;
		}

		$mapping = null;
		if ($is_simple_in_wc) {
			$mapping = $mappings[0];
		} else {
			foreach($mappings as $map_row) {
				if($map_row->wc_variation_id == $variation_id) {
					$mapping = $map_row;
					break;
				}
			}
		}

		if (!$mapping) {
			error_log("Order #" . $item->get_order_id() . ": Could not find specific variation mapping for WC Product #" . $product_id . ". Falling back.");
			return null;
		}

		$bc_product_id = $mapping->bc_product_id;
		$final_product_options = [];
		$needs_options = false;

		if ($variation_id > 0) {
			$final_product_options = $this->get_validated_options(
				$wc_product_object,
				$bc_product_id,
				$item->get_order_id(),
				$target_unit_price
			);
			if ($final_product_options === null) {
				return null; // Option validation failed, trigger fallback.
			}
			$needs_options = true;
		} else {
			// Check if BC product has required options for simple products
			$bc_options_response = $this->bc_api->get_product_options($bc_product_id);
			if(isset($bc_options_response['data']) && !empty($bc_options_response['data'])) {
				foreach($bc_options_response['data'] as $bc_option) {
					if($bc_option['required']) {
						error_log("Order #" . $item->get_order_id() . ": Mapped BC Product #{$bc_product_id} requires options but none were in the original order. Falling back.");
						return null;
					}
				}
				// Product has optional options, so we should include empty options array
				if (!empty($bc_options_response['data'])) {
					$needs_options = true;
				}
			}
		}

		$quantity = (int) $item->get_quantity();
		if ($quantity === 0) return null;

		// Build the product array once
		$product_array = [
			'product_id'      => (int) $bc_product_id,
			'quantity'        => $quantity,
			'price_ex_tax'    => (float) $target_unit_price,
			'price_inc_tax'   => (float) $target_unit_price,
		];

		// Only add product_options if the product actually has options defined
		if ($needs_options) {
			$product_array['product_options'] = $final_product_options;
		}

		return $product_array;
	}

	/**
	 * Enhanced version that handles invalid options with fallbacks
	 */
	private function get_validated_options($wc_variation, $bc_product_id, $order_id, $target_price = null) {
		$wc_attributes = $wc_variation->get_attributes();

		$bc_options_response = $this->bc_api->get_product_options($bc_product_id);

		// If the BC product has no options, and the WC product has no attributes, it's a match.
		if(!isset($bc_options_response['data']) || empty($bc_options_response['data'])) {
			return empty($wc_attributes) ? [] : null;
		}
		$bc_options = $bc_options_response['data'];

		$wc_attributes_map = [];
		foreach ($wc_attributes as $taxonomy => $value_slug) {
			if(empty($value_slug)) continue;
			$term = get_term_by('slug', $value_slug, $taxonomy);
			if (!$term) continue;
			$attribute_label = wc_attribute_label($taxonomy);
			$wc_attributes_map[strtolower(trim($attribute_label))] = $term->name;
		}

		$mapped_options = [];
		$failed_mappings = [];

		foreach ($bc_options as $bc_option) {
			$bc_option_name_lower = strtolower(trim($bc_option['display_name']));

			if (isset($wc_attributes_map[$bc_option_name_lower])) {
				$wc_value_name = $wc_attributes_map[$bc_option_name_lower];
				$value_found = false;

				foreach ($bc_option['option_values'] as $bc_option_value) {
					if (strcasecmp(trim($bc_option_value['label']), trim($wc_value_name)) === 0) {
						$mapped_options[] = [
							'id' => $bc_option['id'],
							'value' => (string) $bc_option_value['id']
						];
						$value_found = true;
						break;
					}
				}

				if (!$value_found) {
					$failed_mappings[] = array(
						'option_name' => $bc_option['display_name'],
						'wc_value' => $wc_value_name,
						'bc_option' => $bc_option,
						'required' => $bc_option['required']
					);
				}
			} elseif ($bc_option['required']) {
				$failed_mappings[] = array(
					'option_name' => $bc_option['display_name'],
					'wc_value' => 'missing',
					'bc_option' => $bc_option,
					'required' => true
				);
			}
		}

		// If we have failed mappings, try to resolve them
		if (!empty($failed_mappings)) {
			$resolved_options = $this->resolve_failed_option_mappings($failed_mappings, $target_price, $order_id, $bc_product_id);

			if ($resolved_options === null) {
				// Could not resolve required options
				error_log("Order #{$order_id}: Could not resolve required options for BC Product #{$bc_product_id}");
				return null;
			}

			$mapped_options = array_merge($mapped_options, $resolved_options);
		}

		return $mapped_options;
	}

	/**
	 * Try to resolve failed option mappings with fallback strategies
	 */
	private function resolve_failed_option_mappings($failed_mappings, $target_price, $order_id, $bc_product_id) {
		$resolved_options = [];

		foreach ($failed_mappings as $failed_mapping) {
			$bc_option = $failed_mapping['bc_option'];
			$resolved_value = null;

			// Strategy 1: Try to find closest match by name similarity
			if ($failed_mapping['wc_value'] !== 'missing') {
				$resolved_value = $this->find_closest_option_value($failed_mapping['wc_value'], $bc_option['option_values']);
			}

			// Strategy 2: If price is provided, try to find value that gives closest price match
			if (!$resolved_value && $target_price) {
				$resolved_value = $this->find_price_matching_option_value($bc_product_id, $bc_option, $target_price);
			}

			// Strategy 3: Use the first available option value (fallback)
			if (!$resolved_value && !empty($bc_option['option_values'])) {
				$resolved_value = $bc_option['option_values'][0];
				error_log("Order #{$order_id}: Using fallback option value '{$resolved_value['label']}' for option '{$bc_option['display_name']}'");
			}

			// Strategy 4: If it's required and we still don't have a value, fail
			if (!$resolved_value && $bc_option['required']) {
				error_log("Order #{$order_id}: Cannot resolve required option '{$bc_option['display_name']}'");
				return null;
			}

			if ($resolved_value) {
				$resolved_options[] = [
					'id' => $bc_option['id'],
					'value' => (string) $resolved_value['id']
				];
			}
		}

		return $resolved_options;
	}

	/**
	 * Find the closest option value by name similarity
	 */
	private function find_closest_option_value($target_value, $option_values) {
		$best_match = null;
		$best_similarity = 0;

		foreach ($option_values as $option_value) {
			$similarity = 0;
			similar_text(strtolower($target_value), strtolower($option_value['label']), $similarity);

			if ($similarity > $best_similarity && $similarity > 70) { // 70% similarity threshold
				$best_similarity = $similarity;
				$best_match = $option_value;
			}
		}

		return $best_match;
	}

	/**
	 * Try to find option value that results in a price closest to target
	 */
	private function find_price_matching_option_value($bc_product_id, $bc_option, $target_price) {
		// This would require checking variant prices for each option value
		// For now, return first available value as a simple fallback
		return !empty($bc_option['option_values']) ? $bc_option['option_values'][0] : null;
	}

	/**
	 * Creates the payload for a "custom product" as a fallback with validation.
	 */
	private function create_fallback_product_payload($item) {
		$quantity = (int) $item->get_quantity();
		if ($quantity === 0) {
			return null;
		}

		$clean_name = $this->sanitize_product_name($item->get_name(), $item->get_product_id());

		// Final validation
		$validation = $this->validate_product_name($clean_name);
		if ($validation !== true) {
			error_log("Product name validation failed for order item " . $item->get_id() . ": " . implode(', ', $validation));
			$clean_name = 'Custom Product ' . $item->get_product_id();
		}

		return array(
			'name'          => $clean_name,
			'quantity'      => $quantity,
			'price_ex_tax'  => (float) ($item->get_subtotal() / $quantity),
			'price_inc_tax' => (float) ($item->get_total() / $quantity),
		);
	}

	/**
	 * Comprehensive product name sanitization
	 */
	private function sanitize_product_name($raw_name, $product_id = null) {
		// 1. Basic cleanup
		$clean_name = trim(preg_replace('/\s+/', ' ', $raw_name));

		// 2. Remove problematic characters
		$clean_name = str_replace(array(".", "/", "\\", "|", "<", ">", ":", "*", "?", '"', "'"), "", $clean_name);

		// 3. Fix duplication pattern
		$half_length = (int) (strlen($clean_name) / 2);
		if (strlen($clean_name) > 10 && $half_length > 5 && substr($clean_name, 0, $half_length) === substr($clean_name, $half_length)) {
			$clean_name = substr($clean_name, 0, $half_length);
		}

		// 4. Length limits
		if (strlen($clean_name) > 250) {
			$clean_name = substr($clean_name, 0, 247) . '...';
		}

		// 5. Remove control characters
		$clean_name = preg_replace('/[\x00-\x1F\x7F]/', '', $clean_name);
		$clean_name = trim($clean_name);

		// 6. Fallback for empty names
		if (strlen($clean_name) < 1) {
			$clean_name = 'Custom Product' . ($product_id ? ' ' . $product_id : '');
		}

		return $clean_name;
	}


	/**
	 * Validate product name for BigCommerce compatibility
	 */
	private function validate_product_name($name) {
		// Check for common issues that cause BigCommerce rejection
		$issues = array();

		if (strlen($name) > 250) {
			$issues[] = 'Name too long (' . strlen($name) . ' chars)';
		}

		if (strlen(trim($name)) < 1) {
			$issues[] = 'Name is empty';
		}

		// Check for control characters
		if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
			$issues[] = 'Contains control characters';
		}

		// Check for problematic patterns
		if (preg_match('/(.+)\1+/', $name)) {
			$issues[] = 'Contains repeated patterns';
		}

		return empty($issues) ? true : $issues;
	}

	private function get_v2_product_options($wc_variation, $bc_product_id) {
		$options = array();
		$attributes = $wc_variation->get_attributes();
		$bc_options_response = $this->bc_api->get_product_options($bc_product_id);
		if (!isset($bc_options_response['data'])) return array();
		$bc_options = $bc_options_response['data'];
		foreach ($attributes as $taxonomy => $value_slug) {
			$term = get_term_by('slug', $value_slug, 'pa_' . $taxonomy);
			if (!$term) continue;
			$attribute_label = wc_attribute_label('pa_' . $taxonomy);
			foreach ($bc_options as $bc_option) {
				if ($bc_option['display_name'] === $attribute_label) {
					foreach ($bc_option['option_values'] as $bc_option_value) {
						if ($bc_option_value['label'] === $term->name) {
							$options[] = array(
								'id' => $bc_option['id'],
								'value' => $bc_option_value['id']
							);
							break 2;
						}
					}
				}
			}
		}
		return $options;
	}

	private function add_customer_data(&$order_data, $wc_order) {
		$customer_id = $wc_order->get_customer_id();
		if ($customer_id) {
			$customer_mapping = WC_BC_Customer_Database::get_customer_by_wp_id($customer_id);
			if ($customer_mapping && $customer_mapping->bc_customer_id) {
				$order_data['customer_id'] = (int) $customer_mapping->bc_customer_id;
			}
		}
		$order_data['billing_address']['email'] = $wc_order->get_billing_email();
		if (!isset($order_data['customer_id'])) {
			$order_data['customer_message'] = 'Guest order from WooCommerce migration';
		}
	}

	private function add_billing_address(&$order_data, $wc_order) {
		$order_data['billing_address'] = array(
			'first_name' => $wc_order->get_billing_first_name(),
			'last_name' => $wc_order->get_billing_last_name(),
			'company' => $wc_order->get_billing_company(),
			'street_1' => $wc_order->get_billing_address_1(),
			'street_2' => $wc_order->get_billing_address_2(),
			'city' => $wc_order->get_billing_city(),
			'state' => $wc_order->get_billing_state(),
			'zip' => $wc_order->get_billing_postcode(),
			'country' => $wc_order->get_billing_country(),
			'country_iso2' => WC_BC_Location_Mapper::get_country_code($wc_order->get_billing_country()),
			'phone' => $wc_order->get_billing_phone(),
			'email' => $wc_order->get_billing_email()
		);
	}

	private function prepare_order_products($wc_order) {
		global $wpdb;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;
		$products = array();
		foreach ($wc_order->get_items() as $item_id => $item) {
			$product_id = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity = $item->get_quantity();
			$bc_product_id = null;
			$bc_variant_id = null;
			if ($variation_id) {
				$mapping = $wpdb->get_row($wpdb->prepare(
					"SELECT bc_product_id, bc_variation_id FROM $product_table 
					 WHERE wc_product_id = %d AND wc_variation_id = %d AND status = 'success'",
					$product_id,
					$variation_id
				));
				if ($mapping) {
					$bc_product_id = $mapping->bc_product_id;
					$bc_variant_id = $mapping->bc_variation_id;
				}
			} else {
				$bc_product_id = $wpdb->get_var($wpdb->prepare(
					"SELECT bc_product_id FROM $product_table 
					 WHERE wc_product_id = %d AND wc_variation_id IS NULL AND status = 'success'",
					$product_id
				));
			}
			if (!$bc_product_id) {
				continue;
			}
			$product_data = array(
				'product_id' => (int) $bc_product_id,
				'quantity' => (int) $quantity,
				'price_inc_tax' => (float) ($item->get_total() + $item->get_total_tax()) / $quantity,
				'price_ex_tax' => (float) $item->get_total() / $quantity,
				'name' => $item->get_name(),
			);
			if ($bc_variant_id) {
				$product_data['variant_id'] = (int) $bc_variant_id;
			}
			$item_meta = $item->get_meta_data();
			if (!empty($item_meta)) {
				$product_options = array();
				foreach ($item_meta as $meta) {
					$key = $meta->key;
					$value = $meta->value;
					if (strpos($key, '_') === 0) {
						continue;
					}
					$product_options[] = array(
						'display_name' => $key,
						'display_value' => $value
					);
				}
				if (!empty($product_options)) {
					$product_data['product_options'] = $product_options;
				}
			}
			$products[] = $product_data;
		}
		return $products;
	}

	private function add_shipping_data(&$order_data, $wc_order) {
		$shipping_address = array(
			'first_name' => $wc_order->get_shipping_first_name() ?: $wc_order->get_billing_first_name(),
			'last_name' => $wc_order->get_shipping_last_name() ?: $wc_order->get_billing_last_name(),
			'company' => $wc_order->get_shipping_company() ?: $wc_order->get_billing_company(),
			'street_1' => $wc_order->get_shipping_address_1() ?: $wc_order->get_billing_address_1(),
			'street_2' => $wc_order->get_shipping_address_2() ?: $wc_order->get_billing_address_2(),
			'city' => $wc_order->get_shipping_city() ?: $wc_order->get_billing_city(),
			'state' => $wc_order->get_shipping_state() ?: $wc_order->get_billing_state(),
			'zip' => $wc_order->get_shipping_postcode() ?: $wc_order->get_billing_postcode(),
			'country' => $wc_order->get_shipping_country() ?: $wc_order->get_billing_country(),
			'country_iso2' => WC_BC_Location_Mapper::get_country_code($wc_order->get_shipping_country() ?: $wc_order->get_billing_country()),
		);
		$order_data['shipping_addresses'] = array($shipping_address);
		$shipping_total = $wc_order->get_shipping_total();
		if ($shipping_total > 0) {
			$order_data['shipping_cost_ex_tax'] = (float) $shipping_total;
			$order_data['shipping_cost_inc_tax'] = (float) ($shipping_total + $wc_order->get_shipping_tax());
		}
		$shipping_methods = $wc_order->get_shipping_methods();
		if (!empty($shipping_methods)) {
			$shipping_method = reset($shipping_methods);
			$order_data['shipping_addresses'][0]['shipping_method'] = $shipping_method->get_method_title();
		}
	}

	private function add_tax_data(&$order_data, $wc_order) {
		$tax_total = $wc_order->get_total_tax();
		if ($tax_total > 0) {
			$order_data['total_tax'] = (float) $tax_total;
			$tax_items = $wc_order->get_items('tax');
			if (!empty($tax_items)) {
				$taxes = array();
				foreach ($tax_items as $tax_item) {
					$taxes[] = array(
						'name' => $tax_item->get_name(),
						'rate' => (float) $tax_item->get_rate_percent(),
						'tax_amount' => (float) $tax_item->get_tax_total(),
						'tax_on_shipping' => (float) $tax_item->get_shipping_tax_total()
					);
				}
				$order_data['taxes'] = $taxes;
			}
		}
	}

	private function prepare_order_custom_fields($wc_order) {
		$custom_fields = array();
		$custom_fields[] = array(
			'name' => 'wc_order_id',
			'value' => (string) $wc_order->get_id()
		);
		$payment_custom_fields = WC_BC_Order_Status_Mapper::prepare_payment_method_custom_fields(
			$wc_order->get_payment_method(),
			$wc_order->get_payment_method_title(),
			$wc_order->get_transaction_id()
		);
		$custom_fields = array_merge($custom_fields, $payment_custom_fields);
		$custom_fields[] = array(
			'name' => 'wc_order_status',
			'value' => $wc_order->get_status()
		);
		$custom_fields[] = array(
			'name' => 'order_source',
			'value' => 'WooCommerce Migration'
		);
		$custom_fields[] = array(
			'name' => 'migrated_at',
			'value' => current_time('Y-m-d H:i:s')
		);
		$financial_status = WC_BC_Order_Status_Mapper::get_financial_status($wc_order);
		$custom_fields[] = array(
			'name' => 'wc_financial_status',
			'value' => $financial_status
		);
		return $custom_fields;
	}

	private function add_order_notes(&$order_data, $wc_order) {
		$customer_note = $wc_order->get_customer_note();
		if (!empty($customer_note)) {
			$order_data['customer_message'] = $customer_note;
		}
		$notes = wc_get_order_notes(array('order_id' => $wc_order->get_id()));
		if (!empty($notes)) {
			$staff_notes = array();
			foreach ($notes as $note) {
				if ($note->customer_note == 0) {
					$staff_notes[] = '[' . $note->date_created->format('Y-m-d H:i:s') . '] ' . $note->content;
				}
			}
			if (!empty($staff_notes)) {
				$order_data['staff_notes'] = implode("\n", $staff_notes);
			}
		}
	}

	private function add_coupon_data(&$order_data, $wc_order) {
		$coupons = $wc_order->get_items('coupon');
		if (!empty($coupons)) {
			$coupon_data = array();
			$total_discount = 0;
			foreach ($coupons as $coupon_item) {
				$coupon_code = $coupon_item->get_code();
				$discount_amount = abs($coupon_item->get_discount());
				$total_discount += $discount_amount;
				$coupon_data[] = array(
					'code' => $coupon_code,
					'discount' => (float) $discount_amount,
					'type' => 'per_total_discount'
				);
			}
			if ($total_discount > 0) {
				$order_data['discount_amount'] = (float) $total_discount;
				$order_data['coupons'] = $coupon_data;
			}
		}
	}

	public function get_migration_stats() {
		return WC_BC_Order_Database::get_dashboard_stats();
	}

	public function retry_failed_orders( $batch_size = 10 ) {
		$failed_orders = WC_BC_Order_Database::get_error_orders( $batch_size );
		if ( empty( $failed_orders ) ) {
			return array( 'success' => true, 'message' => 'No failed orders to retry', 'processed' => 0 );
		}
		$processed = 0;
		$errors    = 0;
		foreach ( $failed_orders as $order_mapping ) {
			WC_BC_Order_Database::update_order_mapping( $order_mapping->wc_order_id, array(
				'migration_status'  => 'pending',
				'migration_message' => 'Retrying migration'
			) );
			$result = $this->migrate_single_order( $order_mapping->wc_order_id );
			if ( isset( $result['error'] ) ) {
				$errors ++;
			} else {
				$processed ++;
			}
			usleep( 500000 );
		}

		return array(
			'success'   => true,
			'processed' => $processed,
			'errors'    => $errors,
			'remaining' => WC_BC_Order_Database::get_remaining_orders_count()
		);
	}
}