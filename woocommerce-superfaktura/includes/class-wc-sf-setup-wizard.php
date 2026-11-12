<?php
/**
 * Setup wizard.
 *
 * @package SuperFaktura WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_SF_Setup_Wizard class.
 *
 * A full-screen guide that connects the shop to SuperFaktúra and recommends settings for the shop's payment
 * gateways and shipping methods. It writes nothing until the summary is confirmed, and then only the settings
 * shown in the summary.
 *
 * It is offered (admin notice, button on the Authorization tab) only after a new activation while the API
 * credentials are empty, so updating the plugin changes nothing for existing sites. Anyone can start it from
 * the Tools section.
 */
class WC_SF_Setup_Wizard {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'wc-sf-setup';

	/**
	 * Option set on a new activation: "notice" shows the admin notice, "available" only the button on the
	 * Authorization tab. Deleted when the wizard is completed.
	 */
	const OPTION = 'wc_sf_setup_wizard';

	/**
	 * Nonce action for the wizard requests.
	 */
	const NONCE = 'wc_sf_setup_wizard';

	/**
	 * Main plugin class.
	 *
	 * @var WC_SuperFaktura
	 */
	protected $wc_sf;

	/**
	 * Secret key for automatic pairing shown on the page: the saved one, or a new one saved with the settings.
	 *
	 * @var string
	 */
	protected $secret_key = '';

	/**
	 * Constructor.
	 *
	 * @param WC_SuperFaktura $wc_sf Main plugin class.
	 */
	public function __construct( $wc_sf ) {
		$this->wc_sf = $wc_sf;

		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_render_page' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_ajax_wc_sf_setup_connect', array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_wc_sf_setup_company', array( $this, 'ajax_company' ) );
		add_action( 'wp_ajax_wc_sf_setup_save', array( $this, 'ajax_save' ) );
	}

	/**
	 * Remember a new activation, so the wizard is offered. Only sites without API credentials get the offer.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 */
	public static function flag_new_activation( $network_wide ) {
		if ( $network_wide && is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::flag_site();
				restore_current_blog();
			}
			return;
		}

		self::flag_site();
	}

	/**
	 * Flag the current site when it has no API credentials yet.
	 */
	private static function flag_site() {
		if ( ! self::has_credentials() ) {
			update_option( self::OPTION, 'notice' );
		}
	}

	/**
	 * Whether the API e-mail and key are filled in.
	 *
	 * @return bool
	 */
	private static function has_credentials() {
		return '' !== trim( (string) get_option( 'woocommerce_sf_email', '' ) ) && '' !== trim( (string) get_option( 'woocommerce_sf_apikey', '' ) );
	}

	/**
	 * Whether the wizard should be offered: after a new activation, until the credentials are filled in.
	 *
	 * @return bool
	 */
	public function should_offer() {
		if ( false === get_option( self::OPTION, false ) ) {
			return false;
		}
		if ( self::has_credentials() ) {
			// Set up another way, the offer is no longer needed.
			delete_option( self::OPTION );
			return false;
		}

		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * URL of the wizard.
	 *
	 * @return string
	 */
	public static function get_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * URL of the plugin settings.
	 *
	 * @param string $section Settings section.
	 * @return string
	 */
	private function get_settings_url( $section = '' ) {
		$args = array(
			'page' => 'wc-settings',
			'tab'  => 'superfaktura',
		);
		if ( '' !== $section ) {
			$args['section'] = $section;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Admin notice after a new activation.
	 */
	public function render_notice() {
		if ( 'notice' !== get_option( self::OPTION, false ) || ! $this->should_offer() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'wc_sf_setup_dismiss', '1' ), 'wc_sf_setup_dismiss' );
		?>
		<div class="notice notice-info wc-sf-setup-notice">
			<p><strong><?php esc_html_e( 'SuperFaktúra WooCommerce', 'woocommerce-superfaktura' ); ?></strong>: <?php esc_html_e( 'Connect your shop to SuperFaktúra. The setup wizard recommends settings for your payment and shipping methods, it takes about 5 minutes.', 'woocommerce-superfaktura' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::get_url() ); ?>"><?php esc_html_e( 'Start the setup wizard', 'woocommerce-superfaktura' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Hide', 'woocommerce-superfaktura' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Hide the admin notice. The wizard stays available on the Authorization tab and in Tools.
	 */
	public function maybe_dismiss_notice() {
		if ( ! isset( $_GET['wc_sf_setup_dismiss'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'wc_sf_setup_dismiss' );

		if ( false !== get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, 'available' );
		}

		wp_safe_redirect( remove_query_arg( array( 'wc_sf_setup_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Notice with the wizard button for the Authorization tab.
	 *
	 * @return string HTML or empty string.
	 */
	public function get_settings_notice() {
		if ( ! $this->should_offer() ) {
			return '';
		}

		return '<div class="sf-notice-info">' . esc_html__( 'The setup wizard connects your shop to SuperFaktúra and recommends settings for your payment and shipping methods.', 'woocommerce-superfaktura' ) . ' <a class="button button-primary" href="' . esc_url( self::get_url() ) . '">' . esc_html__( 'Start the setup wizard', 'woocommerce-superfaktura' ) . '</a></div>';
	}

	/* ---------------------------------------------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------------------------------------------ */

	/**
	 * Order statuses without the "wc-" prefix, as the plugin stores them.
	 *
	 * @return array Status => name.
	 */
	private function get_order_statuses() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $name ) {
			$statuses[ 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key ] = $name;
		}
		unset( $statuses['checkout-draft'] );

		return $statuses;
	}

	/**
	 * Order statuses offered for creating documents, without the ones that end an order unpaid.
	 *
	 * @return array Status => name.
	 */
	private function get_document_statuses() {
		return array_diff_key( $this->get_order_statuses(), array_flip( array( 'cancelled', 'refunded', 'failed' ) ) );
	}

	/**
	 * Name of an order status.
	 *
	 * @param string $status Status without the "wc-" prefix.
	 * @return string
	 */
	private function status_name( $status ) {
		$statuses = $this->get_order_statuses();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * Enabled payment gateways.
	 *
	 * @return WC_Payment_Gateway[]
	 */
	private function get_gateways() {
		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$gateways[ $gateway->id ] = $gateway;
			}
		}

		return $gateways;
	}

	/**
	 * Gateway title as the settings show it.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @return string
	 */
	private function gateway_title( $gateway ) {
		$title = ! empty( $gateway->title ) ? $gateway->title : $gateway->method_title;

		return wp_strip_all_tags( (string) $title );
	}

	/**
	 * Enabled shipping methods of all zones, keyed by the plugin's option suffix.
	 *
	 * @return array Key => array( 'title', 'zone', 'id' ).
	 */
	private function get_shipping_methods() {
		$methods = array();
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $methods;
		}

		$zones = WC_Shipping_Zones::get_zones();
		$rest  = new WC_Shipping_Zone( 0 );

		$zones[0] = array(
			'formatted_zone_location' => $rest->get_formatted_location(),
			'shipping_methods'        => $rest->get_shipping_methods(),
		);

		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'no' === $method->enabled ) {
					continue;
				}
				$methods[ $method->id . ':' . $method->instance_id ] = array(
					'title' => wp_strip_all_tags( (string) $method->title ),
					'zone'  => wp_strip_all_tags( (string) $zone['formatted_zone_location'] ),
					'id'    => $method->id,
				);
			}
		}

		return $methods;
	}

	/**
	 * Order statuses in which invoices are created as paid, the way the plugin decides it.
	 *
	 * @return string[]
	 */
	private function get_paid_statuses() {
		$statuses = get_option( 'woocommerce_sf_invoice_set_as_paid_statuses', false );
		if ( is_array( $statuses ) ) {
			return $statuses;
		}

		// Without the saved option, the plugin falls back to its older options.
		$statuses = array();
		if ( 'yes' === get_option( 'woocommerce_sf_invoice_regular_processing_set_as_paid', 'no' ) ) {
			$statuses[] = 'processing';
		}
		if ( 'no' === get_option( 'woocommerce_sf_invoice_regular_dont_set_as_paid', 'no' ) ) {
			$statuses[] = 'completed';
		}

		return $statuses;
	}

	/**
	 * Recommended settings for a payment gateway.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @return array doc (both|proforma|invoice|manual), proforma and invoice status, paid, pay_types (preferred SuperFaktúra
	 *               payment types, the first one available is used), why.
	 */
	private function recommend_gateway( $gateway ) {
		$id = strtolower( $gateway->id );

		$online = array(
			'gopay'    => 'gopay',
			'trustpay' => 'trustpay',
			'besteron' => 'besteron',
			'barion'   => 'barion',
			'viamo'    => 'viamo',
			'paypal'   => 'paypal',
			'ppcp'     => 'paypal',
		);
		$cards  = '/stripe|woocommerce_payments|braintree|square|mollie|comgate|gpwebpay|gp_webpay|cardpay|payu|revolut|adyen|24pay|tatrapay|card/';

		if ( 'bacs' === $id ) {
			$recommendation = array(
				'doc'       => 'both',
				'proforma'  => 'on-hold',
				'invoice'   => 'processing',
				'paid'      => false,
				'pay_types' => array( 'transfer' ),
				'why'       => sprintf(
					/* translators: %s: order status name. */
					__( 'The customer pays after placing the order. The proforma invoice gives them the payment details and SuperFaktúra pairs the incoming payment with it. With automatic pairing set up, the plugin then changes the order status to %s and creates the invoice.', 'woocommerce-superfaktura' ),
					$this->status_name( 'processing' )
				),
			);
		} elseif ( 'cod' === $id ) {
			$recommendation = array(
				'doc'       => 'invoice',
				'proforma'  => '0',
				'invoice'   => 'completed',
				'paid'      => false,
				'pay_types' => array( 'cod' ),
				'why'       => __( 'The invoice is created when the order is shipped, so it matches what was actually sent.', 'woocommerce-superfaktura' ),
			);
		} else {
			$pay_types = array();
			foreach ( $online as $needle => $pay_type ) {
				if ( false !== strpos( $id, $needle ) ) {
					$pay_types[] = $pay_type;
				}
			}
			if ( $pay_types || preg_match( $cards, $id ) ) {
				$recommendation = array(
					'doc'       => 'invoice',
					'proforma'  => '0',
					'invoice'   => 'processing',
					'paid'      => true,
					'pay_types' => array_merge( $pay_types, array( 'card', 'credit' ) ),
					'why'       => sprintf(
						/* translators: %s: order status name. */
						__( 'The payment is made during checkout. The invoice is created when the paid order changes to %s, and it is marked as paid.', 'woocommerce-superfaktura' ),
						$this->status_name( 'processing' )
					),
				);
			} else {
				$recommendation = array(
					'doc'       => 'manual',
					'proforma'  => '0',
					'invoice'   => '0',
					'paid'      => false,
					'pay_types' => array(),
					'why'       => __( 'We do not recognize this payment method, so no document is created automatically. You can create documents from the order, or choose when to create them here.', 'woocommerce-superfaktura' ),
				);
			}
		}

		/**
		 * Filter the setup wizard recommendation for a payment gateway.
		 *
		 * @since 1.59.0
		 *
		 * @param array              $recommendation doc (both|proforma|invoice|manual), proforma and invoice status ("0" for none),
		 *                                           paid, pay_types (preferred SuperFaktúra payment types), why.
		 * @param WC_Payment_Gateway $gateway        Payment gateway.
		 */
		$recommendation = apply_filters( 'sf_setup_wizard_gateway_recommendation', $recommendation, $gateway );

		return wp_parse_args(
			is_array( $recommendation ) ? $recommendation : array(),
			array(
				'doc'       => 'manual',
				'proforma'  => '0',
				'invoice'   => '0',
				'paid'      => false,
				'pay_types' => array(),
				'why'       => '',
			)
		);
	}

	/**
	 * Recommended SuperFaktúra delivery type for a shipping method.
	 *
	 * @param string $method_id Shipping method ID.
	 * @param string $title     Shipping method title.
	 * @return string Delivery type key, or "0" when unknown.
	 */
	private function recommend_shipping( $method_id, $title ) {
		$text = strtolower( remove_accents( $method_id . ' ' . $title ) );
		$type = '0';
		// Whole words only, not "postovne" (Slovak for a shipping fee in general).
		$post = '/(^|[^a-z])(posta|posty|postou|poste|postu|post|tsseph)([^a-z]|$)|post office/';

		if ( preg_match( '/local_pickup|pickup_location|osobn|odber v predajni|vyzvednuti na prodejne|abholung/', $text ) ) {
			$type = 'personal';
		} elseif ( preg_match( '/(^|[^a-z])(domu|domov|na adresu)([^a-z]|$)|home delivery|address delivery|hausadresse|hauszustellung/', $text ) ) {
			// Home delivery, by the post (e.g. "Slovenská pošta - Balík na adresu") or by a carrier known for pickup points (e.g. "Packeta na adresu").
			$type = preg_match( $post, $text ) ? 'mail' : 'courier';
		} elseif ( preg_match( '/packeta|zasilkovn|zasielkovn|zpoint|z-box|pickup|parcelshop|parcel shop|paketshop|abholstation|odbern|box|vydajn|vydejn|balikovo|balikovn|alzabox|paczkomat/', $text ) ) {
			$type = 'pickup_point';
		} elseif ( preg_match( '/(^|[^a-z])(dpd|gls|ups|dhl|fedex|geis|ppl|sps)([^a-z]|$)|kurier|kuryr|courier/', $text ) ) {
			$type = 'courier';
		} elseif ( preg_match( $post, $text ) ) {
			$type = 'mail';
		}

		/**
		 * Filter the setup wizard recommendation for a shipping method.
		 *
		 * @since 1.59.0
		 *
		 * @param string $type      SuperFaktúra delivery type key, "0" when unknown.
		 * @param string $method_id Shipping method ID.
		 * @param string $title     Shipping method title.
		 */
		return apply_filters( 'sf_setup_wizard_shipping_recommendation', $type, $method_id, $title );
	}

	/**
	 * Current value of a setting, or null when it was never saved.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private function current( $name ) {
		return get_option( $name, null );
	}

	/**
	 * Likely SuperFaktúra version by the site language, for sites that never chose one.
	 *
	 * @return string sk, cz or at.
	 */
	private function guess_account_country() {
		$locale = get_locale();
		if ( 0 === strpos( $locale, 'cs' ) ) {
			return 'cz';
		}
		if ( 0 === strpos( $locale, 'de' ) ) {
			return 'at';
		}

		return 'sk';
	}

	/* ---------------------------------------------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------------------------------------------ */

	/**
	 * Register the page without a menu item, so WordPress allows access to it.
	 */
	public function register_page() {
		add_submenu_page( '', __( 'SuperFaktúra setup wizard', 'woocommerce-superfaktura' ), '', 'manage_woocommerce', self::PAGE, '__return_null' );
	}

	/**
	 * Render the full-screen wizard instead of the admin page and stop.
	 */
	public function maybe_render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || is_network_admin() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'woocommerce-superfaktura' ), 403 );
		}

		// A missing secret key is generated for the page only and saved with the settings.
		$this->secret_key = (string) get_option( 'woocommerce_sf_sync_secret_key', '' );
		if ( '' === $this->secret_key ) {
			$this->secret_key = WC_SF_Helper::generate_secret_key();
		}

		$version = $this->wc_sf->version;
		wp_register_style( 'wc-sf-setup', plugins_url( 'assets/css/admin-setup-wizard.css', WC_SF_FILE_PATH ), array( 'common', 'forms', 'buttons', 'dashicons', 'colors' ), $version );
		wp_register_script( 'wc-sf-setup', plugins_url( 'assets/js/admin-setup-wizard.js', WC_SF_FILE_PATH ), array(), $version, true );
		wp_localize_script( 'wc-sf-setup', 'wcSfSetup', $this->get_script_data() );

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
	<title><?php esc_html_e( 'SuperFaktúra setup wizard', 'woocommerce-superfaktura' ); ?></title>
	<?php wp_print_styles( array( 'wc-sf-setup' ) ); ?>
