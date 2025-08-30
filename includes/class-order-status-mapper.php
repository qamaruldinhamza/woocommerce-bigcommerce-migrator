<?php
/**
 * Order Status Mapper Class
 * Handles mapping between WooCommerce and BigCommerce order statuses
 */
class WC_BC_Order_Status_Mapper {

	/**
	 * Default status mapping from WooCommerce to BigCommerce
	 */
	private static $status_mapping = array(
		// WooCommerce => BigCommerce Status ID
		'pending' => 1,           // Pending
		'processing' => 11,       // Awaiting Fulfillment
		'on-hold' => 7,          // Awaiting Payment
		'completed' => 10,        // Completed
		'cancelled' => 5,         // Cancelled
		'refunded' => 4,         // Refunded
		'failed' => 0,           // Incomplete
		'checkout-draft' => 0,    // Incomplete
	);

	/**
	 * BigCommerce default status names for reference
	 */
	private static $bc_status_names = array(
		0 => 'Incomplete',
		1 => 'Pending',
		2 => 'Shipped',
		3 => 'Partially Shipped',
		4 => 'Refunded',
		5 => 'Cancelled',
		6 => 'Declined',
		7 => 'Awaiting Payment',
		8 => 'Awaiting Pickup',
		9 => 'Awaiting Shipment',
		10 => 'Completed',
		11 => 'Awaiting Fulfillment',
		12 => 'Manual Verification Required',
		13 => 'Disputed',
		14 => 'Partially Refunded'
	);

	/**
	 * Payment method mapping for custom fields
	 */
	private static $payment_method_mapping = array(
		'bacs' => 'Bank Transfer (BACS)',
		'cheque' => 'Check Payment',
		'cod' => 'Cash on Delivery',
		'paypal' => 'PayPal',
		'stripe' => 'Stripe Credit Card',
		'square' => 'Square',
		'authorize_net' => 'Authorize.Net',
		'braintree' => 'Braintree',
		'woocommerce_payments' => 'WooCommerce Payments',
		'klarna_payments' => 'Klarna',
		'afterpay' => 'Afterpay',
		'amazon_payments_advanced' => 'Amazon Pay',
		'paypal_express' => 'PayPal Express',
		'paypal_pro' => 'PayPal Pro',
	);

	/**
	 * Map WooCommerce status to BigCommerce status ID
	 */
	public static function map_status($wc_status) {
		// Remove wc- prefix if present
		$wc_status = str_replace('wc-', '', $wc_status);

		// Check for direct mapping
		if (isset(self::$status_mapping[$wc_status])) {
			return self::$status_mapping[$wc_status];
		}

		// Custom status handling
		$custom_mapping = get_option('wc_bc_custom_status_mapping', array());
		if (isset($custom_mapping[$wc_status])) {
			return $custom_mapping[$wc_status];
		}

		// Default fallback based on status patterns
		if (strpos($wc_status, 'complete') !== false || strpos($wc_status, 'fulfilled') !== false) {
			return 10; // Completed
		}

		if (strpos($wc_status, 'process') !== false || strpos($wc_status, 'ship') !== false) {
			return 11; // Awaiting Fulfillment
		}

		if (strpos($wc_status, 'cancel') !== false) {
			return 5; // Cancelled
		}

		if (strpos($wc_status, 'refund') !== false) {
			return 4; // Refunded
		}

		if (strpos($wc_status, 'hold') !== false || strpos($wc_status, 'payment') !== false) {
			return 7; // Awaiting Payment
		}

		// Default to pending for unknown statuses
		return 1; // Pending
	}

	/**
	 * Get BigCommerce status name from ID
	 */
	public static function get_bc_status_name($status_id) {
		return isset(self::$bc_status_names[$status_id]) ? self::$bc_status_names[$status_id] : 'Unknown';
	}

	/**
	 * Get all available mappings
	 */
	public static function get_status_mapping() {
		return self::$status_mapping;
	}

	/**
	 * Get human-readable payment method name
	 */
	public static function get_payment_method_title($payment_method, $fallback_title = '') {
		// If we have a title already, use it
		if (!empty($fallback_title)) {
			return $fallback_title;
		}

		// Try to get from our mapping
		if (isset(self::$payment_method_mapping[$payment_method])) {
			return self::$payment_method_mapping[$payment_method];
		}

		// Clean up the payment method name
		$title = str_replace(array('_', '-'), ' ', $payment_method);
		return ucwords($title);
	}

