<?php
/**
 * SuperFaktúra WooCommerce Invoice Generation.
 *
 * @package SuperFaktúra WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WC_SF_Invoice class.
 */
class WC_SF_Invoice {

    /**
     * Instance of WC_SuperFaktura.
     *
     * @var WC_SuperFaktura
     */
    private $wc_sf;

    /**
     * Constructor.
     *
     * @param WC_SuperFaktura $wc_sf Instance of WC_SuperFaktura.
     */
    public function __construct( $wc_sf ) {
        $this->wc_sf = $wc_sf;
    }

	/**
	 * Check if invoices can be regenerated.
	 *
	 * @param WC_Order $order Order.
	 */
	public function sf_can_regenerate( $order ) {
		$can_regenerate = true;

		if ( 'completed' === $order->get_status() ) {
			$can_regenerate = false;
		}

		if ( 'processing' === $order->get_status() && 'cod' !== $order->get_payment_method() ) {
			$can_regenerate = false;
		}

		return apply_filters( 'sf_can_regenerate', $can_regenerate, $order );
	}



	/**
	 * Generate or update an invoice.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type Invoice type.
	 * @param bool     $force_create Force creation of new invoice.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function generate_invoice( $order, $type, $force_create = false ) {

		// Filter to allow skipping invoice creation.
		$skip_invoice = apply_filters( 'sf_skip_invoice', false, $order );
		if ( $skip_invoice ) {
			return false;
		}

		try {

			$credentials = apply_filters( 'sf_order_api_credentials', array(), $order );
			$api         = $this->wc_sf->sf_api( $credentials );

			if ( $force_create ) {
				$sf_id = null;
				$edit = false;
			} else {
				$sf_id = $order->get_meta( 'wc_sf_internal_' . $type . '_id', true );

				$edit = false;
				if ( ! empty( $sf_id ) ) {

					$old_invoice_exists = true;
					$old_invoice        = $api->invoice( $sf_id );
					if ( empty( $old_invoice ) ) {
						$error = $api->getLastError();
						if ( isset( $error['status'] ) && '404' === $error['status'] ) {
							$old_invoice_exists = false;
						}
					}

					if ( $old_invoice_exists ) {
						if ( ! $this->sf_can_regenerate( $order ) ) {
							return new WP_Error( 'duplicate_document', __( 'Document was not created, because it already exists.', 'woocommerce-superfaktura' ) );
						}

						$this->wc_sf->sf_clean_invoice_items( $sf_id, $api );
						$edit = true;
					}
				}
			}

			if ( 'yes' === get_option( 'woocommerce_sf_prevent_concurrency', 'no' ) ) {
				$lock_file = get_temp_dir() . sprintf( 'lock_%s_%s_%s', $order->get_id(), $type, date( 'YmdHi' ) );
				$fp = fopen( $lock_file, 'x' );
				if ( ! $fp ) {
					throw new Exception( __( 'Request failed because of concurrency check.', 'woocommerce-superfaktura' ) );
				}
			}

			/* CLIENT DATA */

			if ( $this->wc_sf->wc_nastavenia_skcz_activated ) {
				$plugin  = Webikon\Woocommerce_Plugin\WC_Nastavenia_SKCZ\Plugin::get_instance();
				$details = $plugin->get_customer_details( $order->get_id() );
				$ico     = $details->get_company_id();
				$ic_dph  = $details->get_company_vat_id();
				$dic     = $details->get_company_tax_id();
			} else {
				$ico    = $order->get_meta( 'billing_company_wi_id', true );
				$ic_dph = $order->get_meta( 'billing_company_wi_vat', true );
				$dic    = $order->get_meta( 'billing_company_wi_tax', true );
			}

