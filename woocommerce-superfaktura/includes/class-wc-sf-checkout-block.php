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
			array(),
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

		// Log when registration happens for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SuperFaktura: Registering checkout block fields' );
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
				$valid_eu_vat_number = $this->wc_sf->validate_eu_vat_number( $vat_value );
				if ( false === $valid_eu_vat_number ) {
					$errors->add(
						'billing_company_wi_vat_invalid',
						sprintf(
							/* translators: %s: Field name */
							__( '%s is not valid.', 'woocommerce-superfaktura' ),
							'<strong>' . esc_html__( 'VAT #', 'woocommerce-superfaktura' ) . '</strong>'
						)
					);
				} elseif ( null === $valid_eu_vat_number ) {
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
	 * Sync block checkout data to existing order meta keys.
	 *
	 * The Additional Checkout Fields API saves data with its own meta keys.
	 * This method copies the data to the existing meta keys used by the plugin.
	 *
	 * @param \WC_Order        $order   Order object.
	 * @param \WP_REST_Request $request Request object.
	 */
	public function sync_order_meta( $order, $request ) {
		// Try to get values from order meta first, then from request.
		$is_company = $this->get_field_value( $order, 'wi-as-company' );

		// If not found in order meta, try getting from request.
		if ( '' === $is_company ) {
			$is_company = $this->get_field_value_from_request( $request, 'wi-as-company' );
		}

		if ( $is_company ) {
			// Set company name from our custom field.
			$company_value = $this->get_field_value( $order, 'billing-company' );
			if ( empty( $company_value ) ) {
				$company_value = $this->get_field_value_from_request( $request, 'billing-company' );
			}
			if ( ! empty( $company_value ) ) {
				$company_value = sanitize_text_field( $company_value );
				$order->set_billing_company( $company_value );
				// Also set shipping company to match classic checkout behavior.
				$order->set_shipping_company( $company_value );
			}

			// Copy values to the meta keys expected by the rest of the plugin.
			// Save both with and without underscore prefix to match classic checkout behavior.
			$id_value = $this->get_field_value( $order, 'billing-company-wi-id' );
			if ( empty( $id_value ) ) {
				$id_value = $this->get_field_value_from_request( $request, 'billing-company-wi-id' );
			}
			if ( ! empty( $id_value ) ) {
				$id_value = sanitize_text_field( $id_value );
				$order->update_meta_data( 'billing_company_wi_id', $id_value );
				$order->update_meta_data( '_billing_company_wi_id', $id_value );
			}

			$vat_value = $this->get_field_value( $order, 'billing-company-wi-vat' );
			if ( empty( $vat_value ) ) {
				$vat_value = $this->get_field_value_from_request( $request, 'billing-company-wi-vat' );
			}
			if ( ! empty( $vat_value ) ) {
				$vat_value = sanitize_text_field( $vat_value );
				$order->update_meta_data( 'billing_company_wi_vat', $vat_value );
				$order->update_meta_data( '_billing_company_wi_vat', $vat_value );
			}

			$tax_value = $this->get_field_value( $order, 'billing-company-wi-tax' );
			if ( empty( $tax_value ) ) {
				$tax_value = $this->get_field_value_from_request( $request, 'billing-company-wi-tax' );
			}
			if ( ! empty( $tax_value ) ) {
				$tax_value = sanitize_text_field( $tax_value );
				$order->update_meta_data( 'billing_company_wi_tax', $tax_value );
				$order->update_meta_data( '_billing_company_wi_tax', $tax_value );
			}
		} else {
			// Clear company name if not buying as company (matching classic checkout behavior).
			$order->set_billing_company( '' );
			$order->set_shipping_company( '' );
		}

		// Delete the additional checkout fields meta since we've synced to standard meta.
		// This prevents duplicate display in admin order page.
		// WooCommerce creates meta with three prefixes: _wc_billing/, _wc_shipping/, _wc_other/
		$field_names = array(
			'wi-as-company',
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

		$order->save();
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
		if ( is_array( $additional_fields ) && isset( $additional_fields[ $field_id ] ) ) {
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
