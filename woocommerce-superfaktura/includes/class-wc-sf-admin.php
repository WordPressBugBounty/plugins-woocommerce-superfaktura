<?php
/**
 * SuperFaktúra WooCommerce Admin.
 *
 * @package   SuperFaktúra WooCommerce
 * @author    2day.sk <superfaktura@2day.sk>
 * @copyright 2022 2day.sk s.r.o., Webikon s.r.o.
 * @license   GPL-2.0+
 * @link      https://www.superfaktura.sk/integracia/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * WC_SF_Admin.
 *
 * @package SuperFaktúra WooCommerce
 * @author  2day.sk <superfaktura@2day.sk>
 */
class WC_SF_Admin {

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

		add_action( 'admin_init', array( $this, 'admin_init' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'admin_notices', array( __CLASS__, 'order_number_notice_all' ) );
        add_action( 'woocommerce_settings_wc_superfaktura', array( __CLASS__, 'order_number_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
    }

    /**
     * Initialize hooks.
     */
	public function init() {
        add_action( 'admin_head', array( $this, 'add_admin_css' ) );
		add_action( 'woocommerce_get_settings_pages', array( $this, 'woocommerce_settings' ) );
        add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_custom_order_status_actions_button' ), 100, 2 );
	}

	/**
     * Process input for admin pages.
     */
    public function admin_init() {
		if ( isset( $_GET['sf_regen'] ) || isset( $_GET['sf_invoice_proforma_create'] ) || isset( $_GET['sf_invoice_regular_create'] ) || isset( $_GET['sf_invoice_cancel_create'] ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['sf_order'] ) ) {
				wp_die( 'Unauthorized' );
			}

			$order_id = (int) $_GET['sf_order'];

			$result     = true;
			$result_msg = '';
			if ( isset( $_GET['sf_regen'] ) ) {
				$result      = $this->wc_sf->sf_regen_invoice( $order_id );
				$result_msg .= 'regen';
			} elseif ( isset( $_GET['sf_invoice_proforma_create'] ) ) {
				$order       = wc_get_order( $order_id );
				$result      = $this->wc_sf->invoice_generator->generate_invoice( $order, 'proforma', isset( $_GET['force_create'] ) );
				$result_msg .= 'proforma';
			} elseif ( isset( $_GET['sf_invoice_regular_create'] ) ) {
				$order       = wc_get_order( $order_id );
				$result      = $this->wc_sf->invoice_generator->generate_invoice( $order, 'regular', isset( $_GET['force_create'] ) );
				$result_msg .= 'regular';
			} elseif ( isset( $_GET['sf_invoice_cancel_create'] ) ) {
				$order       = wc_get_order( $order_id );
				$result      = $this->wc_sf->invoice_generator->generate_invoice( $order, 'cancel', isset( $_GET['force_create'] ) );
				$result_msg .= 'cancel';
			}

			if ( is_wp_error( $result ) ) {
				$result_msg = array_key_first( $result->errors );
			} elseif ( $result_msg ) {
				$result_msg .= ( false === $result ) ? '_failed' : '_ok';
			}

			wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&sf_msg=' . $result_msg ) );
			die();
		}

		if ( isset( $_GET['sf_hide_order_number_notice'] ) ) {
			update_option( 'wc_sf_order_number_notice_hidden', 1 );
			wp_safe_redirect( remove_query_arg( 'sf_hide_order_number_notice' ) );
		}
    }

    /**
     * Process notices for admin pages.
     */
    public function admin_notices() {
		// Show API usage notice.
		$api_usage = get_option( 'woocommerce_sf_api_usage', [] );
		if ( $api_usage && 0 >= $api_usage['dailyremaining'] && time() < strtotime( $api_usage['dailyreset'] ) ) {
			$api_usage_notice = '
				<strong><big>' . __( 'SuperFaktúra', 'woocommerce-superfaktura' ) . '</big></strong><br>
				<p>' . __( 'You have exceeded the allowed number of API requests / day. If you issue a large number of invoices, you can increase this number.', 'woocommerce-superfaktura' ) . '</p>
				<a class="button button-primary" href="https://moja.superfaktura.sk/shoppings/index/api_limit_increase/recommended_increase:' . ( ceil ( ( abs( $api_usage['dailyremaining'] ) + 100 ) / 1000 ) * 1000 ) . '/recommended_duration:8">' . __( 'Increase the number of API requests', 'woocommerce-superfaktura' ) . '</a>
			';

			echo sprintf( wp_kses( '<div class="notice notice-error"><p>%s</p></div>', $this->wc_sf->allowed_tags ), $api_usage_notice );
		}

		// Show notices saved in database.
		$admin_notices = get_option( 'woocommerce_sf_admin_notices', array() );
		if ( ! empty( $admin_notices ) ) {
			foreach ( $admin_notices as $admin_notice ) {
				echo sprintf( wp_kses( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $this->wc_sf->allowed_tags ), $admin_notice['type'], $admin_notice['text'] );
			}
			delete_option( 'woocommerce_sf_admin_notices' );
		}

		// Show notices based on GET parameter.
		if ( ! isset( $_GET['sf_msg'] ) || empty( $_GET['sf_msg'] ) ) {
			return;
		}

		// Translators: %s API log URL.
		$see_api_log = sprintf( __( 'See <a href="%s">API log</a> for more information.', 'woocommerce-superfaktura' ), admin_url( 'admin.php?page=wc-settings&tab=superfaktura&section=api_log' ) );

		switch ( $_GET['sf_msg'] ) {

			case 'proforma_ok':
				echo wp_kses( '<div class="notice notice-success is-dismissible"><p>' . __( 'Proforma invoice was created.', 'woocommerce-superfaktura' ) . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'regular_ok':
				echo wp_kses( '<div class="notice notice-success is-dismissible"><p>' . __( 'Invoice was created.', 'woocommerce-superfaktura' ) . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'cancel_ok':
				echo wp_kses( '<div class="notice notice-success is-dismissible"><p>' . __( 'Credit note was created.', 'woocommerce-superfaktura' ) . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'regen_ok':
				echo wp_kses( '<div class="notice notice-success is-dismissible"><p>' . __( 'Documents were regenerated.', 'woocommerce-superfaktura' ) . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'proforma_failed':
				echo wp_kses( '<div class="notice notice-error is-dismissible"><p>' . __( 'Proforma invoice was not created.', 'woocommerce-superfaktura' ) . ' ' . $see_api_log . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'regular_failed':
				echo wp_kses( '<div class="notice notice-error is-dismissible"><p>' . __( 'Invoice was not created.', 'woocommerce-superfaktura' ) . ' ' . $see_api_log . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'cancel_failed':
				echo wp_kses( '<div class="notice notice-error is-dismissible"><p>' . __( 'Credit note was not created.', 'woocommerce-superfaktura' ) . ' ' . $see_api_log . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'regen_failed':
				echo wp_kses( '<div class="notice notice-error is-dismissible"><p>' . __( 'Documents were not regenerated.', 'woocommerce-superfaktura' ) . ' ' . $see_api_log . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			case 'duplicate_document':
				echo wp_kses( '<div class="notice notice-error is-dismissible"><p>' . __( 'Document was not created, because it already exists.', 'woocommerce-superfaktura' ) . '</p></div>', $this->wc_sf->allowed_tags );
				break;

			default:
				echo wp_kses( '<div class="notice notice-warning is-dismissible"><p>' . wp_unslash( $_GET['sf_msg'] ) . '</p></div>', $this->wc_sf->allowed_tags );
				break;
		}
    }

    /**
     * Avoid double notice on superfaktura settings tab.
     */
    public static function order_number_notice_all() {
		if ( isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'wc_superfaktura' === $_GET['tab'] ) {
			return;
		}

		self::order_number_notice();
    }

    /**
     * Display warning if we use custom numbering + [ORDER_NUMBER] variable and do not have active plugin Woocommerce Sequential Order Numbers.
     */
    public static function order_number_notice() {
		if ( ! is_admin() || defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' )
			|| is_plugin_active( 'woocommerce-sequential-order-numbers-pro/woocommerce-sequential-order-numbers.php' )
			|| is_plugin_active( 'woocommerce-sequential-order-numbers-pro/woocommerce-sequential-order-numbers-pro.php' )
		) {
			return;
		}

		if ( 'no' === get_option( 'woocommerce_sf_invoice_custom_num' ) ) {
			return;
		}

		if ( get_option( 'wc_sf_order_number_notice_hidden' ) ) {
			return;
		}

		$tmpl1 = get_option( 'woocommerce_sf_invoice_proforma_id' ) ?? '';
		$tmpl2 = get_option( 'woocommerce_sf_invoice_regular_id' ) ?? '';
		$tmpl3 = get_option( 'woocommerce_sf_invoice_cancel_id' ) ?? '';
		if ( false !== strpos( $tmpl1 . $tmpl2 . $tmpl3, '[ORDER_NUMBER]' ) ) {
			// Translators: %1$s Order number, %2$s Plugin name.
			echo '<div class="notice notice-error is-dismissible">';
			echo '<p><strong>SuperFaktúra Woocommerce</strong>: ' . sprintf( __( 'You use variable %1$s in your invoice nr. or proforma invoice nr., but the plugin "%2$s" is not activated. This may cause that your invoice numbers will not be sequential.', 'woocommerce-superfaktura' ), '[ORDER_NUMBER]', 'WooCommerce Sequential Order Numbers' ) . '</p>';
			echo '<p><a href="' . esc_url( add_query_arg( 'sf_hide_order_number_notice', 1 ) ) . '">' . __( 'Hide notification forever', 'woocommerce-superfaktura' ) . '</a></p>';
			echo '</div>';
		}
    }

    /**
     * Register scripts for admin pages.
     */
    public function admin_enqueue_scripts() {
		wp_enqueue_script( 'wc-sf-admin-js', plugins_url( 'assets/js/admin.js', WC_SF_FILE_PATH ), array( 'jquery' ), $this->wc_sf->version, true );
		wp_localize_script( 'wc-sf-admin-js', 'wc_sf', array( 'ajaxnonce'   => wp_create_nonce( 'ajax_validation' ) ) );
    }

    /**
     * Create invoices meta box in order screen.
     */
    public function add_meta_boxes() {
		try {
			$screen = $this->wc_sf->hpos_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		} catch ( Exception $e ) {
			$screen = 'shop_order';
		}

		add_meta_box( 'wc_sf_invoice_box', __( 'Invoices', 'woocommerce-superfaktura' ), array( $this, 'add_box' ), $screen, 'side' );
    }

    /**
     * Create invoices meta box content.
     *
     * @param WP_Post $post Post.
     */
    public function add_box( $post ) {
		if ( $post instanceof WC_Order ) {
			$order = $post;
		}
		else {
			$order = wc_get_order( $post->ID );
		}

		$proforma = $order->get_meta( 'wc_sf_invoice_proforma', true );
		$invoice  = $order->get_meta( 'wc_sf_invoice_regular', true );
		$cancel   = $order->get_meta( 'wc_sf_invoice_cancel', true );

		echo wp_kses( '<p><strong>' . __( 'View Generated Invoices', 'woocommerce-superfaktura' ) . '</strong>:', $this->wc_sf->allowed_tags );
		if ( empty( $proforma ) && empty( $invoice ) ) {
			echo wp_kses( '<br>' . __( 'No invoice was generated', 'woocommerce-superfaktura' ), $this->wc_sf->allowed_tags );
		}
		echo wp_kses( '</p>', $this->wc_sf->allowed_tags );

		if ( ! empty( $proforma ) ) {
			$error_html = sprintf( '%s<br><a href="%s">%s</a>', __( 'Proforma could not be found in SuperFaktura.', 'woocommerce-superfaktura' ), admin_url( 'admin.php?sf_invoice_proforma_create=1&force_create=1&sf_order=' . $order->get_id() ), __( 'Create new proforma invoice', 'woocommerce-superfaktura' ) );
			echo wp_kses( '<p><a href="' . $proforma . '" class="button sf-url-check" data-error="' . htmlentities( $error_html ) . '" target="_blank">' . __( 'Proforma', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		} elseif ( 'yes' === get_option( 'woocommerce_sf_invoice_proforma_manual', 'no' ) ) {
			echo wp_kses( '<p><a href="' . admin_url( 'admin.php?sf_invoice_proforma_create=1&sf_order=' . $order->get_id() ) . '" class="sf-prevent-duplicity">' . __( 'Create proforma invoice', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		}

		if ( ! empty( $invoice ) ) {
			$error_html = sprintf( '%s<br><a href="%s">%s</a>', __( 'Invoice could not be found in SuperFaktura.', 'woocommerce-superfaktura' ), admin_url( 'admin.php?sf_invoice_regular_create=1&force_create=1&sf_order=' . $order->get_id() ), __( 'Create new invoice', 'woocommerce-superfaktura' ) );
			echo wp_kses( '<p><a href="' . $invoice . '" class="button sf-url-check" data-error="' . htmlentities( $error_html ) . '" target="_blank">' . __( 'Invoice', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		} elseif ( 'yes' === get_option( 'woocommerce_sf_invoice_regular_manual', 'no' ) ) {
			echo wp_kses( '<p><a href="' . admin_url( 'admin.php?sf_invoice_regular_create=1&sf_order=' . $order->get_id() ) . '" class="sf-prevent-duplicity">' . __( 'Create invoice', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		}

		if ( ! empty( $cancel ) ) {
			$error_html = sprintf( '%s<br><a href="%s">%s</a>', __( 'Credit note could not be found in SuperFaktura.', 'woocommerce-superfaktura' ), admin_url( 'admin.php?sf_invoice_cancel_create=1&force_create=1&sf_order=' . $order->get_id() ), __( 'Create new credit note', 'woocommerce-superfaktura' ) );
			echo wp_kses( '<p><a href="' . $cancel . '" class="button sf-url-check" data-error="' . htmlentities( $error_html ) . '" target="_blank">' . __( 'Credit note', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		} elseif ( ! empty( $invoice ) && ( $order->get_refunds() || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) ) {
			echo wp_kses( '<p><a href="' . admin_url( 'admin.php?sf_invoice_cancel_create=1&sf_order=' . $order->get_id() ) . '" class="sf-prevent-duplicity">' . __( 'Create credit note', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		}

		if ( ( ! empty( $proforma ) || ! empty( $invoice ) ) && $this->wc_sf->invoice_generator->sf_can_regenerate( $order ) ) {
			echo wp_kses( '<p><a href="' . esc_url( admin_url( 'admin.php?sf_regen=1&sf_order=' . $order->get_id() ) ) . '" class="sf-prevent-duplicity">' . __( 'Regenerate existing invoices', 'woocommerce-superfaktura' ) . '</a></p>', $this->wc_sf->allowed_tags );
		}

		// 2020/07/01 webikon: Added an action that allows to add content after the invoice button
		do_action( 'sf_metabox_after_invoice_generate_button', $order, $invoice );
    }

    /**
     * Add custom order status actions button.
     */
    public function add_custom_order_status_actions_button( $actions, $order ) {
		if ( 'yes' === get_option( 'woocommerce_sf_invoice_download_button_actions', false ) ) {
			$action_slug = 'invoice';

			$pdf = $order->get_meta( 'wc_sf_invoice_proforma', true );
			if ( $pdf ) {
				$actions[ $action_slug ] = array(
					'url'    => $pdf,
					'name'   => __( 'Proforma', 'woocommerce-superfaktura' ),
					'action' => $action_slug,
				);
			}
			$pdf = $order->get_meta( 'wc_sf_invoice_regular', true );
			if ( $pdf ) {
				$actions[ $action_slug ] = array(
					'url'    => $pdf,
					'name'   => __( 'Invoice', 'woocommerce-superfaktura' ),
					'action' => $action_slug,
				);
			}
		}

		return $actions;
    }

    /**
     * Add admin CSS.
     */
    public function add_admin_css() {
		echo wp_kses(
			'
				<style>
				.wc-action-button-invoice::after {
					font-family: woocommerce !important;
					content: "\e00a" !important;
				}
				p.description .button {
					font-style: normal;
				}
				.wc-sf-api-test-loading,
				.wc-sf-api-test-ok,
				.wc-sf-api-test-fail {
					display: none;
					vertical-align: middle;
				}
				table.wc-sf-api-log {
					border-spacing: 0;
				}
				table.wc-sf-api-log th,
				table.wc-sf-api-log td {
					margin: 0;
					padding: 6px 12px;
					text-align: left;
				}
				table.wc-sf-api-log tr.odd td {
					background: #fff;
				}
				table.wc-sf-api-log tr.error td {
					color: #f00;
				}

				#woocommerce_wi_invoice_creation1-description + .form-table tr:nth-child(odd) th,
				#woocommerce_wi_invoice_creation1-description + .form-table tr:nth-child(odd) td,
				#woocommerce_wi_invoice_creation3-description + .form-table tr:nth-child(odd) th,
				#woocommerce_wi_invoice_creation3-description + .form-table tr:nth-child(odd) td {
					padding-bottom: 0;
				}
				#woocommerce_wi_invoice_creation1-description + .form-table tr:nth-child(even) th,
				#woocommerce_wi_invoice_creation1-description + .form-table tr:nth-child(even) td,
				#woocommerce_wi_invoice_creation3-description + .form-table tr:nth-child(even) th,
				#woocommerce_wi_invoice_creation3-description + .form-table tr:nth-child(even) td {
					padding-top: 0;
				}

				.sf-notice-info {
					margin: 24px 0;
					padding: 12px 24px;
					color: #004085;
					background-color: #cce5ff;
					border: 1px solid #b8daff;
				}

				.sf-notice-error {
					margin: 24px 0;
					padding: 12px 24px;
					color: #721c24;
					background-color: #f8d7da;
					border: 1px solid #f5c6cb;
				}

				.wc-sf-url-error,
				.wc-sf-url-error a {
					color: #f00;
				}
				</style>
			',
			$this->wc_sf->allowed_tags
		);
    }

	/**
	 * Create tab in WooCommerce settings.
	 *
	 * @param array $settings WooCommerce settings.
	 */
	public function woocommerce_settings( $settings ) {
		require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-sf-settings.php';
		$settings[] = new WC_SF_Settings( $this->wc_sf );
		return $settings;
	}
}