			if ( empty( $ic_dph ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';

				// Compatibility with WooCommerce EU VAT Number plugin.
				if ( is_plugin_active( 'woocommerce-eu-vat-number/woocommerce-eu-vat-number.php' ) && 'true' === $order->get_meta( '_vat_number_is_valid', true ) ) {
					$ic_dph = $order->get_meta( '_vat_number', true );
					if ( empty( $ic_dph ) ) {
						$ic_dph = $order->get_meta( '_billing_vat_number', true );
					}
				}

				// Compatibility with WooCommerce EU VAT Assistant plugin.
				if ( is_plugin_active( 'woocommerce-eu-vat-assistant/woocommerce-eu-vat-assistant.php' ) && 'valid' === $order->get_meta( '_vat_number_validated', true ) ) {
					$ic_dph = $order->get_meta( 'vat_number', true );
				}

				// Compatibility with WooCommerce EU/UK VAT Compliance (Premium).
				if ( is_plugin_active( 'woocommerce-eu-vat-compliance-premium/eu-vat-compliance-premium.php' ) && 'true' === $order->get_meta( 'VAT number validated', true ) ) {
					$ic_dph = $order->get_meta( 'VAT Number', true );
				}

				// Compatibility with EU/UK VAT Manager for WooCommerce.
				if ( is_plugin_active( 'eu-vat-for-woocommerce/eu-vat-for-woocommerce.php' ) || is_plugin_active( 'eu-vat-for-woocommerce-pro/eu-vat-for-woocommerce-pro.php' ) ) {
					$ic_dph = $order->get_meta( '_billing_eu_vat_number', true );
				}

				// Compatibility with WPify Woo Česko a Slovensko
				if ( is_plugin_active( 'wpify-woo/wpify-woo.php' ) ) {
					$wpify_settings = get_option( 'wpify-woo-settings-general', [] );
					if ( $wpify_settings && in_array( 'ic_dic', $wpify_settings['enabled_modules'] ?? [] ) ) {
						$ico    = $order->get_meta( '_billing_ic', true );
						$ic_dph = $order->get_meta( ( $order->get_billing_country() === 'SK' ) ? '_billing_dic_dph' : '_billing_dic', true );
						$dic    = $order->get_meta( '_billing_dic', true );
					}
				}
			}

			$client_data = array(
				'name'               => ( $order->get_billing_company() ) ? $order->get_billing_company() : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'ico'                => $ico,
				'dic'                => $dic,
				'ic_dph'             => $ic_dph,
				'email'              => $order->get_billing_email(),
				'address'            => $order->get_billing_address_1() . ( ( $order->get_billing_address_2() ) ? ' ' . $order->get_billing_address_2() : '' ),
				'country_iso_id'     => $order->get_billing_country(),
				'city'               => $order->get_billing_city(),
				'zip'                => $order->get_billing_postcode(),
				'phone'              => $order->get_billing_phone(),
				'update_addressbook' => ( 'yes' === get_option( 'woocommerce_sf_invoice_update_addressbook', 'no' ) ),
			);

			if ( $order->get_formatted_billing_address() !== $order->get_formatted_shipping_address() ) {
				if ( $order->get_shipping_company() ) {
					if ( 'yes' === get_option( 'woocommerce_sf_invoice_delivery_name' ) ) {
						$shipping_name = sprintf( '%s - %s %s', $order->get_shipping_company(), $order->get_shipping_first_name(), $order->get_shipping_last_name() );
					} else {
						$shipping_name = $order->get_shipping_company();
					}
				} else {
					$shipping_name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
				}

				$client_data['delivery_address']        = $order->get_shipping_address_1() . ( ( $order->get_shipping_address_2() ) ? ' ' . $order->get_shipping_address_2() : '' );
				$client_data['delivery_city']           = $order->get_shipping_city();
				$client_data['delivery_country_iso_id'] = $order->get_shipping_country();
				$client_data['delivery_name']           = $shipping_name;
				$client_data['delivery_zip']            = $order->get_shipping_postcode();
			}

			$shipping_phone = $order->get_shipping_phone();
			if ( $shipping_phone ) {
				$client_data['delivery_phone'] = $shipping_phone;
			}

			$client_data = apply_filters( 'sf_client_data', $client_data, $order );

			$api->setClient( $client_data );

			/* INVOICE DATA */

			$delivery_type = null;
			$shipping_methods = $order->get_shipping_methods();
			if ( !empty( $shipping_methods ) ) {
				$shipping_method  = reset( $shipping_methods );
				if ( class_exists( 'WC_Shipping_Zones' ) ) {
					$delivery_type = get_option( 'woocommerce_sf_shipping_' . $shipping_method['method_id'] . ':' . $shipping_method['instance_id'] );
				} else {
					$delivery_type = get_option( 'woocommerce_sf_shipping_' . $shipping_method['method_id'] );
				}
			}

			$set_invoice_data = array(
				'invoice_currency' => $order->get_currency(),
				'payment_type'     => get_option( 'woocommerce_sf_gateway_' . $order->get_payment_method() ),
				'delivery_type'    => $delivery_type,
				'rounding'         => get_option( 'woocommerce_sf_rounding', ( wc_prices_include_tax() ) ? 'item_ext' : 'document' ),
				'issued_by'        => get_option( 'woocommerce_sf_issued_by' ),
				'issued_by_phone'  => get_option( 'woocommerce_sf_issued_phone' ),
				'issued_by_web'    => get_option( 'woocommerce_sf_issued_web' ),
				'issued_by_email'  => get_option( 'woocommerce_sf_issued_email' ),
				'internal_comment' => $order->get_customer_note(),
				'order_no'         => $order->get_order_number(),
			);

			if ( 'cod' === $set_invoice_data['payment_type'] && 'yes' === get_option( 'woocommerce_sf_cod_add_rounding_item', 'no' ) ) {
				$set_invoice_data['add_rounding_item'] = true;
			}

			/* document relations */
			switch ( $type ) {
				case 'regular':
					$sf_proforma_id = $order->get_meta( 'wc_sf_internal_proforma_id', true );
					if ( $sf_proforma_id ) {
						$set_invoice_data['proforma_id'] = $sf_proforma_id;

						$proforma = $api->invoice( $set_invoice_data['proforma_id'] );
					}
					break;

				case 'cancel':
					$sf_invoice_id = $order->get_meta( 'wc_sf_internal_regular_id', true );
					if ( $sf_invoice_id ) {
						$set_invoice_data['parent_id'] = $sf_invoice_id;
					}
					break;
			}

			/* sequence */
			switch ( $type ) {
				case 'proforma':
					$set_invoice_data['sequence_id'] = get_option( 'woocommerce_sf_proforma_invoice_sequence_id' );
					break;

				case 'regular':
					$set_invoice_data['sequence_id'] = get_option( 'woocommerce_sf_invoice_sequence_id' );
					break;

				case 'cancel':
					$set_invoice_data['sequence_id'] = get_option( 'woocommerce_sf_cancel_sequence_id' );
					break;
			}

			/* logo */
			$set_invoice_data['logo_id'] = get_option( 'woocommerce_sf_logo_id' );

			/* bank account */
			$bank_account_id = get_option( 'woocommerce_sf_bank_account_id', null );
			if ( $bank_account_id ) {
				$set_invoice_data['bank_accounts'] = array(
					array( 'id' => $bank_account_id ),
				);
			}

			/* create date */
			if ( 'yes' === get_option( 'woocommerce_sf_created_date_as_order' ) ) {
				$set_invoice_data['created'] = (string) $order->get_date_created();
			}

			/* variable symbol */
			switch ( get_option( 'woocommerce_sf_variable_symbol' ) ) {

				case 'invoice_nr':
					if ( isset( $old_invoice ) && isset( $old_invoice->Invoice ) ) {
						$set_invoice_data['variable'] = $old_invoice->Invoice->invoice_no_formatted_raw;
					}
					break;

				case 'invoice_nr_match':
					if ( 'proforma' == $type && isset( $old_invoice ) && isset( $old_invoice->Invoice ) ) {
						$set_invoice_data['variable'] = $old_invoice->Invoice->invoice_no_formatted_raw;
					}

					if ( 'regular' === $type && isset( $proforma ) && isset( $proforma->Invoice ) ) {
						$set_invoice_data['variable'] = $proforma->Invoice->variable;
					}
					break;

				case 'order_nr':
					$set_invoice_data['variable'] = $order->get_order_number();
					break;
			}

			/* delivery date */
			switch ( get_option( 'woocommerce_sf_delivery_date_value', 'invoice_created' ) ) {

				case 'invoice_created':
					// Do nothing, SuperFaktura API will use invoice creation date by default.
					break;

				case 'order_paid':
					$delivery_date = $order->get_date_paid();
					if ( $delivery_date ) {
						$set_invoice_data['delivery'] = $delivery_date->date( 'Y-m-d' );
					}
					break;

				case 'order_created':
					$delivery_date = $order->get_date_created();
					if ( $delivery_date ) {
						$set_invoice_data['delivery'] = $delivery_date->date( 'Y-m-d' );
					}
					break;

				case 'none':
					$set_invoice_data['delivery'] = -1;
					break;

			}

			/* comment */
			if ( 'yes' === get_option( 'woocommerce_sf_comments' ) ) {
				$comment_parts = array();

				if ( 'yes' === get_option( 'woocommerce_sf_comment_add_proforma_payment', 'no' ) && isset( $proforma ) && isset( $proforma->Invoice ) && 1 != $proforma->Invoice->status ) {
					$comment_parts[] = sprintf(
						// Translators: %1$s Invoice number, %2$s Payment date.
						__( 'Paid with proforma invoice %1$s on %2$s.', 'woocommerce-superfaktura' ),
						$proforma->Invoice->invoice_no_formatted,
						date( 'j.n.Y', strtotime( $proforma->Invoice->paydate ) )
					);
				}

				if ( ( $client_data['ic_dph'] && WC()->countries->get_base_country() !== $order->get_billing_country() ) || ( in_array( WC()->countries->get_base_country(), array( 'SK', 'CZ' ), true ) && $order->get_billing_country() && ! in_array( $order->get_billing_country(), $this->wc_sf->eu_countries, true ) ) ) {
					$set_invoice_data['vat_transfer'] = 1;

					$sf_tax_liability = get_option( 'woocommerce_sf_tax_liability' );
					if ( $sf_tax_liability ) {
						$comment_parts[] = $sf_tax_liability;
					}
				}
				elseif ( $edit ) {
					$set_invoice_data['vat_transfer'] = 0;
				}

				$sf_comment = get_option( 'woocommerce_sf_comment' );
				if ( $sf_comment ) {
					$comment_parts[] = $sf_comment;
				}

				if ( 'yes' === get_option( 'woocommerce_sf_comment_add_order_note' ) ) {
					$customer_note = $order->get_customer_note();
					if ( $customer_note ) {
						$comment_parts[] = $customer_note;
					}
				}

				$set_invoice_data['comment'] = implode( "\r\n\r\n", $comment_parts );
			}

			// Override invoice settings for specific countries based on customer billing address.
			$country_settings = json_decode( get_option( 'woocommerce_sf_country_settings', false ), true );
			if ( $country_settings ) {
				$country_settings = array_column( $country_settings, null, 'country' );

				$billing_country = $order->get_billing_country();

				$override_settings = $country_settings[ $billing_country ] ?? null;
				if ( empty( $override_settings ) ) {
					$override_settings = $country_settings[ '*' ] ?? null;
				}

				if ( ! empty( $override_settings ) ) {

					$client_country_data = array();

					/* override VAT */
					if ( $override_settings['vat_id'] ) {
						if ( empty( $override_settings['vat_id_only_final_consumer'] ) || ( ! empty( $override_settings['vat_id_only_final_consumer'] ) && empty( $client_data['ic_dph'] ) ) ) {
							$client_country_data['ic_dph'] = $override_settings['vat_id'];
						}
					}

					/* override TAX ID */
					if ( $override_settings['tax_id'] ) {
						if ( empty( $override_settings['vat_id_only_final_consumer'] ) || ( ! empty( $override_settings['vat_id_only_final_consumer'] ) && empty( $client_data['ic_dph'] ) ) ) {
							$client_country_data['dic'] = $override_settings['tax_id'];
						}
					}

					// Webikon, 20201001: Added a filter that allows to modify client data based on country.
					$client_country_data = apply_filters( 'sf_client_country_data', $client_country_data, $order );

					if ( ! empty( $client_country_data ) ) {
						$api->setMyData( $client_country_data );
					}

					/* override bank account */
					if ( $override_settings['bank_account_id'] ) {
						$set_invoice_data['bank_accounts'] = array(
							array(
								'id' => $override_settings['bank_account_id'],
							),
						);
					}

					/* override sequences */
					switch ( $type ) {
						case 'proforma':
							if ( $override_settings['proforma_sequence_id'] ) {
								$set_invoice_data['sequence_id'] = $override_settings['proforma_sequence_id'];
							}
							break;

						case 'regular':
							if ( $override_settings['invoice_sequence_id'] ) {
								$set_invoice_data['sequence_id'] = $override_settings['invoice_sequence_id'];
							}
							break;

						case 'cancel':
							if ( $override_settings['cancel_sequence_id'] ) {
								$set_invoice_data['sequence_id'] = $override_settings['cancel_sequence_id'];
							}
							break;
					}
				}
			}

			// Webikon, 20200521: added extra attribute $type to be able to identify credit note in the hook.
			$set_invoice_data = apply_filters( 'sf_invoice_data', $set_invoice_data, $order, $type );

			$api->setInvoice( $set_invoice_data );

			/* INVOICE SETTINGS */

			$settings = array(
				'language'     => $this->wc_sf->get_language( $order->get_id(), get_option( 'woocommerce_sf_invoice_language' ), true ),
				'signature'    => true,
				'payment_info' => true,
				'bysquare'     => 'yes' === get_option( 'woocommerce_sf_bysquare', 'yes' ),
			);

			if ( 'multi' === get_option( 'woocommerce_sf_sync_type', 'single' ) ) {
				$settings['callback_payment'] = site_url( '/' ) . '?callback=wc_sf_order_paid&secret_key=' . get_option( 'woocommerce_sf_sync_secret_key', false );
			}

			$api->setInvoiceSettings( $settings );

			$extras = array();

			if ( 'yes' === get_option( 'woocommerce_sf_oss', 'no' ) ) {

				// Pri vystavení faktúry s odberateľom z inej krajiny EÚ, ktorý je súkromná osoba (nepodnikateľ) alebo firma bez IČ DPH.
				if ( empty( $client_data['ic_dph'] ) && WC()->countries->get_base_country() !== $order->get_billing_country() && in_array( $order->get_billing_country(), $this->wc_sf->eu_countries, true ) ) {
					$extras['oss'] = true;
				}
			}

			/* packeta */
			$pickup_point_id = $order->get_meta( 'zasilkovna_id_pobocky', true );
			if ( $pickup_point_id ) {
				$extras['pickup_point_id'] = $pickup_point_id;
			}

			$weight = $order->get_meta( 'zasilkovna_custom_weight', true );
			if ( empty( $weight ) ) {
				$weight = $order->get_meta( '_cart_weight', true );
			}
			if ( $weight ) {
				$extras['weight'] = $weight;
			}

			$api->setInvoiceExtras( $extras );

			/* PAYMENT STATUS */

			if ( $this->wc_sf->order_is_paid( $order ) || 'yes' === get_option( 'woocommerce_sf_invoice_' . $type . '_' . $order->get_payment_method() . '_set_as_paid', 'no' ) ) {

				// Check if proforma was already paid.
				$proforma_already_paid = false;
				if ( isset( $set_invoice_data['proforma_id'] ) ) {
					if ( isset( $proforma ) && isset( $proforma->Invoice ) && 1 != $proforma->Invoice->status ) {
						$proforma_already_paid = true;
					}
				}

				if ( ! $proforma_already_paid ) {
					$api->setInvoice(
						array(
							'already_paid'     => true,
							'cash_register_id' => get_option( 'woocommerce_sf_cash_register_' . $order->get_payment_method() ),
						)
					);
				}
			}

			$tax_rates                   = array();
			$possible_discount_tax_rates = array();
			foreach ( $order->get_items( 'tax' ) as $tax_item ) {
				if ( 'WC_Order_Item_Tax' === get_class( $tax_item ) ) {
					$tax_rates[ $tax_item->get_rate_id() ] = $tax_item->get_rate_percent();

					if ( 0 < $tax_item->get_tax_total() ) {
						$possible_discount_tax_rates[ $tax_item->get_rate_id() ] = $tax_item->get_rate_percent();
					}
				}
			}

			$refunds = $order->get_refunds();
			if ( 'cancel' === $type && $refunds ) {

				/* REFUNDS */

				foreach ( $refunds as $refund ) {

					// Get refunded amount for items by quantity.
					$refund_items_price             = 0;
					$refund_items_price_without_tax = 0;

					// Subtract refunded amount for items by quantity, because quantity was already subtracted by get_qty_refunded_for_item() above.
					// :TODO: verify why this is here, probebly not necessary at all
					if ( 'yes' === get_option( 'woocommerce_sf_product_subtract_refunded_qty', 'no' ) ) {
						$refunded_items = $refund->get_items();
						if ( $refunded_items ) {
							foreach ( $refunded_items as $item ) {
								$refund_items_price             += abs( $item['qty'] ) * $refund->get_item_subtotal( $item, true );
								$refund_items_price_without_tax += abs( $item['qty'] ) * $refund->get_item_subtotal( $item, false );
							}
						}
					}

					$refund_price = abs( $refund->get_total() ) - $refund_items_price;

					// Skip refund if whole amount was refunded with items by quantity.
					if ( $refund_price <= 0 ) {
						continue;
					}

					$refund_price_without_tax = abs( $refund->get_total() ) - abs( $refund->get_total_tax() ) - $refund_items_price_without_tax;
					$refund_tax               = round( ( $refund_price - $refund_price_without_tax ) / $refund_price_without_tax * 100 );
					$refund_description       = $refund->get_reason();

					$refund_data = array(
						'name'        => __( 'Refunded', 'woocommerce-superfaktura' ),
						'description' => $refund_description ? $refund_description : '',
						'quantity'    => '',
						'unit'        => '',
						'unit_price'  => $refund_price_without_tax * -1,
						'tax'         => $refund_tax,
					);
					$refund_data = apply_filters( 'sf_refund_data', $refund_data, $order );

					if ( $refund_data ) {
						$api->addItem( $refund_data );
					}
				}

			} else {

				/* ITEMS */

				// Array of WC_Order_Item_Product.
				$items = $order->get_items();

				foreach ( $items as $item_id => $item ) {
					$product = $item->get_product();

					// Skip invalid product.
					if ( empty( $product ) ) {
						continue;
					}

					$item_tax = 0;
					$taxes    = $item->get_taxes();
					foreach ( $taxes['subtotal'] as $rate_id => $tax ) {
						if ( empty( $tax ) ) {
							continue;
						}
						$item_tax = $tax_rates[ $rate_id ];
					}

					$quantity = $item['qty'];

					// Subtract refunded items quantity.
					if ( 'yes' === get_option( 'woocommerce_sf_product_subtract_refunded_qty', 'no' ) ) {
						$quantity -= abs( $order->get_qty_refunded_for_item( $item_id ) );

						// Skip item if whole quantity was refunded.
						if ( $quantity <= 0 ) {
							continue;
						}
					}

					$item_data = array(
						'name'       => wp_strip_all_tags( html_entity_decode( $item['name'] ) ),
						'quantity'   => $quantity,
						'sku'        => $product->get_sku(),
						'unit'       => 'ks',
						'unit_price' => $order->get_item_subtotal( $item, false, false ),
						'tax'        => $item_tax,
					);

					if ( 'cancel' === $type ) {
						$item_data['unit_price'] *= -1;
					}

					if ( 'per_item' === get_option( 'woocommerce_sf_coupon_invoice_items', 'total' ) ) {
						$item_discount = $order->get_item_subtotal( $item, true, false ) - $order->get_item_total( $item, true, false );
						if ( $item_discount ) {
							$item_discount_percent             = $item_discount / $order->get_item_subtotal( $item, true, false ) * 100;
							$item_data['discount']             = $item_discount_percent;
							$item_data['discount_description'] = __( get_option( 'woocommerce_sf_discount_name', 'Zľava' ), 'woocommerce-superfaktura' );

							$discount_description = $this->wc_sf->get_discount_description( $order );
							if ( $discount_description ) {
								$item_data['discount_description'] .= ', ' . $discount_description;
							}
						}
					}

					$product_id = ( isset( $item['variation_id'] ) && $item['variation_id'] > 0 ) ? $item['variation_id'] : $item['product_id'];
					$product    = wc_get_product( $product_id );

					if ($product) {
						$attributes                = $this->wc_sf->format_item_meta( $item, $product );
						$non_variations_attributes = $this->wc_sf->get_non_variations_attributes( $item['product_id'] );
						if ( $product->is_type( 'variation' ) ) {
							$variation = $this->wc_sf->convert_to_plaintext( $product->get_description() );

							$parent_product = wc_get_product( $item['product_id'] );
							$short_descr    = $this->wc_sf->convert_to_plaintext( $parent_product->get_short_description() );
						} else {
							$variation   = '';
							$short_descr = $this->wc_sf->convert_to_plaintext( $product->get_short_description() );
						}

						$template = get_option( 'woocommerce_sf_product_description', $this->wc_sf->product_description_template_default );

						$item_data['description'] = strtr(
							$template,
							array(
								'[ATTRIBUTES]'                => $attributes,
								'[NON_VARIATIONS_ATTRIBUTES]' => $non_variations_attributes,
								'[VARIATION]'                 => $variation,
								'[SHORT_DESCR]'               => $short_descr,
								'[SKU]'                       => $product->get_sku(),
								'[WEIGHT]'                    => $product->get_weight(),
								'[CATEGORY]'                  => $this->wc_sf->get_product_category_names( $item['product_id'] ),
							)
						);
						$item_data['description'] = trim( $this->wc_sf->replace_single_attribute_tags( $item['product_id'], $item_data['description'] ) );

						// Compatibility with WooCommerce Wholesale Pricing plugin.
						$wprice = get_post_meta( $product->get_id(), 'wholesale_price', true );

						if ( ! $wprice && $product->is_on_sale() ) {
							$tax      = 1 + ( ( wc_get_price_excluding_tax( $product ) == 0 ) ? 0 : round( ( ( wc_get_price_including_tax( $product ) - wc_get_price_excluding_tax( $product ) ) / wc_get_price_excluding_tax( $product ) ), 2 ) );
							$discount = floatval( $product->get_regular_price() ) - floatval( $product->get_sale_price() );

							if ( 'yes' === get_option( 'woocommerce_sf_product_description_show_discount', 'yes' ) && $discount ) {
								$item_data['description'] = trim( $item_data['description'] . PHP_EOL . __( get_option( 'woocommerce_sf_discount_name', 'Zľava' ), 'woocommerce-superfaktura' ) . ' -' . $discount . ' ' . html_entity_decode( get_woocommerce_currency_symbol() ) );
							}
						}

						/* accounting */
						$item_type_product = get_option( 'woocommerce_sf_item_type_product' );
						if ( $item_type_product ) {
							$item_data['AccountingDetail']['type'] = $item_type_product;
						}

						$analytics_account_product = get_option( 'woocommerce_sf_analytics_account_product' );
						if ( $analytics_account_product ) {
							$item_data['AccountingDetail']['analytics_account'] = $analytics_account_product;
						}

						$synthetic_account_product = get_option( 'woocommerce_sf_synthetic_account_product' );
						if ( $synthetic_account_product ) {
							$item_data['AccountingDetail']['synthetic_account'] = $synthetic_account_product;
						}

						$preconfidence_product = get_option( 'woocommerce_sf_preconfidence_product' );
						if ( $preconfidence_product ) {
							$item_data['AccountingDetail']['preconfidence'] = $preconfidence_product;
						}

						$item_data = apply_filters( 'sf_item_data', $item_data, $order, $product );

						// skip free products
						if ( empty( $item_data['unit_price'] ) && 'yes' === get_option( 'woocommerce_sf_skip_free_products', 'no' ) ) {
							continue;
						}

						if ( $item_data ) {
							$api->addItem( $item_data );
						}
					}
				}

				// Compatibility with WooCommerce Gift Cards (https://woocommerce.com/products/gift-cards/) plugin.
				if ( is_plugin_active( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) ) {

					$giftcards = $order->get_items( 'gift_card' );
					if ( $giftcards ) {
						foreach ( $giftcards as $giftcard ) {
							$item_data = array(
								'name'        => __( 'Gift Card', 'woocommerce-superfaktura' ),
								'quantity'    => '',
								'unit'        => '',
								'unit_price'  => $giftcard->get_amount() * -1,
								'tax'         => 0,
								'description' => $giftcard->get_code(),
							);

							$api->addItem( $item_data );
						}
					}
				}

				/* FEES */

				if ( $order->get_fees() ) {
					foreach ( $order->get_fees() as $fee ) {
						$fee_total     = $fee->get_total();
						$fee_taxes     = $fee->get_taxes();
						$fee_tax_total = array_sum( $fee_taxes['total'] );

						$item_data = array(
							'name'       => $fee['name'],
							'quantity'   => '',
							'unit'       => '',
							'unit_price' => $fee_total,
							'tax'        => ( 0 == $fee_total ) ? 0 : round( ( $fee_tax_total / $fee_total ) * 100 ),
						);

						if ( 'cancel' === $type ) {
							$item_data['unit_price'] *= -1;
						}

						/* accounting */
						$item_type_fees = get_option( 'woocommerce_sf_item_type_fees' );
						if ( $item_type_fees ) {
							$item_data['AccountingDetail']['type'] = $item_type_fees;
						}

						$analytics_account_fees = get_option( 'woocommerce_sf_analytics_account_fees' );
						if ( $analytics_account_fees ) {
							$item_data['AccountingDetail']['analytics_account'] = $analytics_account_fees;
						}

						$synthetic_account_fees = get_option( 'woocommerce_sf_synthetic_account_fees' );
						if ( $synthetic_account_fees ) {
							$item_data['AccountingDetail']['synthetic_account'] = $synthetic_account_fees;
						}

						$preconfidence_fees = get_option( 'woocommerce_sf_preconfidence_fees' );
						if ( $preconfidence_fees ) {
							$item_data['AccountingDetail']['preconfidence'] = $preconfidence_fees;
						}

						$api->addItem( $item_data );
					}
				}

				/* SHIPPING */

				$shipping_price = $order->get_shipping_total() + $order->get_shipping_tax();

				$shipping_tax = 0;
				foreach ( $order->get_items( 'tax' ) as $tax_item ) {
					$tax_rate = WC_Tax::_get_tax_rate($tax_item->get_rate_id());

					if ( ! empty( $tax_item->get_shipping_tax_total() ) || '1' === $tax_rate['tax_rate_shipping'] ) {
						$shipping_tax = $tax_item->get_rate_percent();
					}
				}

				$shipping_item_name = ( $shipping_price > 0 ) ? __( get_option( 'woocommerce_sf_shipping_item_name', 'Poštovné' ), 'woocommerce-superfaktura' ) : __( get_option( 'woocommerce_sf_free_shipping_name' ), 'woocommerce-superfaktura' );

				if ( $shipping_item_name ) {
					$item_data = array(
						'name'       => $shipping_item_name,
						'quantity'   => '',
						'unit'       => '',
						'unit_price' => $shipping_price / ( 1 + $shipping_tax / 100 ),
						'tax'        => $shipping_tax,
					);

					if ( 'cancel' === $type ) {
						$item_data['unit_price'] *= -1;
					}

					/* accounting */
					$item_type_shipping = get_option( 'woocommerce_sf_item_type_shipping' );
					if ( $item_type_shipping ) {
						$item_data['AccountingDetail']['type'] = $item_type_shipping;
					}

					$analytics_account_shipping = get_option( 'woocommerce_sf_analytics_account_shipping' );
					if ( $analytics_account_shipping ) {
						$item_data['AccountingDetail']['analytics_account'] = $analytics_account_shipping;
					}

					$synthetic_account_shipping = get_option( 'woocommerce_sf_synthetic_account_shipping' );
					if ( $synthetic_account_shipping ) {
						$item_data['AccountingDetail']['synthetic_account'] = $synthetic_account_shipping;
					}

					$preconfidence_shipping = get_option( 'woocommerce_sf_preconfidence_shipping' );
					if ( $preconfidence_shipping ) {
						$item_data['AccountingDetail']['preconfidence'] = $preconfidence_shipping;
					}

					$item_data = apply_filters( 'sf_shipping_data', $item_data, $order );

					if ( $item_data ) {
						$api->addItem( $item_data );
					}
				}

				/* DISCOUNT */

				if ( 'total' === get_option( 'woocommerce_sf_coupon_invoice_items', 'total' ) ) {
					if ( $order->get_total_discount() ) {

						// We use highest tax rate (in case there are several different tax rates in the order).
						$discount_tax = ( $possible_discount_tax_rates ) ? max( $possible_discount_tax_rates ) : 0;

						$discount_description = $this->wc_sf->get_discount_description( $order );

						$discount_data = array(
							'name'        => __( get_option( 'woocommerce_sf_discount_name', 'Zľava' ), 'woocommerce-superfaktura' ),
							'description' => $discount_description ? $discount_description : '',
							'quantity'    => '',
							'unit'        => '',
							'unit_price'  => ( 0 == $discount_tax ) ? $order->get_total_discount() * -1 : $order->get_total_discount( false ) / ( 1 + $discount_tax / 100 ) * -1,
							'tax'         => $discount_tax,
						);

						/* accounting */
						$item_type_discount = get_option( 'woocommerce_sf_item_type_discount' );
						if ( $item_type_discount ) {
							$discount_data['AccountingDetail']['type'] = $item_type_discount;
						}

						$analytics_account_discount = get_option( 'woocommerce_sf_analytics_account_discount' );
						if ( $analytics_account_discount ) {
							$discount_data['AccountingDetail']['analytics_account'] = $analytics_account_discount;
						}

						$synthetic_account_discount = get_option( 'woocommerce_sf_synthetic_account_discount' );
						if ( $synthetic_account_discount ) {
							$discount_data['AccountingDetail']['synthetic_account'] = $synthetic_account_discount;
						}

						$preconfidence_discount = get_option( 'woocommerce_sf_preconfidence_discount' );
						if ( $preconfidence_discount ) {
							$discount_data['AccountingDetail']['preconfidence'] = $preconfidence_discount;
						}

						$discount_data = apply_filters( 'sf_discount_data', $discount_data, $order );

						if ( $discount_data ) {
							$api->addItem( $discount_data );
						}
					}
				}
			}

			/* TAG */

			$tag = get_option( 'woocommerce_sf_invoice_tag' );
			if ( $tag ) {
				$tags   = (array) $api->getTags();
				$tag_id = array_search( strtolower( $tag ), array_map( 'strtolower', $tags ), true );

				if ( ! $tag_id ) {
					$add_tag_result = $api->addTag( array( 'name' => $tag ) );
					if ( $add_tag_result && ! $add_tag_result->error ) {
						$tag_id = $add_tag_result->tag_id;
					}
				}

				if ( $tag_id ) {
					$api->addTags( array( $tag_id ) );
				}
			}

			// 2019/05/31 webikon: added invoice items as an extra argument
			// 2020/05/07 webikon: added document type as an extra argument
			foreach ( apply_filters( 'woocommerce_sf_invoice_extra_items', array(), $order, $api->data['InvoiceItem'], $type ) as $extra_item ) {
				$api->addItem( $extra_item );
			}
		} catch ( Throwable $e ) {

			// Add log entry.
			$this->wc_sf->wc_sf_log(
				array(
					'order_id'         => $order->get_id(),
					'document_type'    => $type,
					'request_type'     => ( $edit ) ? 'edit' : 'create',
					'response_status'  => 990,
					'response_message' => sprintf( '%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ),
				)
			);

			return false;
		}

		if ( $edit ) {
			$api->setInvoice(
				array(
					'type' => apply_filters( 'woocommerce_sf_invoice_type', $type, 'edit' ),
					'id'   => $sf_id,
				)
			);

			$response = $api->edit();
		} else {
			$args = array(
				'type' => apply_filters( 'woocommerce_sf_invoice_type', $type, 'create' ),
			);

			$sequence_id = apply_filters( 'wc_sf_sequence_id', false, $type, $order );

			if ( ! $sequence_id ) {
				$sequence_id = '';
			}

			if ( $sequence_id ) {
				$args['sequence_id'] = $sequence_id;
			} else {
				$invoice_id = apply_filters( 'wc_sf_invoice_id', false, $type, $order );

				if ( ! $invoice_id ) {
					$invoice_id = 'yes' === get_option( 'woocommerce_sf_invoice_custom_num' ) ? $this->wc_sf->generate_invoice_id( $order, $type ) : '';
				}

				$args['invoice_no_formatted'] = $invoice_id;
			}

			$api->setInvoice( $args );

			$response = $api->save();
		}

		if ( 'yes' === get_option( 'woocommerce_sf_prevent_concurrency', 'no' ) ) {
			if ( isset( $_GET['callback'] ) && 'wc_sf_order_paid' === $_GET['callback'] ) {
				// Wait for all duplicate callbacks to be blocked.
				sleep( 1 );
			}

			fclose( $fp );
			unlink( $lock_file );
		}

		// Add log entry.
		$log_data = array(
			'order_id'      => $order->get_id(),
			'document_type' => $type,
			'request_type'  => ( $edit ) ? 'edit' : 'create',
		);

		if ( empty( $response ) ) {
			$error                        = $api->getLastError();
			$log_data['response_status']  = ( isset( $error['status'] ) ) ? $error['status'] : 999;
			$log_data['response_message'] = ( isset( $error['message'] ) ) ? $error['message'] : 'Request failed without further information.';

			// If request to create the document failed because SF API did not respond, we'll try again later
			if ( ! $edit &&                                                              // only for creating a new document
				! isset( $_GET['sf_invoice_' . $type . '_create'] ) &&                   // not for manual document creation
				'yes' === get_option( 'woocommerce_sf_retry_failed_api_calls', 'no' ) && // only if "Retry failed API calls" is enabled in plugin settings
				isset( $error['code'] ) && 'http_request_failed' == $error['code']       // only if the API call failed with "http_request_failed"
			) {
				$this->wc_sf->retry_generate_invoice_schedule( $order, $type );
			}

		} elseif ( isset( $response->error ) && $response->error ) {
			$log_data['response_status']  = $response->error;

			if ( isset( $response->message ) ) {
				$log_data['response_message'] = $response->message;
			} elseif ( isset( $response->error_message ) ) {
				if ( is_object( $response->error_message ) ) {
					$log_data['response_message'] = implode(
						' ',
						array_map(
							function( $a ) {
								return ( is_array( $a ) ) ? implode( ' ', $a ) : $a;
							},
							get_object_vars( $response->error_message )
						)
					);
				}
				else {
					$log_data['response_message'] = $response->error_message;
				}
			}
		}

		$this->wc_sf->wc_sf_log( $log_data );

		if ( empty( $response ) || ( isset( $response->error ) && 0 !== $response->error ) ) {
			return false;
		}

		if ( isset( $response->data->PaymentLink ) && 3 != $response->data->Invoice->status ) {
			// Save payment link if there is one and the invoice is not paid yet.
			$order->update_meta_data( 'wc_sf_payment_link', $response->data->PaymentLink );
		} else {
			// Delete payment link otherwise.
			$order->delete_meta_data( 'wc_sf_payment_link' );
		}

		// Save document ID.
		$internal_id = $response->data->Invoice->id;
		$order->update_meta_data( 'wc_sf_internal_' . $type . '_id', $internal_id );

		// Save formatted invoice number.
		$invoice_number = $response->data->Invoice->invoice_no_formatted;
		$order->update_meta_data( 'wc_sf_' . $type . '_invoice_number', $invoice_number );

		// Save pdf url.
		$language = $this->wc_sf->get_language( $order->get_id(), get_option( 'woocommerce_sf_invoice_language' ), true );
		$pdf      = ( ( 'yes' === get_option( 'woocommerce_sf_sandbox', 'no' ) ) ? $api::SANDBOX_URL : $api::SFAPI_URL ) . '/' . $language . '/invoices/pdf/' . $internal_id . '/token:' . $response->data->Invoice->token;
		$order->update_meta_data( 'wc_sf_invoice_' . $type, $pdf );

		switch ( $type ) {
			case 'proforma':
				$order->add_order_note( __( 'Proforma invoice created.', 'woocommerce-superfaktura' ) );
				break;

			case 'regular':
				$order->add_order_note( __( 'Invoice created.', 'woocommerce-superfaktura' ) );
				break;

			case 'cancel':
				$order->add_order_note( __( 'Credit note created.', 'woocommerce-superfaktura' ) );
				break;

			default:
				$order->add_order_note( __( 'Document created.', 'woocommerce-superfaktura' ) );
				break;
		}

		$order->save();

		return true;

	}

}
