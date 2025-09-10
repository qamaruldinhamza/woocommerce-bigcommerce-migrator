<?php
/**
 * Location Mapper Class
 * Uses WooCommerce's built-in location data for comprehensive country and state mapping
 */
class WC_BC_Location_Mapper {
	private static $bc_countries_data = null;

	/**
	 * Load BigCommerce countries data
	 */
	private static function load_bc_countries_data() {
		if (self::$bc_countries_data === null) {
			$json_file = WC_BC_MIGRATOR_PATH . 'includes/bigcommerce-countries.json';
			if (file_exists($json_file)) {
				$json_content = file_get_contents($json_file);
				$data = json_decode($json_content, true);
				self::$bc_countries_data = $data['data'] ?? [];
			} else {
				self::$bc_countries_data = [];
			}
		}
		return self::$bc_countries_data;
	}

	/**
	 * Get BigCommerce country info by country code
	 */
	public static function get_bc_country_info($country_code) {
		$countries = self::load_bc_countries_data();

		foreach ($countries as $country) {
			if ($country['code'] === strtoupper($country_code)) {
				return $country;
			}
		}

		return null;
	}

	/**
	 * Check if country requires states according to BigCommerce
	 */
	public static function bc_requires_state($country_code) {
		$country = self::get_bc_country_info($country_code);
		return $country ? $country['requiresState'] : false;
	}

	/**
	 * Check if country has postal codes according to BigCommerce
	 */
	public static function bc_has_postal_codes($country_code) {
		$country = self::get_bc_country_info($country_code);
		return $country ? $country['hasPostalCodes'] : true;
	}

	/**
	 * Get valid state name for BigCommerce using their subdivision data
	 */
	public static function get_bc_valid_state_name($state_input, $country_code) {
		$country = self::get_bc_country_info($country_code);

		if (!$country || empty($country['subdivisions'])) {
			// No subdivisions defined, use input as-is for non-requiring countries
			return self::bc_requires_state($country_code) ? null : $state_input;
		}

		// Try exact code match first
		foreach ($country['subdivisions'] as $subdivision) {
			if (strcasecmp($subdivision['code'], $state_input) === 0) {
				return $subdivision['name'];
			}
		}

		// Try name match
		foreach ($country['subdivisions'] as $subdivision) {
			if (strcasecmp($subdivision['name'], $state_input) === 0) {
				return $subdivision['name'];
			}
		}

		// If country requires state but we can't find it, return null
		if (self::bc_requires_state($country_code)) {
			return null;
		}

		// For countries that don't require states, return the input
		return $state_input;
	}

	/**
	 * Build validated address for BigCommerce
	 */
	public static function build_bc_validated_address($first_name, $last_name, $company, $address1, $address2, $city, $state, $postal_code, $country, $phone = '') {
		$country_code = self::get_country_code($country);

		if (!$country_code) {
			error_log("Invalid country for address: {$country}");
			return null;
		}

		// Get valid state name using BigCommerce data
		$valid_state = self::get_bc_valid_state_name($state, $country_code);

		// If country requires state but we don't have a valid one, skip this address
		if (self::bc_requires_state($country_code) && !$valid_state) {
			error_log("Skipping address - country {$country_code} requires state but '{$state}' is invalid");
			return null;
		}

		$address = array(
			'first_name' => $first_name,
			'last_name' => $last_name,
			'company' => $company,
			'address1' => $address1,
			'address2' => $address2,
			'city' => $city,
			'country_code' => $country_code,
			'address_type' => 'residential'
		);

		// Add state only if valid
		$address['state_or_province'] = $valid_state ?: '';

		// Some countries may expect this field to exist even when not used
		if (self::bc_has_postal_codes($country_code) && !empty($postal_code)) {
			$address['postal_code'] = $postal_code;
		} else {
			$address['postal_code'] = '';
		}

		// Add phone if provided
		if (!empty($phone)) {
			$address['phone'] = $phone;
		}else{
			$address['phone'] = '';
		}

		return $address;
	}


	/**
	 * Convert country name/code to ISO country code using WooCommerce data
	 */
	public static function get_country_code($country) {
		// If it's already a 2-letter code, return as-is
		if (strlen($country) === 2) {
			return strtoupper($country);
		}

		// Get WooCommerce countries
		$wc_countries = new WC_Countries();
		$countries_array = $wc_countries->get_countries();

		// Search for country name in WooCommerce data
		foreach ($countries_array as $code => $name) {
			if (strcasecmp($name, $country) === 0) {
				return $code;
			}
		}

		// Fallback mapping for common variations
		$country_variations = array(
			'United States' => 'US',
			'United States of America' => 'US',
			'USA' => 'US',
			'United Kingdom' => 'GB',
			'UK' => 'GB',
			'Great Britain' => 'GB',
			'South Korea' => 'KR',
			'Korea, Republic of' => 'KR',
			'People\'s Republic of China' => 'CN',
			'UAE' => 'AE',
			'United Arab Emirates' => 'AE',
		);

		return $country_variations[$country] ?? $country;
	}

	/**
	 * Convert state/province codes to full names using WooCommerce data
	 */
	public static function get_full_state_name($state_code, $country_code) {
		if (empty($state_code) || empty($country_code)) {
			return $state_code;
		}

		$country_code = strtoupper($country_code);

		// Get WooCommerce countries instance
		$wc_countries = new WC_Countries();

		// Get states for the specific country
		$states = $wc_countries->get_states($country_code);

		if (!empty($states) && is_array($states)) {
			$state_code_upper = strtoupper($state_code);

			// Try exact match first
			if (isset($states[$state_code_upper])) {
				return $states[$state_code_upper];
			}

			// Try lowercase match
			$state_code_lower = strtolower($state_code);
			if (isset($states[$state_code_lower])) {
				return $states[$state_code_lower];
			}

			// Try original case
			if (isset($states[$state_code])) {
				return $states[$state_code];
			}

			// If it's already a full name, check if it exists in values
			foreach ($states as $code => $name) {
				if (strcasecmp($name, $state_code) === 0) {
					return $name; // Already a full name
				}
			}
		}

		// Return original if no mapping found
		return $state_code;
	}

