<?php
/**
 * Order Migration Processor
 * Handles the preparation and batch processing of WooCommerce orders for migration
 */
class WC_BC_Order_Processor {

	private $bc_api;
	private $status_mapper;

	public function __construct() {
		$this->bc_api = new WC_BC_BigCommerce_API();
		$this->status_mapper = new WC_BC_Order_Status_Mapper();
	}

	/**
	 * Prepare all WooCommerce orders for migration
	 */
	public function prepare_orders($date_from = null, $date_to = null, $status_filter = null) {
		set_time_limit(300);

		if (!WC_BC_Order_Database::create_table()) {
			return array('success' => false, 'message' => 'Failed to create order migration table');
		}

		$batch_size = 500;
		$offset = 0;
		$inserted = 0;
		$skipped = 0;
		$errors = 0;

		do {
			$args = array(
				'type' => 'shop_order',
				'status' => array_keys(wc_get_order_statuses()),
				'limit' => $batch_size,
				'offset' => $offset,
				'return' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC'
			);

			$order_ids = wc_get_orders($args);

			if (empty($order_ids)) {
				break;
			}

			foreach ($order_ids as $order_id) {
				if (WC_BC_Order_Database::get_order_by_wc_id($order_id)) {
					$skipped++;
					continue;
				}

				$order = wc_get_order($order_id);
				if (!$order) {
					$errors++;
					continue;
				}

				$order_data = $this->extract_order_basic_data($order);
				if (WC_BC_Order_Database::insert_order_mapping($order_data)) {
					$inserted++;
				} else {
					$errors++;
					error_log("Failed to prepare order {$order_id} for migration");
				}
				unset($order);
			}

			$offset += $batch_size;
			wp_cache_flush();

		} while (count($order_ids) === $batch_size);

		return array(
			'success' => true,
			'total_processed' => $inserted + $skipped + $errors,
			'inserted' => $inserted,
			'skipped' => $skipped,
			'errors' => $errors
		);
	}

	/**
	 * Extract basic order data for database storage
	 */
	private function extract_order_basic_data($order) {
		$customer_id = $order->get_customer_id();
		$bc_customer_id = null;
		if ($customer_id) {
			$customer_mapping = WC_BC_Customer_Database::get_customer_by_wp_id($customer_id);
			if ($customer_mapping && $customer_mapping->bc_customer_id) {
				$bc_customer_id = $customer_mapping->bc_customer_id;
			}
		}
		return array(
			'wc_order_id' => $order->get_id(),
			'wc_customer_id' => $customer_id ?: null,
			'bc_customer_id' => $bc_customer_id,
			'order_status' => $order->get_status(),
			'order_total' => $order->get_total(),
			'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'migration_status' => 'pending'
		);
	}

	/**
	 * Process a batch of orders for migration
	 */
	public function process_batch($batch_size = 10) {
		$pending_orders = WC_BC_Order_Database::get_pending_orders($batch_size);

		if (empty($pending_orders)) {
			return array('success' => true, 'message' => 'No pending orders to migrate', 'processed' => 0, 'errors' => 0);
		}

		$processed = 0;
		$errors = 0;
		$results = array();

		foreach ($pending_orders as $order_mapping) {
			$result = $this->migrate_single_order($order_mapping->wc_order_id);
			$results[$order_mapping->wc_order_id] = $result;

			if (isset($result['error'])) {
				$errors++;
			} else {
				$processed++;
			}
			usleep(500000); // 0.5 second delay
		}

		return array(
			'success' => true,
			'processed' => $processed,
			'errors' => $errors,
			'results' => $results,
			'remaining' => WC_BC_Order_Database::get_remaining_orders_count()
		);
	}

