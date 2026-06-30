<?php
/**
 * WooCommerce Checkout Block Support.
 *
 * Adds company billing fields to the WooCommerce Checkout Block
 * using the Additional Checkout Fields API (WooCommerce 8.9+).
 *
 * @package SuperFaktúra WooCommerce
 */

/**
 * WC_SF_Checkout_Block class.
 */
class WC_SF_Checkout_Block {

	/**
	 * Instance of WC_SuperFaktura.
	 *
	 * @var WC_SuperFaktura
	 */
	private $wc_sf;

	/**
	 * Field ID prefix.
	 *
	 * @var string
	 */
	const FIELD_PREFIX = 'superfaktura/';

	/**
	 * Constructor.
	 *
	 * @param WC_SuperFaktura $wc_sf Instance of WC_SuperFaktura.
	 */
	public function __construct( $wc_sf ) {
		$this->wc_sf = $wc_sf;

		// Register checkout fields early - woocommerce_blocks_loaded fires before init.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'on_blocks_loaded' ) );
	}

	/**
	 * Initialize the checkout block integration.
	 */
	public function init() {
		// Only initialize if the feature is enabled and wc-nastavenia-skcz plugin is not active.
		if ( 'yes' !== get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) ) {
			return;
		}

		if ( $this->wc_sf->wc_nastavenia_skcz_activated ) {
			return;
		}

		// Sync block checkout data to existing order meta keys.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'sync_order_meta' ), 10, 2 );