</head>
<body class="wc-sf-setup wp-admin wp-core-ui admin-color-<?php echo esc_attr( sanitize_html_class( (string) get_user_option( 'admin_color' ), 'fresh' ) ); ?>">
		<?php $this->render_body(); ?>
		<?php wp_print_scripts( array( 'wc-sf-setup' ) ); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Data for the script.
	 *
	 * @return array
	 */
	private function get_script_data() {
		$gateways = array();
		foreach ( $this->get_gateways() as $id => $gateway ) {
			$recommendation  = $this->recommend_gateway( $gateway );
			$gateways[ $id ] = array(
				'payTypes' => array_values( (array) $recommendation['pay_types'] ),
				'current'  => $this->current( 'woocommerce_sf_gateway_' . $id ),
			);
		}

		$shipping = array();
		foreach ( $this->get_shipping_methods() as $key => $method ) {
			$shipping[ $key ] = array(
				'recommended' => $this->recommend_shipping( $method['id'], $method['title'] ),
				'current'     => $this->current( 'woocommerce_sf_shipping_' . $key ),
			);
		}

		$hosts = array();
		foreach ( array( 'sk' => 'WC_SF_Api', 'cz' => 'WC_SF_Api_Cz', 'at' => 'WC_SF_Api_At' ) as $lang => $class ) {
			$hosts[ $lang ] = array(
				'live'    => $class::SFAPI_URL,
				'sandbox' => $class::SANDBOX_URL,
			);
		}

		$status_names = array();
		foreach ( $this->get_order_statuses() as $status => $name ) {
			$status_names[ $status ] = $name;
		}

		return array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE ),
			'configured'   => self::has_credentials(),
			'hosts'        => $hosts,
			'paidStatuses' => array_values( $this->get_paid_statuses() ),
			'skippedFix'   => 'yes' === get_option( 'woocommerce_sf_invoice_regular_processing_skipped_fix', 'no' ),
			'manualOn'     => 'yes' === get_option( 'woocommerce_sf_invoice_regular_manual', 'no' ) && 'yes' === get_option( 'woocommerce_sf_invoice_proforma_manual', 'no' ),
			'statusNames'  => $status_names,
			'current'      => array(
				'companyId'   => (string) get_option( 'woocommerce_sf_company_id', '' ),
				'lang'        => (string) get_option( 'woocommerce_sf_lang', 'sk' ),
				'seqInvoice'  => (string) get_option( 'woocommerce_sf_invoice_sequence_id', '' ),
				'seqProforma' => (string) get_option( 'woocommerce_sf_proforma_invoice_sequence_id', '' ),
				'seqCancel'   => (string) get_option( 'woocommerce_sf_cancel_sequence_id', '' ),
				'logo'        => (string) get_option( 'woocommerce_sf_logo_id', '' ),
				'bank'        => (string) get_option( 'woocommerce_sf_bank_account_id', '' ),
			),
			'gateways'     => $gateways,
			'shipping'     => $shipping,
			'i18n'         => array(
				'verifying'      => __( 'Verifying…', 'woocommerce-superfaktura' ),
				'verify'         => __( 'Verify connection', 'woocommerce-superfaktura' ),
				'verifyAgain'    => __( 'Verify again', 'woocommerce-superfaktura' ),
				'loading'        => __( 'Loading data from SuperFaktúra…', 'woocommerce-superfaktura' ),
				'saving'         => __( 'Saving…', 'woocommerce-superfaktura' ),
				'failed'         => __( 'The request failed. Check your internet connection and try again.', 'woocommerce-superfaktura' ),
				'expired'        => __( 'The page has been open for too long. Reload it and try again.', 'woocommerce-superfaktura' ),
				'chooseCompany'  => __( 'Choose a company', 'woocommerce-superfaktura' ),
				'verifyNote'     => __( 'Verify the connection to continue.', 'woocommerce-superfaktura' ),
				'companyNote'    => __( 'Choose a company to continue.', 'woocommerce-superfaktura' ),
				/* translators: %s: name of the default item in SuperFaktúra. */
				'sfDefault'      => __( 'Default in SuperFaktúra: %s', 'woocommerce-superfaktura' ),
				'sfDefaultPlain' => __( 'Default in SuperFaktúra', 'woocommerce-superfaktura' ),
				/* translators: %s: ID of an item in SuperFaktúra. */
				'currentId'      => __( 'Current setting (ID %s)', 'woocommerce-superfaktura' ),
				'notUsed'        => __( 'Don\'t specify', 'woocommerce-superfaktura' ),
				'other'          => __( 'Other', 'woocommerce-superfaktura' ),
				'yes'            => __( 'Yes', 'woocommerce-superfaktura' ),
				'no'             => __( 'No', 'woocommerce-superfaktura' ),
				'changed'        => __( 'Changed', 'woocommerce-superfaktura' ),
				'copied'         => __( 'Copied', 'woocommerce-superfaktura' ),
				'newKey'         => __( 'New key', 'woocommerce-superfaktura' ),
				'skippedFix'     => __( 'Invoice for orders without processing', 'woocommerce-superfaktura' ),
				'manualOn'       => __( 'Creating documents manually in orders', 'woocommerce-superfaktura' ),
				'leave'          => __( 'Leave the wizard? Your choices will not be saved.', 'woocommerce-superfaktura' ),
				/* translators: %s: order status name. */
				'paidBySetting'  => __( 'It is created as paid, because the setting "Set invoice as paid in these order statuses" contains %s.', 'woocommerce-superfaktura' ),
				/* translators: %s: order status name. */
				'sumProforma'    => __( 'proforma invoice at %s', 'woocommerce-superfaktura' ),
				/* translators: %s: order status name. */
				'sumInvoice'     => __( 'invoice at %s', 'woocommerce-superfaktura' ),
				'sumPaid'        => __( 'as paid', 'woocommerce-superfaktura' ),
				'sumManual'      => __( 'manually only', 'woocommerce-superfaktura' ),
				/* translators: %s: payment type in SuperFaktúra. */
				'sumPayType'     => __( 'payment type %s', 'woocommerce-superfaktura' ),
				/* translators: %1$d: step number, %2$d: number of steps, %3$s: step title. */
				'stepOf'         => __( 'Step %1$d of %2$d: %3$s', 'woocommerce-superfaktura' ),
			),
		);
	}

	/**
	 * Select options.
	 *
	 * @param array  $options  Value => label.
	 * @param string $selected Selected value.
	 * @return string HTML.
	 */
	private function options( $options, $selected ) {
		if ( '' !== (string) $selected && ! isset( $options[ $selected ] ) ) {
			// Keep a saved value the list does not offer, so saving does not change it.
			$options = array( $selected => $selected ) + $options;
		}

		$html = '';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $value, (string) $selected, false ) . '>' . esc_html( $label ) . '</option>';
		}

		return $html;
	}

	/**
	 * Recommendation pill, shown by the script while the value matches the recommendation.
	 *
	 * @return string HTML.
	 */
	private function pill() {
		return '<span class="wc-sf-pill" data-pill>' . esc_html__( 'Recommended', 'woocommerce-superfaktura' ) . '</span>';
	}

	/**
	 * Explanation of a recommendation. While another value is chosen, the script shows the recommended one with
	 * a button that applies it.
	 *
	 * @param string $why         Explanation.
	 * @param bool   $recommended Whether there is a recommendation to apply.
	 * @param bool   $pill        Whether the pill is shown inside the explanation.
	 */
	private function render_why( $why, $recommended, $pill = true ) {
		?>
		<div class="wc-sf-why" data-why>
			<?php if ( $recommended ) : ?>
				<p class="wc-sf-rec-alt" data-rec-alt hidden>
					<b><?php esc_html_e( 'We recommend:', 'woocommerce-superfaktura' ); ?></b> <span data-rec-text></span>
					<button type="button" class="button button-small" data-use-rec><?php esc_html_e( 'Use the recommendation', 'woocommerce-superfaktura' ); ?></button>
				</p>
			<?php endif; ?>
			<p data-why-text><?php echo $recommended && $pill ? $this->pill() . ' ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b><?php esc_html_e( 'Why:', 'woocommerce-superfaktura' ); ?></b> <?php echo esc_html( $why ); ?></p>
		</div>
		<?php
	}

	/**
	 * Footer with the back and next buttons of a step.
	 *
	 * @param int    $step      Step number.
	 * @param string $next_note Note shown while the next button is disabled.
	 */
	private function render_foot( $step, $next_note = '' ) {
		?>
		<div class="wc-sf-foot">
			<button type="button" class="button button-large" data-go="<?php echo esc_attr( $step - 1 ); ?>"><?php esc_html_e( 'Back', 'woocommerce-superfaktura' ); ?></button>
			<span class="wc-sf-foot-next">
				<?php if ( $next_note ) : ?>
					<span class="wc-sf-muted wc-sf-small" data-next-note><?php echo esc_html( $next_note ); ?></span>
				<?php endif; ?>
				<button type="button" class="button button-primary button-large" data-go="<?php echo esc_attr( $step + 1 ); ?>"><?php esc_html_e( 'Continue', 'woocommerce-superfaktura' ); ?></button>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the wizard.
	 */
	private function render_body() {
		$steps = array(
			1 => __( 'Connection', 'woocommerce-superfaktura' ),
			2 => __( 'Documents', 'woocommerce-superfaktura' ),
			3 => __( 'Payments', 'woocommerce-superfaktura' ),
			4 => __( 'Shipping', 'woocommerce-superfaktura' ),
			5 => __( 'Checkout and emails', 'woocommerce-superfaktura' ),
			6 => __( 'Summary', 'woocommerce-superfaktura' ),
		);
		?>
		<div class="wc-sf-wrap">
			<header class="wc-sf-head">
				<div class="wc-sf-brand">
					<span class="wc-sf-brand-mark" aria-hidden="true">SF</span>
					<span>
						<span class="wc-sf-brand-name"><?php esc_html_e( 'SuperFaktúra WooCommerce', 'woocommerce-superfaktura' ); ?></span>
						<span class="wc-sf-brand-sub"><?php esc_html_e( 'Setup wizard', 'woocommerce-superfaktura' ); ?></span>
					</span>
				</div>
				<a class="wc-sf-link" id="wc-sf-leave" href="<?php echo esc_url( $this->get_settings_url() ); ?>"><?php self::has_credentials() ? esc_html_e( 'Close the wizard', 'woocommerce-superfaktura' ) : esc_html_e( 'Skip and set up manually', 'woocommerce-superfaktura' ); ?></a>
			</header>

			<p class="screen-reader-text" id="wc-sf-live" role="status"></p>
			<p class="wc-sf-step-now" id="wc-sf-step-now" hidden></p>
			<ol class="wc-sf-steps" id="wc-sf-steps" aria-label="<?php esc_attr_e( 'Wizard steps', 'woocommerce-superfaktura' ); ?>" hidden>
				<?php foreach ( $steps as $number => $title ) : ?>
					<li data-step-tab="<?php echo esc_attr( $number ); ?>" data-title="<?php echo esc_attr( $title ); ?>">
						<button type="button" disabled>
							<?php /* translators: %d: step number. */ ?>
							<span class="wc-sf-step-n"><?php echo esc_html( sprintf( __( 'Step %d', 'woocommerce-superfaktura' ), $number ) ); ?></span>
							<span class="wc-sf-step-t"><?php echo esc_html( $title ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ol>

			<form class="wc-sf-panel" id="wc-sf-setup-form" novalidate>
				<?php
				$this->render_welcome();
				$this->render_connection();
				$this->render_documents();
				$this->render_payments();
				$this->render_shipping();
				$this->render_checkout();
				$this->render_summary( $steps );
				$this->render_done();
				?>
			</form>

			<?php // Backup of the current settings through the Tools export, outside the wizard form. ?>
			<form id="wc-sf-backup-form" method="post" action="<?php echo esc_url( $this->get_settings_url( 'tools' ) ); ?>" target="_blank">
				<?php wp_nonce_field( 'woocommerce-settings' ); ?>
				<input type="hidden" name="save" value="wc_sf_export">
				<input type="hidden" name="wc_sf_export_account" value="1">
				<input type="hidden" name="wc_sf_export_credentials" value="1">
			</form>
		</div>
		<?php
	}

	/**
	 * Welcome screen.
	 */
	private function render_welcome() {
		?>
		<section class="wc-sf-section" data-step="0">
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Set up invoicing for your shop', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'The wizard connects your shop to SuperFaktúra and recommends settings for your payment and shipping methods. It takes about 5 minutes. Nothing is saved until you confirm the summary at the end.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<ol class="wc-sf-list">
					<li><span class="wc-sf-num" aria-hidden="true">1</span><span><b><?php esc_html_e( 'Connection.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'E-mail and API key from SuperFaktúra, choice of company.', 'woocommerce-superfaktura' ); ?></span></li>
					<li><span class="wc-sf-num" aria-hidden="true">2</span><span><b><?php esc_html_e( 'Documents.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Number sequences, logo and bank account from SuperFaktúra, and the invoice language.', 'woocommerce-superfaktura' ); ?></span></li>
					<li><span class="wc-sf-num" aria-hidden="true">3</span><span><b><?php esc_html_e( 'Payments.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'When to create an invoice or a proforma invoice for each payment method.', 'woocommerce-superfaktura' ); ?></span></li>
					<li><span class="wc-sf-num" aria-hidden="true">4</span><span><b><?php esc_html_e( 'Shipping.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Which delivery type the invoice states for each shipping method.', 'woocommerce-superfaktura' ); ?></span></li>
					<li><span class="wc-sf-num" aria-hidden="true">5</span><span><b><?php esc_html_e( 'Checkout and emails.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Company details at checkout, VAT number, OSS, eFaktúra and the invoice in emails.', 'woocommerce-superfaktura' ); ?></span></li>
				</ol>
			</div>
			<div class="wc-sf-foot">
				<span class="wc-sf-muted wc-sf-small"><?php esc_html_e( 'You can leave at any time, nothing is saved until the end.', 'woocommerce-superfaktura' ); ?></span>
				<button type="button" class="button button-primary button-hero" data-go="1"><?php esc_html_e( 'Start', 'woocommerce-superfaktura' ); ?></button>
			</div>
		</section>
		<?php
	}

	/**
	 * Step 1: connection.
	 */
	private function render_connection() {
		$saved     = get_option( 'woocommerce_sf_lang', null );
		$lang      = null !== $saved ? (string) $saved : ( self::has_credentials() ? 'sk' : $this->guess_account_country() );
		$has_key   = '' !== trim( (string) get_option( 'woocommerce_sf_apikey', '' ) );
		$countries = array(
			'sk' => array( __( 'Slovakia', 'woocommerce-superfaktura' ), 'moja.superfaktura.sk' ),
			'cz' => array( __( 'Czech Republic', 'woocommerce-superfaktura' ), 'moje.superfaktura.cz' ),
			'at' => array( __( 'Austria', 'woocommerce-superfaktura' ), 'meine.superfaktura.at' ),
		);
		?>
		<section class="wc-sf-section" data-step="1" data-title="<?php esc_attr_e( 'Connection', 'woocommerce-superfaktura' ); ?>" hidden>
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Connection to SuperFaktúra', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'The plugin connects to your account through the API. You can find the details in SuperFaktúra under Tools > API.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Account', 'woocommerce-superfaktura' ); ?>">
					<span class="wc-sf-label" id="wc-sf-lang-label"><?php esc_html_e( 'Where is your SuperFaktúra account?', 'woocommerce-superfaktura' ); ?></span>
					<div class="wc-sf-choices" role="radiogroup" aria-labelledby="wc-sf-lang-label">
						<?php foreach ( $countries as $value => $country ) : ?>
							<label class="wc-sf-choice">
								<input type="radio" name="lang" value="<?php echo esc_attr( $value ); ?>" data-initial="<?php echo esc_attr( null !== $saved ? $saved : 'sk' ); ?>" <?php checked( $lang, $value ); ?>>
								<b><?php echo esc_html( $country[0] ); ?></b>
								<span><?php echo esc_html( $country[1] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="wc-sf-row2">
					<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'API e-mail', 'woocommerce-superfaktura' ); ?>">
						<label for="wc-sf-email"><?php esc_html_e( 'API e-mail', 'woocommerce-superfaktura' ); ?></label>
						<input type="email" id="wc-sf-email" name="email" autocomplete="off" value="<?php echo esc_attr( get_option( 'woocommerce_sf_email', '' ) ); ?>" data-initial="<?php echo esc_attr( get_option( 'woocommerce_sf_email', '' ) ); ?>">
					</div>
					<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'API key', 'woocommerce-superfaktura' ); ?>" data-sum-secret>
						<label for="wc-sf-apikey"><?php esc_html_e( 'API key', 'woocommerce-superfaktura' ); ?></label>
						<input type="password" id="wc-sf-apikey" name="apikey" autocomplete="new-password" value="" placeholder="<?php echo $has_key ? esc_attr__( 'Saved key stays unchanged', 'woocommerce-superfaktura' ) : ''; ?>">
						<span class="wc-sf-hint">
							<?php
							printf(
								/* translators: %s: link to the API page in SuperFaktúra. */
								esc_html__( 'In SuperFaktúra under %s.', 'woocommerce-superfaktura' ),
								'<a href="' . esc_url( 'https://moja.superfaktura.sk/api_access' ) . '" data-sf-path="/api_access" target="_blank" rel="noopener">' . esc_html__( 'Tools > API', 'woocommerce-superfaktura' ) . '</a>'
							);
							?>
						</span>
					</div>
				</div>
				<label class="wc-sf-check" data-sum="<?php esc_attr_e( 'Sandbox', 'woocommerce-superfaktura' ); ?>">
					<input type="checkbox" name="sandbox" value="yes" data-initial="<?php echo esc_attr( get_option( 'woocommerce_sf_sandbox', 'no' ) ); ?>" <?php checked( 'yes', get_option( 'woocommerce_sf_sandbox', 'no' ) ); ?>>
					<span class="wc-sf-check-text">
						<b><?php esc_html_e( 'Sandbox', 'woocommerce-superfaktura' ); ?></b>
						<span class="wc-sf-muted wc-sf-small"><?php esc_html_e( 'For testing only. Use a test account from sandbox.superfaktura.sk or sandbox.superfaktura.cz, documents are not created in your real account.', 'woocommerce-superfaktura' ); ?></span>
					</span>
				</label>
				<div class="wc-sf-field" id="wc-sf-company-hint" <?php echo '' === (string) get_option( 'woocommerce_sf_company_id', '' ) ? 'hidden' : ''; ?>>
					<label for="wc-sf-company-id"><?php esc_html_e( 'Company ID', 'woocommerce-superfaktura' ); ?></label>
					<input type="text" id="wc-sf-company-id" name="company_hint" inputmode="numeric" value="<?php echo esc_attr( get_option( 'woocommerce_sf_company_id', '' ) ); ?>">
					<span class="wc-sf-hint"><?php esc_html_e( 'Needed when the account has several companies and its default company has no active subscription. You can find it in SuperFaktúra under Tools > API, in the company you want to use.', 'woocommerce-superfaktura' ); ?></span>
				</div>
				<div><button type="button" class="button" id="wc-sf-verify"><?php esc_html_e( 'Verify connection', 'woocommerce-superfaktura' ); ?></button></div>
				<div class="wc-sf-notice wc-sf-notice-error" id="wc-sf-connect-error" role="alert" hidden></div>
				<div id="wc-sf-companies" hidden>
					<div class="wc-sf-notice wc-sf-notice-ok" role="status"><b><?php esc_html_e( 'Connected to SuperFaktúra.', 'woocommerce-superfaktura' ); ?></b> <span class="wc-sf-small"><?php esc_html_e( 'Choose the company that should receive the documents from this shop.', 'woocommerce-superfaktura' ); ?></span></div>
					<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Company', 'woocommerce-superfaktura' ); ?>">
						<label for="wc-sf-company"><?php esc_html_e( 'Company', 'woocommerce-superfaktura' ); ?></label>
						<select id="wc-sf-company" name="company_id" data-initial="<?php echo esc_attr( get_option( 'woocommerce_sf_company_id', '' ) ); ?>"></select>
						<span class="wc-sf-hint"><?php esc_html_e( 'If you run several shops in different companies, each shop uses its own company.', 'woocommerce-superfaktura' ); ?></span>
					</div>
				</div>
			</div>
			<?php $this->render_foot( 1, __( 'Verify the connection to continue.', 'woocommerce-superfaktura' ) ); ?>
		</section>
		<?php
	}

	/**
	 * Step 2: documents.
	 */
	private function render_documents() {
		$language  = (string) get_option( 'woocommerce_sf_invoice_language', '' );
		$languages = array(
			'country'  => __( 'By customer country', 'woocommerce-superfaktura' ),
			'locale'   => __( 'Site language, otherwise the default language of SuperFaktúra', 'woocommerce-superfaktura' ),
			'endpoint' => __( 'Default language of SuperFaktúra', 'woocommerce-superfaktura' ),
		);
		// A configured site keeps what it uses now, a new one starts with the recommendation.
		$selected  = '' !== $language ? $language : ( self::has_credentials() ? 'endpoint' : 'country' );
		if ( ! isset( $languages[ $selected ] ) ) {
			// A specific language or WPML chosen in the settings stays available.
			$languages = array( $selected => __( 'Current setting', 'woocommerce-superfaktura' ) ) + $languages;
		}
		?>
		<section class="wc-sf-section" data-step="2" data-title="<?php esc_attr_e( 'Documents', 'woocommerce-superfaktura' ); ?>" hidden>
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Documents', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'These items were loaded from your company in SuperFaktúra. Choose which ones the documents from the shop use.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<div class="wc-sf-notice" id="wc-sf-company-loading" role="status" hidden><span><span class="wc-sf-spinner" aria-hidden="true"></span><?php esc_html_e( 'Loading data from SuperFaktúra…', 'woocommerce-superfaktura' ); ?></span></div>
				<div class="wc-sf-notice wc-sf-notice-error" id="wc-sf-company-failed" hidden>
					<span id="wc-sf-company-error" role="alert"></span>
					<span><button type="button" class="button" id="wc-sf-company-retry"><?php esc_html_e( 'Try again', 'woocommerce-superfaktura' ); ?></button></span>
				</div>
				<div class="wc-sf-stack" id="wc-sf-docs" hidden>
					<div class="wc-sf-row2">
						<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Invoices', 'woocommerce-superfaktura' ); ?>">
							<label for="wc-sf-seq-invoice"><?php esc_html_e( 'Invoice number sequence', 'woocommerce-superfaktura' ); ?></label>
							<select id="wc-sf-seq-invoice" name="seq_invoice"></select>
						</div>
						<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Proforma invoices', 'woocommerce-superfaktura' ); ?>">
							<label for="wc-sf-seq-proforma"><?php esc_html_e( 'Proforma invoice number sequence', 'woocommerce-superfaktura' ); ?></label>
							<select id="wc-sf-seq-proforma" name="seq_proforma"></select>
						</div>
						<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Credit notes', 'woocommerce-superfaktura' ); ?>">
							<label for="wc-sf-seq-cancel"><?php esc_html_e( 'Credit note number sequence', 'woocommerce-superfaktura' ); ?></label>
							<select id="wc-sf-seq-cancel" name="seq_cancel"></select>
						</div>
						<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Logo', 'woocommerce-superfaktura' ); ?>">
							<label for="wc-sf-logo"><?php esc_html_e( 'Logo', 'woocommerce-superfaktura' ); ?></label>
							<select id="wc-sf-logo" name="logo"></select>
						</div>
					</div>
					<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Bank account', 'woocommerce-superfaktura' ); ?>">
						<label for="wc-sf-bank"><?php esc_html_e( 'Bank account on invoices', 'woocommerce-superfaktura' ); ?></label>
						<select id="wc-sf-bank" name="bank" class="wc-sf-wide"></select>
						<span class="wc-sf-hint"><?php esc_html_e( 'Customers send payments for proforma invoices to this account. For automatic pairing, the account must be connected in SuperFaktúra.', 'woocommerce-superfaktura' ); ?></span>
					</div>
					<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Invoice language', 'woocommerce-superfaktura' ); ?>" data-rec="country">
						<label for="wc-sf-language"><?php esc_html_e( 'Invoice language', 'woocommerce-superfaktura' ); ?></label>
						<select id="wc-sf-language" name="language" data-initial="<?php echo esc_attr( '' !== $language ? $language : 'endpoint' ); ?>"><?php echo $this->options( $languages, $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in options(). ?></select>
						<?php $this->render_why( __( 'A customer from the Czech Republic gets the invoice in Czech, from Austria in German. For countries whose language SuperFaktúra does not support, the fallback language from the settings is used.', 'woocommerce-superfaktura' ), true ); ?>
					</div>
				</div>
			</div>
			<?php $this->render_foot( 2 ); ?>
		</section>
		<?php
	}

	/**
	 * Step 3: payments.
	 */
	private function render_payments() {
		$gateways   = $this->get_gateways();
		$statuses   = $this->get_document_statuses();
		$all        = $this->get_order_statuses();
		$configured = self::has_credentials();
		$docs       = array(
			'both'     => __( 'Proforma invoice, then invoice', 'woocommerce-superfaktura' ),
			'proforma' => __( 'Proforma invoice only', 'woocommerce-superfaktura' ),
			'invoice'  => __( 'Invoice', 'woocommerce-superfaktura' ),
			'manual'   => __( 'Manually only', 'woocommerce-superfaktura' ),
		);
		// With the "multi" sync type, documents carry the callback themselves and nothing has to be set up in SuperFaktúra.
		$manual_pairing = 'multi' !== get_option( 'woocommerce_sf_sync_type', 'single' );
		// A new key is saved only when pairing is not in use yet (see ajax_save()).
		$show_key = '' !== (string) get_option( 'woocommerce_sf_sync_secret_key', '' ) || ! ( $configured && $this->pairing_in_use() );
		?>
		<section class="wc-sf-section" data-step="3" data-title="<?php esc_attr_e( 'Payments', 'woocommerce-superfaktura' ); ?>" hidden>
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Payments', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'For each payment method enabled in your shop, we recommend which document to create and when. You can change the recommendation.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<?php if ( ! $gateways ) : ?>
					<div class="wc-sf-notice"><?php esc_html_e( 'No payment method is enabled in your shop. Enable them in WooCommerce > Settings > Payments and set up the documents later in the plugin settings.', 'woocommerce-superfaktura' ); ?></div>
				<?php else : ?>
					<div class="wc-sf-rows">
						<?php
						$index = 0;
						foreach ( $gateways as $id => $gateway ) :
							$recommendation = $this->recommend_gateway( $gateway );
							$cur_invoice    = $this->current( 'woocommerce_sf_invoice_regular_' . $id );
							$cur_proforma   = $this->current( 'woocommerce_sf_invoice_proforma_' . $id );
							$cur_paid       = $this->current( 'woocommerce_sf_invoice_regular_' . $id . '_set_as_paid' );
							$saved          = null !== $cur_invoice || null !== $cur_proforma;

							if ( $saved || $configured ) {
								// What the plugin does now; a gateway never saved creates no documents.
								$invoice  = empty( $cur_invoice ) ? '0' : (string) $cur_invoice;
								$proforma = empty( $cur_proforma ) ? '0' : (string) $cur_proforma;
								$paid     = 'yes' === $cur_paid;
							} else {
								$invoice  = (string) $recommendation['invoice'];
								$proforma = (string) $recommendation['proforma'];
								$paid     = ! empty( $recommendation['paid'] );
							}
							$doc = $this->doc_type( $proforma, $invoice );

							// A status that is not chosen keeps the recommended one for when the document type changes.
							$invoice_select  = '0' === $invoice ? ( '0' !== $recommendation['invoice'] ? $recommendation['invoice'] : 'processing' ) : $invoice;
							$proforma_select = '0' === $proforma ? ( '0' !== $recommendation['proforma'] ? $recommendation['proforma'] : 'on-hold' ) : $proforma;
							$rec             = array(
								'doc'      => $recommendation['doc'],
								'proforma' => $recommendation['proforma'],
								'invoice'  => $recommendation['invoice'],
								'paid'     => ! empty( $recommendation['paid'] ),
							);
							?>
							<div class="wc-sf-r" data-gateway="<?php echo esc_attr( $id ); ?>" data-rec="<?php echo esc_attr( wp_json_encode( $rec ) ); ?>" data-sum-row="<?php echo esc_attr( $this->gateway_title( $gateway ) ); ?>">
								<input type="hidden" name="gw[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $id ); ?>">
								<input type="hidden" name="gw[<?php echo esc_attr( $index ); ?>][paid_auto]" value="1" data-f="paid_auto" disabled>
								<div class="wc-sf-r-name">
									<b><?php echo esc_html( $this->gateway_title( $gateway ) ); ?></b>
									<span class="wc-sf-id"><?php echo esc_html( $id ); ?></span>
									<?php if ( 'manual' !== $recommendation['doc'] ) : ?>
										<span><?php echo $this->pill(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<?php endif; ?>
								</div>
								<div class="wc-sf-r-set">
									<div class="wc-sf-r-ctrls">
										<div class="wc-sf-field">
											<label for="wc-sf-gw-doc-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Document', 'woocommerce-superfaktura' ); ?></label>
											<select id="wc-sf-gw-doc-<?php echo esc_attr( $index ); ?>" name="gw[<?php echo esc_attr( $index ); ?>][doc]" data-f="doc" data-initial="<?php echo esc_attr( $this->doc_type( empty( $cur_proforma ) ? '0' : (string) $cur_proforma, empty( $cur_invoice ) ? '0' : (string) $cur_invoice ) ); ?>"><?php echo $this->options( $docs, $doc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
										</div>
										<div class="wc-sf-r-pair" data-show="both proforma invoice">
											<div class="wc-sf-field" data-show="both proforma">
												<label for="wc-sf-gw-pf-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Create the proforma invoice in status', 'woocommerce-superfaktura' ); ?></label>
												<select id="wc-sf-gw-pf-<?php echo esc_attr( $index ); ?>" name="gw[<?php echo esc_attr( $index ); ?>][proforma]" data-f="proforma" data-initial="<?php echo esc_attr( empty( $cur_proforma ) ? '0' : (string) $cur_proforma ); ?>"><?php echo $this->options( $this->with_status( $statuses, $proforma_select, $all ), $proforma_select ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
											</div>
											<div class="wc-sf-field" data-show="both invoice">
												<label for="wc-sf-gw-inv-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Create the invoice in status', 'woocommerce-superfaktura' ); ?></label>
												<select id="wc-sf-gw-inv-<?php echo esc_attr( $index ); ?>" name="gw[<?php echo esc_attr( $index ); ?>][invoice]" data-f="invoice" data-initial="<?php echo esc_attr( empty( $cur_invoice ) ? '0' : (string) $cur_invoice ); ?>"><?php echo $this->options( $this->with_status( $statuses, $invoice_select, $all ), $invoice_select ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
											</div>
										</div>
										<p class="wc-sf-notice wc-sf-notice-warn wc-sf-small" data-same-status hidden><?php esc_html_e( 'The proforma invoice and the invoice would be created in the same status.', 'woocommerce-superfaktura' ); ?></p>
										<div class="wc-sf-field">
											<label for="wc-sf-gw-pt-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Payment type in SuperFaktúra', 'woocommerce-superfaktura' ); ?></label>
											<select id="wc-sf-gw-pt-<?php echo esc_attr( $index ); ?>" name="gw[<?php echo esc_attr( $index ); ?>][pay_type]" data-f="pay_type" data-pay-type="<?php echo esc_attr( $id ); ?>"></select>
										</div>
									</div>
									<div data-show="both invoice">
										<label class="wc-sf-check">
											<input type="checkbox" name="gw[<?php echo esc_attr( $index ); ?>][paid]" value="yes" data-f="paid" data-initial="<?php echo esc_attr( 'yes' === $cur_paid ? 'yes' : 'no' ); ?>" <?php checked( $paid ); ?>>
											<span class="wc-sf-check-text"><?php esc_html_e( 'Create the invoice as paid', 'woocommerce-superfaktura' ); ?></span>
										</label>
										<p class="wc-sf-hint wc-sf-check-note" data-paid-note hidden></p>
									</div>
									<?php $this->render_why( $recommendation['why'], 'manual' !== $recommendation['doc'], false ); ?>
								</div>
							</div>
							<?php
							$index++;
						endforeach;
						?>
					</div>
					<?php if ( $manual_pairing ) : ?>
						<div class="wc-sf-pair" id="wc-sf-pairing" hidden>
							<div>
								<h2><?php esc_html_e( 'Automatic pairing of bank transfers', 'woocommerce-superfaktura' ); ?></h2>
								<p class="wc-sf-muted wc-sf-small"><?php esc_html_e( 'When SuperFaktúra pairs a payment with the proforma invoice, it notifies the shop and the order is marked as paid. For this, enter these two values in SuperFaktúra once.', 'woocommerce-superfaktura' ); ?></p>
							</div>
							<div class="wc-sf-field">
								<span class="wc-sf-label"><?php esc_html_e( 'Callback URL', 'woocommerce-superfaktura' ); ?></span>
								<div class="wc-sf-copyline"><code><?php echo esc_html( site_url( '/' ) . '?callback=wc_sf_order_paid' ); ?></code><button type="button" class="button" data-copy="<?php echo esc_attr( site_url( '/' ) . '?callback=wc_sf_order_paid' ); ?>" aria-label="<?php esc_attr_e( 'Copy the Callback URL', 'woocommerce-superfaktura' ); ?>"><?php esc_html_e( 'Copy', 'woocommerce-superfaktura' ); ?></button></div>
							</div>
							<div class="wc-sf-field">
								<span class="wc-sf-label"><?php esc_html_e( 'Secret Key', 'woocommerce-superfaktura' ); ?></span>
								<?php if ( $show_key ) : ?>
									<div class="wc-sf-copyline"><code><?php echo esc_html( $this->secret_key ); ?></code><button type="button" class="button" data-copy="<?php echo esc_attr( $this->secret_key ); ?>" aria-label="<?php esc_attr_e( 'Copy the Secret Key', 'woocommerce-superfaktura' ); ?>"><?php esc_html_e( 'Copy', 'woocommerce-superfaktura' ); ?></button></div>
									<input type="hidden" name="secret_key" value="<?php echo esc_attr( $this->secret_key ); ?>">
								<?php else : ?>
									<span class="wc-sf-hint"><?php esc_html_e( 'The Secret Key is not set yet. You can create it in the plugin settings under Integration.', 'woocommerce-superfaktura' ); ?></span>
								<?php endif; ?>
							</div>
							<p class="wc-sf-small">
								<?php
								printf(
									/* translators: %1$s: link to SuperFaktúra settings, %2$s: field name, %3$s: field name. */
									esc_html__( 'In SuperFaktúra, in the company you selected, open %1$s and enter them in the fields %2$s and %3$s.', 'woocommerce-superfaktura' ),
									'<a href="' . esc_url( 'https://moja.superfaktura.sk/users/edit_profile/settings#tab-bmails' ) . '" data-sf-path="/users/edit_profile/settings#tab-bmails" target="_blank" rel="noopener">' . esc_html__( 'the settings', 'woocommerce-superfaktura' ) . '</a>',
									'<b>' . esc_html__( 'Callback for payment', 'woocommerce-superfaktura' ) . '</b>',
									'<b>' . esc_html__( 'Secret Key', 'woocommerce-superfaktura' ) . '</b>'
								);
								?>
							</p>
							<label class="wc-sf-check">
								<input type="checkbox" id="wc-sf-pairing-done">
								<span class="wc-sf-check-text">
									<b><?php esc_html_e( 'This is set up in SuperFaktúra', 'woocommerce-superfaktura' ); ?></b>
									<span class="wc-sf-muted wc-sf-small"><?php esc_html_e( 'If you do it later, the summary reminds you.', 'woocommerce-superfaktura' ); ?></span>
								</span>
							</label>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php $this->render_foot( 3 ); ?>
		</section>
		<?php
	}

	/**
	 * Document type of a gateway by its proforma and invoice status.
	 *
	 * @param string $proforma Proforma status, "0" for none.
	 * @param string $invoice  Invoice status, "0" for none.
	 * @return string both, proforma, invoice or manual.
	 */
	private function doc_type( $proforma, $invoice ) {
		if ( '0' !== $proforma ) {
			return '0' !== $invoice ? 'both' : 'proforma';
		}

		return '0' !== $invoice ? 'invoice' : 'manual';
	}

	/**
	 * Statuses offered in a select, with a chosen status the list leaves out.
	 *
	 * @param array  $statuses Offered statuses.
	 * @param string $selected Chosen status.
	 * @param array  $all      All statuses.
	 * @return array
	 */
	private function with_status( $statuses, $selected, $all ) {
		if ( ! isset( $statuses[ $selected ] ) && isset( $all[ $selected ] ) ) {
			$statuses[ $selected ] = $all[ $selected ];
		}

		return $statuses;
	}

	/**
	 * Whether automatic pairing may already work: an enabled gateway creates proforma invoices, or invoices that are
	 * not created as paid.
	 *
	 * @return bool
	 */
	private function pairing_in_use() {
		foreach ( array_keys( $this->get_gateways() ) as $id ) {
			// Proforma invoices, or invoices that are not created as paid, can be paired in SuperFaktúra.
			if ( ! empty( $this->current( 'woocommerce_sf_invoice_proforma_' . $id ) ) ) {
				return true;
			}
			if ( ! empty( $this->current( 'woocommerce_sf_invoice_regular_' . $id ) ) && 'yes' !== $this->current( 'woocommerce_sf_invoice_regular_' . $id . '_set_as_paid' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Posted order status of a gateway row: a known status, or the saved one even when it is no longer registered.
	 *
	 * @param array  $row      Posted row.
	 * @param string $field    Field name.
	 * @param array  $statuses Known statuses.
	 * @param string $saved    Saved status.
	 * @return string Status, or "0" for none.
	 */
	private function posted_status( $row, $field, $statuses, $saved ) {
		if ( ! isset( $row[ $field ] ) || ! is_string( $row[ $field ] ) ) {
			return '0';
		}
		$status = $row[ $field ];

		return isset( $statuses[ $status ] ) || ( '' !== $saved && $status === $saved ) ? $status : '0';
	}

	/**
	 * Step 4: shipping.
	 */
	private function render_shipping() {
		$methods = $this->get_shipping_methods();
		$name    = get_option( 'woocommerce_sf_shipping_item_name', null );
		$why     = array(
			'personal'     => __( 'Recognized as personal pickup.', 'woocommerce-superfaktura' ),
			'pickup_point' => __( 'Recognized as delivery to a pickup point or parcel locker.', 'woocommerce-superfaktura' ),
			'courier'      => __( 'Recognized as delivery by courier.', 'woocommerce-superfaktura' ),
			'mail'         => __( 'Recognized as delivery by post.', 'woocommerce-superfaktura' ),
			'0'            => __( 'We could not recognize this method. Choose which delivery type the invoice states. The shipping price is on the invoice either way.', 'woocommerce-superfaktura' ),
		);
		?>
		<section class="wc-sf-section" data-step="4" data-title="<?php esc_attr_e( 'Shipping', 'woocommerce-superfaktura' ); ?>" hidden>
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Shipping', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'The delivery method appears on the invoice. We recommend it based on the shipping methods in your shop.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<?php if ( ! $methods ) : ?>
					<div class="wc-sf-notice"><?php esc_html_e( 'Your shop has no enabled shipping methods. You can add them in WooCommerce > Settings > Shipping and set them up later in the plugin settings.', 'woocommerce-superfaktura' ); ?></div>
				<?php else : ?>
					<div class="wc-sf-rows">
						<?php
						$index = 0;
						foreach ( $methods as $key => $method ) :
							$recommended = $this->recommend_shipping( $method['id'], $method['title'] );
							?>
							<div class="wc-sf-r" data-sum-row="<?php echo esc_attr( $method['title'] . ' (' . $method['zone'] . ')' ); ?>" data-rec-shipping="<?php echo esc_attr( $recommended ); ?>">
								<input type="hidden" name="ship[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $key ); ?>">
								<div class="wc-sf-r-name">
									<b><?php echo esc_html( $method['title'] ); ?></b>
									<span class="wc-sf-id"><?php echo esc_html( $method['zone'] . ' · ' . $key ); ?></span>
									<span><?php echo $this->pill(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								</div>
								<div class="wc-sf-r-set">
									<div class="wc-sf-r-ctrls">
										<div class="wc-sf-field">
											<label for="wc-sf-ship-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'On the invoice', 'woocommerce-superfaktura' ); ?></label>
											<select id="wc-sf-ship-<?php echo esc_attr( $index ); ?>" name="ship[<?php echo esc_attr( $index ); ?>][type]" data-shipping="<?php echo esc_attr( $key ); ?>"></select>
										</div>
									</div>
									<?php $this->render_why( isset( $why[ $recommended ] ) ? $why[ $recommended ] : $why['0'], '0' !== $recommended, false ); ?>
								</div>
							</div>
							<?php
							$index++;
						endforeach;
						?>
					</div>
				<?php endif; ?>
				<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'Shipping item name', 'woocommerce-superfaktura' ); ?>">
					<label for="wc-sf-shipping-name"><?php esc_html_e( 'Shipping item name on the invoice', 'woocommerce-superfaktura' ); ?></label>
					<input type="text" id="wc-sf-shipping-name" name="shipping_item_name" value="<?php echo esc_attr( null === $name ? 'Poštovné' : $name ); ?>" data-initial="<?php echo esc_attr( null === $name ? 'Poštovné' : $name ); ?>">
				</div>
			</div>
			<?php $this->render_foot( 4 ); ?>
		</section>
		<?php
	}

	/**
	 * Countries in the EU other than the shop's, to which the shop sells.
	 *
	 * @return string[] Country names.
	 */
	private function get_other_eu_countries() {
		$base    = WC()->countries->get_base_country();
		$allowed = array_keys( WC()->countries->get_allowed_countries() );
		$names   = WC()->countries->get_countries();
		$other   = array();
		foreach ( array_intersect( $allowed, $this->wc_sf->eu_countries ) as $country ) {
			if ( $country !== $base ) {
				$other[] = isset( $names[ $country ] ) ? $names[ $country ] : $country;
			}
		}

		return $other;
	}

	/**
	 * Checkbox row of the checkout step.
	 *
	 * @param string $name        Field name.
	 * @param bool   $checked     Checked.
	 * @param string $initial     Saved value, or empty when never saved.
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @param string $pill        "recommended" (pill while checked), "applies" (always) or empty.
	 * @param string $requires    Name of the checkbox this one depends on.
	 * @param string $note        Note shown while the checkbox it depends on is off.
	 */
	private function render_check( $name, $checked, $initial, $title, $description, $pill, $requires = '', $note = '' ) {
		?>
		<label class="wc-sf-check" data-sum="<?php echo esc_attr( $title ); ?>" <?php echo 'recommended' === $pill ? 'data-rec="yes"' : ''; ?> <?php echo $requires ? 'data-requires="' . esc_attr( $requires ) . '"' : ''; ?>>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="yes" data-initial="<?php echo esc_attr( $initial ); ?>" <?php checked( $checked ); ?>>
			<span class="wc-sf-check-text">
				<b>
					<?php echo esc_html( $title ); ?>
					<?php if ( 'recommended' === $pill ) : ?>
						<?php echo $this->pill(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php elseif ( 'applies' === $pill ) : ?>
						<span class="wc-sf-pill wc-sf-pill-blue"><?php esc_html_e( 'If it applies to you', 'woocommerce-superfaktura' ); ?></span>
					<?php endif; ?>
				</b>
				<span class="wc-sf-muted wc-sf-small"><?php echo esc_html( $description ); ?></span>
				<?php if ( $note ) : ?>
					<span class="wc-sf-check-warn wc-sf-small" data-requires-note hidden><?php echo esc_html( $note ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Checkbox state: the saved value, or the plugin default on a configured site, or the recommendation on a new one.
	 *
	 * @param string $name        Option name.
	 * @param bool   $recommended Recommended value.
	 * @param string $default     Value the plugin uses while the option is not saved.
	 * @return array checked, initial value (yes/no).
	 */
	private function check_state( $name, $recommended, $default ) {
		$value = $this->current( $name );
		if ( null === $value ) {
			$checked = self::has_credentials() ? 'yes' === $default : $recommended;

			return array( $checked, 'yes' === $default ? 'yes' : 'no' );
		}
		$checked = 'yes' === $value || 'customer' === $value;

		return array( $checked, $checked ? 'yes' : 'no' );
	}

	/**
	 * Step 5: checkout and emails.
	 */
	private function render_checkout() {
		$other_eu = $this->get_other_eu_countries();

		if ( ! $other_eu ) {
			$oss_description = __( 'Your shop does not sell to other EU countries, so OSS is not needed.', 'woocommerce-superfaktura' );
		} elseif ( count( $other_eu ) > 5 ) {
			/* translators: %d: number of countries. */
			$oss_description = sprintf( __( 'Your shop also sells to %d other EU countries. Invoices to private customers there are created in the OSS scheme. Enable only if you are registered for OSS, or check with your accountant.', 'woocommerce-superfaktura' ), count( $other_eu ) );
		} else {
			/* translators: %s: list of countries. */
			$oss_description = sprintf( __( 'Your shop also sells to %s. Invoices to private customers there are created in the OSS scheme. Enable only if you are registered for OSS, or check with your accountant.', 'woocommerce-superfaktura' ), implode( ', ', $other_eu ) );
		}

		$company  = $this->check_state( 'woocommerce_sf_add_company_billing_fields', true, 'yes' );
		$vies     = $this->check_state( 'woocommerce_sf_validate_eu_vat_number', true, 'no' );
		$reverse  = $this->check_state( 'woocommerce_sf_exempt_vat_on_valid_vat_number', false, 'no' );
		$oss      = $this->check_state( 'woocommerce_sf_oss', false, 'no' );
		$pdf      = $this->check_state( 'woocommerce_sf_invoice_pdf_attachment', true, 'no' );
		$link     = $this->check_state( 'woocommerce_sf_order_received_invoice_link', true, 'yes' );
		$bysquare = $this->check_state( 'woocommerce_sf_bysquare', true, 'yes' );

		// eFaktúra stays off until the shop decides: the wizard cannot tell whether eFaktúra is activated in SuperFaktúra.
		$peppol        = $this->check_state( 'woocommerce_sf_peppol', false, 'no' );
		$peppol_pdf    = $this->check_state( 'woocommerce_sf_peppol_email_pdf', true, 'yes' );
		$peppol_status = $this->current( 'woocommerce_sf_peppol_status' );
		$peppol_status = null !== $peppol_status && '' !== $peppol_status ? $peppol_status : 'completed';

		$vies_description = __( 'An invalid VAT number is not accepted.', 'woocommerce-superfaktura' );
		if ( 'allow' === get_option( 'woocommerce_sf_validate_eu_vat_number_behavior', 'allow' ) ) {
			$vies_description .= ' ' . __( 'When the VIES service is unavailable, the order goes through.', 'woocommerce-superfaktura' );
		}
		?>
		<section class="wc-sf-section" data-step="5" data-title="<?php esc_attr_e( 'Checkout and emails', 'woocommerce-superfaktura' ); ?>" hidden>
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Checkout and emails', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'What customers fill in at checkout and what they receive with the order.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<div class="wc-sf-checks">
					<h2><?php esc_html_e( 'Checkout', 'woocommerce-superfaktura' ); ?></h2>
					<?php
					$this->render_check( 'company_fields', $company[0], $company[1], __( 'Company details at checkout', 'woocommerce-superfaktura' ), __( 'The "Buy as Business client" checkbox with the company ID, tax ID and VAT number fields. The details appear on the invoice.', 'woocommerce-superfaktura' ), 'recommended' );
					$this->render_check( 'vies', $vies[0], $vies[1], __( 'Validate the VAT number in VIES', 'woocommerce-superfaktura' ), $vies_description, 'recommended' );
					$this->render_check( 'reverse_charge', $reverse[0], $reverse[1], __( 'No VAT for EU businesses with a valid VAT number', 'woocommerce-superfaktura' ), __( 'Reverse charge. Enable if you sell to businesses in other EU countries and you are a VAT payer.', 'woocommerce-superfaktura' ), 'applies', 'vies', __( 'Works only with the VAT number validation in VIES turned on.', 'woocommerce-superfaktura' ) );
					$this->render_check( 'oss', $oss[0], $oss[1], __( 'OSS for sales to private customers in other EU countries', 'woocommerce-superfaktura' ), $oss_description, 'applies' );
					?>
				</div>
				<div class="wc-sf-checks" id="wc-sf-efaktura">
					<h2><?php esc_html_e( 'eFaktúra', 'woocommerce-superfaktura' ); ?></h2>
					<?php
					$this->render_check( 'peppol', $peppol[0], $peppol[1], __( 'Send invoices and credit notes to companies from Slovakia via Peppol', 'woocommerce-superfaktura' ), __( 'Enable if you are a VAT payer, sell to companies in Slovakia and have eFaktúra activated in SuperFaktúra. Other customers get documents as before.', 'woocommerce-superfaktura' ), 'applies', 'company_fields', __( 'Works only with company details at checkout.', 'woocommerce-superfaktura' ) );
					?>
					<div class="wc-sf-field" data-sum="<?php esc_attr_e( 'eFaktúra at order status', 'woocommerce-superfaktura' ); ?>" data-peppol-only>
						<label for="wc-sf-peppol-status"><?php esc_html_e( 'Send the invoice at order status', 'woocommerce-superfaktura' ); ?></label>
						<select id="wc-sf-peppol-status" name="peppol_status" data-initial="<?php echo esc_attr( $peppol_status ); ?>"><?php echo $this->options( $this->get_document_statuses(), $peppol_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
						<span class="wc-sf-hint"><?php esc_html_e( 'The invoice must exist at this status. A sent invoice is corrected by a credit note only.', 'woocommerce-superfaktura' ); ?></span>
					</div>
					<div data-peppol-only>
						<?php $this->render_check( 'peppol_email_pdf', $peppol_pdf[0], $peppol_pdf[1], __( 'PDF also with eFaktúra', 'woocommerce-superfaktura' ), __( 'The PDF of documents sent via Peppol stays in the order e-mails. The customer might book the document twice.', 'woocommerce-superfaktura' ), '' ); ?>
					</div>
				</div>
				<div class="wc-sf-checks">
					<h2><?php esc_html_e( 'Emails and order page', 'woocommerce-superfaktura' ); ?></h2>
					<?php
					$this->render_check( 'pdf_attachment', $pdf[0], $pdf[1], __( 'Invoice as a PDF attachment', 'woocommerce-superfaktura' ), __( 'The document is attached to the emails the customer receives from the shop.', 'woocommerce-superfaktura' ), 'recommended' );
					$this->render_check( 'invoice_link', $link[0], $link[1], __( 'Invoice link after the order', 'woocommerce-superfaktura' ), __( 'On the "Order received" page.', 'woocommerce-superfaktura' ), 'recommended' );
					$this->render_check( 'bysquare', $bysquare[0], $bysquare[1], __( 'QR code for payment on the invoice', 'woocommerce-superfaktura' ), __( 'The customer pays a proforma invoice by scanning the code in their banking app.', 'woocommerce-superfaktura' ), 'recommended' );
					?>
				</div>
			</div>
			<?php $this->render_foot( 5 ); ?>
		</section>
		<?php
	}

	/**
	 * Step 6: summary.
	 *
	 * @param array $steps Step titles.
	 */
	private function render_summary( $steps ) {
		?>
		<section class="wc-sf-section" data-step="6" hidden>
			<div class="wc-sf-body">
				<div>
					<h1><?php esc_html_e( 'Summary', 'woocommerce-superfaktura' ); ?></h1>
					<p class="wc-sf-lead"><?php esc_html_e( 'These settings will be saved. All other settings stay as they are, you can find them in WooCommerce > Settings > SuperFaktúra.', 'woocommerce-superfaktura' ); ?></p>
				</div>
				<div class="wc-sf-notice wc-sf-notice-warn" id="wc-sf-pairing-warning" hidden>
					<?php if ( self::has_credentials() ) : ?>
						<b><?php esc_html_e( 'Check that automatic pairing is set up in SuperFaktúra.', 'woocommerce-superfaktura' ); ?></b>
					<?php else : ?>
						<b><?php esc_html_e( 'Automatic pairing is not set up in SuperFaktúra yet.', 'woocommerce-superfaktura' ); ?></b>
					<?php endif; ?>
					<span class="wc-sf-small"><?php esc_html_e( 'Without it, orders paid by bank transfer are not marked as paid automatically. The details are in the Payments step.', 'woocommerce-superfaktura' ); ?></span>
				</div>
				<?php if ( self::has_credentials() ) : ?>
					<div class="wc-sf-notice">
						<span class="wc-sf-small"><?php esc_html_e( 'The plugin is already set up. Settings that will change are marked. If you might need the current settings again, download a backup first.', 'woocommerce-superfaktura' ); ?></span>
						<span><button type="submit" form="wc-sf-backup-form" class="button"><?php esc_html_e( 'Download a backup of the current settings', 'woocommerce-superfaktura' ); ?></button></span>
					</div>
				<?php endif; ?>
				<div class="wc-sf-sum" id="wc-sf-summary"></div>
				<div class="wc-sf-notice wc-sf-notice-error" id="wc-sf-save-error" role="alert" hidden></div>
			</div>
			<div class="wc-sf-foot">
				<button type="button" class="button button-large" data-go="5"><?php esc_html_e( 'Back', 'woocommerce-superfaktura' ); ?></button>
				<button type="button" class="button button-primary button-hero" id="wc-sf-save"><?php esc_html_e( 'Save settings', 'woocommerce-superfaktura' ); ?></button>
			</div>
			<template id="wc-sf-sum-group">
				<div class="wc-sf-sum-g">
					<div class="wc-sf-sum-h"><h2></h2><button type="button" class="wc-sf-link wc-sf-small"><?php esc_html_e( 'Edit', 'woocommerce-superfaktura' ); ?></button></div>
					<dl></dl>
				</div>
			</template>
		</section>
		<?php
	}

	/**
	 * Done screen.
	 */
	private function render_done() {
		?>
		<section class="wc-sf-section" data-step="7" hidden>
			<div class="wc-sf-body">
				<div class="wc-sf-done-head">
					<span class="wc-sf-done-mark dashicons dashicons-yes" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'Done, invoicing is set up', 'woocommerce-superfaktura' ); ?></h1>
						<p class="wc-sf-lead"><?php esc_html_e( 'The next order gets its documents according to these settings.', 'woocommerce-superfaktura' ); ?></p>
					</div>
				</div>
				<div class="wc-sf-checks">
					<h2><?php esc_html_e( 'What we recommend next', 'woocommerce-superfaktura' ); ?></h2>
					<ul class="wc-sf-list wc-sf-list-plain">
						<li id="wc-sf-done-sandbox" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><b><?php esc_html_e( 'The sandbox is on.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Documents are created in the test account. Before you start selling, turn the sandbox off in the plugin settings.', 'woocommerce-superfaktura' ); ?></span></li>
						<li id="wc-sf-done-pairing" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><b><?php self::has_credentials() ? esc_html_e( 'Check automatic pairing in SuperFaktúra.', 'woocommerce-superfaktura' ) : esc_html_e( 'Set up automatic pairing in SuperFaktúra.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Enter the Callback URL and Secret Key in the company settings, otherwise orders paid by bank transfer are not marked as paid automatically. You can find them in the plugin settings under Integration.', 'woocommerce-superfaktura' ); ?></span></li>
						<li><span class="dashicons dashicons-cart" aria-hidden="true"></span><span><b><?php esc_html_e( 'Try one order.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Ideally with the payment method your customers use most. You can see the result in the order under Invoices and in the API log.', 'woocommerce-superfaktura' ); ?></span></li>
						<li><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><span><b><?php esc_html_e( 'Go through the other settings.', 'woocommerce-superfaktura' ); ?></b> <?php esc_html_e( 'Accounting, cash registers and more options are in the plugin settings.', 'woocommerce-superfaktura' ); ?></span></li>
					</ul>
				</div>
			</div>
			<div class="wc-sf-foot">
				<a class="button button-large" href="<?php echo esc_url( $this->get_settings_url( 'api_log' ) ); ?>"><?php esc_html_e( 'API log', 'woocommerce-superfaktura' ); ?></a>
				<a class="button button-primary button-large" href="<?php echo esc_url( $this->get_settings_url() ); ?>"><?php esc_html_e( 'Open the plugin settings', 'woocommerce-superfaktura' ); ?></a>
			</div>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------------------------------------------
	 * Requests
	 * ------------------------------------------------------------------------------------------------------ */

	/**
	 * Check the nonce and the capability of a wizard request.
	 */
	private function check_request() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do this.', 'woocommerce-superfaktura' ) ), 403 );
		}
	}

	/**
	 * Posted string.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private function posted( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check_request().
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * API credentials from the request. An empty API key means the saved one.
	 *
	 * @param bool $with_company Whether to include the posted company ID.
	 * @return array
	 */
	private function posted_credentials( $with_company ) {
		$lang   = $this->posted( 'lang' );
		$apikey = $this->posted( 'apikey' );

		return array(
			'woocommerce_sf_lang'       => in_array( $lang, array( 'sk', 'cz', 'at' ), true ) ? $lang : 'sk',
			'woocommerce_sf_email'      => $this->posted( 'email' ),
			'woocommerce_sf_apikey'     => '' !== $apikey ? $apikey : (string) get_option( 'woocommerce_sf_apikey', '' ),
			'woocommerce_sf_company_id' => $with_company ? preg_replace( '/\D/', '', $this->posted( 'company_id' ) ) : '',
			'woocommerce_sf_sandbox'    => 'yes' === $this->posted( 'sandbox' ) ? 'yes' : 'no',
		);
	}

	/**
	 * API client for the wizard requests. They may use credentials that are not saved, so they do not overwrite the
	 * stored API usage of the saved account.
	 *
	 * @param array $credentials API credentials.
	 * @return WC_SF_Api
	 */
	private function sf_api( $credentials ) {
		$api = $this->wc_sf->sf_api( $credentials );
		$api->skipUsageInfo();

		return $api;
	}

	/**
	 * Error message of the last API request.
	 *
	 * @param WC_SF_Api $api      API client.
	 * @param mixed     $response Response with an error, if any.
	 * @return string
	 */
	private function api_error( $api, $response = null ) {
		$error   = $api->getLastError();
		$message = isset( $error['message'] ) ? wp_strip_all_tags( (string) $error['message'] ) : '';
		if ( '' === $message && is_object( $response ) && ! empty( $response->error_message ) && is_scalar( $response->error_message ) ) {
			// An error answered with a successful HTTP status.
			$message = wp_strip_all_tags( (string) $response->error_message );
		}

		// SuperFaktúra answers wrong credentials in English on all its versions.
		if ( '' === $message || false !== stripos( $message, 'Invalid API credentials' ) ) {
			return __( 'SuperFaktúra did not accept the request. Check the e-mail, the API key, the account country and the Sandbox setting.', 'woocommerce-superfaktura' );
		}

		return $message;
	}

	/**
	 * Verify the credentials and list the companies of the account.
	 */
	public function ajax_connect() {
		$this->check_request();

		$credentials = $this->posted_credentials( false );
		if ( '' === $credentials['woocommerce_sf_email'] || '' === $credentials['woocommerce_sf_apikey'] ) {
			wp_send_json_error( array( 'message' => __( 'Fill in the API e-mail and API key.', 'woocommerce-superfaktura' ) ) );
		}

		// Without a company ID, the API answers for the account's default company, which may have no subscription.
		$credentials['woocommerce_sf_company_id'] = preg_replace( '/\D/', '', $this->posted( 'company_hint' ) );

		$api      = $this->sf_api( $credentials );
		$response = $api->getUserCompaniesData( true );

		if ( ! is_object( $response ) || ! empty( $response->error ) || empty( $response->data ) ) {
			wp_send_json_error( array( 'message' => $this->api_error( $api, $response ) ) );
		}

		$companies = array();
		foreach ( (array) $response->data as $id => $company ) {
			$name        = isset( $company->UserProfile->company_name ) ? (string) $company->UserProfile->company_name : '';
			$companies[] = array(
				'id'   => (string) $id,
				'name' => wp_strip_all_tags( '' !== $name ? $name : (string) $id ),
			);
		}

		wp_send_json_success( array( 'companies' => $companies ) );
	}

	/**
	 * Load the number sequences, logos, bank accounts, payment and delivery types of the chosen company.
	 */
	public function ajax_company() {
		$this->check_request();

		$credentials = $this->posted_credentials( true );
		$api         = $this->sf_api( $credentials );

		$sequences = $api->getSequences();
		if ( ! is_object( $sequences ) || ! empty( $sequences->error ) ) {
			wp_send_json_error( array( 'message' => $this->api_error( $api, $sequences ) ) );
		}

		$result = array(
			'sequences' => array(),
			'logos'     => array(),
			'banks'     => array(),
			'payTypes'  => array(),
			'delivery'  => array(),
		);

		$result['sequences'] = WC_SF_Company_Lists::sequences( $sequences );
		$result['logos']     = WC_SF_Company_Lists::logos( $api->getLogos() );
		$result['banks']     = WC_SF_Company_Lists::banks( $api->getBankAccounts() );

		$data = $api->get( '/dashboard/full_data' );
		if ( ! is_object( $data ) ) {
			// Without the payment and delivery types, saving would reset the current mappings.
			wp_send_json_error( array( 'message' => __( 'The payment and delivery types could not be loaded from SuperFaktúra.', 'woocommerce-superfaktura' ) . ' ' . __( 'The request failed. Check your internet connection and try again.', 'woocommerce-superfaktura' ) ) );
		}
		if ( isset( $data->company->custom_options->invoice ) ) {
			$invoice = $data->company->custom_options->invoice;
			foreach ( array( 'payment' => 'payTypes', 'delivery' => 'delivery' ) as $source => $target ) {
				foreach ( isset( $invoice->$source ) ? (array) $invoice->$source : array() as $item ) {
					if ( ! is_object( $item ) || ! isset( $item->key ) || empty( $item->active ) || 'custom' === $item->key ) {
						continue;
					}
					$result[ $target ][] = array(
						'key'   => (string) $item->key,
						'label' => wp_strip_all_tags( (string) $item->name ),
					);
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * Save the confirmed settings.
	 */
	public function ajax_save() {
		$this->check_request();

		$credentials = $this->posted_credentials( true );
		if ( '' === $credentials['woocommerce_sf_email'] || '' === $credentials['woocommerce_sf_apikey'] ) {
			wp_send_json_error( array( 'message' => __( 'Fill in the API e-mail and API key.', 'woocommerce-superfaktura' ) ) );
		}

		// Never save credentials that SuperFaktúra does not accept.
		$api       = $this->sf_api( $credentials );
		$sequences = $api->getSequences();
		if ( ! is_object( $sequences ) || ! empty( $sequences->error ) ) {
			wp_send_json_error( array( 'message' => $this->api_error( $api, $sequences ) ) );
		}

		// On a configured site, settings that were never saved and stay at what the plugin does without them are not written.
		$configured = self::has_credentials();
		$options    = array();

		foreach ( $credentials as $name => $value ) {
			$options[ $name ] = $value;
		}

		$ids = array(
			'seq_invoice'  => 'woocommerce_sf_invoice_sequence_id',
			'seq_proforma' => 'woocommerce_sf_proforma_invoice_sequence_id',
			'seq_cancel'   => 'woocommerce_sf_cancel_sequence_id',
			'logo'         => 'woocommerce_sf_logo_id',
			'bank'         => 'woocommerce_sf_bank_account_id',
		);
		foreach ( $ids as $field => $name ) {
			$options[ $name ] = preg_replace( '/\D/', '', $this->posted( $field ) );
		}

		$language = $this->posted( 'language' );
		if ( '' !== $language && preg_match( '/^[a-z]{2,10}$/', $language ) ) {
			$options['woocommerce_sf_invoice_language'] = $language;
		}

		// Payments: only enabled gateways, only known statuses.
		$statuses = $this->get_order_statuses();
		$gateways = $this->get_gateways();
		$manual          = false;
		$proforma        = false;
		$skipped_fix     = false;
		$pairing_before  = $this->pairing_in_use();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in check_request(), each value is sanitized below.
		$posted_gateways = isset( $_POST['gw'] ) && is_array( $_POST['gw'] ) ? wp_unslash( $_POST['gw'] ) : array();
		foreach ( $posted_gateways as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || ! isset( $gateways[ $row['id'] ] ) ) {
				continue;
			}
			$id  = $row['id'];
			$doc = isset( $row['doc'] ) && in_array( $row['doc'], array( 'both', 'proforma', 'invoice', 'manual' ), true ) ? $row['doc'] : 'manual';

			$pay_type = isset( $row['pay_type'] ) && is_string( $row['pay_type'] ) ? sanitize_text_field( $row['pay_type'] ) : '0';
			$pay_type = '' !== $pay_type ? $pay_type : '0';
			if ( '0' !== $pay_type || ! $configured || null !== $this->current( 'woocommerce_sf_gateway_' . $id ) ) {
				$options[ 'woocommerce_sf_gateway_' . $id ] = $pay_type;
			}

			$unsaved = null === $this->current( 'woocommerce_sf_invoice_regular_' . $id ) && null === $this->current( 'woocommerce_sf_invoice_proforma_' . $id );
			if ( 'manual' === $doc && $configured && $unsaved ) {
				continue;
			}

			$saved_invoice   = (string) $this->current( 'woocommerce_sf_invoice_regular_' . $id );
			$saved_proforma  = (string) $this->current( 'woocommerce_sf_invoice_proforma_' . $id );
			$invoice_status  = $this->posted_status( $row, 'invoice', $statuses, $saved_invoice );
			$proforma_status = $this->posted_status( $row, 'proforma', $statuses, $saved_proforma );
			if ( 'manual' === $doc ) {
				$invoice_status  = '0';
				$proforma_status = '0';
				// The manual buttons apply to all orders, so a configured site gets them only for a newly manual gateway.
				if ( ! $configured || 'manual' !== $this->doc_type( '' !== $saved_proforma ? $saved_proforma : '0', '' !== $saved_invoice ? $saved_invoice : '0' ) ) {
					$manual = true;
				}
			} elseif ( 'invoice' === $doc ) {
				$proforma_status = '0';
			} elseif ( 'proforma' === $doc ) {
				$invoice_status = '0';
				$proforma       = true;
			} else {
				$proforma = true;
			}

			if ( 'processing' === $invoice_status ) {
				$skipped_fix = true;
			}
			$options[ 'woocommerce_sf_invoice_regular_' . $id ]  = $invoice_status;
			$options[ 'woocommerce_sf_invoice_proforma_' . $id ] = $proforma_status;
			if ( in_array( $doc, array( 'both', 'invoice' ), true ) ) {
				// While the invoice status makes the invoice paid anyway, the checkbox is locked and the saved value stays.
				$paid = ! empty( $row['paid_auto'] ) ? get_option( 'woocommerce_sf_invoice_regular_' . $id . '_set_as_paid', 'no' ) : ( ! empty( $row['paid'] ) ? 'yes' : 'no' );
				$options[ 'woocommerce_sf_invoice_regular_' . $id . '_set_as_paid' ] = 'yes' === $paid ? 'yes' : 'no';
			}
		}

		if ( $skipped_fix && ! $configured && 'yes' !== get_option( 'woocommerce_sf_invoice_regular_processing_skipped_fix', 'no' ) ) {
			// Orders with only virtual and downloadable products skip "Processing" and would get no invoice.
			$options['woocommerce_sf_invoice_regular_processing_skipped_fix'] = 'yes';
		}
		if ( $manual ) {
			$options['woocommerce_sf_invoice_regular_manual']  = 'yes';
			$options['woocommerce_sf_invoice_proforma_manual'] = 'yes';
		}
		if ( $proforma && ! $configured && false === get_option( 'woocommerce_sf_sync_type', false ) ) {
			$options['woocommerce_sf_sync_type'] = 'single';
		}
		// Pairing that works today without a key must keep working, so a key is created only when pairing is not in use yet.
		if ( $proforma && ! ( $configured && $pairing_before ) && '' === (string) get_option( 'woocommerce_sf_sync_secret_key', '' ) ) {
			// The key shown on the page, which the user may have entered in SuperFaktúra already.
			$secret_key = $this->posted( 'secret_key' );
			$options['woocommerce_sf_sync_secret_key'] = preg_match( '/^[A-Za-z0-9]{16,64}$/', $secret_key ) ? $secret_key : WC_SF_Helper::generate_secret_key();
		}

		// Shipping: only enabled methods.
		$methods = $this->get_shipping_methods();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in check_request(), each value is sanitized below.
		$posted_shipping = isset( $_POST['ship'] ) && is_array( $_POST['ship'] ) ? wp_unslash( $_POST['ship'] ) : array();
		foreach ( $posted_shipping as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['key'] ) || ! is_string( $row['key'] ) || ! isset( $methods[ $row['key'] ] ) ) {
				continue;
			}
			$type = isset( $row['type'] ) && is_string( $row['type'] ) ? sanitize_text_field( $row['type'] ) : '0';
			$type = '' !== $type ? $type : '0';
			if ( '0' !== $type || ! $configured || null !== $this->current( 'woocommerce_sf_shipping_' . $row['key'] ) ) {
				$options[ 'woocommerce_sf_shipping_' . $row['key'] ] = $type;
			}
		}

		$shipping_name = $this->posted( 'shipping_item_name' );
		if ( '' !== $shipping_name ) {
			$options['woocommerce_sf_shipping_item_name'] = $shipping_name;
		}

		// Checkout and emails.
		$checks = array(
			'company_fields' => 'woocommerce_sf_add_company_billing_fields',
			'vies'           => 'woocommerce_sf_validate_eu_vat_number',
			'reverse_charge' => 'woocommerce_sf_exempt_vat_on_valid_vat_number',
			'oss'            => 'woocommerce_sf_oss',
			'invoice_link'   => 'woocommerce_sf_order_received_invoice_link',
			'bysquare'       => 'woocommerce_sf_bysquare',
		);
		foreach ( $checks as $field => $name ) {
			$options[ $name ] = 'yes' === $this->posted( $field ) ? 'yes' : 'no';
		}

		// eFaktúra: only a SuperFaktúra SK account sends via Peppol. The status and the PDF stay as they are while it is off.
		$options['woocommerce_sf_peppol'] = 'sk' === $credentials['woocommerce_sf_lang'] && 'yes' === $this->posted( 'peppol' ) ? 'yes' : 'no';
		if ( 'yes' === $options['woocommerce_sf_peppol'] ) {
			$peppol_status = $this->posted( 'peppol_status' );
			if ( isset( $this->get_document_statuses()[ $peppol_status ] ) ) {
				$options['woocommerce_sf_peppol_status'] = $peppol_status;
			}
			$options['woocommerce_sf_peppol_email_pdf'] = 'yes' === $this->posted( 'peppol_email_pdf' ) ? 'yes' : 'no';
		}

		// "Yes" attaches the PDF also to the shop's own emails; keep it when it is already set.
		if ( 'yes' === $this->posted( 'pdf_attachment' ) ) {
			$options['woocommerce_sf_invoice_pdf_attachment'] = 'yes' === get_option( 'woocommerce_sf_invoice_pdf_attachment', 'no' ) ? 'yes' : 'customer';
		} else {
			$options['woocommerce_sf_invoice_pdf_attachment'] = 'no';
		}

		if ( $configured ) {
			// Values the plugin uses while these options are not saved.
			$defaults = array(
				'woocommerce_sf_lang'                           => 'sk',
				'woocommerce_sf_company_id'                     => '',
				'woocommerce_sf_sandbox'                        => 'no',
				'woocommerce_sf_invoice_sequence_id'            => '',
				'woocommerce_sf_proforma_invoice_sequence_id'   => '',
				'woocommerce_sf_cancel_sequence_id'             => '',
				'woocommerce_sf_logo_id'                        => '',
				'woocommerce_sf_bank_account_id'                => '',
				'woocommerce_sf_invoice_language'               => 'endpoint',
				'woocommerce_sf_shipping_item_name'             => 'Poštovné',
				'woocommerce_sf_add_company_billing_fields'     => 'yes',
				'woocommerce_sf_validate_eu_vat_number'         => 'no',
				'woocommerce_sf_exempt_vat_on_valid_vat_number' => 'no',
				'woocommerce_sf_oss'                            => 'no',
				'woocommerce_sf_order_received_invoice_link'    => 'yes',
				'woocommerce_sf_bysquare'                       => 'yes',
				'woocommerce_sf_invoice_pdf_attachment'         => 'no',
				'woocommerce_sf_peppol'                         => 'no',
				'woocommerce_sf_peppol_status'                  => 'completed',
				'woocommerce_sf_peppol_email_pdf'               => 'yes',
			);
			foreach ( $options as $name => $value ) {
				if ( isset( $defaults[ $name ] ) ) {
					$default = $defaults[ $name ];
				} elseif ( preg_match( '/_set_as_paid$/', $name ) ) {
					$default = 'no';
				} elseif ( preg_match( '/^woocommerce_sf_(invoice_regular_|invoice_proforma_|gateway_|shipping_)/', $name ) ) {
					// No document, payment type or delivery type, the same as an option that is not saved.
					$default = '0';
				} else {
					continue;
				}
				if ( $default === $value && null === $this->current( $name ) ) {
					unset( $options[ $name ] );
				}
			}
		}

		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}

		delete_option( self::OPTION );

		wp_send_json_success( array( 'saved' => count( $options ) ) );
	}
}