	/**
	 * Migrate a single order to BigCommerce
	 */
	public function migrate_single_order($wc_order_id) {
		$wc_order = wc_get_order($wc_order_id);
		if (!$wc_order) {
			return array('error' => 'WooCommerce order not found');
		}

		try {
			$existing_mapping = WC_BC_Order_Database::get_order_by_wc_id($wc_order_id);
			if ($existing_mapping && $existing_mapping->bc_order_id) {
				WC_BC_Order_Database::update_order_mapping($wc_order_id, ['migration_status' => 'success', 'migration_message' => 'Order already migrated.']);
				return array('success' => true, 'bc_order_id' => $existing_mapping->bc_order_id, 'already_exists' => true);
			}

			$order_data = $this->prepare_order_data($wc_order);

			// If there are no products (all fallbacks failed), mark as error.
			if (empty($order_data['products'])) {
				throw new Exception('Could not process any line items for this order. Check product mapping and data.');
			}

			$result = $this->bc_api->create_order($order_data);

			if (isset($result['error'])) {
				throw new Exception(is_array($result) ? json_encode($result) : $result);
			}

			if (!isset($result['data']['id'])) {
				throw new Exception('No order ID returned from BigCommerce: ' . json_encode($result));
			}

			$bc_order_id = $result['data']['id'];

			WC_BC_Order_Database::update_order_mapping($wc_order_id, array(
				'bc_order_id' => $bc_order_id,
				'migration_status' => 'success',
				'migration_message' => 'Order migrated successfully'
			));

			return array('success' => true, 'bc_order_id' => $bc_order_id);

		} catch (Exception $e) {
			$error_message = $e->getMessage();
			error_log("Order migration error for order {$wc_order_id}: {$error_message}");

			WC_BC_Order_Database::update_order_mapping($wc_order_id, array(
				'migration_status' => 'error',
				'migration_message' => $error_message
			));

			return array('error' => $error_message);
		}
	}

	/**
	 * Prepare complete order data for BigCommerce V2 API
	 * FINAL VERSION: Ignores inventory checks for historical orders.
	 */
	private function prepare_order_data($wc_order) {
		$bc_customer_id = 0;
		$customer_id = $wc_order->get_customer_id();
		if ($customer_id) {
			$customer_mapping = WC_BC_Customer_Database::get_customer_by_wp_id($customer_id);
			if ($customer_mapping && $customer_mapping->bc_customer_id) {
				$bc_customer_id = (int) $customer_mapping->bc_customer_id;
			}
		}

		$staff_notes = 'Migrated from WooCommerce. WC Order ID: ' . $wc_order->get_id();
		$coupon_codes = $wc_order->get_coupon_codes();
		if (!empty($coupon_codes)) {
			$staff_notes .= ' | Coupon(s) Used: ' . implode(', ', $coupon_codes);
		}

		return array(
			'customer_id' => $bc_customer_id,
			'status_id' => WC_BC_Order_Status_Mapper::map_status($wc_order->get_status()),
			'date_created' => $wc_order->get_date_created()->format('r'),
			'billing_address' => $this->prepare_v2_billing_address($wc_order),
			'shipping_addresses' => $this->prepare_v2_shipping_addresses($wc_order),
			'products' => $this->prepare_v2_order_products($wc_order),
			'subtotal_ex_tax' => (float) $wc_order->get_subtotal(),
			'subtotal_inc_tax' => (float) ($wc_order->get_subtotal() + ($wc_order->get_total_tax() - $wc_order->get_shipping_tax())),
			'total_ex_tax' => (float) ($wc_order->get_total() - $wc_order->get_total_tax()),
			'total_inc_tax' => (float) $wc_order->get_total(),
			'shipping_cost_ex_tax' => (float) $wc_order->get_shipping_total(),
			'shipping_cost_inc_tax' => (float) ($wc_order->get_shipping_total() + $wc_order->get_shipping_tax()),
			'payment_method' => $wc_order->get_payment_method_title(),
			'staff_notes' => $staff_notes,
			'customer_message' => $wc_order->get_customer_note(),
			'discount_amount' => (float) $wc_order->get_discount_total(),
			'is_inventory_tracking_enabled' => false, // THIS IS THE FIX for OutOfStock errors
		);
	}

	private function prepare_v2_billing_address($wc_order) {
		$country_code = $wc_order->get_billing_country();
		$country_name = WC_BC_Location_Mapper::get_bc_country_name($country_code);
		if (empty($country_name)) {
			throw new Exception("Invalid or unrecognized billing country code '{$country_code}' for Order #" . $wc_order->get_id());
		}
		return array(
			'first_name' => $wc_order->get_billing_first_name(),
			'last_name' => $wc_order->get_billing_last_name(),
			'company' => $wc_order->get_billing_company(),
			'street_1' => $wc_order->get_billing_address_1(),
			'street_2' => $wc_order->get_billing_address_2(),
			'city' => $wc_order->get_billing_city(),
			'state' => WC_BC_Location_Mapper::get_full_state_name($wc_order->get_billing_state(), $country_code),
			'zip' => $wc_order->get_billing_postcode(),
			'country' => $country_name,
			'country_iso2' => $country_code,
			'phone' => $wc_order->get_billing_phone(),
			'email' => $wc_order->get_billing_email(),
		);
	}