	/**
	 * Get all countries supported by WooCommerce
	 */
	public static function get_all_countries() {
		$wc_countries = new WC_Countries();
		return $wc_countries->get_countries();
	}

	/**
	 * Get all states for a specific country
	 */
	public static function get_states_for_country($country_code) {
		$wc_countries = new WC_Countries();
		return $wc_countries->get_states($country_code);
	}

	/**
	 * Check if a country has state/province data
	 */
	public static function country_has_states($country_code) {
		$states = self::get_states_for_country($country_code);
		return !empty($states);
	}

	/**
	 * Validate if country-state combination is valid
	 */
	public static function is_valid_state_for_country($state_code, $country_code) {
		$states = self::get_states_for_country($country_code);

		if (empty($states)) {
			return true; // Country doesn't require states
		}

		// Check if state code exists
		return isset($states[strtoupper($state_code)]) ||
		       isset($states[strtolower($state_code)]) ||
		       isset($states[$state_code]);
	}

	/**
	 * Get countries that require states/provinces
	 */
	public static function get_countries_with_states() {
		$wc_countries = new WC_Countries();
		$all_countries = $wc_countries->get_countries();
		$countries_with_states = array();

		foreach ($all_countries as $code => $name) {
			$states = $wc_countries->get_states($code);
			if (!empty($states)) {
				$countries_with_states[$code] = $name;
			}
		}

		return $countries_with_states;
	}

	/**
	 * Get customer distribution data (from your database)
	 */
	public static function get_customer_distribution() {
		return array(
			'US' => 1774, 'CA' => 74, 'GB' => 48, 'AU' => 43, 'TW' => 22,
			'MX' => 17, 'HK' => 14, 'DE' => 13, 'CN' => 13, 'TR' => 12,
			'RO' => 11, 'FR' => 11, 'PL' => 9, 'CL' => 9, 'BR' => 7,
			'IQ' => 7, 'LT' => 6, 'DK' => 6, 'NL' => 6, 'SG' => 6
		);
	}

	/**
	 * Debug method to check state mapping
	 */
	public static function debug_state_mapping($state_code, $country_code) {
		$states = self::get_states_for_country($country_code);

		return array(
			'country_code' => $country_code,
			'state_code' => $state_code,
			'available_states' => $states,
			'mapped_state' => self::get_full_state_name($state_code, $country_code),
			'is_valid' => self::is_valid_state_for_country($state_code, $country_code)
		);
	}

	/**
	 * Gets the BigCommerce-compatible full country name from a WC country code.
	 * This acts as a translation layer for names that differ between platforms.
	 */
	public static function get_bc_country_name($country_code) {
		if (empty($country_code)) {
			return '';
		}

		// Use WooCommerce's country data as the primary source
		$countries = WC()->countries->get_countries();
		$wc_country_name = $countries[strtoupper($country_code)] ?? '';

		if (empty($wc_country_name)) {
			return '';
		}

		// Only remove WooCommerce country code suffixes like "(US)", "(UK)", not descriptive text
		$clean_name = preg_replace('/\s*\(' . strtoupper($country_code) . '\)$/', '', $wc_country_name);

		// Mapping for names that are known to differ between WooCommerce and BigCommerce
		$bc_overrides = array(
			'Côte d\'Ivoire' => 'Ivory Coast',
			'Korea, Republic of' => 'South Korea',
			'Russian Federation' => 'Russia',
			'Syrian Arab Republic' => 'Syria',
			'Iran, Islamic Republic of' => 'Iran',
			'Venezuela, Bolivarian Republic of' => 'Venezuela',
			'Virgin Islands (British)' => 'Virgin Islands, British',
			'Virgin Islands (US)' => 'Virgin Islands, U.S.',
			'Virgin Islands' => 'Virgin Islands, U.S.'
		);

		return $bc_overrides[$clean_name] ?? $clean_name;
	}

	// Add this method to WC_BC_Location_Mapper class
	public static function get_wc_locale_data($country_code) {
		$locale = WC()->countries->get_country_locale();
		$country_code = strtoupper($country_code);

		return isset($locale[$country_code]) ? $locale[$country_code] : array();
	}

// Enhanced method using WooCommerce locale data
	public static function is_postal_code_required($country_code) {
		$locale_data = self::get_wc_locale_data($country_code);

		// Check WooCommerce locale data for postcode requirements
		if (isset($locale_data['postcode']['required'])) {
			return $locale_data['postcode']['required'] !== false;
		}

		// Check if postcode is hidden (means not required)
		if (isset($locale_data['postcode']['hidden'])) {
			return !$locale_data['postcode']['hidden'];
		}

		// Default to required for most countries
		return true;
	}

	public static function get_address_format($country_code) {
		$locale_data = self::get_wc_locale_data($country_code);
		$countries = WC()->countries;

		return array(
			'postcode_required' => self::is_postal_code_required($country_code),
			'state_required' => !empty($countries->get_states($country_code)),
			'has_states' => !empty($countries->get_states($country_code)),
			'locale_data' => $locale_data
		);
	}

}