	/**
	 * Prepare payment method data for custom fields
	 */
	public static function prepare_payment_method_custom_fields($payment_method, $payment_method_title, $transaction_id = '') {
		$custom_fields = array();

		// Original payment method
		if (!empty($payment_method)) {
			$custom_fields[] = array(
				'name' => 'wc_payment_method',
				'value' => $payment_method
			);
		}

		// Payment method title (user-friendly name)
		if (!empty($payment_method_title)) {
			$custom_fields[] = array(
				'name' => 'wc_payment_method_title',
				'value' => $payment_method_title
			);
		}

		// Transaction ID
		if (!empty($transaction_id)) {
			$custom_fields[] = array(
				'name' => 'wc_transaction_id',
				'value' => $transaction_id
			);
		}

		return $custom_fields;
	}

	/**
	 * Update custom status mapping
	 */
	public static function update_status_mapping($wc_status, $bc_status_id) {
		$custom_mapping = get_option('wc_bc_custom_status_mapping', array());
		$custom_mapping[$wc_status] = (int) $bc_status_id;
		update_option('wc_bc_custom_status_mapping', $custom_mapping);
	}

	/**
	 * Get all WooCommerce order statuses
	 */
	public static function get_woocommerce_statuses() {
		if (!function_exists('wc_get_order_statuses')) {
			return array();
		}

		return wc_get_order_statuses();
	}

	/**
	 * Get BigCommerce status options for admin interface
	 */
	public static function get_bigcommerce_status_options() {
		return self::$bc_status_names;
	}

	/**
	 * Validate if a BigCommerce status ID is valid
	 */
	public static function is_valid_bc_status($status_id) {
		return isset(self::$bc_status_names[$status_id]);
	}

	/**
	 * Get recommended BigCommerce status for WooCommerce status
	 * Returns array with status_id, name, and reasoning
	 */
	public static function get_recommended_mapping($wc_status) {
		$status_id = self::map_status($wc_status);
		$status_name = self::get_bc_status_name($status_id);

		$reasoning = '';
		switch ($wc_status) {
			case 'pending':
				$reasoning = 'Order is awaiting payment or processing';
				break;
			case 'processing':
				$reasoning = 'Order is paid and ready for fulfillment';
				break;
			case 'completed':
				$reasoning = 'Order has been fulfilled and completed';
				break;
			case 'cancelled':
				$reasoning = 'Order was cancelled by customer or admin';
				break;
			case 'refunded':
				$reasoning = 'Order was refunded';
				break;
			case 'on-hold':
				$reasoning = 'Order is on hold, usually awaiting payment';
				break;
			default:
				$reasoning = 'Best match based on status name';
				break;
		}

		return array(
			'status_id' => $status_id,
			'status_name' => $status_name,
			'reasoning' => $reasoning
		);
	}

	/**
	 * Get order financial status for BigCommerce
	 * This helps determine if the order is paid, pending payment, etc.
	 */
	public static function get_financial_status($wc_order) {
		$status = $wc_order->get_status();
		$payment_method = $wc_order->get_payment_method();

		// Check if order has been paid
		if ($wc_order->is_paid()) {
			return 'paid';
		}

		// Check payment method requirements
		$payment_gateways_requiring_payment = array('stripe', 'paypal', 'square', 'authorize_net');

		if (in_array($payment_method, $payment_gateways_requiring_payment)) {
			// These gateways usually mean payment was attempted
			if (in_array($status, array('processing', 'completed', 'shipped'))) {
				return 'paid';
			} else if (in_array($status, array('failed', 'cancelled'))) {
				return 'voided';
			} else {
				return 'pending';
			}
		}

		// Manual payment methods
		$manual_payment_methods = array('bacs', 'cheque', 'cod');
		if (in_array($payment_method, $manual_payment_methods)) {
			if (in_array($status, array('processing', 'completed'))) {
				return 'paid';
			} else {
				return 'pending';
			}
		}

		// Default based on status
		switch ($status) {
			case 'completed':
			case 'processing':
				return 'paid';
			case 'refunded':
				return 'refunded';
			case 'cancelled':
				return 'voided';
			default:
				return 'pending';
		}
	}
}