	private function prepare_v2_shipping_addresses($wc_order) {
		if (!$wc_order->get_shipping_address_1()) {
			return array();
		}
		$shipping_methods = $wc_order->get_shipping_methods();
		$shipping_method_name = !empty($shipping_methods) ? reset($shipping_methods)->get_method_title() : 'Migrated Shipping';
		$country_code = $wc_order->get_shipping_country();
		$country_name = WC_BC_Location_Mapper::get_bc_country_name($country_code);
		if (empty($country_name)) {
			throw new Exception("Invalid or unrecognized shipping country code '{$country_code}' for Order #" . $wc_order->get_id());
		}
		$shipping_address = array(
			'first_name' => $wc_order->get_shipping_first_name(),
			'last_name' => $wc_order->get_shipping_last_name(),
			'company' => $wc_order->get_shipping_company(),
			'street_1' => $wc_order->get_shipping_address_1(),
			'street_2' => $wc_order->get_shipping_address_2(),
			'city' => $wc_order->get_shipping_city(),
			'state' => WC_BC_Location_Mapper::get_full_state_name($wc_order->get_shipping_state(), $country_code),
			'zip' => $wc_order->get_shipping_postcode(),
			'country' => $country_name,
			'country_iso2' => $country_code,
			'shipping_method' => $shipping_method_name,
		);
		return array($shipping_address);
	}

	private function prepare_v2_order_products($wc_order) {
		$products = array();
		foreach ($wc_order->get_items() as $item_id => $item) {
			$line_item = $this->get_v2_line_item($item);
			if ($line_item) {
				$products[] = $line_item;
			}
		}
		return $products;
	}

	private function get_v2_line_item($item) {
		$mapped_product = $this->get_v2_mapped_product($item);
		if ($mapped_product) {
			return $mapped_product;
		}
		error_log("Order #" . $item->get_order_id() . ": Falling back to custom product for item '" . $item->get_name() . "'. The original product may be deleted or unmapped.");
		$product = $item->get_product();
		$sku_prefix = 'WC-' . ($item->get_variation_id() ?: $item->get_product_id());
		$original_sku = is_object($product) ? $product->get_sku() : 'DELETED';
		$clean_sku = preg_replace('/[^a-zA-Z0-9\-\_]/', '', $original_sku); // Clean the SKU

		if (!is_object($product)) {
			return array(
				'name' => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'price_ex_tax' => (float) ($item->get_subtotal() / $item->get_quantity()),
				'price_inc_tax' => (float) ($item->get_total() / $item->get_quantity()),
				'sku' => 'WC-DELETED-' . $item->get_product_id()
			);
		}

		return array(
			'name' => $item->get_name(),
			'quantity' => (int) $item->get_quantity(),
			'price_ex_tax' => (float) ($item->get_subtotal() / $item->get_quantity()),
			'price_inc_tax' => (float) ($item->get_total() / $item->get_quantity()),
			'sku' => $sku_prefix . '-' . $clean_sku,
			'ignore_inventory' => true, // THIS IS THE FIX for OutOfStock errors
		);
	}

	private function get_v2_mapped_product($item) {
		global $wpdb;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;
		$product_id = $item->get_product_id();
		$variation_id = $item->get_variation_id();
		if (empty($product_id)) return null;

		$bc_product_id = null;
		$product_options = array();

		if ($variation_id) {
			$mapping = $wpdb->get_row($wpdb->prepare(
				"SELECT bc_product_id FROM $product_table WHERE wc_product_id = %d AND wc_variation_id = %d AND status = 'success'",
				$product_id, $variation_id
			));
			if (!$mapping) return null;
			$bc_product_id = $mapping->bc_product_id;
			$wc_variation = wc_get_product($variation_id);
			if ($wc_variation) {
				$product_options = $this->get_v2_product_options_from_db($wc_variation, $bc_product_id);
				$wc_attributes_count = count($wc_variation->get_attributes());
				if ($wc_attributes_count > 0 && empty($product_options)) {
					return null;
				}
			} else {
				return null;
			}
		} else {
			$bc_product_id = $wpdb->get_var($wpdb->prepare(
				"SELECT bc_product_id FROM $product_table WHERE wc_product_id = %d AND wc_variation_id IS NULL AND status = 'success'",
				$product_id
			));
			if (!$bc_product_id) return null;
		}

		return array(
			'product_id' => (int) $bc_product_id,
			'quantity' => (int) $item->get_quantity(),
			'product_options' => $product_options,
			'ignore_inventory' => true, // THIS IS THE FIX for OutOfStock errors
		);
	}

