<?php
/**
 * SuperFaktúra WooCommerce.
 *
 * @package   SuperFaktúra WooCommerce
 * @author    2day.sk <superfaktura@2day.sk>
 * @copyright 2022 2day.sk s.r.o., Webikon s.r.o.
 * @license   GPL-2.0+
 * @link      https://www.superfaktura.sk/integracia/
 */
 
/**
 * WC_SuperFaktura.
 *
 * @package SuperFaktúra WooCommerce
 * @author  2day.sk <superfaktura@2day.sk>
 */
class WC_SuperFaktura {

	/**
	 * Fake payment gateway ID used to target zero value orders without payment method set.
	 *
	 * @var string
	 */
	public static $zero_value_order_fake_payment_method_id = 'wc_sf_zero_value_fake_gateway';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version = '1.42.6';

	/**
	 * Database version.
	 *
	 * @var string
	 */
	public $db_version = '1.1';

	/**
	 * Unique identifier for your plugin.
	 *
	 * Use this value (not the variable name) as the text domain when internationalizing strings of text. It should match the Text Domain file header in the main plugin file.
	 *
	 * @var string
	 */
	public $plugin_slug = 'woocommerce-superfaktura';

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	public static $instance = null;

	/**
	 * Slug of the plugin screen.
	 *
	 * @var string
	 */
	public $plugin_screen_hook_suffix = null;

	/**
	 * Default product description template.
	 *
	 * @var string
	 */
	public $product_description_template_default;

	/**
	 * Stored result of detection wc-nastavenia-skcz plugin.
	 *
	 * @var bool
	 */
	public $wc_nastavenia_skcz_activated;

	/**
	 * List of EU countries.
	 *
	 * @var array
	 */
	public $eu_countries;

	/**
	 * List of EU countries + Northern Ireland for VAT Number validation
	 *
	 * @var array
	 */
	public $eu_vat_countries;

	/**
	 * Allowed tags in HTML output.
	 *
	 * @var array
	 */
	public $allowed_tags;

	/**
	 * Instance of WC_SF_Admin.
	 *
	 * @var WC_SF_Admin
	 */
	public $admin;

	/**
	 * Instance of WC_SF_Email.
	 *
	 * @var WC_SF_Email
	 */
	public $email;

	/**
	 * Instance of WC_SF_Invoice.
	 *
	 * @var WC_SF_Invoice
	 */
	public $invoice_generator;



	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_wc_sf_api_test', array( $this, 'wc_sf_api_test' ) );
		add_action( 'wp_ajax_wc_sf_url_check', array( $this, 'wc_sf_url_check' ) );

		// Backward compatibility with previous removed option.
		$this->product_description_template_default = '[ATTRIBUTES]' . ( 'yes' === get_option( 'woocommerce_sf_product_description_visibility', 'yes' ) ? "\n[SHORT_DESCR]" : '' );

		$this->eu_countries = array(
			'AT', // Austria
			'BE', // Belgium
			'BG', // Bulgaria
			'CY', // Cyprus
			'CZ', // Czechia
			'DE', // Germany
			'DK', // Denmark
			'EE', // Estonia
			'ES', // Spain
			'FI', // Finland
			'FR', // France
			'GR', // Greece
			'HR', // Croatia
			'HU', // Hungary
			'IE', // Ireland
			'IT', // Italy
			'LT', // Lithuania
			'LU', // Luxembourg
			'LV', // Latvia
			'MT', // Malta
			'NL', // The Netherlands
			'PL', // Poland
			'PT', // Portugal
			'RO', // Romania
			'SE', // Sweden
			'SI', // Slovenia
			'SK', // Slovakia
		);

		$this->eu_vat_countries = array(
			'AT', // Austria
			'BE', // Belgium
			'BG', // Bulgaria
			'CY', // Cyprus
			'CZ', // Czechia
			'DE', // Germany
			'DK', // Denmark
			'EE', // Estonia
			'EL', // Greece
			'ES', // Spain
			'FI', // Finland
			'FR', // France
			'HR', // Croatia
			'HU', // Hungary
			'IE', // Ireland
			'IT', // Italy
			'LT', // Lithuania
			'LU', // Luxembourg
			'LV', // Latvia
			'MT', // Malta
			'NL', // The Netherlands
			'PL', // Poland
			'PT', // Portugal
			'RO', // Romania
			'SE', // Sweden
			'SI', // Slovenia
			'SK', // Slovakia
			'XI', // Northern Ireland
		);

