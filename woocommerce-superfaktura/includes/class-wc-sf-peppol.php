<?php
/**
 * Sending invoices and credit notes via Peppol (eFaktúra).
 *
 * @package SuperFaktura WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_SF_Peppol class.
 *
 * Sends the invoice of an order for a Slovak company with DIČ via Peppol when the order reaches the chosen status,
 * then its credit notes, and follows the sending status. A document sent via Peppol is never edited again.
 */
class WC_SF_Peppol {

	/**
	 * Order meta with the Peppol state of the order's documents, keyed by the SuperFaktura document ID.
	 */
	const META = 'wc_sf_peppol';

	/**
	 * Order meta with the tax documents for a received payment SuperFaktura created, keyed by the SuperFaktura ID.
	 */
	const TAX_DOCUMENTS_META = 'wc_sf_tax_documents';

	/**
	 * Minutes before another attempt to send, when SuperFaktura could not be reached.
	 */
	const RETRY_MINUTES = array( 5, 30, 60 );

	/**
	 * Seconds before each check of the sending status, counted from the previous one, until it is final: about
	 * 2 minutes, an hour and a day after sending. Few checks, because status reads count to the daily API limit.
	 */
	const CHECK_DELAYS = array( 120, 3600, 86400 );

	/**
	 * Provider outcomes that mean the document did not reach the customer.
	 */
	const FAILED_PROVIDER_STATUSES = array( 'rejected', 'failed_delivery' );

	/**
	 * Plugin log status of a sending status check that found a failed sending.
	 */
	const LOG_STATUS_FAILED = 920;

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
	 * Register hooks.
	 */
	public function init() {
		// Sends scheduled again and status checks finish even when sending is turned off in the meantime.
		add_action( 'sf_peppol_retry_send', array( $this, 'scheduled_send' ), 10, 3 );
		add_action( 'sf_peppol_check_status', array( $this, 'scheduled_check' ), 10, 3 );

		if ( ! $this->enabled() ) {
			return;
		}

		// Priority 6: after the invoice created at the same status (priority 5), before the order e-mail (priority 10).
		add_action( 'woocommerce_order_status_' . $this->get_send_status(), array( $this, 'order_status_reached' ), 6 );
		add_action( 'sf_after_invoice_create', array( $this, 'document_created' ), 10, 3 );
	}

	/**
	 * Whether sending via Peppol is turned on.
	 *
	 * @return bool
	 */
	public function enabled() {
		return 'yes' === get_option( 'woocommerce_sf_peppol', 'no' );
	}

	/**
	 * Order status at which the invoice is sent via Peppol.
	 *
	 * @return string Status without the wc- prefix.
	 */
	public function get_send_status() {
		$status = (string) get_option( 'woocommerce_sf_peppol_status', 'completed' );

		return '' !== $status ? $status : 'completed';
	}

	/**
	 * Whether an order e-mail carries the PDF of a document. The PDF is left out only when chosen in the settings and
	 * the document goes via Peppol, or went there; a sending that failed keeps it.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $type        Document type: 'regular' or 'cancel'. Other types always keep the PDF.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @return bool
	 */
	public function pdf_in_emails( $order, $type = 'regular', $document_id = 0 ) {
		if ( 'no' !== get_option( 'woocommerce_sf_peppol_email_pdf', 'yes' ) || ! in_array( $type, array( 'regular', 'cancel' ), true ) ) {
			return true;
		}

		if ( ! $this->should_send( $order, 'regular' ) ) {
			return true;
		}

		// The e-mail may carry an order object loaded before the document was sent.
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh ? $fresh : $order;

		$regular_id = (int) $order->get_meta( 'wc_sf_internal_regular_id', true );
		if ( 'cancel' === $type ) {
			// A credit note goes via Peppol only after its invoice.
			return ! $this->is_locked( $order, $regular_id ) || self::is_failed( $this->get_state( $order, $document_id ) );
		}

		return self::is_failed( $this->get_state( $order, $document_id ? $document_id : $regular_id ) );
	}