	private function get_v2_product_options_from_db($wc_variation, $bc_product_id) {
		$options = array();
		$attributes = $wc_variation->get_attributes();
		$bc_options_response = $this->bc_api->get_product_options($bc_product_id);
		if (!isset($bc_options_response['data'])) return array();
		$bc_options = $bc_options_response['data'];

		foreach ($attributes as $taxonomy_slug => $value_slug) {
			if (empty($value_slug)) continue;
			$term = get_term_by('slug', $value_slug, 'pa_' . $taxonomy_slug);
			if (!$term) continue;

			$attribute_label = wc_attribute_label('pa_' . $taxonomy_slug);

			foreach ($bc_options as $bc_option) {
				if (strcasecmp($bc_option['display_name'], $attribute_label) === 0) {
					foreach ($bc_option['option_values'] as $bc_option_value) {
						if (strcasecmp($bc_option_value['label'], $term->name) === 0) {
							$options[] = array(
								'id' => $bc_option_value['id'],
								'value' => (string) $bc_option_value['id'],
								'product_option_id' => $bc_option['id']
							);
							break 2;
						}
					}
				}
			}
		}
		return count($attributes) === count($options) ? $options : array();
	}

	/**
	 * Get product options in V2 format for a variation
	 */
	private function get_v2_product_options($wc_variation, $bc_product_id) {
		$options = array();
		$attributes = $wc_variation->get_attributes();

		// Get options from BigCommerce product to find the correct product_option_id
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
								'product_option_id' => $bc_option['id'],
								'value' => $bc_option_value['id']
							);
							break 2; // Move to the next attribute
						}
					}
				}
			}
		}
		return $options;
	}

	/**
	 * Add customer data to order
	 */
	private function add_customer_data(&$order_data, $wc_order) {
		$customer_id = $wc_order->get_customer_id();

		if ($customer_id) {
			// Registered customer
			$customer_mapping = WC_BC_Customer_Database::get_customer_by_wp_id($customer_id);
			if ($customer_mapping && $customer_mapping->bc_customer_id) {
				$order_data['customer_id'] = (int) $customer_mapping->bc_customer_id;
			}
		}

		// Always include customer information for guest orders or as backup
		$order_data['billing_address']['email'] = $wc_order->get_billing_email();
		if (!isset($order_data['customer_id'])) {
			$order_data['customer_message'] = 'Guest order from WooCommerce migration';
		}
	}

	/**
	 * Add billing address to order
	 */
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

	/**
	 * Prepare order products/line items
	 */
	private function prepare_order_products($wc_order) {
		global $wpdb;
		$product_table = $wpdb->prefix . WC_BC_MIGRATOR_TABLE;
		$products = array();

		foreach ($wc_order->get_items() as $item_id => $item) {
			$product_id = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$quantity = $item->get_quantity();

			// Get BigCommerce product/variant ID
			$bc_product_id = null;
			$bc_variant_id = null;

			if ($variation_id) {
				// Get variation mapping
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
				// Get simple product mapping
				$bc_product_id = $wpdb->get_var($wpdb->prepare(
					"SELECT bc_product_id FROM $product_table 
					 WHERE wc_product_id = %d AND wc_variation_id IS NULL AND status = 'success'",
					$product_id
				));
			}

			if (!$bc_product_id) {
				continue; // Skip products that aren't migrated
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

			// Add product options/meta
			$item_meta = $item->get_meta_data();
			if (!empty($item_meta)) {
				$product_options = array();
				foreach ($item_meta as $meta) {
					$key = $meta->key;
					$value = $meta->value;

					// Skip internal WooCommerce meta
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

	/**
	 * Add shipping data to order
	 */
	private function add_shipping_data(&$order_data, $wc_order) {
		// Shipping address
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

		// Shipping cost
		$shipping_total = $wc_order->get_shipping_total();
		if ($shipping_total > 0) {
			$order_data['shipping_cost_ex_tax'] = (float) $shipping_total;
			$order_data['shipping_cost_inc_tax'] = (float) ($shipping_total + $wc_order->get_shipping_tax());
		}

		// Shipping method
		$shipping_methods = $wc_order->get_shipping_methods();
		if (!empty($shipping_methods)) {
			$shipping_method = reset($shipping_methods);
			$order_data['shipping_addresses'][0]['shipping_method'] = $shipping_method->get_method_title();
		}
	}

	/**
	 * Add tax data to order
	 */
	private function add_tax_data(&$order_data, $wc_order) {
		$tax_total = $wc_order->get_total_tax();

		if ($tax_total > 0) {
			$order_data['total_tax'] = (float) $tax_total;

			// Get tax details
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

	/**
	 * Prepare custom fields including payment method and WC data
	 */
	private function prepare_order_custom_fields($wc_order) {
		$custom_fields = array();

		// WooCommerce order ID for reference
		$custom_fields[] = array(
			'name' => 'wc_order_id',
			'value' => (string) $wc_order->get_id()
		);

		// Payment method information
		$payment_custom_fields = WC_BC_Order_Status_Mapper::prepare_payment_method_custom_fields(
			$wc_order->get_payment_method(),
			$wc_order->get_payment_method_title(),
			$wc_order->get_transaction_id()
		);
		$custom_fields = array_merge($custom_fields, $payment_custom_fields);

		// Original order status
		$custom_fields[] = array(
			'name' => 'wc_order_status',
			'value' => $wc_order->get_status()
		);

		// Order source
		$custom_fields[] = array(
			'name' => 'order_source',
			'value' => 'WooCommerce Migration'
		);

		// Migration timestamp
		$custom_fields[] = array(
			'name' => 'migrated_at',
			'value' => current_time('Y-m-d H:i:s')
		);

		// Financial status
		$financial_status = WC_BC_Order_Status_Mapper::get_financial_status($wc_order);
		$custom_fields[] = array(
			'name' => 'wc_financial_status',
			'value' => $financial_status
		);

		return $custom_fields;
	}

	/**
	 * Add order notes to order
	 */
	private function add_order_notes(&$order_data, $wc_order) {
		// Customer notes
		$customer_note = $wc_order->get_customer_note();
		if (!empty($customer_note)) {
			$order_data['customer_message'] = $customer_note;
		}

		// Staff notes (order notes)
		$notes = wc_get_order_notes(array('order_id' => $wc_order->get_id()));
		if (!empty($notes)) {
			$staff_notes = array();
			foreach ($notes as $note) {
				if ($note->customer_note == 0) { // Staff note
					$staff_notes[] = '[' . $note->date_created->format('Y-m-d H:i:s') . '] ' . $note->content;
				}
			}

			if (!empty($staff_notes)) {
				$order_data['staff_notes'] = implode("\n", $staff_notes);
			}
		}
	}

	/**
	 * Add coupon/discount data to order
	 */
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
					'type' => 'per_total_discount' // BigCommerce coupon type
				);
			}

			if ($total_discount > 0) {
				$order_data['discount_amount'] = (float) $total_discount;
				$order_data['coupons'] = $coupon_data;
			}
		}
	}

	/**
	 * Get migration statistics
	 */
	public function get_migration_stats() {
		return WC_BC_Order_Database::get_dashboard_stats();
	}

	/**
	 * Retry failed order migrations
	 */
	public function retry_failed_orders($batch_size = 10) {
		$failed_orders = WC_BC_Order_Database::get_error_orders($batch_size);

		if (empty($failed_orders)) {
			return array('success' => true, 'message' => 'No failed orders to retry', 'processed' => 0);
		}

		$processed = 0;
		$errors = 0;

		foreach ($failed_orders as $order_mapping) {
			WC_BC_Order_Database::update_order_mapping($order_mapping->wc_order_id, array(
				'migration_status' => 'pending',
				'migration_message' => 'Retrying migration'
			));

			$result = $this->migrate_single_order($order_mapping->wc_order_id);

			if (isset($result['error'])) {
				$errors++;
			} else {
				$processed++;
			}
			usleep(500000);
		}

		return array(
			'success' => true,
			'processed' => $processed,
			'errors' => $errors,
			'remaining' => WC_BC_Order_Database::get_remaining_orders_count()
		);
	}
}