		// Sync company data to the Store API customer/session during checkout updates.
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'sync_customer_meta_from_request' ), 10, 2 );

		// Re-apply the reverse-charge exemption from the accumulated customer session state on every
		// Store API recalculation (checkout and cart endpoints, which send company fields as partial deltas).
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_vat_exemption_before_cart_totals' ), 1, 1 );

		// Belt-and-suspenders: strip shipping-rate taxes for VAT-exempt customers. Invalidating the cached
		// shipping rates above forces a fresh calculation, so this filter runs again and removes the tax even
		// for third-party shipping methods (e.g. Packeta) that put precomputed taxes on the rate without
		// honouring WC_Customer::is_vat_exempt() during WC_Shipping_Method::add_rate().
		add_filter( 'woocommerce_package_rates', array( $this, 'remove_shipping_taxes_for_vat_exempt_customer' ), PHP_INT_MAX, 2 );

		// Persist synced company data to newly created accounts during block checkout.
		add_action( 'woocommerce_created_customer', array( $this, 'sync_created_customer_meta' ), 10, 1 );

		// Prefill block checkout company fields from the existing customer meta keys used by the classic checkout.
		add_filter( 'woocommerce_get_default_value_for_superfaktura/wi-as-company', array( $this, 'get_default_company_field_value' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_superfaktura/billing-company', array( $this, 'get_default_company_field_value' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_superfaktura/billing-company-wi-id', array( $this, 'get_default_company_field_value' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_superfaktura/billing-company-wi-tax', array( $this, 'get_default_company_field_value' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_superfaktura/billing-company-wi-vat', array( $this, 'get_default_company_field_value' ), 10, 3 );

		// Validate fields - use contact location validation hook.
		add_action( 'woocommerce_blocks_validate_location_contact_fields', array( $this, 'validate_fields' ), 10, 2 );

		// Enqueue scripts for block checkout.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts for block checkout.
	 */
	public function enqueue_scripts() {
		// Only enqueue on checkout page with block checkout.
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// Check if checkout uses blocks.
		if ( ! has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-sf-checkout-blocks',
			plugins_url( 'assets/js/checkout-blocks.js', dirname( __FILE__ ) ),
			array( 'wp-data' ),
			filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/checkout-blocks.js' ),
			true
		);
	}

	/**
	 * Check if the Additional Checkout Fields API is available.
	 *
	 * @return bool
	 */
	private function is_checkout_fields_api_available() {
		return function_exists( 'woocommerce_register_additional_checkout_field' );
	}

	/**
	 * Called when WooCommerce Blocks is loaded.
	 */
	public function on_blocks_loaded() {
		// Check if the feature is enabled.
		if ( 'yes' !== get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) ) {
			return;
		}

		// Check if wc-nastavenia-skcz plugin is active.
		if ( class_exists( 'Webikon\Woocommerce_Plugin\WC_Nastavenia_SKCZ\Plugin', false ) ) {
			return;
		}

		// Register the fields immediately when blocks are loaded.
		$this->register_checkout_fields();

		// Hide default company field from address sections.
		add_filter( 'woocommerce_get_country_locale_default', array( $this, 'hide_default_company_field' ) );
		add_filter( 'woocommerce_get_country_locale', array( $this, 'hide_default_company_field_locale' ) );
	}

	/**
	 * Register additional checkout fields for the block checkout.
	 *
	 * Fields are only registered on the frontend checkout page.
	 * We don't need them in admin because we sync values to our own meta keys.
	 */
	public function register_checkout_fields() {
		// Only register on frontend, not in admin.
		// This prevents WooCommerce from displaying these fields in admin order page.
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->is_checkout_fields_api_available() ) {
			// Log that the function is not available for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SuperFaktura: woocommerce_register_additional_checkout_field function not available' );
			}
			return;
		}

		// Translators: Use the same labels as in the classic checkout.
		$label_buy_as_company = __( 'Buy as Business client', 'woocommerce-superfaktura' );
		$label_company_name   = __( 'Company name', 'woocommerce-superfaktura' );
		$label_id             = __( 'ID #', 'woocommerce-superfaktura' );
		$label_vat            = __( 'VAT #', 'woocommerce-superfaktura' );
		$label_tax            = __( 'TAX ID #', 'woocommerce-superfaktura' );

		// Get required settings.
		$company_name_required = 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_name', 'optional' );
		$id_required           = 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_id', 'optional' );
		$vat_required          = 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_vat', 'optional' );
		$tax_required          = 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_tax', 'optional' );

		// Register "Buy as Business client" checkbox.
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::FIELD_PREFIX . 'wi-as-company',
				'label'         => $label_buy_as_company,
				'optionalLabel' => $label_buy_as_company,
				'location'      => 'contact',
				'type'          => 'checkbox',
			)
		);

		// Register "Company name" field.
		// Note: We use optionalLabel to control whether "(optional)" is shown.
		// Actual validation is done in validate_fields() based on checkbox state.
		$company_name_args = array(
			'id'       => self::FIELD_PREFIX . 'billing-company',
			'label'    => $label_company_name,
			'location' => 'contact',
			'type'     => 'text',
			'required' => false,
		);
		if ( $company_name_required ) {
			$company_name_args['optionalLabel'] = $label_company_name;
		}
		woocommerce_register_additional_checkout_field( $company_name_args );

		// Register "ID #" field (IČO).
		if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_id', 'optional' ) ) {
			$id_args = array(
				'id'       => self::FIELD_PREFIX . 'billing-company-wi-id',
				'label'    => $label_id,
				'location' => 'contact',
				'type'     => 'text',
				'required' => false,
			);
			if ( $id_required ) {
				$id_args['optionalLabel'] = $label_id;
			}
			woocommerce_register_additional_checkout_field( $id_args );
		}

		// Register "TAX ID #" field (DIČ).
		if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_tax', false ) ) {
			$tax_args = array(
				'id'       => self::FIELD_PREFIX . 'billing-company-wi-tax',
				'label'    => $label_tax,
				'location' => 'contact',
				'type'     => 'text',
				'required' => false,
			);
			if ( $tax_required ) {
				$tax_args['optionalLabel'] = $label_tax;
			}
			woocommerce_register_additional_checkout_field( $tax_args );
		}

		// Register "VAT #" field (IČ DPH).
		if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_vat', false ) ) {
			$vat_args = array(
				'id'       => self::FIELD_PREFIX . 'billing-company-wi-vat',
				'label'    => $label_vat,
				'location' => 'contact',
				'type'     => 'text',
				'required' => false,
			);
			if ( $vat_required ) {
				$vat_args['optionalLabel'] = $label_vat;
			}
			woocommerce_register_additional_checkout_field( $vat_args );
		}
	}

	/**
	 * Hide default company field from address locale defaults.
	 *
	 * @param array $locale Default locale fields.
	 * @return array Modified locale fields.
	 */
	public function hide_default_company_field( $locale ) {
		$locale['company'] = array(
			'hidden'   => true,
			'required' => false,
		);
		return $locale;
	}

	/**
	 * Hide default company field from all country locales.
	 *
	 * @param array $locales Country locale fields.
	 * @return array Modified locale fields.
	 */
	public function hide_default_company_field_locale( $locales ) {
		foreach ( $locales as $country => $locale ) {
			$locales[ $country ]['company'] = array(
				'hidden'   => true,
				'required' => false,
			);
		}
		return $locales;
	}

	/**
	 * Validate checkout fields.
	 *
	 * @param \WP_Error $errors WP_Error object.
	 * @param array     $fields Fields data.
	 */
	public function validate_fields( $errors, $fields ) {
		// Only validate during actual checkout submission, not real-time field changes.
		// Check if this is a POST request to the checkout endpoint.
		if ( ! $this->is_checkout_submission() ) {
			return;
		}

		// Check if buying as business client.
		$as_company_key = self::FIELD_PREFIX . 'wi-as-company';
		$is_company     = isset( $fields[ $as_company_key ] ) && $fields[ $as_company_key ];

		if ( ! $is_company ) {
			return;
		}

		// Validate Company name if required.
		if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_name', 'optional' ) ) {
			$company_key = self::FIELD_PREFIX . 'billing-company';
			if ( empty( $fields[ $company_key ] ) ) {
				$errors->add(
					'billing_company_required',
					sprintf(
						/* translators: %s: Field name */
						__( '%s is a required field.', 'woocommerce' ),
						'<strong>' . esc_html__( 'Company name', 'woocommerce-superfaktura' ) . '</strong>'
					)
				);
			}
		}

		// Validate ID # if required.
		if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_id', 'optional' ) ) {
			$id_key = self::FIELD_PREFIX . 'billing-company-wi-id';
			if ( empty( $fields[ $id_key ] ) ) {
				$errors->add(
					'billing_company_wi_id_required',
					sprintf(
						/* translators: %s: Field name */
						__( '%s is a required field.', 'woocommerce' ),
						'<strong>' . esc_html__( 'ID #', 'woocommerce-superfaktura' ) . '</strong>'
					)
				);
			}
		}

		// Validate VAT # if required.
		if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_vat', 'optional' ) ) {
			$vat_key = self::FIELD_PREFIX . 'billing-company-wi-vat';
			if ( empty( $fields[ $vat_key ] ) ) {
				$errors->add(
					'billing_company_wi_vat_required',
					sprintf(
						/* translators: %s: Field name */
						__( '%s is a required field.', 'woocommerce' ),
						'<strong>' . esc_html__( 'VAT #', 'woocommerce-superfaktura' ) . '</strong>'
					)
				);
			}
		}

		// Validate EU VAT number if enabled.
		if ( 'yes' === get_option( 'woocommerce_sf_validate_eu_vat_number', 'no' ) ) {
			$vat_key   = self::FIELD_PREFIX . 'billing-company-wi-vat';
			$vat_value = $fields[ $vat_key ] ?? '';
			if ( ! empty( $vat_value ) ) {
				$valid_eu_vat_number = $this->wc_sf->is_eu_vat_number_valid_cached( $vat_value );
				if ( false === $valid_eu_vat_number ) {
					$errors->add(
						'billing_company_wi_vat_invalid',
						sprintf(
							/* translators: %s: Field name */
							__( '%s is not valid.', 'woocommerce-superfaktura' ),
							'<strong>' . esc_html__( 'VAT #', 'woocommerce-superfaktura' ) . '</strong>'
						)
					);
				}
				// When the VAT number could not be validated (null) because the EU VIES service is
				// unreachable, the behavior depends on the merchant setting. Default is "allow"
				// (fail open); "block" stops checkout until validation succeeds.
				elseif ( null === $valid_eu_vat_number && 'block' === get_option( 'woocommerce_sf_validate_eu_vat_number_behavior', 'allow' ) ) {
					$errors->add(
						'billing_company_wi_vat_validation_failed',
						sprintf(
							/* translators: %s: Field name */
							__( '%s could not be validated.', 'woocommerce-superfaktura' ),
							'<strong>' . esc_html__( 'VAT #', 'woocommerce-superfaktura' ) . '</strong>'
						)
					);
				}
			}
		}

		// Validate TAX ID # if required.
		if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_tax', 'optional' ) ) {
			$tax_key = self::FIELD_PREFIX . 'billing-company-wi-tax';
			if ( empty( $fields[ $tax_key ] ) ) {
				$errors->add(
					'billing_company_wi_tax_required',
					sprintf(
						/* translators: %s: Field name */
						__( '%s is a required field.', 'woocommerce' ),
						'<strong>' . esc_html__( 'TAX ID #', 'woocommerce-superfaktura' ) . '</strong>'
					)
				);
			}
		}
	}

	/**
	 * Check if this is an actual checkout submission (not real-time validation).
	 *
	 * @return bool True if this is a checkout submission.
	 */
	private function is_checkout_submission() {
		// Check if payment_method is in the request - this indicates actual checkout.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_input = file_get_contents( 'php://input' );
		if ( ! empty( $raw_input ) ) {
			$data = json_decode( $raw_input, true );
			// Actual checkout submission includes payment_method.
			if ( isset( $data['payment_method'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read and cache the raw JSON request body.
	 *
	 * @return string
	 */
	private function get_raw_request_body() {
		static $raw_input = null;

		if ( null === $raw_input ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw_input = file_get_contents( 'php://input' );
		}

		return is_string( $raw_input ) ? $raw_input : '';
	}

	/**
	 * Extract normalized SuperFaktura company data from a Store API checkout request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array|null
	 */
	private function get_company_data_from_request( $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return null;
		}

		$additional_fields = $request->get_param( 'additional_fields' );
		if ( null === $additional_fields ) {
			$raw_input = $this->get_raw_request_body();
			$data      = '' !== $raw_input ? json_decode( $raw_input, true ) : null;
			if ( is_array( $data ) && isset( $data['additional_fields'] ) ) {
				$additional_fields = $data['additional_fields'];
				$request->set_param( 'additional_fields', $additional_fields );
			}
			if ( is_array( $data ) && isset( $data['billing_address'] ) ) {
				$request->set_param( 'billing_address', $data['billing_address'] );
			}
		}

		$checkbox_field_id = self::FIELD_PREFIX . 'wi-as-company';
		if ( ! is_array( $additional_fields ) || ! array_key_exists( $checkbox_field_id, $additional_fields ) ) {
			return null;
		}

		$billing_country = '';
		$billing_address = $request->get_param( 'billing_address' );
		if ( is_array( $billing_address ) && ! empty( $billing_address['country'] ) ) {
			$billing_country = sanitize_text_field( $billing_address['country'] );
		} elseif ( function_exists( 'WC' ) && WC()->customer ) {
			$billing_country = WC()->customer->get_billing_country();
		}

		return array(
			'is_company'      => $this->is_checked_value( $additional_fields[ $checkbox_field_id ] ),
			'id'              => sanitize_text_field( $this->get_field_value_from_request( $request, 'billing-company-wi-id' ) ),
			'tax'             => sanitize_text_field( $this->get_field_value_from_request( $request, 'billing-company-wi-tax' ) ),
			'vat'             => sanitize_text_field( $this->get_field_value_from_request( $request, 'billing-company-wi-vat' ) ),
			'billing_country' => $billing_country,
		);
	}

	/**
	 * Read normalized SuperFaktura company data from the persisted Store API customer session.
	 *
	 * The block checkout sends partial field deltas across several requests and two endpoints
	 * (`/wc/store/v1/checkout` and `/wc/store/v1/cart/update-customer`), so no single request
	 * carries the full company picture. WooCommerce persists each additional field onto the
	 * customer session (group "other" => `_wc_other/<field id>`) before totals are calculated,
	 * which is the authoritative accumulated state to drive the reverse-charge exemption from.
	 *
	 * @param \WC_Customer $customer Customer object.
	 * @return array|null
	 */
	private function get_company_data_from_customer( $customer ) {
		if ( ! $customer instanceof WC_Customer ) {
			return null;
		}

		// WooCommerce stores contact-location additional fields under the `_wc_other/` meta prefix.
		$prefix = '_wc_other/' . self::FIELD_PREFIX;

		$is_company = '1' === (string) $customer->get_meta( $prefix . 'wi-as-company', true );

		$billing_country = $customer->get_billing_country();
		if ( '' === $billing_country ) {
			$billing_country = $customer->get_shipping_country();
		}

		return array(
			'is_company'      => $is_company,
			'id'              => sanitize_text_field( (string) $customer->get_meta( $prefix . 'billing-company-wi-id', true ) ),
			'tax'             => sanitize_text_field( (string) $customer->get_meta( $prefix . 'billing-company-wi-tax', true ) ),
			'vat'             => sanitize_text_field( (string) $customer->get_meta( $prefix . 'billing-company-wi-vat', true ) ),
			'billing_country' => $billing_country,
		);
	}

	/**
	 * Apply VAT exemption and invalidate cached shipping rates when the exemption state changes.
	 *
	 * @param \WC_Customer $customer     Customer object.
	 * @param array        $company_data Normalized Store API company data.
	 */
	private function apply_vat_exemption_from_company_data( $customer, $company_data ) {
		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		$was_exempt = (bool) $customer->get_is_vat_exempt();
		$this->wc_sf->apply_vat_exemption( $customer, $company_data['is_company'], $company_data['vat'], $company_data['billing_country'] );

		if ( $was_exempt !== (bool) $customer->get_is_vat_exempt() ) {
			$this->clear_cached_shipping_rates();
		}
	}

	/**
	 * Clear cached shipping rates for the current session.
	 *
	 * WooCommerce's shipping package hash does not include WC_Customer::is_vat_exempt(), so toggling
	 * reverse charge can otherwise reuse rates whose tax arrays were calculated for the previous state.
	 */
	private function clear_cached_shipping_rates() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( WC()->cart ) {
			foreach ( array_keys( WC()->cart->get_shipping_packages() ) as $package_key ) {
				WC()->session->__unset( 'shipping_for_package_' . $package_key );
			}
		}

		if ( method_exists( WC()->session, 'get_session_data' ) ) {
			foreach ( array_keys( WC()->session->get_session_data() ) as $key ) {
				if ( 0 === strpos( $key, 'shipping_for_package_' ) ) {
					WC()->session->__unset( $key );
				}
			}
		}
	}

	/**
	 * Sync block checkout data to existing order meta keys.
	 *
	 * The Additional Checkout Fields API saves data with its own meta keys.
	 * This method copies the data to the existing meta keys used by the plugin.
	 *
	 * @param \WC_Order        $order   Order object.
	 * @param \WP_REST_Request $request Request object.
	 */
	public function sync_order_meta( $order, $request ) {
		$additional_fields = $request->get_param( 'additional_fields' );
		$checkbox_field_id = self::FIELD_PREFIX . 'wi-as-company';

		if ( ! is_array( $additional_fields ) || ! array_key_exists( $checkbox_field_id, $additional_fields ) ) {
			return;
		}

		$is_company = $this->is_checked_value( $additional_fields[ $checkbox_field_id ] );

		if ( $is_company ) {
			$company_value = $this->get_field_value_from_request( $request, 'billing-company' );
			if ( '' === $company_value ) {
				$company_value = $this->get_field_value( $order, 'billing-company' );
			}
			if ( ! empty( $company_value ) ) {
				$company_value = sanitize_text_field( $company_value );
				$order->set_billing_company( $company_value );
				// Also set shipping company to match classic checkout behavior.
				$order->set_shipping_company( $company_value );
			}

			// Copy values to the meta keys expected by the rest of the plugin.
			// Save both with and without underscore prefix to match classic checkout behavior.
			$id_value = $this->get_field_value_from_request( $request, 'billing-company-wi-id' );
			if ( '' === $id_value ) {
				$id_value = $this->get_field_value( $order, 'billing-company-wi-id' );
			}
			if ( ! empty( $id_value ) ) {
				$id_value = sanitize_text_field( $id_value );
				$order->update_meta_data( 'billing_company_wi_id', $id_value );
				$order->update_meta_data( '_billing_company_wi_id', $id_value );
			}

			$tax_value = $this->get_field_value_from_request( $request, 'billing-company-wi-tax' );
			if ( '' === $tax_value ) {
				$tax_value = $this->get_field_value( $order, 'billing-company-wi-tax' );
			}
			if ( ! empty( $tax_value ) ) {
				$tax_value = sanitize_text_field( $tax_value );
				$order->update_meta_data( 'billing_company_wi_tax', $tax_value );
				$order->update_meta_data( '_billing_company_wi_tax', $tax_value );
			}

			$vat_value = $this->get_field_value_from_request( $request, 'billing-company-wi-vat' );
			if ( '' === $vat_value ) {
				$vat_value = $this->get_field_value( $order, 'billing-company-wi-vat' );
			}
			if ( ! empty( $vat_value ) ) {
				$vat_value = sanitize_text_field( $vat_value );
				$order->update_meta_data( 'billing_company_wi_vat', $vat_value );
				$order->update_meta_data( '_billing_company_wi_vat', $vat_value );
			}
		} else {
			// Clear company data only when the checkbox is explicitly unchecked.
			$order->set_billing_company( '' );
			$order->set_shipping_company( '' );
			foreach ( array( 'billing_company_wi_id', '_billing_company_wi_id', 'billing_company_wi_tax', '_billing_company_wi_tax', 'billing_company_wi_vat', '_billing_company_wi_vat' ) as $meta_key ) {
				$order->delete_meta_data( $meta_key );
			}
		}

		// Delete the additional checkout fields meta since we've synced to standard meta.
		// This prevents duplicate display in admin order page.
		// WooCommerce creates meta with three prefixes: _wc_billing/, _wc_shipping/, _wc_other/
		$field_names = array(
			'billing-company',
			'billing-company-wi-id',
			'billing-company-wi-vat',
			'billing-company-wi-tax',
		);

		foreach ( $field_names as $field_name ) {
			$field_id = self::FIELD_PREFIX . $field_name;
			$order->delete_meta_data( '_wc_billing/' . $field_id );
			$order->delete_meta_data( '_wc_shipping/' . $field_id );
			$order->delete_meta_data( '_wc_other/' . $field_id );
		}

		// The "Buy as business" checkbox is a contact-location field, so only its _wc_other/ copy is meaningful.
		// Remove _wc_billing/ and _wc_shipping/ duplicates to not clutter the order meta.
		$checkbox_field_id = self::FIELD_PREFIX . 'wi-as-company';
		$order->delete_meta_data( '_wc_billing/' . $checkbox_field_id );
		$order->delete_meta_data( '_wc_shipping/' . $checkbox_field_id );

		$order->save();
	}

	/**
	 * Sync company data from the block checkout request to the current customer object.
	 *
	 * @param \WC_Customer     $customer Customer object.
	 * @param \WP_REST_Request $request  Request object.
	 */
	public function sync_customer_meta_from_request( $customer, $request ) {
		$company_data = $this->get_company_data_from_request( $request );
		if ( null === $company_data ) {
			return;
		}

		$meta_data  = array(
			'wi_as_company'         => $company_data['is_company'] ? '1' : '0',
			'billing_company_wi_id'  => $company_data['is_company'] ? $company_data['id'] : '',
			'billing_company_wi_tax' => $company_data['is_company'] ? $company_data['tax'] : '',
			'billing_company_wi_vat' => $company_data['is_company'] ? $company_data['vat'] : '',
		);

		foreach ( $meta_data as $meta_key => $meta_value ) {
			$customer->update_meta_data( $meta_key, $meta_value );
		}

		// Apply or clear the intra-EU B2B VAT exemption so the block checkout totals and the resulting order reflect the reverse charge.
		$this->apply_vat_exemption_from_company_data( $customer, $company_data );
	}

	/**
	 * Apply VAT exemption before WooCommerce calculates Store API cart totals.
	 *
	 * The Store API recalculates cart totals and shipping on both the checkout and the
	 * cart/update-customer endpoints, and sends company fields as partial deltas, so the flag must be
	 * (re)applied from the accumulated customer session state on every Store API recalculation rather
	 * than from a single request. Running here makes product and shipping taxes see the reverse-charge
	 * state during the calculation that powers the block checkout response.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_vat_exemption_before_cart_totals( $cart ) {
		if ( ! $cart instanceof WC_Cart || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		// Only act during Store API / REST checkout recalculations to avoid touching unrelated cart calculations.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return;
		}

		$company_data = $this->get_company_data_from_customer( WC()->customer );
		if ( null === $company_data ) {
			return;
		}

		$this->apply_vat_exemption_from_company_data( WC()->customer, $company_data );
	}

	/**
	 * Remove shipping-rate taxes for VAT-exempt customers.
	 *
	 * Runs on the fresh shipping calculation triggered after the cached rates are invalidated on an
	 * exemption state change. Standard methods already produce tax-free rates because is_taxable() is
	 * false for exempt customers, but some third-party methods set precomputed taxes on the rate object
	 * regardless; this strips those so the reverse charge also applies to shipping for any method.
	 *
	 * @param array $rates   Shipping rates.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function remove_shipping_taxes_for_vat_exempt_customer( $rates, $package ) {
		if ( 'yes' !== get_option( 'woocommerce_sf_exempt_vat_on_valid_vat_number', 'no' ) ) {
			return $rates;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->customer || ! WC()->customer->get_is_vat_exempt() ) {
			return $rates;
		}

		$tax_free_rates = array();
		foreach ( $rates as $rate_id => $rate ) {
			if ( $rate instanceof WC_Shipping_Rate ) {
				$rate = clone $rate;
				$rate->set_taxes( array() );
			}
			$tax_free_rates[ $rate_id ] = $rate;
		}

		return $tax_free_rates;
	}

	/**
	 * Sync block checkout company data to a newly created customer account.
	 *
	 * @param int $customer_id New customer ID.
	 */
	public function sync_created_customer_meta( $customer_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		$meta_data = array(
			'wi_as_company'         => WC()->customer->get_meta( 'wi_as_company', true ),
			'billing_company_wi_id'  => WC()->customer->get_meta( 'billing_company_wi_id', true ),
			'billing_company_wi_tax' => WC()->customer->get_meta( 'billing_company_wi_tax', true ),
			'billing_company_wi_vat' => WC()->customer->get_meta( 'billing_company_wi_vat', true ),
		);

		foreach ( $meta_data as $meta_key => $meta_value ) {
			update_user_meta( $customer_id, $meta_key, $meta_value );
		}
	}

	/**
	 * Get a default block checkout field value from the customer meta used by the classic checkout.
	 *
	 * @param mixed        $value    Existing default value.
	 * @param string       $group    Checkout field group.
	 * @param \WC_Customer $customer Customer object.
	 *
	 * @return mixed
	 */
	public function get_default_company_field_value( $value, $group, $customer ) {
		$field_id_to_meta_key = array(
			'woocommerce_get_default_value_for_superfaktura/wi-as-company'         => 'wi_as_company',
			'woocommerce_get_default_value_for_superfaktura/billing-company'       => 'billing_company',
			'woocommerce_get_default_value_for_superfaktura/billing-company-wi-id' => 'billing_company_wi_id',
			'woocommerce_get_default_value_for_superfaktura/billing-company-wi-tax' => 'billing_company_wi_tax',
			'woocommerce_get_default_value_for_superfaktura/billing-company-wi-vat' => 'billing_company_wi_vat',
		);

		$current_filter = current_filter();
		if ( ! isset( $field_id_to_meta_key[ $current_filter ] ) ) {
			return $value;
		}

		if ( ! $customer || ! is_callable( array( $customer, 'get_meta' ) ) ) {
			return $value;
		}

		$meta_key   = $field_id_to_meta_key[ $current_filter ];
		$meta_value = 'billing_company' === $meta_key && is_callable( array( $customer, 'get_billing_company' ) )
			? $customer->get_billing_company()
			: $customer->get_meta( $meta_key, true );

		return '' !== $meta_value ? $meta_value : $value;
	}

	/**
	 * Normalize checkbox values from Blocks to a strict boolean.
	 *
	 * @param mixed $value Raw field value.
	 *
	 * @return bool Whether the checkbox should be treated as checked.
	 */
	private function is_checked_value( $value ) {
		return in_array( $value, array( true, 1, '1', 'true', 'yes' ), true );
	}

	/**
	 * Get field value from the REST API request.
	 *
	 * @param \WP_REST_Request $request    Request object.
	 * @param string           $field_name Field name (without prefix).
	 *
	 * @return mixed Field value or empty string.
	 */
	private function get_field_value_from_request( $request, $field_name ) {
		$field_id = self::FIELD_PREFIX . $field_name;

		// WooCommerce stores additional checkout fields in 'additional_fields' parameter.
		$additional_fields = $request->get_param( 'additional_fields' );
		if ( is_array( $additional_fields ) && array_key_exists( $field_id, $additional_fields ) ) {
			return $additional_fields[ $field_id ];
		}

		return '';
	}

	/**
	 * Get additional checkout field value from order.
	 *
	 * Uses WooCommerce's CheckoutFields API to retrieve field values.
	 *
	 * @param \WC_Order $order      Order object.
	 * @param string    $field_name Field name (without prefix).
	 *
	 * @return mixed Field value or empty string.
	 */
	private function get_field_value( $order, $field_name ) {
		$field_id = self::FIELD_PREFIX . $field_name;

		// Use WooCommerce's CheckoutFields API (WC 8.9+).
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			try {
				$checkout_fields = \Automattic\WooCommerce\Blocks\Package::container()->get(
					\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class
				);
				// Our fields use 'contact' location, which maps to 'contact' group.
				$value = $checkout_fields->get_field_from_object( $field_id, $order, 'contact' );
				if ( '' !== $value && null !== $value ) {
					return $value;
				}
			} catch ( \Exception $e ) {
				// Fall through to direct meta access.
			}
		}

		// Direct meta access - WooCommerce stores contact location fields as _wc_other/{field_id}
		// Note: This is only used during sync_order_meta() to read values before we
		// copy them to our own meta keys and delete the WooCommerce ones.
		return $order->get_meta( '_wc_other/' . $field_id, true );
	}
}