	/**
	 * Whether the documents of the order are created in a SuperFaktura SK account, the only one that sends via Peppol.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_sk_account( $order ) {
		$credentials = $this->wc_sf->invoice_generator->get_order_api_credentials( $order );

		return 'sk' === ( $credentials['woocommerce_sf_lang'] ?? get_option( 'woocommerce_sf_lang', 'sk' ) );
	}

	/**
	 * DIČ of a Slovak company from its IČ DPH, which is SK followed by the DIČ.
	 *
	 * @param string $vat_number IČ DPH.
	 * @return string DIČ, or an empty string when the number is not a Slovak IČ DPH.
	 */
	public static function dic_from_vat_number( $vat_number ) {
		$vat_number = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $vat_number ) );

		return preg_match( '/^SK(\d{10})$/', $vat_number, $matches ) ? $matches[1] : '';
	}

	/**
	 * DIČ of the customer who gets the order's documents via Peppol: a company from Slovakia with DIČ, invoiced
	 * from a SuperFaktura SK account.
	 *
	 * @param WC_Order $order Order.
	 * @return string DIČ, or an empty string when the documents go as before.
	 */
	public function get_recipient( $order ) {
		if ( ! $this->enabled() || 'SK' !== strtoupper( (string) $order->get_billing_country() ) || ! $this->is_sk_account( $order ) ) {
			return '';
		}

		// The block checkout stores the business checkbox; the classic one keeps company data only for a business.
		$checkbox = $order->get_meta( '_wc_other/superfaktura/wi-as-company', true );
		if ( '' !== $checkbox && ! in_array( $checkbox, array( true, 1, '1', 'true', 'yes' ), true ) ) {
			return '';
		}

		$client = $this->wc_sf->invoice_generator->get_client_data( $order, false );
		$dic    = preg_replace( '/\s+/', '', (string) ( $client['dic'] ?? '' ) );
		if ( '' === $dic ) {
			$dic = self::dic_from_vat_number( $client['ic_dph'] ?? '' );
		}

		return $dic;
	}

	/**
	 * Whether a document of the order goes via Peppol.
	 *
	 * @param WC_Order $order         Order.
	 * @param string   $document_type 'regular' or 'cancel'.
	 * @return bool
	 */
	public function should_send( $order, $document_type ) {
		$send = '' !== $this->get_recipient( $order );

		/**
		 * Filters whether a document of the order is sent via Peppol (eFaktúra).
		 *
		 * By default it is, when sending via Peppol is on and the customer is a company from Slovakia with DIČ
		 * (or with IČ DPH, which contains the DIČ). The filter can add exceptions both ways.
		 *
		 * @since 1.61.0
		 *
		 * @param bool     $send          Whether to send the document via Peppol.
		 * @param WC_Order $order         Order.
		 * @param string   $document_type Document type: 'regular' (invoice), 'tax' (tax document for a received payment)
		 *                                or 'cancel' (credit note).
		 */
		return (bool) apply_filters( 'sf_peppol_send', $send, $order, $document_type );
	}

	/**
	 * Invoice, tax documents for a received payment and credit notes of the order that can go via Peppol.
	 *
	 * @param WC_Order $order Order.
	 * @return array SuperFaktura ID => array with 'type' ('regular', 'tax' or 'cancel'), 'number' and 'refund_id'.
	 */
	public function get_documents( $order ) {
		$documents = array();

		$regular_id = (int) $order->get_meta( 'wc_sf_internal_regular_id', true );
		if ( $regular_id ) {
			$documents[ $regular_id ] = array(
				'type'      => 'regular',
				'number'    => (string) $order->get_meta( 'wc_sf_regular_invoice_number', true ),
				'refund_id' => 0,
			);
		}

		$cancel_id = (int) $order->get_meta( 'wc_sf_internal_cancel_id', true );
		if ( $cancel_id ) {
			$documents[ $cancel_id ] = array(
				'type'      => 'cancel',
				'number'    => (string) $order->get_meta( 'wc_sf_cancel_invoice_number', true ),
				'refund_id' => 0,
			);
		}

		foreach ( self::get_tax_documents( $order ) as $tax_id => $tax_document ) {
			$documents[ (int) $tax_id ] = array(
				'type'      => 'tax',
				'number'    => (string) ( $tax_document['number'] ?? '' ),
				'refund_id' => 0,
			);
		}

		foreach ( WC_SF_Invoice::get_refund_credit_notes( $order ) as $refund_id => $credit_note ) {
			if ( ! empty( $credit_note['id'] ) ) {
				$documents[ (int) $credit_note['id'] ] = array(
					'type'      => 'cancel',
					'number'    => (string) ( $credit_note['number'] ?? '' ),
					'refund_id' => (int) $refund_id,
				);
			}
		}

		return $documents;
	}

	/**
	 * Peppol state of the order's documents.
	 *
	 * @param WC_Order $order Order.
	 * @return array SuperFaktura ID => state.
	 */
	public function get_states( $order ) {
		$states = $order->get_meta( self::META, true );

		return is_array( $states ) ? $states : array();
	}

	/**
	 * Peppol state of a document.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @return array|null State with 'status' (queued, processing, pending, accepted, failed or error), 'provider_status',
	 *                    'failure_code', 'operation_id', 'error_code', 'message', 'sent_at' and 'checked_at', null when
	 *                    the document was never sent.
	 */
	public function get_state( $order, $document_id ) {
		$states = $this->get_states( $order );

		return $states[ (int) $document_id ] ?? null;
	}

	/**
	 * Save the Peppol state of a document.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @param array    $state       State to merge into the saved one.
	 */
	private function save_state( $order, $document_id, $state ) {
		$states                        = $this->get_states( $order );
		$states[ (int) $document_id ] = array_merge( $states[ (int) $document_id ] ?? array(), $state );

		$order->update_meta_data( self::META, $states );
		$order->save();

		WC_SF_Notices::flush_errors();
	}

	/**
	 * Whether the sending of a document failed: refused before sending, failed, rejected or not delivered.
	 *
	 * @param array|null $state Peppol state.
	 * @return bool
	 */
	public static function is_failed( $state ) {
		if ( empty( $state['status'] ) ) {
			return false;
		}

		return in_array( $state['status'], array( 'failed', 'error' ), true ) || in_array( $state['provider_status'] ?? '', self::FAILED_PROVIDER_STATUSES, true );
	}

	/**
	 * Whether the outcome of a sending is known, so its status is not checked again.
	 *
	 * @param array|null $state Peppol state.
	 * @return bool
	 */
	public static function is_final( $state ) {
		if ( self::is_failed( $state ) ) {
			return true;
		}

		return 'accepted' === ( $state['status'] ?? '' ) && 'accepted' === ( $state['provider_status'] ?? '' );
	}

	/**
	 * Whether a document was sent via Peppol, or is being sent, so it must not be changed any more.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @return bool
	 */
	public function is_locked( $order, $document_id ) {
		if ( empty( $document_id ) ) {
			return false;
		}

		$state = $this->get_state( $order, $document_id );

		return ! empty( $state['status'] ) && ! self::is_failed( $state );
	}

	/**
	 * Name of a document in notes and messages, e.g. "invoice IN260614".
	 *
	 * @param array $document Document from get_documents().
	 * @return string
	 */
	public function document_label( $document ) {
		if ( 'cancel' === $document['type'] ) {
			// Translators: %s Credit note number.
			return sprintf( __( 'credit note %s', 'woocommerce-superfaktura' ), $document['number'] );
		}

		if ( 'tax' === $document['type'] ) {
			// Translators: %s Tax document number.
			return sprintf( __( 'tax document %s', 'woocommerce-superfaktura' ), $document['number'] );
		}

		// Translators: %s Invoice number.
		return sprintf( __( 'invoice %s', 'woocommerce-superfaktura' ), $document['number'] );
	}

	/**
	 * Human readable Peppol state of a document.
	 *
	 * @param array $state Peppol state.
	 * @return string
	 */
	public function state_label( $state ) {
		if ( 'error' === ( $state['status'] ?? '' ) ) {
			return __( 'Not sent', 'woocommerce-superfaktura' );
		}

		if ( 'rejected' === ( $state['provider_status'] ?? '' ) ) {
			return __( 'Rejected', 'woocommerce-superfaktura' );
		}

		if ( self::is_failed( $state ) ) {
			return __( 'Not delivered', 'woocommerce-superfaktura' );
		}

		if ( self::is_final( $state ) ) {
			return __( 'Delivered', 'woocommerce-superfaktura' );
		}

		if ( 'accepted' === ( $state['status'] ?? '' ) ) {
			return __( 'Sent', 'woocommerce-superfaktura' );
		}

		return __( 'Sending', 'woocommerce-superfaktura' );
	}

	/**
	 * Send the order's invoice and its credit notes when the order reaches the chosen status.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_status_reached( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->send_order_documents( $order );
	}

	/**
	 * Send a document created via the API: the invoice when the order already has the chosen status, a credit note
	 * when its invoice was sent via Peppol.
	 *
	 * @param object   $response API response.
	 * @param WC_Order $order    Order.
	 * @param string   $type     Document type.
	 */
	public function document_created( $response, $order, $type ) {
		if ( 'regular' === $type ) {
			if ( $order->get_status() === $this->get_send_status() ) {
				$this->send_order_documents( $order );
			}
			return;
		}

		if ( 'cancel' === $type && ! empty( $response->data->Invoice->id ) ) {
			$this->send_credit_note( $order, (int) $response->data->Invoice->id );
		}
	}

	/**
	 * Send a document that was attached to the order without being created by the plugin, e.g. the invoice
	 * SuperFaktura created from a paid proforma invoice.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  Document type.
	 */
	public function document_attached( $order, $type ) {
		if ( ! $this->enabled() ) {
			return;
		}

		if ( 'regular' === $type && $order->get_status() === $this->get_send_status() ) {
			$this->send_order_documents( $order );
		} elseif ( 'cancel' === $type ) {
			$this->send_credit_note( $order, (int) $order->get_meta( 'wc_sf_internal_cancel_id', true ) );
		}
	}

	/**
	 * Tax documents for a received payment SuperFaktura created for the order's proforma invoice.
	 *
	 * @param WC_Order $order Order.
	 * @return array SuperFaktura ID => array with 'number' and 'pdf'.
	 */
	public static function get_tax_documents( $order ) {
		$tax_documents = $order->get_meta( self::TAX_DOCUMENTS_META, true );

		return is_array( $tax_documents ) ? $tax_documents : array();
	}

	/**
	 * Attach the tax documents for a received payment that SuperFaktura created from the paid proforma invoice,
	 * and send them via Peppol. They are final documents, so they go at once, whatever the order status.
	 *
	 * Only while sending via Peppol is on: otherwise the order stays as before.
	 *
	 * @param WC_Order $order    Order.
	 * @param object   $proforma Proforma invoice details from the SuperFaktura API, with Invoice and RelatedItems.
	 */
	public function attach_tax_documents( $order, $proforma ) {
		if ( ! $this->enabled() || ! $this->is_sk_account( $order ) || ! is_object( $proforma ) ) {
			return;
		}

		$ids = array();
		foreach ( (array) ( $proforma->RelatedItems ?? array() ) as $item ) {
			if ( isset( $item->Invoice ) && 'regular' === ( $item->Invoice->type ?? '' ) && ! empty( $item->Invoice->tax_document ) ) {
				$ids[] = (int) $item->Invoice->id;
			}
		}

		$known = self::get_tax_documents( $order );
		$new   = array_diff( array_filter( $ids ), array_keys( $known ) );
		if ( ! $new ) {
			return;
		}

		$api      = $this->wc_sf->sf_api( $this->wc_sf->invoice_generator->get_order_api_credentials( $order ) );
		$language = $this->wc_sf->get_language( $order->get_id(), get_option( 'woocommerce_sf_invoice_language' ), true );
		$attached = array();
		foreach ( $new as $tax_id ) {
			$details = $api->getInvoiceDetails( $tax_id );
			if ( ! is_object( $details ) || ! isset( $details->{$tax_id}->Invoice ) ) {
				continue;
			}

			$invoice          = $details->{$tax_id}->Invoice;
			$known[ $tax_id ] = array(
				'number' => (string) ( $invoice->invoice_no_formatted ?? '' ),
				'pdf'    => ( ( 'yes' === get_option( 'woocommerce_sf_sandbox', 'no' ) ) ? $api::SANDBOX_URL : $api::SFAPI_URL ) . '/' . $language . '/invoices/pdf/' . $tax_id . '/token:' . ( $invoice->token ?? '' ),
			);
			$attached[]       = $tax_id;

			// Translators: %s Tax document number.
			$order->add_order_note( sprintf( __( 'Tax document %s for the received payment, created in SuperFaktura, was attached to the order.', 'woocommerce-superfaktura' ), $known[ $tax_id ]['number'] ) );
		}

		if ( ! $attached ) {
			return;
		}

		$order->update_meta_data( self::TAX_DOCUMENTS_META, $known );
		$order->save();

		if ( ! $this->should_send( $order, 'tax' ) ) {
			return;
		}

		foreach ( $attached as $tax_id ) {
			$this->send_document( $order, $tax_id );
		}
	}

	/**
	 * Send the order's invoice if it was not sent yet. Its credit notes follow it, see send_credit_notes().
	 *
	 * @param WC_Order $order Order.
	 */
	private function send_order_documents( $order ) {
		$regular_id = (int) $order->get_meta( 'wc_sf_internal_regular_id', true );
		if ( ! $regular_id || ! $this->should_send( $order, 'regular' ) ) {
			return;
		}

		if ( null === $this->get_state( $order, $regular_id ) ) {
			$this->send_document( $order, $regular_id );
		} else {
			$this->send_credit_notes( $order );
		}
	}

	/**
	 * Send the credit notes of the order that were not sent yet, once its invoice was sent via Peppol.
	 *
	 * @param WC_Order $order Order.
	 */
	private function send_credit_notes( $order ) {
		if ( ! $this->can_follow_invoice( $order ) || ! $this->should_send( $order, 'cancel' ) ) {
			return;
		}

		foreach ( $this->get_documents( $order ) as $document_id => $document ) {
			if ( 'cancel' === $document['type'] && null === $this->get_state( $order, $document_id ) ) {
				$this->send_document( $order, $document_id );
			}
		}
	}

	/**
	 * Whether the credit notes of the order can go via Peppol: its invoice went there or is being sent, and is not
	 * waiting for another attempt.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function can_follow_invoice( $order ) {
		$regular_id = (int) $order->get_meta( 'wc_sf_internal_regular_id', true );
		$state      = $this->get_state( $order, $regular_id );

		return $this->is_locked( $order, $regular_id ) && 'retry' !== $state['status'];
	}

	/**
	 * Send a new credit note. An invoice that was not sent yet goes first: once a credit note corrects it, it is final.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the credit note.
	 */
	private function send_credit_note( $order, $document_id ) {
		if ( ! $document_id || null !== $this->get_state( $order, $document_id ) ) {
			return;
		}

		$regular_id = (int) $order->get_meta( 'wc_sf_internal_regular_id', true );
		if ( $regular_id && null === $this->get_state( $order, $regular_id ) ) {
			// Sends the invoice, then its credit notes.
			$this->send_order_documents( $order );
			return;
		}

		$this->send_credit_notes( $order );
	}

	/**
	 * Send a document via Peppol.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @param int      $attempt     Number of the attempt, for sends scheduled again.
	 * @return true|WP_Error
	 */
	public function send_document( $order, $document_id, $attempt = 0 ) {
		$documents = $this->get_documents( $order );
		if ( ! isset( $documents[ $document_id ] ) ) {
			return new WP_Error( 'peppol_unknown_document', __( 'The document does not belong to this order.', 'woocommerce-superfaktura' ) );
		}

		$document = $documents[ $document_id ];
		$state    = $this->get_state( $order, $document_id );
		if ( $this->is_locked( $order, $document_id ) && 'retry' !== $state['status'] ) {
			return new WP_Error( 'peppol_already_sent', __( 'The document was already sent via Peppol.', 'woocommerce-superfaktura' ) );
		}

		// A credit note goes via Peppol only after its invoice: an invoice not sent yet goes first and takes it along.
		if ( 'cancel' === $document['type'] ) {
			$regular_id    = (int) $order->get_meta( 'wc_sf_internal_regular_id', true );
			$regular_state = $this->get_state( $order, $regular_id );
			if ( $regular_id && null === $regular_state ) {
				return $this->send_document( $order, $regular_id );
			}
			if ( ! $this->can_follow_invoice( $order ) ) {
				return new WP_Error( 'peppol_invoice_first', __( 'The invoice of this credit note has to be sent via Peppol first.', 'woocommerce-superfaktura' ) );
			}
		}

		$api      = $this->wc_sf->sf_api( $this->wc_sf->invoice_generator->get_order_api_credentials( $order ) );
		$response = $api->sendPeppol( $document_id );
		$label    = $this->document_label( $document );

		if ( is_object( $response ) && empty( $response->error ) ) {
			$this->save_state(
				$order,
				$document_id,
				array(
					'status'          => (string) ( $response->data->status ?? 'queued' ),
					'provider_status' => '',
					'failure_code'    => '',
					'operation_id'    => (string) ( $response->data->operation_id ?? '' ),
					'error_code'      => '',
					'message'         => '',
					'sent_at'         => time(),
				)
			);
			$this->log( $order, $document, 'peppol_send', null, 'Queued for sending via Peppol (operation ' . ( $response->data->operation_id ?? '?' ) . ').' );

			// Translators: %s Document, e.g. "invoice IN260614".
			$order->add_order_note( sprintf( __( 'Handed over for sending via Peppol (eFaktúra): %s.', 'woocommerce-superfaktura' ), $label ) );
			$this->schedule_check( $order, $document_id, 0 );

			if ( 'regular' === $document['type'] ) {
				$this->send_credit_notes( $order );
			}

			return true;
		}

		$error = $this->response_error( $api, $response );

		$status     = (int) ( $error['status'] ?? 999 );
		$error_code = (string) ( $error['error_code'] ?? '' );
		$message    = $this->error_message( $error );
		$this->log( $order, $document, 'peppol_send', $status, $this->log_text( $error ) );

		// Already sent or being sent: follow the existing sending.
		if ( in_array( $error_code, array( 'peppol.invoice_already_sent', 'peppol.send_already_pending' ), true ) ) {
			$this->save_state(
				$order,
				$document_id,
				array(
					'status'          => 'queued',
					'provider_status' => '',
					'failure_code'    => '',
					'error_code'      => '',
					'message'         => '',
					'sent_at'         => time(),
				)
			);
			$this->check_status( $order, $document_id, 0 );

			if ( 'regular' === $document['type'] ) {
				$this->send_credit_notes( $order );
			}

			return true;
		}

		// SuperFaktura could not be reached or was busy: try again later. Until then the document is not changed,
		// SuperFaktura may have queued it anyway.
		if ( 501 === $status || 429 === $status || 500 <= $status ) {
			if ( isset( self::RETRY_MINUTES[ $attempt ] ) ) {
				$minutes = self::RETRY_MINUTES[ $attempt ];
				$args    = array(
					'order_id'    => $order->get_id(),
					'document_id' => (int) $document_id,
					'attempt'     => $attempt + 1,
				);

				$this->save_state(
					$order,
					$document_id,
					array(
						'status'          => 'retry',
						'provider_status' => '',
						'failure_code'    => '',
						'error_code'      => $error_code,
						'message'         => $message,
						'sent_at'         => time(),
					)
				);

				if ( false === as_next_scheduled_action( 'sf_peppol_retry_send', $args, 'woocommerce-superfaktura' ) ) {
					as_schedule_single_action( time() + MINUTE_IN_SECONDS * $minutes, 'sf_peppol_retry_send', $args, 'woocommerce-superfaktura' );
				}

				// Translators: %1$s Document, e.g. "invoice IN260614", %2$d Minutes.
				$order->add_order_note( sprintf( __( 'Sending via Peppol (eFaktúra) failed: %1$s. We will try again in %2$d minutes.', 'woocommerce-superfaktura' ), $label, $minutes ) );

				return new WP_Error( 'peppol_retry', $message );
			}
		}

		$this->save_state(
			$order,
			$document_id,
			array(
				'status'          => 'error',
				'provider_status' => '',
				'failure_code'    => '',
				'error_code'      => $error_code,
				'message'         => $message,
				'sent_at'         => time(),
			)
		);

		// Translators: %1$s Document, e.g. "invoice IN260614", %2$s Reason.
		$order->add_order_note( sprintf( __( 'Not sent via Peppol (eFaktúra): %1$s. %2$s', 'woocommerce-superfaktura' ), $label, $message ) );

		return new WP_Error( 'peppol_not_sent', $message );
	}

	/**
	 * Send a document again, scheduled after SuperFaktura could not be reached.
	 *
	 * @param int $order_id    Order ID.
	 * @param int $document_id SuperFaktura ID of the document.
	 * @param int $attempt     Number of the attempt.
	 */
	public function scheduled_send( $order_id, $document_id, $attempt ) {
		$order = wc_get_order( $order_id );
		$state = $order ? $this->get_state( $order, $document_id ) : null;
		if ( ! $order || ( null !== $state && 'retry' !== $state['status'] ) ) {
			return;
		}

		$this->send_document( $order, (int) $document_id, (int) $attempt );
	}

	/**
	 * Schedule a check of the sending status.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @param int      $attempt     Index in CHECK_DELAYS.
	 */
	private function schedule_check( $order, $document_id, $attempt ) {
		if ( ! isset( self::CHECK_DELAYS[ $attempt ] ) ) {
			return;
		}

		$args = array(
			'order_id'    => $order->get_id(),
			'document_id' => (int) $document_id,
			'attempt'     => (int) $attempt,
		);

		if ( 0 === (int) $attempt ) {
			// A new chain of checks only when the document has none pending.
			foreach ( array_keys( self::CHECK_DELAYS ) as $index ) {
				if ( false !== as_next_scheduled_action( 'sf_peppol_check_status', array_merge( $args, array( 'attempt' => $index ) ), 'woocommerce-superfaktura' ) ) {
					return;
				}
			}
		} elseif ( false !== as_next_scheduled_action( 'sf_peppol_check_status', $args, 'woocommerce-superfaktura' ) ) {
			return;
		}

		as_schedule_single_action( time() + self::CHECK_DELAYS[ $attempt ], 'sf_peppol_check_status', $args, 'woocommerce-superfaktura' );
	}

	/**
	 * Scheduled check of the sending status.
	 *
	 * @param int $order_id    Order ID.
	 * @param int $document_id SuperFaktura ID of the document.
	 * @param int $attempt     Index in CHECK_DELAYS.
	 */
	public function scheduled_check( $order_id, $document_id, $attempt ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->check_status( $order, (int) $document_id, (int) $attempt );
	}

	/**
	 * Read the sending status of a document from SuperFaktura and save it.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @param int|null $attempt     Index in CHECK_DELAYS for a scheduled check, null for a check from the order box.
	 * @return true|WP_Error
	 */
	public function check_status( $order, $document_id, $attempt = null ) {
		$documents = $this->get_documents( $order );
		$state     = $this->get_state( $order, $document_id );
		if ( ! isset( $documents[ $document_id ] ) || null === $state ) {
			return new WP_Error( 'peppol_unknown_document', __( 'The document was not sent via Peppol.', 'woocommerce-superfaktura' ) );
		}

		if ( in_array( $state['status'], array( 'error', 'retry' ), true ) ) {
			return true;
		}

		$document = $documents[ $document_id ];
		$api      = $this->wc_sf->sf_api( $this->wc_sf->invoice_generator->get_order_api_credentials( $order ) );
		$response = $api->getPeppolStatus( $document_id );

		if ( ! is_object( $response ) || ! empty( $response->error ) || empty( $response->data->status ) ) {
			$error   = $this->response_error( $api, $response );
			$message = $this->error_message( $error );
			$this->log( $order, $document, 'peppol_status', (int) ( $error['status'] ?? 999 ), $this->log_text( $error ) );

			if ( null !== $attempt ) {
				$this->schedule_check( $order, $document_id, $attempt + 1 );
			}

			return new WP_Error( 'peppol_status_failed', $message );
		}

		$new_state = array(
			'status'          => (string) $response->data->status,
			'provider_status' => (string) ( $response->data->provider_status ?? '' ),
			'failure_code'    => (string) ( $response->data->failure_code ?? '' ),
			'operation_id'    => (string) ( $response->data->operation_id ?? ( $state['operation_id'] ?? '' ) ),
			'checked_at'      => time(),
		);
		$failed    = self::is_failed( $new_state );

		$this->log(
			$order,
			$document,
			'peppol_status',
			$failed ? self::LOG_STATUS_FAILED : null,
			sprintf( 'Status: %s, provider status: %s, failure code: %s', $new_state['status'], $new_state['provider_status'] ? $new_state['provider_status'] : '-', $new_state['failure_code'] ? $new_state['failure_code'] : '-' )
		);

		$old_label = $this->state_label( $state );
		$this->save_state( $order, $document_id, $new_state );
		$state     = $this->get_state( $order, $document_id );
		$new_label = $this->state_label( $state );

		if ( $old_label !== $new_label ) {
			if ( $failed && '' !== $new_state['failure_code'] ) {
				$new_label .= ' (' . $new_state['failure_code'] . ')';
			}

			// Translators: %1$s Document, e.g. "invoice IN260614", %2$s State, e.g. "Delivered".
			$order->add_order_note( sprintf( __( 'eFaktúra, %1$s: %2$s.', 'woocommerce-superfaktura' ), $this->document_label( $document ), $new_label ) );

			/**
			 * Fires when the Peppol (eFaktúra) state of an invoice or a credit note changes.
			 *
			 * @since 1.61.0
			 *
			 * @param WC_Order $order       Order.
			 * @param int      $document_id SuperFaktura ID of the document.
			 * @param array    $state       State with 'status', 'provider_status', 'failure_code' and more, see the
			 *                              SuperFaktura API documentation of the Peppol sending status.
			 */
			do_action( 'sf_peppol_status_changed', $order, $document_id, $state );
		}

		if ( null !== $attempt && ! self::is_final( $state ) ) {
			$this->schedule_check( $order, $document_id, $attempt + 1 );
		}

		return true;
	}

	/**
	 * Whether a document the plugin wants to change was sent via Peppol from SuperFaktura itself.
	 *
	 * The plugin knows only what it sent, so before it changes an invoice or a credit note while sending via Peppol
	 * is on, it asks SuperFaktura. A document found there is followed like one the plugin sent.
	 *
	 * @param WC_Order $order       Order.
	 * @param int      $document_id SuperFaktura ID of the document.
	 * @return bool
	 */
	public function is_sent_in_superfaktura( $order, $document_id ) {
		$documents = $this->get_documents( $order );
		if ( ! $document_id || ! isset( $documents[ $document_id ] ) || ! $this->should_send( $order, $documents[ $document_id ]['type'] ) ) {
			return false;
		}

		if ( null !== $this->get_state( $order, $document_id ) ) {
			return $this->is_locked( $order, $document_id );
		}

		$api      = $this->wc_sf->sf_api( $this->wc_sf->invoice_generator->get_order_api_credentials( $order ) );
		$response = $api->getPeppolStatus( $document_id );
		if ( ! is_object( $response ) || ! empty( $response->error ) || empty( $response->data->status ) ) {
			// Not sent (404), or not known now: the document stays as the plugin knows it.
			$error = $this->response_error( $api, $response );
			if ( 'peppol.send_status_not_found' === ( $error['error_code'] ?? '' ) ) {
				$this->log( $order, $documents[ $document_id ], 'peppol_status', null, 'Not sent via Peppol yet.' );
			} else {
				$this->log( $order, $documents[ $document_id ], 'peppol_status', (int) ( $error['status'] ?? 999 ), $this->log_text( $error ) );
			}
			return false;
		}

		$state = array(
			'status'          => (string) $response->data->status,
			'provider_status' => (string) ( $response->data->provider_status ?? '' ),
			'failure_code'    => (string) ( $response->data->failure_code ?? '' ),
			'operation_id'    => (string) ( $response->data->operation_id ?? '' ),
			'error_code'      => '',
			'message'         => '',
			'sent_at'         => time(),
			'checked_at'      => time(),
		);
		$this->log( $order, $documents[ $document_id ], 'peppol_status', self::is_failed( $state ) ? self::LOG_STATUS_FAILED : null, sprintf( 'Sent from SuperFaktura. Status: %s, provider status: %s', $state['status'], $state['provider_status'] ? $state['provider_status'] : '-' ) );
		$this->save_state( $order, $document_id, $state );

		if ( ! self::is_final( $state ) ) {
			$this->schedule_check( $order, $document_id, 0 );
		}

		return ! self::is_failed( $state );
	}

	/**
	 * Error of a request: the API client's last error, or an error answered with a successful HTTP status.
	 *
	 * @param WC_SF_Api $api      API client.
	 * @param mixed     $response Response.
	 * @return array Keys status, message, error_code and data.
	 */
	private function response_error( $api, $response ) {
		if ( is_object( $response ) && ! empty( $response->error ) ) {
			return array(
				'status'     => (int) $response->error,
				'message'    => is_scalar( $response->error_message ?? null ) ? (string) $response->error_message : '',
				'error_code' => is_scalar( $response->error_code ?? null ) ? (string) $response->error_code : '',
				'data'       => $response->data ?? null,
			);
		}

		$error = $api->getLastError();
		if ( ! $error && is_object( $response ) ) {
			// A successful answer without the expected data.
			$error = array(
				'status'  => 999,
				'message' => 'Unexpected response.',
			);
		}

		return $error;
	}

	/**
	 * Readable reason of a refused or failed request.
	 *
	 * @param array $error Last error of the API client.
	 * @return string
	 */
	public function error_message( $error ) {
		$error_code = (string) ( $error['error_code'] ?? '' );
		$data       = $error['data'] ?? null;

		if ( 'peppol.sending_not_available' === $error_code && is_object( $data ) && 'REGISTRATION_MISSING' === ( $data->reason ?? '' ) ) {
			return __( 'eFaktúra is not activated for the company in SuperFaktura.', 'woocommerce-superfaktura' );
		}

		$issues = $this->get_issues( $error );
		if ( 'peppol.precheck_failed' === $error_code && $issues ) {
			// Translators: %s Problems found by SuperFaktura, separated by semicolons.
			return sprintf( __( 'SuperFaktura found problems in the document: %s', 'woocommerce-superfaktura' ), implode( '; ', $issues ) );
		}

		$message = trim( (string) ( $error['message'] ?? '' ) );
		if ( is_object( $data ) && ! empty( $data->reason ) && is_scalar( $data->reason ) ) {
			$message .= ' (' . $data->reason . ')';
		}

		return '' !== $message ? $message : __( 'SuperFaktura did not answer.', 'woocommerce-superfaktura' );
	}

	/**
	 * Problems SuperFaktura found when checking a document before sending it.
	 *
	 * @param array $error Last error of the API client.
	 * @return string[]
	 */
	private function get_issues( $error ) {
		$data = $error['data'] ?? null;
		if ( ! is_object( $data ) || empty( $data->issues ) ) {
			return array();
		}

		$issues = array();
		foreach ( (array) $data->issues as $issue ) {
			if ( is_scalar( $issue ) ) {
				$issues[] = (string) $issue;
			} elseif ( is_object( $issue ) || is_array( $issue ) ) {
				$issue    = (array) $issue;
				$text     = $issue['message'] ?? ( $issue['description'] ?? ( $issue['text'] ?? '' ) );
				$issues[] = is_scalar( $text ) && '' !== (string) $text ? (string) $text : wp_json_encode( $issue );
			}
		}

		return $issues;
	}

	/**
	 * Untranslated text of a refused or failed request for the API log: error code, reason, message and problems.
	 *
	 * @param array $error Last error of the API client.
	 * @return string
	 */
	private function log_text( $error ) {
		$data = $error['data'] ?? null;
		$text = (string) ( $error['error_code'] ?? '' );
		if ( is_object( $data ) && ! empty( $data->reason ) && is_scalar( $data->reason ) ) {
			$text .= ' (' . $data->reason . ')';
		}

		$message = trim( (string) ( $error['message'] ?? '' ) );
		if ( '' !== $message ) {
			$text .= ( '' !== $text ? ': ' : '' ) . $message;
		}

		$issues = $this->get_issues( $error );
		if ( $issues ) {
			$text .= ' ' . implode( '; ', $issues );
		}

		return '' !== $text ? $text : 'Request failed without further information.';
	}

	/**
	 * Write a Peppol request to the API log.
	 *
	 * @param WC_Order $order        Order.
	 * @param array    $document     Document from get_documents().
	 * @param string   $request_type 'peppol_send' or 'peppol_status'.
	 * @param int|null $status       Status, null on success.
	 * @param string   $message      Message.
	 */
	private function log( $order, $document, $request_type, $status, $message ) {
		$this->wc_sf->wc_sf_log(
			array(
				'order_id'         => $order->get_id(),
				'document_type'    => $document['type'],
				'request_type'     => $request_type,
				'response_status'  => $status,
				'response_message' => mb_substr( ( '' !== $document['number'] ? $document['number'] . ': ' : '' ) . $message, 0, 1024 ),
			)
		);
	}
}