        if ( is_admin() ) {
			$this->admin = new WC_SF_Admin($this);
        }
		$this->email = new WC_SF_Email($this);
		$this->invoice_generator = new WC_SF_Invoice($this);
	}



	/**
	 * Return an instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}



	/**
	 * Fired when the plugin is activated.
	 *
	 * @param boolean $network_wide True if WPMU superadmin uses "Network Activate" action, false if WPMU is disabled or plugin is activated on an individual blog.
	 */
	public static function activate( $network_wide ) {
		// Check if WooCommerce is active.
		if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			deactivate_plugins( plugin_basename( WC_SF_FILE_PATH ) );
			wp_die( __( 'Please install WooCommerce before activating SuperFaktura WooCommerce plugin.', 'woocommerce-superfaktura' ), 'Plugin dependency check', array( 'back_link' => true ) );
		}
	}



	/**
	 * Fired when the plugin is deactivated.
	 *
	 * @param boolean $network_wide True if WPMU superadmin uses "Network Activate" action, false if WPMU is disabled or plugin is activated on an individual blog.
	 */
	public static function deactivate( $network_wide ) {
		// :TODO: Define deactivation functionality here.
	}



	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_plugin_textdomain() {

		$domain = $this->plugin_slug;
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_plugin_textdomain( $domain, false, dirname( plugin_basename( WC_SF_FILE_PATH ) ) . '/languages/' );
	}



	/**
	 * Register scripts for public pages.
	 */
	public function enqueue_scripts() {
		if ( is_checkout() || is_account_page() ) {
			if ( 'yes' === get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) ) {
				wp_enqueue_script( 'wc-sf-checkout-js', plugins_url( 'assets/js/checkout.js', WC_SF_FILE_PATH ), array( 'jquery' ), $this->version, true );
			}
		}
	}



	/**
	 * Fires once activated plugins have loaded.
	 */
	public function plugins_loaded() {
		if ( get_site_option( 'wc_sf_db_version' ) !== $this->db_version ) {
			$this->wc_sf_db_install();
		}
	}



	/**
	 * Install database tables.
	 */
	public function wc_sf_db_install() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'wc_sf_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NULL,
            document_type varchar(16) NULL,
            request_type varchar(16) NULL,
            response_status int(11) NULL,
            response_message varchar(1024) NULL,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wc_sf_db_version', $this->db_version );
	}



	/**
	 * Initialize the plugin.
	 */
	public function init() {
		$this->load_plugin_textdomain();

		$this->allowed_tags = wp_kses_allowed_html( 'post' );
		$this->allowed_tags['style'] = array( 'type' => 1 );

		$this->wc_nastavenia_skcz_activated = class_exists( 'Webikon\Woocommerce_Plugin\WC_Nastavenia_SKCZ\Plugin', false );

		if ( 'yes' === get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) && ! $this->wc_nastavenia_skcz_activated ) {
			add_filter( 'woocommerce_billing_fields', array( $this, 'billing_fields' ) );
			add_filter( 'woocommerce_form_field', array( $this, 'billing_fields_labels' ), 10, 4 );
			add_filter( 'woocommerce_checkout_process', array( $this, 'checkout_process' ) );

			add_filter( 'woocommerce_admin_billing_fields', array( $this, 'woocommerce_admin_billing_fields' ), 10, 1 );
			add_action( 'woocommerce_process_shop_order_meta', array( $this, 'woocommerce_process_shop_order_meta' ), 10, 2 );

			// Add editable fields to user profile in admin.
			add_filter( 'woocommerce_customer_meta_fields' , array( $this, 'woocommerce_customer_meta_fields' ) );

			// Add custom fields values to customer details.
			add_filter( 'woocommerce_ajax_get_customer_details', array( $this, 'woocommerce_ajax_get_customer_details' ), 10, 3 );
		}

		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'checkout_order_meta' ) );

		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_orders_actions' ), 10, 2 );

		// Custom order filter by wc_sf_internal_regular_id (see https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query#adding-custom-parameter-support).
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'filter_order_by_internal_regular_id' ), 10, 2 );

		$wc_get_order_statuses = $this->get_order_statuses();
		foreach ( $wc_get_order_statuses as $key => $status ) {
			add_action( 'woocommerce_order_status_' . $key, array( $this, 'sf_new_invoice' ), 5 );
		}

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sf_new_invoice' ), 5 );

		add_action( 'woocommerce_thankyou', array( $this, 'sf_invoice_link_page' ) );
		add_action( 'wp_loaded', array( $this, 'set_order_as_paid' ) );
		add_action( 'sf_fetch_related_invoice', array( $this, 'fetch_related_invoice'), 10, 1 );
		add_action( 'sf_retry_generate_invoice', array( $this, 'retry_generate_invoice'), 10, 3 );
		add_action( 'wp_ajax_wc_sf_generate_secret_key', array( $this, 'generate_secret_key' ) );

        if ( is_admin() ) {
			$this->admin->init();
        }

		$this->email->init();
	}



	/**
	 * Generate secret key.
	 */
	public function generate_secret_key() {
		check_ajax_referer( 'wc_sf' );
		echo esc_attr( WC_SF_Helper::generate_secret_key() );
		wp_die();
	}



	/**
	 * Check if HPOS is enabled.
	 */
	public function hpos_enabled() {
		return wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
	}



	/**
	 * Get orders by metadata.
	 */
	public function get_orders_by_meta( $meta_key, $meta_value ) {
		if ( $this->hpos_enabled() ) {
			return wc_get_orders(
				array(
					'meta_query' => array(
						array(
							'key'   => $meta_key,
							'value' => $meta_value
						)
					),
				)
			);
		}

		// Support for custom metadata in query is added via woocommerce_order_data_store_cpt_get_orders_query filter.
		return wc_get_orders( array( $meta_key => $meta_value ) );
	}



	/**
	 * Set order as paid.
	 */
	public function set_order_as_paid() {
		if ( ! isset( $_GET['callback'] ) || 'wc_sf_order_paid' !== $_GET['callback'] ) {
			return;
		}

		if ( ! isset( $_GET['invoice_id'] ) || ! isset( $_GET['secret_key'] ) ) {
			exit();
		}
	
		$invoice_id      = sanitize_text_field( wp_unslash( $_GET['invoice_id'] ) );
		$secret_key      = sanitize_text_field( wp_unslash( $_GET['secret_key'] ) );
		$user_secret_key = get_option( 'woocommerce_sf_sync_secret_key', false );

		if ( is_numeric( $invoice_id ) && ( false === $user_secret_key || $secret_key === $user_secret_key ) ) {

			// Query order by custom field.
			$orders = $this->get_orders_by_meta( 'wc_sf_internal_proforma_id', $invoice_id );
			if ( count( $orders ) === 0 ) {
				$orders = $this->get_orders_by_meta( 'wc_sf_internal_regular_id', $invoice_id );
			}

			// Check invoice status.
			$api      = $this->sf_api();
			$response = $api->getInvoiceDetails( $invoice_id );

			// Invoice not found.
			if ( ! $response || ! isset( $response->{$invoice_id} ) ) {
				$this->wc_sf_log(
					array(
						'request_type'     => 'callback_paid',
						'response_status'  => 902,
						'response_message' => sprintf( 'Invoice ID %d not found', $invoice_id ),
					)
				);
				exit();
			}

			// 1 = not paid, 2 = paid partially, 3 = paid
			if ( 3 != $response->{$invoice_id}->Invoice->status ) {
				$this->wc_sf_log(
					array(
						'request_type'     => 'callback_paid',
						'response_status'  => 903,
						'response_message' => sprintf( 'Invoice ID %d not paid', $invoice_id ),
					)
				);
				exit();
			}

			if ( count( $orders ) === 1 ) {
				$order = $orders[0];

				// Get order status (see https://docs.woocommerce.com/document/managing-orders/).
				$order_status = $order->get_status();
				if ( 'on-hold' === $order_status ) {
					// Mark order as paid (see https://woocommerce.wp-a2z.org/oik_api/wc_orderpayment_complete/).
					$order->payment_complete();

					$this->wc_sf_log(
						array(
							'order_id'     => $order->get_id(),
							'request_type' => 'callback_paid',
						)
					);

					// Check if related regular invoice was created automatically in SuperFaktura.
					if ( 'proforma' == $response->{$invoice_id}->Invoice->type ) {

						// Only if regular invoice does not already exist in WooCommerce.
						$regular_id = $order->get_meta( 'wc_sf_internal_regular_id', true );
						if ( empty( $regular_id ) ) {

							// Schedule an action (because SuperFaktura calls the callback BEFORE it creates the regular invoice automatically).
							as_schedule_single_action( time() + 300, 'sf_fetch_related_invoice', array( 'proforma_id' => $invoice_id ), 'woocommerce-superfaktura' );
						}
					}
				}
				else {
					$this->wc_sf_log(
						array(
							'order_id'         => $order->get_id(),
							'request_type'     => 'callback_paid',
							'response_status'  => 905,
							'response_message' => 'Order is not on hold',
						)
					);
				}
			}
			else {
				$this->wc_sf_log(
					array(
						'request_type'     => 'callback_paid',
						'response_status'  => 904,
						'response_message' => sprintf( 'Order with invoice ID %d not found', $invoice_id ),
					)
				);
			}
		}
		else {
			$this->wc_sf_log(
				array(
					'request_type'     => 'callback_paid',
					'response_status'  => 901,
					'response_message' => 'Incorrect parameters',
				)
			);
		}

		exit();
	}



	/**
	 * Check if there is a related invoice created automatically in SuperFaktura and update order meta if there is.
	 */
	public function fetch_related_invoice( $proforma_id ) {

		// Find order by proforma invoice id.
		$orders = $this->get_orders_by_meta( 'wc_sf_internal_proforma_id', $proforma_id );
		if ( empty( $orders ) ) {
			return false;
		}

		$order = $orders[0];

		// Only if regular invoice does not already exist in WooCommerce.
		$regular_id = $order->get_meta( 'wc_sf_internal_regular_id', true );
		if ( ! empty( $regular_id ) ) {
			return false;
		}

		// Get proforma invoice data from SF API.
		$api      = $this->sf_api();
		$proforma = $api->getInvoiceDetails( $proforma_id );
		if ( ! $proforma || ! isset( $proforma->{$proforma_id} ) || 'proforma' !== $proforma->{$proforma_id}->Invoice->type ) {
			return false;
		}

		$regular_id = null;

		// Find if there is a regular invoice in RelatedItems.
		foreach ( $proforma->{$proforma_id}->RelatedItems as $related_item ) {
			if ( 'regular' != $related_item->Invoice->type || $related_item->Invoice->tax_document ) {
				continue;
			}

			$regular_id = $related_item->Invoice->id;
		}

		// Find if there is a regular invoice in parent_id.
		if ( $proforma->{$proforma_id}->Invoice->parent_id && 'regular' ===  $proforma->{$proforma_id}->Parent->Invoice->type && ! $proforma->{$proforma_id}->Parent->Invoice->tax_document ) {
			$regular_id = $proforma->{$proforma_id}->Parent->Invoice->id;
		}

		if ( empty( $regular_id ) ) {
			return false;
		}

		$regular = $api->getInvoiceDetails( $regular_id );
		if ( ! $regular || ! isset( $regular->{$regular_id} ) ) {
			return false;
		}

		// Delete payment link.
		if ( 3 == $regular->{$regular_id}->Invoice->status ) {
			$order->delete_meta_data( 'wc_sf_payment_link' );
		}

		// Save document ID.
		$order->update_meta_data( 'wc_sf_internal_regular_id', $regular_id );

		// Save formatted invoice number.
		$order->update_meta_data( 'wc_sf_regular_invoice_number', $regular->{$regular_id}->Invoice->invoice_no_formatted );

		// Save pdf url.
		$language = $this->get_language( $order->get_id(), get_option( 'woocommerce_sf_invoice_language' ), true );
		$pdf      = ( ( 'yes' === get_option( 'woocommerce_sf_sandbox', 'no' ) ) ? $api::SANDBOX_URL : $api::SFAPI_URL ) . '/' . $language . '/invoices/pdf/' . $regular_id . '/token:' . $regular->{$regular_id}->Invoice->token;
		$order->update_meta_data( 'wc_sf_invoice_regular', $pdf );

		$order->save();

		return true;
	}



	/**
	 * Schedule next attempt to create the document.
	 */
	public function retry_generate_invoice_schedule( $order, $type ) {

		$attempt = $order->get_meta( 'wc_sf_' . $type . '_create_retry_attempts', true );
		if ( ! $attempt ) {
			$attempt = 0;
		}
		$attempt++;

		if ( $attempt < 4 ) {
			switch ( $attempt ) {
				case 1:
				default:
					$mins_to_next_attempt = 5;
					break;

				case 2:
					$mins_to_next_attempt = 30;
					break;

				case 3:
					$mins_to_next_attempt = 60;
					break;
			}

			switch ( $type ) {
				case 'proforma':
					$order->add_order_note( sprintf( __( 'API call to create proforma invoice failed. We will try again in %d minutes.', 'woocommerce-superfaktura' ), $mins_to_next_attempt ) );
					break;

				case 'regular':
					$order->add_order_note( sprintf( __( 'API call to create invoice failed. We will try again in %d minutes.', 'woocommerce-superfaktura' ), $mins_to_next_attempt ) );
					break;

				case 'cancel':
					$order->add_order_note( sprintf( __( 'API call to create credit note failed. We will try again in %d minutes.', 'woocommerce-superfaktura' ), $mins_to_next_attempt ) );
					break;

				default:
					$order->add_order_note( sprintf( __( 'API call to create document failed. We will try again in %d minutes.', 'woocommerce-superfaktura' ), $mins_to_next_attempt ) );
					break;
			}

			as_schedule_single_action( time() + ( 60 * $mins_to_next_attempt ), 'sf_retry_generate_invoice', array( 'order_id' => $order->get_id(), 'type' => $type, 'attempt' => $attempt), 'woocommerce-superfaktura' );
		}
		else {
			switch ( $type ) {
				case 'proforma':
					$this->save_admin_notice( sprintf( __( 'API call to create proforma invoice for <a href="%s">order #%d</a> failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $order->get_edit_order_url(), $order->get_id(), $attempt ), 'error' );
					$order->add_order_note( sprintf( __( 'API call to create proforma invoice failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $attempt ) );
					break;

				case 'regular':
					$this->save_admin_notice( sprintf( __( 'API call to create invoice for <a href="%s">order #%d</a> failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $order->get_edit_order_url(), $order->get_id(), $attempt ), 'error' );
					$order->add_order_note( sprintf( __( 'API call to create invoice failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $attempt ) );
					break;

				case 'cancel':
					$this->save_admin_notice( sprintf( __( 'API call to create credit note for <a href="%s">order #%d</a> failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $order->get_edit_order_url(), $order->get_id(), $attempt ), 'error' );
					$order->add_order_note( sprintf( __( 'API call to create credit note failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $attempt ) );
					break;

				default:
					$this->save_admin_notice( sprintf( __( 'API call to create document for <a href="%s">order #%d</a> failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $order->get_edit_order_url(), $order->get_id(), $attempt ), 'error' );
					$order->add_order_note( sprintf( __( 'API call to create document failed %d times. Try creating it manually.', 'woocommerce-superfaktura' ), $attempt ) );
					break;
			}
		}
	}



	/**
	 * Try creating the document again, if the previous attempt failed.
	 */
	public function retry_generate_invoice( $order_id, $type, $attempt ) {

		$order = wc_get_order( $order_id );
		$order->update_meta_data( 'wc_sf_' . $type . '_create_retry_attempts', $attempt );

		// Check if the document has not been created in the meantime.
		$sf_id = $order->get_meta( 'wc_sf_internal_' . $type . '_id', true );
		if ( ! empty( $sf_id ) ) {
			return false;
		}

		// Check if the document was created in SF but we didn't get a response due to timeout and therefore the order metadata is empty.
		$invoices = $this->sf_api()->invoices( array( 'order_no' => $order_id, 'type' => $type ) );
		if ( ! empty( $invoices ) && $invoices->itemCount > 0 ) {

			// Save document ID.
			$internal_id = $invoices->items[0]->Invoice->id;
			$order->update_meta_data( 'wc_sf_internal_' . $type . '_id', $internal_id );

			// Save formatted invoice number.
			$invoice_number = $invoices->items[0]->Invoice->invoice_no_formatted;
			$order->update_meta_data( 'wc_sf_' . $type . '_invoice_number', $invoice_number );

			// Save pdf url.
			$language = $this->get_language( $order->get_id(), get_option( 'woocommerce_sf_invoice_language' ), true );
			$token    = $invoices->items[0]->Invoice->token;
			$pdf      = ( ( 'yes' === get_option( 'woocommerce_sf_sandbox', 'no' ) ) ? $this->sf_api()::SANDBOX_URL : $this->sf_api()::SFAPI_URL ) . '/' . $language . '/invoices/pdf/' . $internal_id . '/token:' . $token;
			$order->update_meta_data( 'wc_sf_invoice_' . $type, $pdf );

			$order->save();

			return true;
		}

		// Try to generate the document again.
		$this->invoice_generator->generate_invoice( $order, $type );

		return true;
	}



	/**
	 * Save the admin notification to appear the next time admin page loads
	 */
	public function save_admin_notice( $notice_text, $notice_type = 'info' ) {
		$admin_notices = get_option( 'woocommerce_sf_admin_notices', array() );
		$admin_notices[] = array( 'text' => $notice_text, 'type' => $notice_type );
		update_option( 'woocommerce_sf_admin_notices', $admin_notices );
	}



	/**
	 * Handle a custom 'wc_sf_internal_proforma_id' and 'wc_sf_internal_regular_id' query var to get orders with the 'wc_sf_internal_proforma_id' or 'wc_sf_internal_regular_id' meta respectively.
	 *
	 * @param array $query Args for WP_Query.
	 * @param array $query_vars Query vars from WC_Order_Query.
	 */
	public function filter_order_by_internal_regular_id( $query, $query_vars ) {

		if ( ! empty( $query_vars['wc_sf_internal_proforma_id'] ) ) {
			$query['meta_query'][] = array(
				'key'   => 'wc_sf_internal_proforma_id',
				'value' => esc_attr( $query_vars['wc_sf_internal_proforma_id'] ),
			);
		}

		if ( ! empty( $query_vars['wc_sf_internal_regular_id'] ) ) {
			$query['meta_query'][] = array(
				'key'   => 'wc_sf_internal_regular_id',
				'value' => esc_attr( $query_vars['wc_sf_internal_regular_id'] ),
			);
		}

		return $query;
	}



	/**
	 * Initialize SuperFaktura API.
	 *
	 * @param array $credentials SuperFaktura API credentials.
	 */
	public function sf_api( $credentials = array() ) {

		$sf_lang       = ( isset( $credentials['woocommerce_sf_lang'] ) ) ? $credentials['woocommerce_sf_lang'] : get_option( 'woocommerce_sf_lang', 'sk' );
		$sf_email      = ( isset( $credentials['woocommerce_sf_email'] ) ) ? $credentials['woocommerce_sf_email'] : get_option( 'woocommerce_sf_email' );
		$sf_key        = ( isset( $credentials['woocommerce_sf_apikey'] ) ) ? $credentials['woocommerce_sf_apikey'] : get_option( 'woocommerce_sf_apikey' );
		$sf_company_id = ( isset( $credentials['woocommerce_sf_company_id'] ) ) ? $credentials['woocommerce_sf_company_id'] : get_option( 'woocommerce_sf_company_id' );
		$sf_sandbox    = ( isset( $credentials['woocommerce_sf_sandbox'] ) ) ? $credentials['woocommerce_sf_sandbox'] : get_option( 'woocommerce_sf_sandbox', 'no' );

		$module_id = sprintf( 'WordPress %s (WC %s, WC SF %s)', get_bloginfo( 'version' ), WC()->version, $this->version );

		switch ( $sf_lang ) {
			case 'at':
				$api = new WC_SF_Api_At( $sf_email, $sf_key, sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ?? '' ) ), $module_id, $sf_company_id );
				break;

			case 'cz':
				$api = new WC_SF_Api_Cz( $sf_email, $sf_key, sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ?? '' ) ), $module_id, $sf_company_id );
				break;

			default:
				$api = new WC_SF_Api( $sf_email, $sf_key, sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ?? '' ) ), $module_id, $sf_company_id );
				break;
		}

		if ( 'yes' === $sf_sandbox ) {
			$api->useSandBox();
		}

		return $api;
	}



	/**
	 * Delete invoice items.
	 *
	 * @param int                                     $invoice_id Invoice ID.
	 * @param WC_SF_Api|WC_SF_Api_At|WC_SF_Api_Cz $api SuperFaktura API client.
	 */
	public function sf_clean_invoice_items( $invoice_id, $api ) {
		$response = $api->invoice( $invoice_id );
		if ( ! isset( $response->error ) || 0 == $response->error ) {
			if ( isset( $response->InvoiceItem ) && is_array( $response->InvoiceItem ) ) {
				$delete_item_ids = array();
				foreach ( $response->InvoiceItem as $item ) {
					$delete_item_ids[] = $item->id;
				}

				// Delete items in chunks of 50.
				$delete_item_ids_chunks = array_chunk( $delete_item_ids, 50 );
				foreach ( $delete_item_ids_chunks as $delete_item_ids_chunk ) {
					$delete_response = $api->deleteInvoiceItem( $invoice_id, $delete_item_ids_chunk );
				}
			}
		}
	}



	/**
	 * Check if invoice has to be created.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sf_new_invoice( $order_id ) {
		$order          = wc_get_order( $order_id );
		$order_status   = $order->get_status();
		$payment_method = $order->get_payment_method();

		// Payment method is missing for zero value orders, we check the "Zero value invoices" status from settings using fake payment method name.
		if ( empty( $payment_method ) && 0.0 === abs( floatval( $order->get_total() ) ) ) {
			$payment_method = self::$zero_value_order_fake_payment_method_id;
		}

		// Payment method for WooCommerce Subscriptions manual renewal.
		if ( empty( $payment_method ) && class_exists( 'WC_Subscriptions' ) ) {
			$subscription_order_id = $order->get_meta( '_subscription_renewal', true );
			if ( $subscription_order_id ) {
				$subscription_order = wc_get_order( $subscription_order_id );
				$payment_method     = $subscription_order->get_payment_method();
			}
		}

		foreach ( array( 'regular', 'proforma', 'cancel' ) as $type ) {
			$generate_invoice_status = ( 'cancel' === $type ) ? null : get_option( 'woocommerce_sf_invoice_' . $type . '_' . $payment_method );

			if ( $order_status === $generate_invoice_status ) {
				$generate_invoice = true;
			} else {
				$generate_invoice = false;

				if ( 'regular' === $type ) {
					/*
					 * Workaround for orders that don't need processing. Invoice won't be generated in some cases because
					 * the "Processing" order state is skipped. We need to allow the generation of invoice in "Completed"
					 * order state instead.
					 */
					$workaround_enabled = wc_string_to_bool( get_option( 'woocommerce_sf_invoice_regular_processing_skipped_fix' ) );
					if ( $workaround_enabled && 'processing' === $generate_invoice_status && 'completed' === $order_status && ! $order->needs_processing() ) {
						$generate_invoice = true;
					}
				}
			}

			/**
			 * Filter to allow forcing or skipping invoice creation.
			 *
			 * Example:
			 * function custom_generate_invoice( $generate_invoice, $order, $type, $payment_method ) {
			 *     if ( 'regular' === $type && 'ready_to_pickup' === $order->get_status() ) {
			 *         return true;
			 *     }
			 *     return $generate_invoice;
			 * }
			 * add_filter( 'sf_generate_invoice', 'custom_generate_invoice', 10, 4 );
			 */
			$generate_invoice = apply_filters( 'sf_generate_invoice', $generate_invoice, $order, $type, $payment_method );

			if ( $generate_invoice ) {
				$this->invoice_generator->generate_invoice( $order, $type );
			}
		}
	}



	/**
	 * Regenerate existing invoices.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sf_regen_invoice( $order_id ) {
		$order = wc_get_order( $order_id );

		$result = true;

		foreach ( array( 'proforma', 'regular', 'cancel' ) as $type ) {
			$sf_id = $order->get_meta( 'wc_sf_internal_' . $type . '_id', true );
			if ( ! empty( $sf_id ) ) {
				$result = $result && $this->invoice_generator->generate_invoice( $order, $type );
			}
		}

		return $result;
	}



	/**
	 * Write log entry to database.
	 *
	 * @param array $log_data Data to write to log table.
	 */
	public function wc_sf_log( $log_data ) {
		global $wpdb;

		$log_data['time'] = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'wc_sf_log', $log_data );
	}



	/**
	 * Format product attributes.
	 *
	 * @param array      $item Item data.
	 * @param WC_Product $product Product.
	 */
	public function format_item_meta( $item, $product ) {

		$item_meta = $item['item_meta'];

		if ( empty( $item_meta ) ) {
			return false;
		}

		$processed_item_meta = $item_meta;

		// Remove meta from WooCommerce Product Add-Ons plugin.
		unset( $processed_item_meta['product_extras'] );

		// Compatibility with N-Media WooCommerce PPOM plugin.
		if ( function_exists( 'ppom_woocommerce_order_key' ) ) {
			$processed_item_meta = array();
			foreach ( $item_meta as $meta_key => $meta_value ) {
				$meta_key                         = ppom_woocommerce_order_key( $meta_key, null, $item );
				$processed_item_meta[ $meta_key ] = html_entity_decode( wp_strip_all_tags( $meta_value ) );
			}
		}

		// Compatibility with YITH WooCommerce Product Add-ons & Extra Options plugin.
		if ( defined( 'YITH_WAPO' ) ) {
			$processed_item_meta = array();
			foreach ( $item_meta as $meta_key => $meta_value ) {

				if ( 0 === strpos( $meta_key, 'ywapo-addon-' ) ) {
					list( $addon_id, $option_id ) = explode( '-', str_replace( 'ywapo-addon-', '', $meta_key) );
					$info = yith_wapo_get_option_info( $addon_id, $option_id );
					$meta_key = $info['addon_label'] ?? $meta_key;
				}

				$processed_item_meta[ $meta_key ] = $meta_value;
			}
		}

		// Compatibility with Cost Calculator Builder plugin.
		foreach ( $processed_item_meta as $meta_key => $meta_value ) {
			if ( 'ccb_calculator' == $meta_key && isset( $meta_value['calc_data'] )) {
				foreach ( $meta_value['calc_data'] as $calc_data_item ) {
					if ( ! isset( $calc_data_item['label'] ) || ! isset( $calc_data_item['value'] ) ) {
						continue;
					}
					$processed_item_meta[ trim( $calc_data_item['label'] ) ] = trim( $calc_data_item['value'] );
				}

				unset( $processed_item_meta['ccb_calculator'] );
			}
		}

		if ( empty( $processed_item_meta ) || ! is_array( $processed_item_meta ) ) {
			return false;
		}

		$result = array();
		foreach ( $processed_item_meta as $attribute => $slug ) {

			// Skip meta attributes.
			if ( '_' === $attribute[0] ) {
				continue;
			}

			$value = '';
			if ( taxonomy_exists( esc_attr( str_replace( 'attribute_', '', $attribute ) ) ) ) {
				$term = get_term_by( 'slug', $slug, esc_attr( str_replace( 'attribute_', '', $attribute ) ) );
				if ( ! is_wp_error( $term ) && $term->name ) {
					$value = $term->name;
				}
			} else {
				$value = apply_filters( 'woocommerce_variation_option_name', $slug, null, $attribute, $product );
			}

			$result[] = sprintf( '%s: %s', wc_attribute_label( $attribute, $product ), $value );
		}

		$separator = apply_filters( 'sf_attr_separator', ', ' );
		return implode( $separator, $result );
	}



	/**
	 * Format discount description.
	 *
	 * @param WC_Order $order Order.
	 */
	public function get_discount_description( $order ) {
		$coupons_codes = $order->get_coupon_codes();
		if ( ! $coupons_codes ) {
			return false;
		}

		$coupons = array();
		foreach ( $coupons_codes as $coupon_code ) {
			$coupon = new WC_Coupon( $coupon_code );

			$sign = '';
			if ( $coupon->is_type( 'fixed_cart' ) ) {
				$sign = ' ' . $order->get_currency();
			} elseif ( $coupon->is_type( 'percent' ) ) {
				$sign = '%';
			}

			if ( 'yes' === get_option( 'woocommerce_sf_product_description_show_coupon_code', 'yes' ) ) {
				$coupons[] = $coupon_code . ' (' . $coupon->get_amount() . $sign . ')';
			} else {
				$coupons[] = $coupon->get_amount() . $sign;
			}
		}

		$result = __( 'Coupons', 'woocommerce-superfaktura' ) . ': ' . implode( ', ', $coupons );
		return $result;
	}



	/**
	 * Get invoice language.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $woocommerce_sf_invoice_language Option value from plugin settings.
	 * @param bool   $strict If true allows only languages supported by SuperFaktura.
	 */
	public function get_language( $order_id, $woocommerce_sf_invoice_language, $strict = false ) {
		$locale_map = array(
			'sk' => 'slo',
			'cs' => 'cze',
			'en' => 'eng',
			'de' => 'deu',
			'nl' => 'nld',
			'hr' => 'hrv',
			'hu' => 'hun',
			'pl' => 'pol',
			'ro' => 'rom',
			'ru' => 'rus',
			'sl' => 'slv',
			'es' => 'spa',
			'it' => 'ita',
			'uk' => 'ukr',
		);

		$language = $woocommerce_sf_invoice_language;
		switch ( $language ) {
			case 'locale':
				$locale = substr( get_locale(), 0, 2 );
				if ( isset( $locale_map[ $locale ] ) ) {
					$language = $locale_map[ $locale ];
				}
				break;

			case 'wpml':
				$order = wc_get_order( $order_id );
				$wpml_language = $order->get_meta( 'wpml_language', true );
				if ( isset( $locale_map[ $wpml_language ] ) ) {
					$language = $locale_map[ $wpml_language ];
				}

				if ( class_exists( 'sitepress' ) ) {
					global $sitepress;
					$sitepress->switch_lang( $wpml_language, false );
				}
				break;

			case 'endpoint':
			default:
				// Nothing to do.
				break;
		}

		if ( $strict ) {
			if ( ! in_array( $language, $locale_map, true ) ) {
				$language = ( 'cz' === get_option( 'woocommerce_sf_lang' ) ) ? 'cze' : 'slo';
			}
		}

		$language = apply_filters( 'sf_invoice_language', $language, $order_id, $woocommerce_sf_invoice_language );

		return $language;
	}


	/**
	 * Get non-variation product attributes.
	 *
	 * @param int $product_id Product ID.
	 */
	public function get_non_variations_attributes( $product_id ) {
		$attributes = get_post_meta( $product_id, '_product_attributes' );
		if ( ! $attributes ) {
			return false;
		}
		$result = array();
		foreach ( $attributes[0] as $attribute ) {
			if ( $attribute['is_variation'] ) {
				continue;
			}

			if ( $attribute['is_taxonomy'] ) {
				$taxonomy = get_taxonomy( $attribute['name'] );
				$result[] = ( ( $taxonomy ) ? $taxonomy->labels->singular_name : $attribute['name'] ) . ': ' . implode( ', ', wc_get_product_terms( $product_id, $attribute['name'], array( 'fields' => 'names' ) ) );
			} else {
				$result[] = $attribute['name'] . ': ' . $attribute['value'];
			}
		}

		$separator = apply_filters( 'sf_attr_separator', ', ' );
		return implode( $separator, $result );
	}



	/**
	 * Replace [ATTRIBUTE:name] tags in product description.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $description Product description.
	 */
	public function replace_single_attribute_tags( $product_id, $description ) {

		preg_match_all( '/\[ATTRIBUTE:([^\]]*)\]/', $description, $matches, PREG_SET_ORDER );

		if ( $matches ) {
			$attributes = get_post_meta( $product_id, '_product_attributes' );

			foreach ( $matches as $match ) {
				$att_name  = $match[1];
				$att_slug  = sanitize_title( $match[1] );
				$att_value = null;

				if ( isset( $attributes[0][ $att_slug ] ) ) {
					$att_value = $attributes[0][ $att_slug ]['value'];
				} elseif ( isset( $attributes[0][ 'pa_' . $att_slug ] ) ) {
					$att_value = implode( ', ', wc_get_product_terms( $product_id, 'pa_' . $att_slug, array( 'fields' => 'names' ) ) );
				}

				$description = str_replace( $match[0], ( $att_value ) ? sprintf( '%s: %s', $att_name, $att_value ) : '', $description );
			}
		}

		return $description;
	}



	/**
	 * Get product category names.
	 * If Yoast SEO is active and a primary category is set, return only the name of the primary category.
	 *
	 * @param int    $product_id Product ID.
	 */
	public function get_product_category_names( $product_id ) {
		$categories = array();

		// Check if Yoast SEO plugin is active.
		if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
			// Get the primary category set by Yoast SEO.
			$primary_term_id = get_post_meta( $product_id, '_yoast_wpseo_primary_product_cat', true );
			if ( $primary_term_id ) {
				$primary_category_term = get_term( $primary_term_id, 'product_cat' );
				if ( ! is_wp_error( $primary_category_term ) && $primary_category_term ) {
					$categories[] = $primary_category_term->name;
				}
			}
		}

		// If Yoast SEO is not active or no primary category is set, get all category names
		if ( empty( $categories ) ) {
			$categories = wc_get_product_terms( $product_id, 'product_cat', array( 'fields' => 'names', 'exclude' => get_option( 'default_product_cat', 0 ) ) );
		}

		if (empty($categories) || is_wp_error($categories)) {
			return false;
		}

		return implode(', ', $categories);
	}



	/**
	 * Add additional billing fields to checkout page.
	 *
	 * @param array $fields Billing fields.
	 */
	public function billing_fields( $fields ) {
		$new_fields = array();
		foreach ( $fields as $key => $value ) {

			if ( 'billing_company' === $key ) {

				// Add "Buy as Business client" checkbox.
				$new_fields['wi_as_company'] = array(
					'type'  => 'checkbox',
					'label' => __( 'Buy as Business client', 'woocommerce-superfaktura' ),
					'class' => array( 'form-row-wide' ),
				);

				// Keep "Company name" field.
				$new_fields[ $key ]             = $value;
				$new_fields[ $key ]['required'] = false;

				// Add "ID #" field (ICO).
				if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_id', 'optional' ) ) {
					$new_fields['billing_company_wi_id'] = array(
						'type'     => 'text',
						'label'    => __( 'ID #', 'woocommerce-superfaktura' ),
						'required' => false,
						'class'    => array( 'form-row-wide' ),
					);
				}

				// Add "VAT #" field (IC DPH).
				if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_vat', false ) ) {
					$new_fields['billing_company_wi_vat'] = array(
						'type'     => 'text',
						'label'    => __( 'VAT #', 'woocommerce-superfaktura' ),
						'required' => false,
						'class'    => array( 'form-row-wide' ),
					);
				}

				// Add "TAX ID #" field (DIC).
				if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_tax', false ) ) {
					$new_fields['billing_company_wi_tax'] = array(
						'type'     => 'text',
						'label'    => __( 'TAX ID #', 'woocommerce-superfaktura' ),
						'required' => false,
						'class'    => array( 'form-row-wide' ),
					);
				}

				continue;
			}

			// Keep all other fields without change.
			$new_fields[ $key ] = $value;
		}

		return $new_fields;
	}



	/**
	 * Customize billing fields labels.
	 *
	 * @param string $field Billing field HTML code.
	 * @param string $key Billing field name.
	 * @param array  $args Billing field parameters.
	 * @param string $value Billing field value.
	 */
	public function billing_fields_labels( $field, $key, $args, $value ) {
		$replace = false;
		switch ( $key ) {
			case 'billing_company':
				$replace = ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_name', 'optional' ) );
				break;

			case 'billing_company_wi_id':
				$replace = ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_id', 'optional' ) );
				break;

			case 'billing_company_wi_vat':
				$replace = ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_vat', 'optional' ) );
				break;

			case 'billing_company_wi_tax':
				$replace = ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_tax', 'optional' ) );
				break;
		}

		if ($replace) {
			$field = str_replace( '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span>', '&nbsp;<abbr class="required" title="' . esc_html__( 'required', 'woocommerce' ) . '">*</abbr>', $field );
		}

		return $field;
	}



	/*
	 * Validate EU VAT Number
	 */
	public function validate_eu_vat_number( $vat_number ) {
		$sanitized_vat_number = preg_replace( '/[^A-Z0-9]/i', '', sanitize_text_field( $vat_number ) );
		$country = substr( $sanitized_vat_number, 0, 2 );
		$vatno = substr( $sanitized_vat_number, 2 );

		if ( ! in_array( strtoupper( $country ), $this->eu_vat_countries ) ) {
			return null;
		}

		try {
			$response = wp_remote_get(
				"https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{$country}/vat/{$vatno}",
				array(
					'timeout' => 10
				)
			);

			if (is_wp_error($response)) {
				$this->wc_sf_log(
					array(
						'request_type'     => 'eu_vat_number',
						'response_status'  => ( (int)$response->get_error_code() ) ? $response->get_error_code() : 912,
						'response_message' => $response->get_error_message(),
					)
				);

				return null;
			}

			if (substr($response['response']['code'], 0, 1) != 2) {
				$this->wc_sf_log(
					array(
						'request_type'     => 'eu_vat_number',
						'response_status'  => $response['response']['code'],
						'response_message' => $response['response']['message'],
					)
				);

				return null;
			}

			$result = json_decode($response['body']);

		} catch (Exception $e) {
			$this->wc_sf_log(
				array(
					'request_type'     => 'eu_vat_number',
					'response_status'  => 911,
					'response_message' => $this->exceptionHandling($e),
				)
			);

			return null;
		}



		if ( 'VALID' !== $result->userError ) {
			return false;
		}

		return true;
	}



	/**
	 * Validate additional billing fields in checkout.
	 */
	public function checkout_process() {
		if ( isset( $_POST['wi_as_company'] ) ) {
			if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_name', 'optional' ) && empty( $_POST['billing_company'] )) {
				// Translators: %s Field name.
				wc_add_notice( sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( __( 'Company name', 'woocommerce' ) ) . '</strong>' ), 'error' );
			}

			if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_id', 'optional' ) && empty( $_POST['billing_company_wi_id'] ) ) {
				// Translators: %s Field name.
				wc_add_notice( sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( __( 'ID #', 'woocommerce-superfaktura' ) ) . '</strong>' ), 'error' );
			}

			if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_vat', 'optional' ) && empty( $_POST['billing_company_wi_vat'] ) ) {
				// Translators: %s Field name.
				wc_add_notice( sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( __( 'VAT #', 'woocommerce-superfaktura' ) ) . '</strong>' ), 'error' );
			}
			elseif ( 'yes' === get_option( 'woocommerce_sf_validate_eu_vat_number', 'no' ) && ! empty( $_POST['billing_company_wi_vat'] ) ) {
				$valid_eu_vat_number = $this->validate_eu_vat_number( $_POST['billing_company_wi_vat'] );
				if ( false === $valid_eu_vat_number ) {
					// Translators: %s Field name.
					wc_add_notice( sprintf( __( '%s is not valid.', 'woocommerce-superfaktura' ), '<strong>' . esc_html( __( 'VAT #', 'woocommerce-superfaktura' ) ) . '</strong>' ), 'error' );
				}
				elseif ( null === $valid_eu_vat_number ) {
					wc_add_notice( sprintf( __( '%s could not be validated.', 'woocommerce-superfaktura' ), '<strong>' . esc_html( __( 'VAT #', 'woocommerce-superfaktura' ) ) . '</strong>' ), 'error' );
				}
			}

			if ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_tax', 'optional' ) && empty( $_POST['billing_company_wi_tax'] ) ) {
				// Translators: %s Field name.
				wc_add_notice( sprintf( __( '%s is a required field.', 'woocommerce' ), '<strong>' . esc_html( __( 'TAX ID #', 'woocommerce-superfaktura' ) ) . '</strong>' ), 'error' );
			}
		}
	}



	/**
	 * Process additional billing fields in checkout.
	 *
	 * @param int $order_id Order ID.
	 */
	public function checkout_order_meta( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->update_meta_data( 'has_shipping', ( isset( $_POST['shiptobilling'] ) && '1' == $_POST['shiptobilling'] ) ? '0' : '1' );

		if ( isset( $_POST['wi_as_company'] ) && '1' == $_POST['wi_as_company'] ) {
			foreach ( array( 'billing_company_wi_id', 'billing_company_wi_vat', 'billing_company_wi_tax' ) as $key ) {
				if ( isset( $_POST[ $key ] ) ) {
					$order->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				}
			}
		} else {
			// Delete the private custom fields prefixed with "_" which are automatically saved even if "Buy as Business client" checkbox is not checked.
			foreach ( array( '_billing_company_wi_id', '_billing_company_wi_vat', '_billing_company_wi_tax' ) as $key ) {
				$order->delete_meta_data( $key );
			}
		}

		$order->save();
	}



	/**
	 * Add additional billing fields to admin order page.
	 *
	 * @param array $fields Billing fields.
	 */
	public function woocommerce_admin_billing_fields( $fields ) {

		$fields['company_wi_id'] = array(
			'label'         => __( 'ID #', 'woocommerce-superfaktura' ),
			'show'          => true,
			'wrapper_class' => 'form-field-wide',
		);

		$fields['company_wi_vat'] = array(
			'label'         => __( 'VAT #', 'woocommerce-superfaktura' ),
			'show'          => true,
			'wrapper_class' => 'form-field-wide',
		);

		$fields['company_wi_tax'] = array(
			'label'         => __( 'TAX ID #', 'woocommerce-superfaktura' ),
			'show'          => true,
			'wrapper_class' => 'form-field-wide',
		);

		return $fields;
	}



	/**
	 * Process additional billing fields in admin order page.
	 *
	 * @param int      $order_id Order ID.
	 * @param WP_Order $order Order.
	 */
	public function woocommerce_process_shop_order_meta( $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		$should_save = false;

		// Because the filter "woocommerce_admin_billing_fields" above saves only private custom fields prefixed with "_" and the plugin for some reason uses duplicates of these fields without the prefix, we need to update those values here as well.
		foreach ( array( 'billing_company_wi_id', 'billing_company_wi_vat', 'billing_company_wi_tax' ) as $key ) {
			if ( isset( $_POST[ '_' . $key ] ) ) {
				$order->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ '_' . $key ] ) ) );
				$should_save = true;
			}
		}

		if ( $should_save ) {
			$order->save();
		}
	}



	/**
	 * Add additional billing fields to admin user profile page.
	 *
	 * @param array $fields User profile fields.
	 */
	function woocommerce_customer_meta_fields( $fields ) {
		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields']['billing_company_wi_id'] = array(
				'label'       => __( 'ID #', 'woocommerce-superfaktura' ),
				'description' => '',
			);

			$fields['billing']['fields']['billing_company_wi_vat'] = array(
				'label'       => __( 'VAT #', 'woocommerce-superfaktura' ),
				'description' => '',
			);

			$fields['billing']['fields']['billing_company_wi_tax'] = array(
				'label'       => __( 'TAX ID #', 'woocommerce-superfaktura' ),
				'description' => '',
			);
		}

		return $fields;
	}



	/**
	 * Add additional billing fields values to customer details.
	 *
	 * @param  array $data Customer data.
	 * @param  WC_Customer $customer Customer.
	 * @param  int $user_id User ID.
	 *
	 * @return
	 */
	function woocommerce_ajax_get_customer_details( $data, $customer, $user_id ) {
		if ( isset ( $data['billing'] ) ) {
			$data['billing']['company_wi_id'] = get_user_meta( $user_id, 'billing_company_wi_id', true );
			$data['billing']['company_wi_vat'] = get_user_meta( $user_id, 'billing_company_wi_vat', true );
			$data['billing']['company_wi_tax'] = get_user_meta( $user_id, 'billing_company_wi_tax', true );
		}

		return $data;
	}



	/**
	 * Create invoices meta box actions for downloading PDFs.
	 *
	 * @param array    $actions Actions.
	 * @param WC_Order $order Order.
	 */
	public function my_orders_actions( $actions, $order ) {
		$pdf = $order->get_meta( 'wc_sf_invoice_proforma', true );
		if ( $pdf ) {
			$actions['wc_sf_invoice_proforma'] = array(
				'url'  => $pdf,
				'name' => __( 'Proforma', 'woocommerce-superfaktura' ),
			);
		}
		$pdf = $order->get_meta( 'wc_sf_invoice_regular', true );
		if ( $pdf ) {
			$actions['wc_sf_invoice_regular'] = array(
				'url'  => $pdf,
				'name' => __( 'Invoice', 'woocommerce-superfaktura' ),
			);
		}

		return $actions;
	}



	/**
	 * Generate invoice ID.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key Invoice type.
	 */
	public function generate_invoice_id( $order, $key = 'regular' ) {
		$invoice_id = $order->get_meta( 'wc_sf_invoice_' . $key . '_id', true );
		if ( ! empty( $invoice_id ) ) {
			return $invoice_id;
		}

		$invoice_id_template = get_option( 'woocommerce_sf_invoice_' . $key . '_id', true );
		if ( empty( $invoice_id_template ) ) {
			$invoice_id_template = '[YEAR][MONTH][COUNT]';
		}

		$num_decimals = get_option( 'woocommerce_sf_invoice_count_decimals', true );
		if ( empty( $num_decimals ) ) {
			$num_decimals = 4;
		}

		$count = get_option( 'woocommerce_sf_invoice_' . $key . '_count', true );
		update_option( 'woocommerce_sf_invoice_' . $key . '_count', intval( $count ) + 1 );
		$count = str_pad( $count, intval( $num_decimals ), '0', STR_PAD_LEFT );

		$date = current_time( 'timestamp' );

		$template_tags = array(
			'[YEAR]'         => date( 'Y', $date ),
			'[YEAR_SHORT]'   => date( 'y', $date ),
			'[MONTH]'        => date( 'm', $date ),
			'[DAY]'          => date( 'd', $date ),
			'[COUNT]'        => $count,
			'[ORDER_NUMBER]' => $order->get_order_number(),
		);
		$invoice_id    = strtr( $invoice_id_template, $template_tags );

		$invoice_id = apply_filters( 'superfaktura_invoice_id', $invoice_id, $template_tags, $key );

		$order->update_meta_data( 'wc_sf_invoice_' . $key . '_id', $invoice_id );
		$order->save();

		return $invoice_id;
	}



	/**
	 * Create invoice link for "Thank You" page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sf_invoice_link_page( $order_id ) {
		if ( 'yes' === get_option( 'woocommerce_sf_order_received_invoice_link', 'yes' ) ) {
			$order = wc_get_order( $order_id );

			$pdf = $order->get_meta( 'wc_sf_invoice_regular', true );
			if ( $pdf ) {
				echo wp_kses( '<section class="woocommerce-superfaktura"><h2 class="woocommerce-superfaktura__title">' . __( 'Invoice', 'woocommerce-superfaktura' ) . "</h2>\n\n" . '<a href="' . esc_attr( $pdf ) . '" target="_blank">' . __( 'Download invoice', 'woocommerce-superfaktura' ) . "</a></section>\n\n", $this->allowed_tags );
				return;
			}

			$pdf = $order->get_meta( 'wc_sf_invoice_proforma', true );
			if ( $pdf ) {
				echo wp_kses( '<section class="woocommerce-superfaktura"><h2 class="woocommerce-superfaktura__title">' . __( 'Proforma invoice', 'woocommerce-superfaktura' ) . "</h2>\n\n" . '<a href="' . esc_attr( $pdf ) . '" target="_blank">' . __( 'Download proforma invoice', 'woocommerce-superfaktura' ) . "</a></section>\n\n", $this->allowed_tags );
			}
		}
	}


	/**
	 * Get invoice data.
	 *
	 * @param int $order_id Order ID.
	 */
	public function get_invoice_data( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$pdf = $order->get_meta( 'wc_sf_invoice_regular', true );
		if ( $pdf ) {
			return array(
				'type'       => 'regular',
				'pdf'        => $pdf,
				'invoice_id' => (int) $order->get_meta( 'wc_sf_internal_regular_id', true ),
			);
		}

		$pdf = $order->get_meta( 'wc_sf_invoice_proforma', true );
		if ( $pdf ) {
			return array(
				'type'       => 'proforma',
				'pdf'        => $pdf,
				'invoice_id' => (int) $order->get_meta( 'wc_sf_internal_proforma_id', true ),
			);
		}

		return false;
	}



	/**
	 * Get available order statuses.
	 */
	public function get_order_statuses() {
		if ( function_exists( 'wc_order_status_manager_get_order_status_posts' ) ) {
			$wc_order_statuses = array_reduce(
				wc_order_status_manager_get_order_status_posts(),
				function( $result, $item ) {
					$result[ $item->post_name ] = $item->post_title;
					return $result;
				},
				array()
			);

			return $wc_order_statuses;
		}

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$wc_get_order_statuses = wc_get_order_statuses();

			return $this->alter_wc_statuses( $wc_get_order_statuses );
		}

		$order_status_terms = get_terms( 'shop_order_status', 'hide_empty=0' );

		$shop_order_statuses = array();
		if ( ! is_wp_error( $order_status_terms ) ) {
			foreach ( $order_status_terms as $term ) {
				$shop_order_statuses[ $term->slug ] = $term->name;
			}
		}

		return $shop_order_statuses;
	}



	/**
	 * Modify order statuses.
	 *
	 * @param array $array Order statuses.
	 */
	public function alter_wc_statuses( $array ) {
		$new_array = array();
		foreach ( $array as $key => $value ) {
			$new_array[ substr( $key, 3 ) ] = $value;
		}

		return $new_array;
	}



	/**
	 * Check if order is paid.
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_is_paid( $order ) {

		$is_paid              = false;
		$set_as_paid_statuses = get_option( 'woocommerce_sf_invoice_set_as_paid_statuses', false );

		if ( class_exists( 'WC_Order_Status_Manager_Order_Status' ) ) {

			// Compatibility with WooCommerce Order Status Manager plugin.
			$order_status = new WC_Order_Status_Manager_Order_Status( $order->get_status() );

			if ( false !== $set_as_paid_statuses ) {
				if ( in_array( $order_status->get_slug(), $set_as_paid_statuses, true ) || $order_status->is_paid() ) {
					$is_paid = true;
				}
			} else {

				// Backward compatibility with previous options woocommerce_sf_invoice_regular_processing_set_as_paid and woocommerce_sf_invoice_regular_dont_set_as_paid.
				switch ( $order_status->get_slug() ) {

					case 'processing':
						if ( 'yes' === get_option( 'woocommerce_sf_invoice_regular_processing_set_as_paid', 'no' ) ) {
							$is_paid = true;
						}
						break;

					case 'completed':
						if ( 'no' === get_option( 'woocommerce_sf_invoice_regular_dont_set_as_paid', 'no' ) ) {
							$is_paid = true;
						}
						break;

					default:
						$is_paid = $order_status->is_paid();
						break;
				}
			}
		} else {

			// Default WooCommerce order statuses.
			if ( false !== $set_as_paid_statuses ) {
				if ( in_array( $order->get_status(), $set_as_paid_statuses, true ) ) {
					$is_paid = true;
				}
			} else {

				// Backward compatibility with previous options woocommerce_sf_invoice_regular_processing_set_as_paid and woocommerce_sf_invoice_regular_dont_set_as_paid.
				switch ( $order->get_status() ) {

					case 'processing':
						if ( 'yes' === get_option( 'woocommerce_sf_invoice_regular_processing_set_as_paid', 'no' ) ) {
							$is_paid = true;
						}
						break;

					case 'completed':
						if ( 'no' === get_option( 'woocommerce_sf_invoice_regular_dont_set_as_paid', 'no' ) ) {
							$is_paid = true;
						}
						break;
				}
			}
		}

		return apply_filters( 'woocommerce_sf_order_is_paid', $is_paid, $order );
	}



	/**
	 * Convert string to plain text.
	 *
	 * @param string $string String.
	 */
	public function convert_to_plaintext( $string ) {
		return html_entity_decode( wp_strip_all_tags( $string ), ENT_QUOTES, get_option( 'blog_charset' ) );
	}



	/**
	 * Test SuperFaktura API connection.
	 */
	public function wc_sf_api_test() {

		$api = $this->sf_api(
			array(
				'woocommerce_sf_lang'       => sanitize_text_field( wp_unslash( $_POST['woocommerce_sf_lang'] ?? '' ) ),
				'woocommerce_sf_email'      => sanitize_text_field( wp_unslash( $_POST['woocommerce_sf_email'] ?? '' ) ),
				'woocommerce_sf_apikey'     => sanitize_text_field( wp_unslash( $_POST['woocommerce_sf_apikey'] ?? '' ) ),
				'woocommerce_sf_company_id' => sanitize_text_field( wp_unslash( $_POST['woocommerce_sf_company_id'] ?? '' ) ),
				'woocommerce_sf_sandbox'    => sanitize_text_field( wp_unslash( $_POST['woocommerce_sf_sandbox'] ?? '' ) ),
			)
		);

		$result = $api->getSequences();

		if ( empty( $result ) ) {
			$error = $api->getLastError();
			echo wp_kses( $error['message'], $this->allowed_tags );
		} else {
			echo 'OK';
		}

		wp_die();
	}



	/**
	 * Get URL status.
	 */
	public function wc_sf_url_check() {
		if ( ! check_ajax_referer( 'ajax_validation', 'security' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die();
		}

		$result = wp_safe_remote_get( $_POST['url'] );
		if ( is_wp_error( $result ) ) {
			echo 'ERROR';
		}
		else {
			echo $result['response']['code'] ?? 'ERROR';
		}

		wp_die();
	}
}
