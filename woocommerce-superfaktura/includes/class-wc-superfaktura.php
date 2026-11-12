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
	public $version = '1.62.0';

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
	 * VAT numbers checked in this request: normalized number => 'valid', 'invalid', 'verified_earlier' or 'unreachable'.
	 *
	 * @var array
	 */
	private $vies_states = array();

	/**
	 * Why the last validate_eu_vat_number() returned null: 'busy' (VIES busy for a moment), 'outage' (VIES did not
	 * answer or is down) or '' (no answer was needed, or the number itself was the problem).
	 *
	 * @var string
	 */
	private $vies_failure = '';

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
	 * Sending of invoices and credit notes via Peppol (eFaktúra).
	 *
	 * @var WC_SF_Peppol
	 */
	public $peppol;

	/**
	 * Instance of WC_SF_Bulk.
	 *
	 * @var WC_SF_Bulk
	 */
	public $bulk;

	/**
	 * Instance of WC_SF_Checkout_Block.
	 *
	 * @var WC_SF_Checkout_Block
	 */
	public $checkout_block;

	/**
	 * Settings export, import and reset.
	 *
	 * @var WC_SF_Tools
	 */
	public $tools;

	/**
	 * Support information on the Help section (admin only).
	 *
	 * @var WC_SF_Support_Info
	 */
	public $support_info;

	/**
	 * WooCommerce Subscriptions company data sync.
	 *
	 * @var WC_SF_Subscriptions
	 */
	public $subscriptions;

	/**
	 * Admin notices (eFaktúra announcement, failed documents).
	 *
	 * @var WC_SF_Notices
	 */
	public $notices;

	/**
	 * Setup wizard, only in the administration.
	 *
	 * @var WC_SF_Setup_Wizard|null
	 */
	public $setup_wizard;



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
			$this->setup_wizard = new WC_SF_Setup_Wizard($this);
			$this->support_info = new WC_SF_Support_Info($this);
        }
		$this->email = new WC_SF_Email($this);
		$this->invoice_generator = new WC_SF_Invoice($this);
		$this->peppol = new WC_SF_Peppol($this);
		$this->checkout_block = new WC_SF_Checkout_Block($this);
		$this->bulk = new WC_SF_Bulk($this);
		$this->tools = new WC_SF_Tools($this);
		$this->subscriptions = new WC_SF_Subscriptions($this);
		$this->notices = new WC_SF_Notices($this);
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
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( WC_SF_FILE_PATH ) );
			wp_die( __( 'Please install WooCommerce before activating SuperFaktura WooCommerce plugin.', 'woocommerce-superfaktura' ), 'Plugin dependency check', array( 'back_link' => true ) );
		}

		// Offer the setup wizard on sites without API credentials. Updates do not run this hook.
		WC_SF_Setup_Wizard::flag_new_activation( $network_wide );
	}



	/**
	 * Fired when the plugin is deactivated.
	 *
	 * @param boolean $network_wide True if WPMU superadmin uses "Network Activate" action, false if WPMU is disabled or plugin is activated on an individual blog.
	 */
	public static function deactivate( $network_wide ) {
		// Stop the daily download of announcements, on every site of the network when deactivated network-wide.
		if ( $network_wide && is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				WC_SF_Notices::unschedule_messages();
				restore_current_blog();
			}
			return;
		}

		WC_SF_Notices::unschedule_messages();
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
			add_action( 'woocommerce_checkout_update_customer', array( $this, 'checkout_update_customer' ), 10, 2 );

			// Re-apply the reverse-charge exemption on every classic-checkout cart recalculation.
			// Setting it once in checkout_update_customer is not enough: WooCommerce recalculates the
			// cart again later in process_checkout() (resetting the session customer's is_vat_exempt
			// flag), so without this the order is created with VAT even for a valid EU VAT number.
			// Mirrors the block-checkout safety net in WC_SF_Checkout_Block.
			add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_vat_exemption_classic_before_cart_totals' ), 1, 1 );

			add_filter( 'woocommerce_admin_billing_fields', array( $this, 'woocommerce_admin_billing_fields' ), 10, 1 );
			add_action( 'woocommerce_process_shop_order_meta', array( $this, 'woocommerce_process_shop_order_meta' ), 10, 2 );

			// Subscriptions use their own admin save hook, with the same ($id, $object) signature.
			add_action( 'woocommerce_process_shop_subscription_meta', array( $this, 'woocommerce_process_shop_order_meta' ), 10, 2 );

			// Add editable fields to user profile in admin.
			add_filter( 'woocommerce_customer_meta_fields' , array( $this, 'woocommerce_customer_meta_fields' ) );

			// Add custom fields values to customer details.
			add_filter( 'woocommerce_ajax_get_customer_details', array( $this, 'woocommerce_ajax_get_customer_details' ), 10, 3 );
		}

		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'checkout_order_meta' ) );

		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_orders_actions' ), 10, 2 );

		$wc_get_order_statuses = $this->get_order_statuses();
		foreach ( $wc_get_order_statuses as $key => $status ) {
			add_action( 'woocommerce_order_status_' . $key, array( $this, 'sf_new_invoice' ), 5 );
		}

		// Note a VAT number VIES could not verify at checkout. Priority 4, before the invoice created at checkout (priority 5).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'note_unverified_vat_number' ), 4 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'note_unverified_vat_number' ), 4 );

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sf_new_invoice' ), 5 );

		// Credit notes for refunds. Priority 5, before WooCommerce sends the refund e-mail (priority 10), so it can carry the credit note.
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'sf_refund_credit_note' ), 5, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'sf_refund_credit_note' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'sf_cancelled_order_credit_note' ), 5 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'sf_refund_deleted' ), 10, 2 );

		add_action( 'woocommerce_thankyou', array( $this, 'sf_invoice_link_page' ) );
		add_action( 'wp_loaded', array( $this, 'set_order_as_paid' ) );
		add_action( 'sf_fetch_related_invoice', array( $this, 'fetch_related_invoice'), 10, 1 );
		add_action( 'sf_retry_generate_invoice', array( $this, 'retry_generate_invoice'), 10, 3 );
		add_action( 'wp_ajax_wc_sf_generate_secret_key', array( $this, 'generate_secret_key' ) );

        if ( is_admin() ) {
			$this->admin->init();
        }

		$this->email->init();
		$this->checkout_block->init();
		$this->bulk->init();
		$this->notices->init();
		$this->peppol->init();
	}



	/**
	 * Generate secret key.
	 */
	public function generate_secret_key() {
		check_ajax_referer( 'wc_sf' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1, 403 );
		}

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
		if ( ! is_array( $meta_key ) ) {
			$meta_key = array( $meta_key );
		}

		foreach ( $meta_key as $_meta_key ) {

			// Get orders by metadata if HPOS is enabled.
			if ( $this->hpos_enabled() ) {
				$orders = wc_get_orders(
					array(
						'meta_query' => array(
							array(
								'key'   => $_meta_key,
								'value' => $meta_value
							)
						),
					)
				);
			}

			// Get orders by metadata if HPOS is disabled.
			else {
				$query = new WP_Query(
					array(
						'post_type'   => 'shop_order',
						'post_status' => array_keys( wc_get_order_statuses() ),
						'meta_query'  => array(
							array(
								'key'   => $_meta_key,
								'value' => $meta_value
							)
						)
					)
				);
				$orders = array_map('wc_get_order', $query->posts);
			}

			if ( count( $orders ) > 0 ) {
				return $orders;
			}
		}

		return [];
	}



	/**
	 * Set order as paid.
	 */
	public function set_order_as_paid() {
		if ( ! isset( $_GET['callback'] ) || 'wc_sf_order_paid' !== $_GET['callback'] ) {
			return;
		}

		if ( ! isset( $_GET['invoice_id'] ) || ! isset( $_GET['secret_key'] ) ) {
			$this->wc_sf_log(
				array(
					'request_type'     => 'callback_paid',
					'response_status'  => 908,
					'response_message' => 'Missing parameters',
				)
			);
			exit();
		}
	
		$invoice_id      = sanitize_text_field( wp_unslash( $_GET['invoice_id'] ) );
		$secret_key      = sanitize_text_field( wp_unslash( $_GET['secret_key'] ) );
		$user_secret_key = get_option( 'woocommerce_sf_sync_secret_key', false );

		if ( ! is_numeric( $invoice_id ) || ( false !== $user_secret_key && $secret_key !== $user_secret_key ) ) {
			$this->wc_sf_log(
				array(
					'request_type'     => 'callback_paid',
					'response_status'  => 901,
					'response_message' => 'Incorrect parameters',
				)
			);
			exit();
		}

		// Query order by custom field.
		$orders = $this->get_orders_by_meta( array( 'wc_sf_internal_regular_id', 'wc_sf_internal_proforma_id', 'wc_sf_internal_cancel_id' ), $invoice_id );
		if ( count( $orders ) !== 1 ) {
			$this->wc_sf_log(
				array(
					'request_type'     => 'callback_paid',
					'response_status'  => 904,
					'response_message' => sprintf( 'Order with invoice ID %d not found', $invoice_id ),
				)
			);
			exit();
		}

		$order    = $orders[0];
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

		// Check invoice type.
		if ( ! in_array( $response->{$invoice_id}->Invoice->type, array( 'proforma', 'regular' ) ) ) {
			$this->wc_sf_log(
				array(
					'order_id'         => $order->get_id(),
					'document_type'    => $response->{$invoice_id}->Invoice->type,
					'request_type'     => 'callback_paid',
					'response_status'  => 906,
					'response_message' => sprintf( 'Invoice ID %d type is not proforma or regular', $invoice_id ),
				)
			);
			exit();
		}

		// Check invoice status (1 = not paid, 2 = paid partially, 3 = paid).
		if ( 3 != $response->{$invoice_id}->Invoice->status ) {
			$this->wc_sf_log(
				array(
					'order_id'         => $order->get_id(),
					'document_type'    => $response->{$invoice_id}->Invoice->type,
					'request_type'     => 'callback_paid',
					'response_status'  => 903,
					'response_message' => sprintf( 'Invoice ID %d not paid', $invoice_id ),
				)
			);
			exit();
		}

		// Check the latest InvoicePayment.payment type and ignore 'accreditation', which means the invoice was paid by creating a credit note.
		if ( 'accreditation' === end( $response->{$invoice_id}->InvoicePayment )->payment_type ) {
			$this->wc_sf_log(
				array(
					'order_id'         => $order->get_id(),
					'document_type'    => $response->{$invoice_id}->Invoice->type,
					'request_type'     => 'callback_paid',
					'response_status'  => 907,
					'response_message' => sprintf( 'Invoice ID %d was paid by accreditation', $invoice_id ),
				)
			);
			exit();
		}

		// Get order status (see https://docs.woocommerce.com/document/managing-orders/).
		$order_status  = $order->get_status();
		$sync_statuses = $this->get_sync_order_statuses();
		if ( ! in_array( $order_status, $sync_statuses, true ) ) {
			$this->wc_sf_log(
				array(
					'order_id'         => $order->get_id(),
					'document_type'    => $response->{$invoice_id}->Invoice->type,
					'request_type'     => 'callback_paid',
					'response_status'  => 905,
					'response_message' => sprintf( 'Order status "%s" is not set for automatic pairing', $order_status ),
				)
			);
			exit();
		}

		// WooCommerce changes the status only for orders in its own unpaid statuses. For an order in another status
		// set for automatic pairing, allow that status for this order only. On hold orders are left to WooCommerce as before.
		$valid_statuses = null;
		if ( 'on-hold' !== $order_status ) {
			$paired_order_id = $order->get_id();
			$valid_statuses  = function( $statuses, $paid_order = null ) use ( $order_status, $paired_order_id ) {
				if ( $paid_order instanceof WC_Order && $paid_order->get_id() === $paired_order_id ) {
					$statuses   = (array) $statuses;
					$statuses[] = $order_status;
				}
				return $statuses;
			};
			add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $valid_statuses, 10, 2 );
		}

		// Mark order as paid (see https://woocommerce.wp-a2z.org/oik_api/wc_orderpayment_complete/).
		$order->payment_complete();

		if ( $valid_statuses ) {
			remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $valid_statuses, 10 );
		}

		$this->wc_sf_log(
			array(
				'order_id'     => $order->get_id(),
				'document_type'    => $response->{$invoice_id}->Invoice->type,
				'request_type' => 'callback_paid',
			)
		);

		// Check if related regular invoice was created automatically in SuperFaktura.
		if ( 'proforma' == $response->{$invoice_id}->Invoice->type ) {

			// Only if regular invoice does not already exist in WooCommerce. With eFaktúra on, also for the tax
			// document for the received payment SuperFaktura may create.
			$regular_id = $order->get_meta( 'wc_sf_internal_regular_id', true );
			if ( empty( $regular_id ) || $this->peppol->enabled() ) {

				// Schedule an action (because SuperFaktura calls the callback BEFORE it creates the regular invoice automatically).
				as_schedule_single_action( time() + 300, 'sf_fetch_related_invoice', array( 'proforma_id' => $invoice_id ), 'woocommerce-superfaktura' );
			}
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

		// Only if regular invoice does not already exist in WooCommerce, or for the tax documents of eFaktúra.
		$has_regular = ! empty( $order->get_meta( 'wc_sf_internal_regular_id', true ) );
		if ( $has_regular && ! $this->peppol->enabled() ) {
			return false;
		}

		// Get proforma invoice data from SF API.
		$api      = $this->sf_api();
		$proforma = $api->getInvoiceDetails( $proforma_id );
		if ( ! $proforma || ! isset( $proforma->{$proforma_id} ) || 'proforma' !== $proforma->{$proforma_id}->Invoice->type ) {
			return false;
		}

		// eFaktúra: tax documents for the received payment go via Peppol too.
		$this->peppol->attach_tax_documents( $order, $proforma->{$proforma_id} );

		if ( $has_regular ) {
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

		// The invoice is attached without a log entry, so refresh the failed documents warning.
		WC_SF_Notices::flush_errors();

		$this->peppol->document_attached( $order, 'regular' );

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

			// The document is attached without a log entry, so refresh the failed documents warning.
			WC_SF_Notices::flush_errors();

			$this->peppol->document_attached( $order, $type );

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
	 * Initialize SuperFaktura API.
	 *
	 * @param array $credentials SuperFaktura API credentials.
	 */
	public function sf_api( $credentials = array() ) {

		$sf_lang       = ( isset( $credentials['woocommerce_sf_lang'] ) ) ? $credentials['woocommerce_sf_lang'] : get_option( 'woocommerce_sf_lang', 'sk' );
		$sf_email      = ( isset( $credentials['woocommerce_sf_email'] ) ) ? $credentials['woocommerce_sf_email'] : get_option( 'woocommerce_sf_email' );
		$sf_key        = ( isset( $credentials['woocommerce_sf_apikey'] ) ) ? $credentials['woocommerce_sf_apikey'] : get_option( 'woocommerce_sf_apikey' );
		$sf_company_id = ( isset( $credentials['woocommerce_sf_company_id'] ) ) ? $credentials['woocommerce_sf_company_id'] : get_option( 'woocommerce_sf_company_id', '' );
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
	 * @return bool False when the deletion was refused by the sf_clean_invoice_items_allowed filter.
	 */
	public function sf_clean_invoice_items( $invoice_id, $api ) {
		$response = $api->invoice( $invoice_id );
		if ( ! isset( $response->error ) || 0 == $response->error ) {
			if ( isset( $response->InvoiceItem ) && is_array( $response->InvoiceItem ) ) {
				$delete_item_ids = array();
				foreach ( $response->InvoiceItem as $item ) {
					$delete_item_ids[] = $item->id;
				}

				/**
				 * Last chance to refuse replacing the document's items, now that their number is known.
				 *
				 * @since 1.54.0
				 *
				 * @param bool  $allowed         Whether to delete the items and continue regenerating.
				 * @param int   $invoice_id      Invoice ID.
				 * @param array $delete_item_ids IDs of the items on the document.
				 */
				if ( ! apply_filters( 'sf_clean_invoice_items_allowed', true, $invoice_id, $delete_item_ids ) ) {
					return false;
				}

				// Delete items in chunks of 50.
				$delete_item_ids_chunks = array_chunk( $delete_item_ids, 50 );
				foreach ( $delete_item_ids_chunks as $delete_item_ids_chunk ) {
					$delete_response = $api->deleteInvoiceItem( $invoice_id, $delete_item_ids_chunk );
				}
			}
		}

		return true;
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
			 *
			 * @since 1.10.11
			 *
			 * @param bool     $generate_invoice Whether to create the document now, from the order status settings.
			 * @param WC_Order $order            Order.
			 * @param string   $type             Document type: 'proforma', 'regular' or 'cancel'.
			 * @param string   $payment_method   Payment method ID of the order.
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

		foreach ( $this->regenerated_document_types( $order ) as $type ) {
			$sf_id = $order->get_meta( 'wc_sf_internal_' . $type . '_id', true );
			if ( ! empty( $sf_id ) ) {
				$generated = $this->invoice_generator->generate_invoice( $order, $type );
				if ( is_wp_error( $generated ) && 'peppol_sent' === $generated->get_error_code() ) {
					// A document sent via Peppol is not regenerated, say so.
					return $generated;
				}
				$result = $result && $generated;
			}
		}

		return $result;
	}



	/**
	 * Document types of an order that regeneration edits.
	 *
	 * When credit notes for refunds are on, an issued credit note stays as it is, and so does the invoice once
	 * a credit note corrects it. A document sent via Peppol (eFaktúra) stays as it is too.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	public function regenerated_document_types( $order ) {
		if ( ! $this->auto_credit_notes() ) {
			$types = array( 'proforma', 'regular', 'cancel' );
		} elseif ( $order->get_meta( 'wc_sf_internal_cancel_id', true ) || WC_SF_Invoice::get_refund_credit_notes( $order ) ) {
			$types = array( 'proforma' );
		} else {
			$types = array( 'proforma', 'regular' );
		}

		return array_values(
			array_filter(
				$types,
				function( $type ) use ( $order ) {
					return ! $this->peppol->is_locked( $order, $order->get_meta( 'wc_sf_internal_' . $type . '_id', true ) );
				}
			)
		);
	}



	/**
	 * Whether a credit note is created automatically for every refund and for a cancelled order with an invoice.
	 *
	 * @return bool
	 */
	public function auto_credit_notes() {
		return 'yes' === get_option( 'woocommerce_sf_cancel_auto', 'no' );
	}



	/**
	 * Create the credit note of a refund.
	 *
	 * Runs before WooCommerce sends the refund e-mail, so the e-mail can carry the credit note.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function sf_refund_credit_note( $order_id, $refund_id ) {
		if ( ! $this->auto_credit_notes() ) {
			return;
		}

		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! ( $refund instanceof WC_Order_Refund ) || (int) $refund->get_parent_id() !== (int) $order->get_id() ) {
			return;
		}

		// A credit note corrects an invoice; a proforma invoice is not a tax document.
		if ( ! $order->get_meta( 'wc_sf_internal_regular_id', true ) ) {
			// Translators: %d Refund ID.
			$order->add_order_note( sprintf( __( 'No credit note was created for refund #%d, because the order has no invoice.', 'woocommerce-superfaktura' ), $refund_id ) );
			return;
		}

		// The whole invoice is already credited, for example after the order was cancelled.
		if ( $order->get_meta( 'wc_sf_internal_cancel_id', true ) ) {
			// Translators: %d Refund ID.
			$order->add_order_note( sprintf( __( 'No credit note was created for refund #%d, because the order already has a credit note for the whole invoice.', 'woocommerce-superfaktura' ), $refund_id ) );
			return;
		}

		/**
		 * Filters whether to create a credit note automatically, for a refund or for a cancelled order with an invoice.
		 *
		 * @since 1.60.0
		 *
		 * @param bool                 $create Whether to create the credit note. Default true.
		 * @param WC_Order             $order  Order.
		 * @param WC_Order_Refund|null $refund Refund, or null for the credit note of a cancelled order.
		 */
		if ( ! apply_filters( 'sf_auto_credit_note', true, $order, $refund ) ) {
			return;
		}

		$result = $this->invoice_generator->generate_invoice( $order, 'cancel', false, $refund );
		if ( true !== $result ) {
			// Translators: %d Refund ID.
			$order->add_order_note( sprintf( __( 'Credit note for refund #%d was not created. You can create it in the Invoices box.', 'woocommerce-superfaktura' ), $refund_id ) );
		}
	}



	/**
	 * Create a credit note for the whole invoice when an order with an invoice is cancelled without a refund.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sf_cancelled_order_credit_note( $order_id ) {
		if ( ! $this->auto_credit_notes() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( 'wc_sf_internal_regular_id', true ) || $order->get_meta( 'wc_sf_internal_cancel_id', true ) || $order->get_refunds() ) {
			return;
		}

		/** This filter is documented in includes/class-wc-superfaktura.php */
		if ( ! apply_filters( 'sf_auto_credit_note', true, $order, null ) ) {
			return;
		}

		if ( true !== $this->invoice_generator->generate_invoice( $order, 'cancel' ) ) {
			$order->add_order_note( __( 'Credit note for the cancelled order was not created. You can create it in the Invoices box.', 'woocommerce-superfaktura' ) );
		}
	}



	/**
	 * Note in the order that the credit note of a deleted refund stays in SuperFaktura.
	 *
	 * @param int $refund_id Refund ID.
	 * @param int $order_id  Order ID.
	 */
	public function sf_refund_deleted( $refund_id, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$credit_notes = WC_SF_Invoice::get_refund_credit_notes( $order );
		if ( empty( $credit_notes[ $refund_id ] ) ) {
			return;
		}

		// Translators: %1$d Refund ID, %2$s Credit note number.
		$order->add_order_note( sprintf( __( 'Refund #%1$d was deleted. Its credit note %2$s stays in SuperFaktura.', 'woocommerce-superfaktura' ), $refund_id, $credit_notes[ $refund_id ]['number'] ) );
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

		if ( isset( $log_data['request_type'] ) && in_array( $log_data['request_type'], WC_SF_Notices::ERROR_REQUEST_TYPES, true ) ) {
			WC_SF_Notices::flush_errors();
		}
	}



	/**
	 * Explain a log entry in plain language, so that shop owners understand the error without contacting support.
	 *
	 * @param array $row Log row with request_type, response_status and response_message.
	 * @return string Explanation (may contain a link) or an empty string when there is nothing to add.
	 */
	public function get_log_explanation( $row ) {
		$request_type = isset( $row['request_type'] ) ? (string) $row['request_type'] : '';
		$status       = isset( $row['response_status'] ) ? (int) $row['response_status'] : 0;
		$message      = isset( $row['response_message'] ) ? (string) $row['response_message'] : '';
		$explanation  = '';

		if ( 'callback_paid' === $request_type ) {
			switch ( $status ) {
				case 908:
					$explanation = __( 'The automatic pairing URL was called without an invoice ID or secret key. SuperFaktura sends both, so the request most likely did not come from SuperFaktura.', 'woocommerce-superfaktura' );
					break;

				case 901:
					$explanation = __( 'The invoice ID or secret key in the request is not valid. Check that the Secret Key in SuperFaktura settings matches the one in the plugin settings.', 'woocommerce-superfaktura' );
					break;

				case 904:
					$explanation = __( 'SuperFaktura reported a payment for an invoice that does not belong to exactly one order in this shop. Either no order has it (for example an invoice issued directly in SuperFaktura, or an order in the trash), or several orders have the same invoice.', 'woocommerce-superfaktura' );
					break;

				case 902:
					$explanation = __( 'SuperFaktura did not return this invoice. It may have been deleted, the plugin may be connected to a different SuperFaktura account or company, or the connection to SuperFaktura failed.', 'woocommerce-superfaktura' );
					break;

				case 906:
					$explanation = __( 'Only payments of invoices and proforma invoices mark the order as paid.', 'woocommerce-superfaktura' );
					break;

				case 903:
					$explanation = __( 'The invoice is not paid or is paid only partially, so the order was not marked as paid.', 'woocommerce-superfaktura' );
					break;

				case 907:
					$explanation = __( 'The invoice was settled by a credit note, so the order was not marked as paid.', 'woocommerce-superfaktura' );
					break;

				case 905:
					// Since 1.57.0 the message contains the order status, older entries do not.
					$order_status = preg_match( '/^Order status "([^"]+)"/', $message, $matches ) ? $matches[1] : '';

					if ( $order_status && in_array( $order_status, wc_get_is_paid_statuses(), true ) ) {
						$explanation = __( 'The order was already paid, so its status was not changed. No action is needed.', 'woocommerce-superfaktura' );
						break;
					}

					$explanation = sprintf(
						// Translators: %s Link to the setting.
						__( 'SuperFaktura reported a payment, but the order was not in any of the statuses set in %s, so it was not marked as paid. Add the status there if such orders should be marked as paid too.', 'woocommerce-superfaktura' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=superfaktura&section=integration' ) ) . '">' . esc_html__( 'Order statuses for automatic pairing', 'woocommerce-superfaktura' ) . '</a>'
					);

					if ( ! $order_status ) {
						$explanation .= ' ' . __( 'If the order is already paid, no action is needed.', 'woocommerce-superfaktura' );
					}
					break;
			}
		} elseif ( 'eu_vat_number' === $request_type ) {
			if ( 0 === strpos( $message, 'cURL error' ) ) {
				$explanation = __( 'The server could not connect to VIES, so the VAT number could not be verified. If this happens often, contact your hosting provider.', 'woocommerce-superfaktura' );
			} elseif ( 500 <= $status || preg_match( '/SERVICE_UNAVAILABLE|MS_UNAVAILABLE|TIMEOUT|MAX_CONCURRENT_REQ/', $message ) ) {
				$explanation = __( 'VIES was temporarily unavailable, so the VAT number could not be verified.', 'woocommerce-superfaktura' );
			}
		} elseif ( 0 === strpos( $request_type, 'peppol_' ) && false !== strpos( $message, 'REGISTRATION_MISSING' ) ) {
			$explanation = sprintf(
				// Translators: %s Link to the eFaktúra page of SuperFaktura.
				__( 'eFaktúra is not activated for the company in SuperFaktura. %s, or turn off sending via Peppol in the plugin settings.', 'woocommerce-superfaktura' ),
				'<a href="https://www.superfaktura.sk/efaktura/">' . esc_html__( 'Choose SuperFaktura as your digital postman', 'woocommerce-superfaktura' ) . '</a>'
			);
		} elseif ( 0 === strpos( $request_type, 'peppol_' ) && false !== strpos( $message, 'peppol.precheck_failed' ) ) {
			$explanation = __( 'SuperFaktura found problems that prevent sending the document via Peppol. Correct them, then send the document again from the Invoices box of the order.', 'woocommerce-superfaktura' );
		} elseif ( 'peppol_status' === $request_type && WC_SF_Peppol::LOG_STATUS_FAILED === $status ) {
			$explanation = __( 'The document did not reach the customer via Peppol. Check the customer details with the customer and agree how to deliver the document.', 'woocommerce-superfaktura' );
		} elseif ( 401 === $status ) {
			$explanation = __( 'Check the API email, API key and Company ID in the plugin settings, or use the Test API connection button.', 'woocommerce-superfaktura' );
		} elseif ( 0 === strpos( $message, 'cURL error' ) ) {
			$explanation = __( 'The server could not connect to SuperFaktura. If this happens often, contact your hosting provider.', 'woocommerce-superfaktura' );
		} elseif ( $this->is_concurrency_message( $message ) ) {
			$explanation = sprintf(
				// Translators: %s Setting name.
				__( 'The plugin stopped this request, because another request for the same document was running at the same time (setting %s). Usually the other request has already created or updated the document. If not, create or regenerate it again.', 'woocommerce-superfaktura' ),
				__( 'Prevent document duplicity', 'woocommerce-superfaktura' )
			);
		}

		/**
		 * Filters the explanation shown with an API log entry and in the failed document notice.
		 *
		 * @since 1.57.0
		 *
		 * @param string $explanation Explanation, may contain a link. Empty when there is nothing to add.
		 * @param array  $row         Log row with request_type, response_status and response_message.
		 */
		$explanation = apply_filters( 'sf_log_explanation', $explanation, $row );

		return is_string( $explanation ) ? $explanation : '';
	}



	/**
	 * Check if a log message comes from the plugin's own concurrency check (setting Prevent document duplicity).
	 *
	 * @param string $message Log message, possibly translated and followed by the file and line.
	 * @return bool
	 */
	private function is_concurrency_message( $message ) {
		$texts = array(
			'Request failed because of concurrency check.',
			__( 'Request failed because of concurrency check.', 'woocommerce-superfaktura' ),
			// Slovak and Czech translations, for entries logged in another admin language.
			'Požiadavka zlyhala z dôvodu súbežných API volaní.',
			'Požadavek selhal z důvodu souběžných API volání.',
		);

		foreach ( $texts as $text ) {
			if ( '' !== $text && false !== strpos( $message, $text ) ) {
				return true;
			}
		}

		return false;
	}



	/**
	 * Order statuses that cannot be used for automatic pairing.
	 *
	 * Paid orders have nothing to pair, refunded and draft orders are not waiting for a payment. On hold is the
	 * original pairing status and is never excluded.
	 *
	 * @return string[]
	 */
	public function get_sync_excluded_order_statuses() {
		return array_diff( array_merge( wc_get_is_paid_statuses(), array( 'refunded', 'checkout-draft' ) ), array( 'on-hold' ) );
	}



	/**
	 * Order statuses in which a payment paired in SuperFaktura marks the order as paid.
	 *
	 * @return string[]
	 */
	public function get_sync_order_statuses() {
		$statuses = get_option( 'woocommerce_sf_sync_order_statuses', array( 'on-hold' ) );

		// An invalid stored value falls back to the default instead of disabling automatic pairing.
		if ( ! is_array( $statuses ) ) {
			$statuses = array( 'on-hold' );
		}

		return array_values( array_diff( $statuses, $this->get_sync_excluded_order_statuses() ) );
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

			if ( false === is_string( $value ) ) {
				continue;
			}

			$result[] = sprintf( '%s: %s', wc_attribute_label( $attribute, $product ), $value );
		}

		/**
		 * Filters the separator between product attributes in the item description on the document.
		 *
		 * @since 1.20.5
		 *
		 * @param string $separator Separator. Default ', '.
		 */
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
	 * Map of customer countries to the SuperFaktúra invoice language of that country.
	 *
	 * Only countries whose language SuperFaktúra supports; other countries use the fallback language from the settings.
	 *
	 * @return array Country code => SuperFaktúra language code.
	 */
	public function get_language_country_map() {
		$country_map = array(
			'SK' => 'slo',
			'CZ' => 'cze',
			'DE' => 'deu',
			'AT' => 'deu',
			'LI' => 'deu',
			'NL' => 'nld',
			'HR' => 'hrv',
			'HU' => 'hun',
			'PL' => 'pol',
			'RO' => 'rom',
			'RU' => 'rus',
			'SI' => 'slv',
			'ES' => 'spa',
			'MX' => 'spa',
			'AR' => 'spa',
			'CO' => 'spa',
			'CL' => 'spa',
			'PE' => 'spa',
			'VE' => 'spa',
			'EC' => 'spa',
			'GT' => 'spa',
			'CU' => 'spa',
			'BO' => 'spa',
			'DO' => 'spa',
			'HN' => 'spa',
			'PY' => 'spa',
			'SV' => 'spa',
			'NI' => 'spa',
			'CR' => 'spa',
			'PA' => 'spa',
			'UY' => 'spa',
			'IT' => 'ita',
			'SM' => 'ita',
			'UA' => 'ukr',
			'GB' => 'eng',
			'IE' => 'eng',
			'US' => 'eng',
			'AU' => 'eng',
			'NZ' => 'eng',
		);

		/**
		 * Filter the map of customer countries to invoice languages used by the "Customer country" invoice language.
		 *
		 * @since 1.56.0
		 *
		 * @param array $country_map Country code => SuperFaktúra language code (slo, cze, eng, deu, nld, hrv, hun, pol, rom, rus, slv, spa, ita, ukr).
		 */
		return apply_filters( 'sf_invoice_language_country_map', $country_map );
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

			case 'country':
				$order   = wc_get_order( $order_id );
				$country = '';
				if ( $order ) {
					$country = $order->get_billing_country() ? $order->get_billing_country() : $order->get_shipping_country();
				}

				$country_map = $this->get_language_country_map();
				if ( isset( $country_map[ $country ] ) ) {
					$language = $country_map[ $country ];
				} else {
					// SuperFaktúra has no language for this country.
					$language = get_option( 'woocommerce_sf_invoice_language_fallback', 'endpoint' );
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

		/**
		 * Filters the language of the document.
		 *
		 * @since 1.20.6
		 *
		 * @param string $language                        SuperFaktura language code, such as 'slo', 'cze' or 'eng'.
		 * @param int    $order_id                        Order ID.
		 * @param string $woocommerce_sf_invoice_language Invoice language setting of the plugin.
		 */
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

		/** This filter is documented in includes/class-wc-superfaktura.php */
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

				// Add "TAX ID #" field (DIC).
				if ( 'no' !== get_option( 'woocommerce_sf_add_company_billing_fields_tax', false ) ) {
					$new_fields['billing_company_wi_tax'] = array(
						'type'     => 'text',
						'label'    => __( 'TAX ID #', 'woocommerce-superfaktura' ),
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

			case 'billing_company_wi_tax':
				$replace = ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_tax', 'optional' ) );
				break;

			case 'billing_company_wi_vat':
				$replace = ( 'required' === get_option( 'woocommerce_sf_add_company_billing_fields_vat', 'optional' ) );
				break;
		}

		if ($replace) {
			$field = str_replace( '&nbsp;<span class="optional">(' . esc_html__( 'optional', 'woocommerce' ) . ')</span>', '&nbsp;<abbr class="required" title="' . esc_html__( 'required', 'woocommerce' ) . '">*</abbr>', $field );
		}

		return $field;
	}



	/**
	 * VIES answers that mean the member state is busy for a moment. The check is asked again after a short pause.
	 */
	const VIES_BUSY_ERRORS = array( 'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ' );

	/**
	 * VIES answers that mean the service or the member state is down. Not asked again for two minutes.
	 */
	const VIES_OUTAGE_ERRORS = array( 'MS_UNAVAILABLE', 'SERVICE_UNAVAILABLE', 'TIMEOUT' );

	/*
	 * Validate EU VAT Number
	 */
	public function validate_eu_vat_number( $vat_number ) {
		$this->vies_failure = '';

		$sanitized_vat_number = preg_replace( '/[^A-Z0-9]/i', '', sanitize_text_field( $vat_number ) );
		$country = substr( $sanitized_vat_number, 0, 2 );
		$vatno = substr( $sanitized_vat_number, 2 );

		if ( ! in_array( strtoupper( $country ), $this->eu_vat_countries ) ) {
			return null;
		}

		$url = "https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{$country}/vat/{$vatno}";

		// A busy member state answers at once and usually accepts the request a moment later, so these
		// answers are asked again after a short pause. A connection that fails is not, it already took its timeout.
		$retry_delays = array( 1, 2 );
		$httpversion  = null;

		for ( $attempt = 0; ; $attempt++ ) {
			try {
				// First attempt: let cURL negotiate the protocol (usually HTTP/2).
				$response = $this->vies_remote_get( $url, $httpversion );

				// Many hosting setups fail to talk to ec.europa.eu over HTTP/2 and abort the
				// connection ("cURL error 56: Connection closed abruptly"). Retry once over
				// HTTP/1.1, which is far more tolerant of strict firewalls and proxies.
				// A busy answer is then asked again over HTTP/1.1 straight away.
				if ( is_wp_error( $response ) && null === $httpversion ) {
					$httpversion = '1.1';
					$response    = $this->vies_remote_get( $url, $httpversion );
				}

				if ( is_wp_error( $response ) ) {
					$this->wc_sf_log(
						array(
							'request_type'     => 'eu_vat_number',
							'response_status'  => $response->get_error_code(),
							'response_message' => $response->get_error_message(),
						)
					);

					$this->vies_failure = 'outage';

					return null;
				}

				$http_response_code = wp_remote_retrieve_response_code( $response );
				if ( substr( (string) $http_response_code, 0, 1 ) !== '2' ) {
					$this->wc_sf_log(
						array(
							'request_type'     => 'eu_vat_number',
							'response_status'  => $http_response_code,
							'response_message' => get_status_header_desc( $http_response_code ),
						)
					);

					// A server error or too many requests is VIES. Another 4xx is the request, e.g. a number without digits.
					if ( 500 <= (int) $http_response_code || 429 === (int) $http_response_code ) {
						$this->vies_failure = 'outage';
					}

					return null;
				}

				$result = json_decode( wp_remote_retrieve_body( $response ) );

			} catch ( Exception $e ) {
				$this->wc_sf_log(
					array(
						'request_type'     => 'eu_vat_number',
						'response_status'  => 911,
						'response_message' => $this->exceptionHandling($e),
					)
				);

				$this->vies_failure = 'outage';

				return null;
			}

			// Treat an unexpected/unparseable response as "could not validate" rather than invalid.
			if ( ! is_object( $result ) || ! isset( $result->userError ) ) {
				$this->wc_sf_log(
					array(
						'request_type'     => 'eu_vat_number',
						'response_status'  => $http_response_code,
						'response_message' => 'Unexpected VIES response',
					)
				);

				$this->vies_failure = 'outage';

				return null;
			}

			if ( 'VALID' === $result->userError ) {
				return true;
			}

			// Only a genuine "INVALID" verdict means the VAT # is not valid.
			// Every other userError (MS_MAX_CONCURRENT_REQ, GLOBAL_MAX_CONCURRENT_REQ, MS_UNAVAILABLE, SERVICE_UNAVAILABLE, TIMEOUT) must not be mistaken for an invalid number.
			if ( 'INVALID' === $result->userError ) {
				return false;
			}

			if ( isset( $retry_delays[ $attempt ] ) && in_array( $result->userError, self::VIES_BUSY_ERRORS, true ) ) {
				sleep( $retry_delays[ $attempt ] );
				continue;
			}

			$this->wc_sf_log(
				array(
					'request_type'     => 'eu_vat_number',
					'response_status'  => $http_response_code,
					'response_message' => 'VIES could not validate (userError: ' . $result->userError . ')' . ( $attempt > 0 ? ' after ' . ( $attempt + 1 ) . ' attempts' : '' ),
				)
			);

			// A busy or unavailable service, not a problem with the number (INVALID_INPUT and similar).
			if ( in_array( $result->userError, self::VIES_BUSY_ERRORS, true ) ) {
				$this->vies_failure = 'busy';
			} elseif ( in_array( $result->userError, self::VIES_OUTAGE_ERRORS, true ) ) {
				$this->vies_failure = 'outage';
			}

			return null;
		}
	}



	/**
	 * Validate an EU VAT number through VIES with a transient cache.
	 *
	 * A valid or invalid result is remembered for a day. When VIES is unavailable, a number VIES confirmed
	 * as valid in the last 30 days is accepted (the order gets a note with the date of that check). When VIES
	 * is down for a country, it is not asked again for that country for two minutes, so one checkout does not
	 * wait for the timeout on every update.
	 *
	 * @param string $vat_number VAT number to validate.
	 * @return bool|null True if valid, false if invalid, null if it could not be validated.
	 */
	public function is_eu_vat_number_valid_cached( $vat_number ) {
		$normalized = $this->normalize_vat_number( $vat_number );
		if ( '' === $normalized ) {
			return null;
		}

		$state = $this->get_vat_number_check_state( $normalized );
		if ( 'valid' === $state || 'verified_earlier' === $state ) {
			return true;
		}
		if ( 'invalid' === $state ) {
			return false;
		}

		$result = null;
		if ( 'unreachable' !== $state ) {
			$result = $this->validate_eu_vat_number( $normalized );
		}

		if ( true === $result ) {
			set_transient( $this->vat_number_cache_key( 'wc_sf_vies_', $normalized ), 'valid', DAY_IN_SECONDS );
			set_transient( $this->vat_number_cache_key( 'wc_sf_vies_verified_', $normalized ), current_time( 'Y-m-d' ), 30 * DAY_IN_SECONDS );
			$this->vies_states[ $normalized ] = 'valid';
		} elseif ( false === $result ) {
			set_transient( $this->vat_number_cache_key( 'wc_sf_vies_', $normalized ), 'invalid', DAY_IN_SECONDS );
			delete_transient( $this->vat_number_cache_key( 'wc_sf_vies_verified_', $normalized ) );
			$this->vies_states[ $normalized ] = 'invalid';
		} elseif ( 'unreachable' === $state || '' !== $this->vies_failure ) {
			// VIES did not answer or was busy. A problem with the number itself (or a number without an EU country code) is not remembered.
			if ( 'outage' === $this->vies_failure ) {
				set_transient( 'wc_sf_vies_unreachable_' . substr( $normalized, 0, 2 ), 1, 2 * MINUTE_IN_SECONDS );
			}

			if ( get_transient( $this->vat_number_cache_key( 'wc_sf_vies_verified_', $normalized ) ) ) {
				$this->vies_states[ $normalized ] = 'verified_earlier';
				return true;
			}

			$this->vies_states[ $normalized ] = 'unreachable';
		}

		return $result;
	}

	/**
	 * What the plugin knows about a VAT number from this request and the cache, without asking VIES.
	 *
	 * @param string $vat_number VAT number.
	 * @return string 'valid', 'invalid', 'verified_earlier' (VIES unavailable, accepted by a check from the last
	 *                30 days), 'unreachable' (VIES unavailable) or '' (not known).
	 */
	public function get_vat_number_check_state( $vat_number ) {
		$normalized = $this->normalize_vat_number( $vat_number );
		if ( '' === $normalized ) {
			return '';
		}

		if ( isset( $this->vies_states[ $normalized ] ) ) {
			return $this->vies_states[ $normalized ];
		}

		$cached = get_transient( $this->vat_number_cache_key( 'wc_sf_vies_', $normalized ) );
		if ( 'valid' === $cached || 'invalid' === $cached ) {
			return $cached;
		}

		if ( get_transient( 'wc_sf_vies_unreachable_' . substr( $normalized, 0, 2 ) ) ) {
			return get_transient( $this->vat_number_cache_key( 'wc_sf_vies_verified_', $normalized ) ) ? 'verified_earlier' : 'unreachable';
		}

		return '';
	}

	/**
	 * VAT number without spaces and punctuation, in upper case.
	 *
	 * @param string $vat_number VAT number.
	 * @return string
	 */
	private function normalize_vat_number( $vat_number ) {
		return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $vat_number ) );
	}

	/**
	 * Transient name for a normalized VAT number.
	 *
	 * @param string $prefix     Transient prefix.
	 * @param string $normalized Normalized VAT number.
	 * @return string
	 */
	private function vat_number_cache_key( $prefix, $normalized ) {
		return $prefix . md5( $normalized );
	}

	/**
	 * Add an order note when VIES was unavailable at checkout: the VAT number could not be verified, or it was
	 * accepted by an earlier check.
	 *
	 * @param int|WC_Order $order Order or its ID.
	 */
	public function note_unverified_vat_number( $order ) {
		if ( 'yes' !== get_option( 'woocommerce_sf_validate_eu_vat_number', 'no' ) ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}

		// The block checkout stores the business checkbox; the classic one keeps the VAT number only for a business.
		$checkbox = $order->get_meta( '_wc_other/superfaktura/wi-as-company', true );
		if ( '' !== $checkbox && ! in_array( $checkbox, array( true, 1, '1', 'true', 'yes' ), true ) ) {
			return;
		}

		$vat_number = trim( (string) $order->get_meta( 'billing_company_wi_vat', true ) );
		if ( '' === $vat_number ) {
			return;
		}

		$state = $this->get_vat_number_check_state( $vat_number );
		if ( 'verified_earlier' === $state ) {
			$verified = (string) get_transient( $this->vat_number_cache_key( 'wc_sf_vies_verified_', $this->normalize_vat_number( $vat_number ) ) );
			$note     = sprintf(
				// Translators: %1$s VAT number, %2$s Date of the last successful check.
				__( 'VIES was unavailable, so the VAT number %1$s was accepted based on its verification on %2$s.', 'woocommerce-superfaktura' ),
				$vat_number,
				$verified ? date_i18n( get_option( 'date_format' ), strtotime( $verified ) ) : '?'
			);
		} elseif ( 'unreachable' === $state ) {
			// Translators: %s VAT number.
			$note = sprintf( __( 'The VAT number %s could not be verified, because VIES was unavailable.', 'woocommerce-superfaktura' ), $vat_number );

			// Only when a verified number would have removed VAT (see should_exempt_vat()).
			$billing_country = strtoupper( (string) $order->get_billing_country() );
			if ( 'yes' === get_option( 'woocommerce_sf_exempt_vat_on_valid_vat_number', 'no' ) && 0 < (float) $order->get_total_tax() && $billing_country !== WC()->countries->get_base_country() && in_array( $billing_country, $this->eu_countries, true ) ) {
				$note .= ' ' . __( 'VAT was therefore not removed from the order.', 'woocommerce-superfaktura' );
			}
			$note .= ' ' . __( 'Details are in the API log.', 'woocommerce-superfaktura' );
		} else {
			return;
		}

		// A checkout submitted again for the same order (e.g. after a failed payment) does not repeat the note.
		if ( $order->get_meta( '_wc_sf_vies_note', true ) === $note ) {
			return;
		}
		$order->update_meta_data( '_wc_sf_vies_note', $note );
		$order->save();

		$order->add_order_note( $note );
	}



	/**
	 * Decide whether an order should be exempted from VAT (intra-EU B2B reverse charge).
	 *
	 * Returns true only for a business purchase with a VIES-verified VAT number billing to an EU country other than the shop base country.
	 * A VAT number that could not be validated (VIES unreachable) exempts only when VIES confirmed it in the last 30 days.
	 * Otherwise VAT is charged, the safe default.
	 *
	 * @param bool   $is_company      Whether the customer is buying as a business.
	 * @param string $vat_number      Entered VAT number.
	 * @param string $billing_country Billing country ISO code.
	 * @return bool
	 */
	public function should_exempt_vat( $is_company, $vat_number, $billing_country ) {
		// Dependent on VAT validation being enabled (mirrors the admin UI, where this option is
		// only shown when "Validate VAT #" is on).
		if ( 'yes' !== get_option( 'woocommerce_sf_validate_eu_vat_number', 'no' ) ) {
			return false;
		}

		if ( 'yes' !== get_option( 'woocommerce_sf_exempt_vat_on_valid_vat_number', 'no' ) ) {
			return false;
		}

		if ( ! $is_company || '' === trim( (string) $vat_number ) ) {
			return false;
		}

		$billing_country = strtoupper( (string) $billing_country );
		if ( '' === $billing_country ) {
			return false;
		}

		// Domestic business customers keep local VAT; reverse charge only applies cross-border within the EU.
		if ( $billing_country === WC()->countries->get_base_country() ) {
			return false;
		}

		if ( ! in_array( $billing_country, $this->eu_countries, true ) ) {
			return false;
		}

		return true === $this->is_eu_vat_number_valid_cached( $vat_number );
	}



	/**
	 * Apply (or clear) the VAT exemption on a customer based on the entered company billing data.
	 *
	 * @param WC_Customer $customer        Customer to update.
	 * @param bool        $is_company      Whether the customer is buying as a business.
	 * @param string      $vat_number      Entered VAT number.
	 * @param string      $billing_country Billing country ISO code.
	 */
	public function apply_vat_exemption( $customer, $is_company, $vat_number, $billing_country ) {
		if ( 'yes' !== get_option( 'woocommerce_sf_exempt_vat_on_valid_vat_number', 'no' ) ) {
			return;
		}

		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		$customer->set_is_vat_exempt( $this->should_exempt_vat( $is_company, $vat_number, $billing_country ) );
	}



	/**
	 * Apply (or clear) the VAT exemption and invalidate cached shipping rates when the exemption state changes.
	 *
	 * WooCommerce caches calculated shipping rates in the session under a hash of the shipping package, and
	 * that hash does not include WC_Customer::is_vat_exempt(). Toggling the reverse charge therefore reuses
	 * rates whose tax arrays were computed for the previous exemption state, so the shipping tax would not
	 * follow the reverse charge unless the cache is cleared. Clearing it forces a fresh calculation, which
	 * re-runs the woocommerce_package_rates filter that strips shipping taxes for exempt customers.
	 *
	 * Shared by the classic and block checkouts so both invalidate the cache the same way.
	 *
	 * @param WC_Customer $customer        Customer to update.
	 * @param bool        $is_company      Whether the customer is buying as a business.
	 * @param string      $vat_number      Entered VAT number.
	 * @param string      $billing_country Billing country ISO code.
	 */
	public function apply_vat_exemption_with_shipping_refresh( $customer, $is_company, $vat_number, $billing_country ) {
		if ( ! $customer instanceof WC_Customer ) {
			return;
		}

		$was_exempt = (bool) $customer->get_is_vat_exempt();
		$this->apply_vat_exemption( $customer, $is_company, $vat_number, $billing_country );

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
	public function clear_cached_shipping_rates() {
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
	 * Perform a GET request to the EU VIES REST API.
	 *
	 * @param string      $url         Request URL.
	 * @param string|null $httpversion Force a specific HTTP version (e.g. '1.1'), or null to auto-negotiate.
	 * @return array|WP_Error
	 */
	private function vies_remote_get( $url, $httpversion = null ) {
		$args = array(
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
			'sslverify'  => true,
			'headers'    => array(
				// Request an uncompressed response. On many hosts the compressed TLS stream from ec.europa.eu is aborted mid-transfer ("cURL error 56: unexpected eof while reading"), which made VIES appear permanently unreachable.
				'Accept-Encoding' => 'identity',
			),
		);

		if ( null === $httpversion ) {
			return wp_safe_remote_get( $url, $args );
		}

		$args['httpversion'] = $httpversion;

		// Force the HTTP version at the cURL transport level too.
		// WordPress' "httpversion" is not consistently honored across WP/Requests versions, so on the HTTP/1.1 retry we also set CURLOPT_HTTP_VERSION directly.
		$force_http_1_1 = '1.1' === $httpversion;
		$curl_filter    = function ( $handle ) {
			curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
		};

		if ( $force_http_1_1 ) {
			add_action( 'http_api_curl', $curl_filter );
		}

		$response = wp_safe_remote_get( $url, $args );

		if ( $force_http_1_1 ) {
			remove_action( 'http_api_curl', $curl_filter );
		}

		return $response;
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
				$valid_eu_vat_number = $this->is_eu_vat_number_valid_cached( $_POST['billing_company_wi_vat'] );
				if ( false === $valid_eu_vat_number ) {
					// Translators: %s Field name.
					wc_add_notice( sprintf( __( '%s is not valid.', 'woocommerce-superfaktura' ), '<strong>' . esc_html( __( 'VAT #', 'woocommerce-superfaktura' ) ) . '</strong>' ), 'error' );
				}
				// When the VAT number could not be validated (null) because the EU VIES service is
				// unreachable, the behavior depends on the merchant setting. Default is "allow"
				// (fail open); "block" stops checkout until validation succeeds.
				elseif ( null === $valid_eu_vat_number && 'block' === get_option( 'woocommerce_sf_validate_eu_vat_number_behavior', 'allow' ) ) {
					// Translators: %s Field name.
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

		if ( ! isset( $_POST['wi_as_company'] ) && ! $this->is_classic_checkout_submission() ) {
			$order->save();
			return;
		}

		$order->update_meta_data( 'has_shipping', ( isset( $_POST['shiptobilling'] ) && '1' == $_POST['shiptobilling'] ) ? '0' : '1' );

		if ( isset( $_POST['wi_as_company'] ) && '1' == $_POST['wi_as_company'] ) {
			foreach ( array( 'billing_company_wi_id', 'billing_company_wi_tax', 'billing_company_wi_vat' ) as $key ) {
				if ( isset( $_POST[ $key ] ) ) {
					$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
					$order->update_meta_data( $key, $value );
					$order->update_meta_data( '_' . $key, $value );
				}
			}
		} else {
			// Clear company ID fields which WooCommerce may prefill from the customer profile even when the business checkbox is unchecked.
			foreach ( array( 'billing_company_wi_id', 'billing_company_wi_tax', 'billing_company_wi_vat', '_billing_company_wi_id', '_billing_company_wi_tax', '_billing_company_wi_vat' ) as $key ) {
				$order->delete_meta_data( $key );
			}

			// If the company billing fields are enabled, yet the 'Buy as Business client' checkbox has not been checked.
			if ( 'yes' === get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) ) {
				$order->set_billing_company('');

				if ( 0 == ( $_POST['ship_to_different_address'] ?? 0 ) ) {
					$order->set_shipping_company('');
				}
			}
		}

		// A subscription renewal inherits the block checkout's stored company fields from its subscription. When the
		// renewal is paid through the classic checkout, update that copy too, so it never contradicts this choice.
		// A renewal order always records the choice, so an invoice issued before the subscriptions are synced never
		// falls back to the subscription's company data for a private renewal.
		$is_renewal      = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
		$fields_enabled  = 'yes' === get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) && ! $this->wc_nastavenia_skcz_activated;
		$fields_in_form  = isset( $_POST['billing_company_wi_id'] ) || isset( $_POST['billing_company_wi_tax'] ) || isset( $_POST['billing_company_wi_vat'] );
		if ( $fields_enabled && $fields_in_form && ( $is_renewal || $order->meta_exists( '_wc_other/superfaktura/wi-as-company' ) ) ) {
			$is_company = isset( $_POST['wi_as_company'] ) && '1' == $_POST['wi_as_company'];
			$order->update_meta_data( '_wc_other/superfaktura/wi-as-company', $is_company ? '1' : '0' );
			$order->update_meta_data( '_wc_other/superfaktura/billing-company', $is_company ? $order->get_billing_company() : '' );
			foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
				$order->update_meta_data( '_wc_other/superfaktura/billing-company-wi-' . $field, $is_company ? (string) $order->get_meta( 'billing_company_wi_' . $field, true ) : '' );
			}
		}

		$order->save();
	}

	/**
	 * Sync the classic checkout business checkbox to the customer profile.
	 *
	 * @param \WC_Customer $customer Customer object.
	 * @param array        $data     Posted checkout data.
	 */
	public function checkout_update_customer( $customer, $data ) {
		$is_company = ! empty( $data['wi_as_company'] );
		$customer->update_meta_data( 'wi_as_company', $is_company ? '1' : '0' );

		// Keep the block checkout's stored choice in step when the customer has one, so My Account and a later
		// block checkout show what was chosen here.
		if ( $customer->meta_exists( '_wc_other/superfaktura/wi-as-company' ) ) {
			$customer->update_meta_data( '_wc_other/superfaktura/wi-as-company', $is_company ? '1' : '0' );
			if ( $is_company ) {
				$customer->update_meta_data( '_wc_other/superfaktura/billing-company', isset( $data['billing_company'] ) ? sanitize_text_field( $data['billing_company'] ) : '' );
				foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
					if ( isset( $data[ 'billing_company_wi_' . $field ] ) ) {
						$customer->update_meta_data( '_wc_other/superfaktura/billing-company-wi-' . $field, sanitize_text_field( $data[ 'billing_company_wi_' . $field ] ) );
					}
				}
			}
		}

		// Apply or clear the intra-EU B2B VAT exemption on every checkout update so the cart totals and the resulting order reflect the reverse charge.
		$billing_country = isset( $data['billing_country'] ) ? $data['billing_country'] : $customer->get_billing_country();
		$this->apply_vat_exemption_with_shipping_refresh( $customer, $is_company, isset( $data['billing_company_wi_vat'] ) ? $data['billing_company_wi_vat'] : '', $billing_country );
	}

	/**
	 * Re-apply the intra-EU B2B VAT exemption before WooCommerce calculates the classic-checkout cart totals.
	 *
	 * The classic checkout recalculates the cart totals several times per request (the
	 * update_order_review AJAX and again inside WC_Checkout::process_checkout()), and the later
	 * recalculation resets the session customer's is_vat_exempt flag. Applying the exemption only in
	 * checkout_update_customer therefore does not survive to order creation, so the order is created
	 * with VAT even for a valid EU VAT number. Re-asserting it from the posted checkout fields on every
	 * cart calculation makes the reverse charge stick for both the displayed totals and the order.
	 *
	 * The block checkout is handled separately in WC_SF_Checkout_Block, so this deliberately skips
	 * REST requests to avoid applying the exemption twice from two different data sources.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public function apply_vat_exemption_classic_before_cart_totals( $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		// The block / Store API checkout re-applies the exemption in its own handler.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Read the posted checkout fields. On the update_order_review AJAX the form is sent serialized
		// in 'post_data'; on final submission the fields are present in $_POST directly.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted = array();
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $posted );
		} elseif ( ! empty( $_POST ) ) {
			$posted = wp_unslash( $_POST );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Only act on our checkout context; leave the exemption untouched for unrelated cart calculations.
		if ( ! isset( $posted['wi_as_company'] ) && ! isset( $posted['billing_company_wi_vat'] ) ) {
			return;
		}

		$is_company      = ! empty( $posted['wi_as_company'] );
		$vat_number      = isset( $posted['billing_company_wi_vat'] ) ? sanitize_text_field( $posted['billing_company_wi_vat'] ) : '';
		$billing_country = isset( $posted['billing_country'] ) && '' !== $posted['billing_country'] ? sanitize_text_field( $posted['billing_country'] ) : WC()->customer->get_billing_country();

		$this->apply_vat_exemption_with_shipping_refresh( WC()->customer, $is_company, $vat_number, $billing_country );
	}

	/**
	 * Check whether the current request is a real classic checkout submission.
	 *
	 * @return bool True when WooCommerce's classic checkout form nonce is present.
	 */
	private function is_classic_checkout_submission() {
		return isset( $_POST['woocommerce-process-checkout-nonce'] );
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

		$fields['company_wi_tax'] = array(
			'label'         => __( 'TAX ID #', 'woocommerce-superfaktura' ),
			'show'          => true,
			'wrapper_class' => 'form-field-wide',
		);

		$fields['company_wi_vat'] = array(
			'label'         => __( 'VAT #', 'woocommerce-superfaktura' ),
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
		$had_ids     = '' !== (string) $order->get_meta( 'billing_company_wi_id', true ) . (string) $order->get_meta( 'billing_company_wi_tax', true ) . (string) $order->get_meta( 'billing_company_wi_vat', true );

		// Because the filter "woocommerce_admin_billing_fields" above saves only private custom fields prefixed with "_" and the plugin for some reason uses duplicates of these fields without the prefix, we need to update those values here as well.
		foreach ( array( 'billing_company_wi_id', 'billing_company_wi_vat', 'billing_company_wi_tax' ) as $key ) {
			if ( isset( $_POST[ '_' . $key ] ) ) {
				$order->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ '_' . $key ] ) ) );
				$should_save = true;
			}
		}

		// Keep the block checkout's stored copy in step with the admin's edit, so a renewal repair or the next
		// renewal checkout never brings back values the admin changed or removed.
		if ( $should_save && $order->meta_exists( '_wc_other/superfaktura/wi-as-company' ) ) {
			$has_ids = false;
			foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
				$value   = (string) $order->get_meta( 'billing_company_wi_' . $field, true );
				$has_ids = $has_ids || '' !== $value;
				$order->update_meta_data( '_wc_other/superfaktura/billing-company-wi-' . $field, $value );
			}
			if ( $has_ids ) {
				// The order's own data box saves later (priority 40), so take the company name from the form.
				$company = isset( $_POST['_billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['_billing_company'] ) ) : $order->get_billing_company();
				$order->update_meta_data( '_wc_other/superfaktura/wi-as-company', '1' );
				$order->update_meta_data( '_wc_other/superfaktura/billing-company', $company );
			} elseif ( $had_ids ) {
				// The admin removed all company IDs: record a private choice. A company without IDs stays as it was.
				$order->update_meta_data( '_wc_other/superfaktura/wi-as-company', '0' );
				$order->update_meta_data( '_wc_other/superfaktura/billing-company', '' );
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

			$fields['billing']['fields']['billing_company_wi_tax'] = array(
				'label'       => __( 'TAX ID #', 'woocommerce-superfaktura' ),
				'description' => '',
			);

			$fields['billing']['fields']['billing_company_wi_vat'] = array(
				'label'       => __( 'VAT #', 'woocommerce-superfaktura' ),
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
			$data['billing']['company_wi_tax'] = get_user_meta( $user_id, 'billing_company_wi_tax', true );
			$data['billing']['company_wi_vat'] = get_user_meta( $user_id, 'billing_company_wi_vat', true );
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

		// Credit notes, when they are created automatically for refunds and cancelled orders.
		if ( $this->auto_credit_notes() ) {
			$pdf = $order->get_meta( 'wc_sf_invoice_cancel', true );
			if ( $pdf ) {
				$actions['wc_sf_invoice_cancel'] = array(
					'url'  => $pdf,
					'name' => __( 'Credit note', 'woocommerce-superfaktura' ),
				);
			}

			foreach ( WC_SF_Invoice::get_refund_credit_notes( $order ) as $refund_id => $credit_note ) {
				$actions[ 'wc_sf_invoice_cancel_' . $refund_id ] = array(
					'url'  => $credit_note['pdf'],
					// Translators: %s Credit note number.
					'name' => sprintf( __( 'Credit note %s', 'woocommerce-superfaktura' ), $credit_note['number'] ),
				);
			}
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

		/**
		 * Filters the document number made from the number template in the plugin settings.
		 *
		 * Used when the plugin numbers the documents itself instead of SuperFaktura.
		 *
		 * @param string $invoice_id    Document number.
		 * @param array  $template_tags Values of the template tags, such as [YEAR], [MONTH], [COUNT] and [ORDER_NUMBER].
		 * @param string $key           Document type: 'proforma', 'regular' or 'cancel'.
		 */
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

		$order_status_terms = get_terms( array( 'taxonomy' => 'shop_order_status', 'hide_empty' => false ) );

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

		/**
		 * Filters whether the order counts as paid, so that its invoice is created as paid.
		 *
		 * @since 1.6.36
		 *
		 * @param bool     $is_paid Whether the order is paid.
		 * @param WC_Order $order   Order.
		 */
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
		check_ajax_referer( 'ajax_validation', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1, 403 );
		}

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

		$result = wp_safe_remote_get( $_POST['url'], [ 'redirection' => 0 ] );
		if ( is_wp_error( $result ) || 'application/pdf' != $result['headers']['content-type'] ?? '' ) {
			echo 'ERROR';
		}
		else {
			echo $result['response']['code'] ?? 'ERROR';
		}

		wp_die();
	}
}
