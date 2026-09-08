<?php
/**
 * Bulk document actions in the WooCommerce orders list.
 *
 * Adds "create invoice", "create proforma invoice" and "regenerate existing documents"
 * to the bulk actions of both order list screens (legacy posts list and HPOS list).
 *
 * The bulk handler itself does no API work: it stores the selected orders as a batch
 * and redirects immediately, so the admin never waits on the SuperFaktúra API. The
 * orders list then processes the batch one order per AJAX request with a progress bar.
 *
 * @package SuperFaktúra WooCommerce
 */

/**
 * WC_SF_Bulk class.
 */
class WC_SF_Bulk {

	const ACTION_REGULAR  = 'wc_sf_bulk_regular';
	const ACTION_PROFORMA = 'wc_sf_bulk_proforma';
	const ACTION_REGEN    = 'wc_sf_bulk_regen';

	/**
	 * Soft cap on orders per batch.
	 */
	const MAX_ORDERS = 250;

	/**
	 * Consecutive failures after which the batch pauses (API most likely down).
	 */
	const MAX_CONSECUTIVE_FAILURES = 5;

	/**
	 * How long an unfinished batch is kept for resuming.
	 */
	const BATCH_TTL = DAY_IN_SECONDS;

	/**
	 * A step lock older than this is considered abandoned (PHP fatal, killed request).
	 */
	const LOCK_TTL = 120;

	/**
	 * API requests needed to create one document: the create call.
	 */
	const REQUESTS_PER_CREATE = 1;

	/**
	 * API requests needed to regenerate one document on top of deleting its items:
	 * load the document, load its items, edit.
	 */
	const REQUESTS_PER_REGEN = 3;

	/**
	 * Invoice items deleted per request when regenerating (WC_SuperFaktura::sf_clean_invoice_items()).
	 */
	const REGEN_DELETE_CHUNK = 50;

	/**
	 * Daily API requests a batch always leaves free for the store's own checkout invoicing.
	 */
	const QUOTA_RESERVE = 100;

	/**
	 * Order statuses for which no document makes sense.
	 *
	 * @var string[]
	 */
	private $skipped_statuses = array( 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' );

	/**
	 * Order list screen IDs (legacy posts list, HPOS list).
	 *
	 * @var string[]
	 */
	private $screens = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );

	/**
	 * Instance of WC_SuperFaktura.
	 *
	 * @var WC_SuperFaktura
	 */
	private $wc_sf;

	/**
	 * Token identifying this request as the owner of the step lock.
	 *
	 * @var string
	 */
	private $lock_token = '';

	/**
	 * Whether another request took the step lock over while this one was still working.
	 *
	 * @var bool
	 */
	private $lock_lost = false;

	/**
	 * API requests the document being regenerated still needs after its items are deleted
	 * (tag lookup and creation, proforma load), on top of the deletions and the edit itself.
	 *
	 * @var int
	 */
	private $regen_extra_requests = 0;

	/**
	 * Whether the current step refused to delete a document's items for lack of API quota.
	 *
	 * @var bool
	 */
	private $quota_short = false;

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
		if ( ! is_admin() ) {
			return;
		}

		foreach ( $this->screens as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( $this, 'register_bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( $this, 'handle_bulk_action' ), 10, 3 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_ajax_wc_sf_bulk_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_wc_sf_bulk_cancel', array( $this, 'ajax_cancel' ) );
	}

	/**
	 * Add the plugin's entries to the bulk actions dropdown.
	 *
	 * Creation actions follow the same settings as the manual links in the order meta box.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		if ( 'yes' === get_option( 'woocommerce_sf_invoice_regular_manual', 'no' ) ) {
			$actions[ self::ACTION_REGULAR ] = __( 'SuperFaktúra: create invoices', 'woocommerce-superfaktura' );
		}
		if ( 'yes' === get_option( 'woocommerce_sf_invoice_proforma_manual', 'no' ) ) {
			$actions[ self::ACTION_PROFORMA ] = __( 'SuperFaktúra: create proforma invoices', 'woocommerce-superfaktura' );
		}
		$actions[ self::ACTION_REGEN ] = __( 'SuperFaktúra: regenerate existing documents', 'woocommerce-superfaktura' );

		return $actions;
	}

	/**
	 * Store the selected orders as a batch and redirect back to the list.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action.
	 * @param int[]  $ids         Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ) {
		$types = array(
			self::ACTION_REGULAR  => 'regular',
			self::ACTION_PROFORMA => 'proforma',
			self::ACTION_REGEN    => 'regen',
		);

		if ( ! isset( $types[ $action ] ) ) {
			return $redirect_to;
		}

		$redirect_to = remove_query_arg( 'sf_bulk', $redirect_to );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		if ( empty( $ids ) ) {
			return add_query_arg( 'sf_bulk', 'empty', $redirect_to );
		}

		if ( count( $ids ) > self::MAX_ORDERS ) {
			return add_query_arg( 'sf_bulk', 'too_many', $redirect_to );
		}

		$pending = $this->sort_oldest_first( $ids );

		if ( ! $this->has_api_quota_for( $this->estimate_requests( $types[ $action ], $pending ) ) ) {
			return add_query_arg( 'sf_bulk', 'quota', $redirect_to );
		}

		$batch = array(
			'id'                   => wp_generate_uuid4(),
			'type'                 => $types[ $action ],
			'pending'              => $pending,
			'total'                => count( $ids ),
			'processed'            => 0,
			'counts'               => array(
				'created'             => 0,
				'regenerated'         => 0,
				'skipped_exists'      => 0,
				'skipped_no_document' => 0,
				'skipped_not_allowed' => 0,
				'skipped_status'      => 0,
				'failed'              => 0,
			),
			'failed_orders'        => array(),
			'consecutive_failures' => 0,
			'state'                => 'running',
			'pause_reason'         => '',
			'autostart'            => true,
			'started'              => time(),
			'version'              => 0,
		);

		// A new selection replaces whatever batch the user had; in-flight steps of the old
		// batch notice the changed id and drop their results.
		$this->save_batch( $batch );

		return add_query_arg( 'sf_bulk', 'started', $redirect_to );
	}

	/**
	 * Enqueue the batch script on the order list screens only.
	 */
	public function enqueue_scripts() {
		if ( ! $this->is_orders_list_screen() ) {
			return;
		}

		wp_enqueue_style( 'wc-sf-admin-bulk-css', plugins_url( 'assets/css/admin-bulk.css', WC_SF_FILE_PATH ), array( 'dashicons' ), $this->wc_sf->version );
		wp_enqueue_script( 'wc-sf-admin-bulk-js', plugins_url( 'assets/js/admin-bulk.js', WC_SF_FILE_PATH ), array( 'jquery' ), $this->wc_sf->version, true );

		$batch = $this->get_batch();

		wp_localize_script(
			'wc-sf-admin-bulk-js',
			'wc_sf_bulk',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wc_sf_bulk' ),
				'actions'    => array( self::ACTION_REGULAR, self::ACTION_PROFORMA, self::ACTION_REGEN ),
				'max_orders' => self::MAX_ORDERS,
				'batch'      => $batch ? $this->batch_summary( $batch ) : null,
				'i18n'       => array(
					// Translators: %d number of selected orders.
					'confirm'             => __( 'Run this SuperFaktúra action for %d selected orders? If e-mail sending is enabled in your SuperFaktúra account, customers will receive their documents right away.', 'woocommerce-superfaktura' ),
					// Translators: %d maximum number of orders per batch.
					'too_many'            => __( 'Please select at most %d orders per batch.', 'woocommerce-superfaktura' ),
					'title_running'       => __( 'SuperFaktúra: processing documents…', 'woocommerce-superfaktura' ),
					'title_paused'        => __( 'SuperFaktúra: processing paused', 'woocommerce-superfaktura' ),
					'title_unfinished'    => __( 'SuperFaktúra: unfinished batch', 'woocommerce-superfaktura' ),
					'title_done'          => __( 'SuperFaktúra: batch finished', 'woocommerce-superfaktura' ),
					// Translators: %1$d processed orders, %2$d total orders.
					'progress'            => __( '%1$d of %2$d orders processed', 'woocommerce-superfaktura' ),
					'paused'              => __( 'Several documents in a row could not be created — the SuperFaktúra API may be unavailable. Check the API log and continue later.', 'woocommerce-superfaktura' ),
					// Translators: %d number of API requests kept free for new orders.
					'paused_quota'        => sprintf( __( 'The remaining daily limit of SuperFaktúra API requests is too low to continue (%d requests are always kept for new orders). Continue after the limit resets or increase it.', 'woocommerce-superfaktura' ), $this->quota_reserve() ),
					'request_failed'      => __( 'The request failed. Check your connection and continue.', 'woocommerce-superfaktura' ),
					'continue'            => __( 'Continue', 'woocommerce-superfaktura' ),
					'cancel'              => __( 'Cancel batch', 'woocommerce-superfaktura' ),
					'dismiss'             => __( 'Dismiss', 'woocommerce-superfaktura' ),
					'created'             => __( 'created', 'woocommerce-superfaktura' ),
					'regenerated'         => __( 'regenerated', 'woocommerce-superfaktura' ),
					'skipped_exists'      => __( 'skipped – document already exists', 'woocommerce-superfaktura' ),
					'skipped_no_document' => __( 'skipped – no document to regenerate', 'woocommerce-superfaktura' ),
					'skipped_not_allowed' => __( 'skipped – paid or completed orders are not regenerated (see the sf_can_regenerate filter)', 'woocommerce-superfaktura' ),
					'skipped_status'      => __( 'skipped – cancelled, refunded or failed orders', 'woocommerce-superfaktura' ),
					'failed'              => __( 'failed', 'woocommerce-superfaktura' ),
					'failed_orders'       => __( 'Failed orders:', 'woocommerce-superfaktura' ),
					// Translators: %s API log URL.
					'api_log'             => sprintf( __( 'See <a href="%s">API log</a> for more information.', 'woocommerce-superfaktura' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=superfaktura&section=api_log' ) ) ),
				),
			)
		);
	}

	/**
	 * Render the batch notice (progress container) and the pre-flight error notices.
	 */
	public function render_notice() {
		if ( ! $this->is_orders_list_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flag = isset( $_GET['sf_bulk'] ) ? sanitize_key( wp_unslash( $_GET['sf_bulk'] ) ) : '';

		$errors = array(
			'empty'    => __( 'No orders were selected.', 'woocommerce-superfaktura' ),
			// Translators: %d maximum number of orders per batch.
			'too_many' => sprintf( __( 'Please select at most %d orders per batch.', 'woocommerce-superfaktura' ), self::MAX_ORDERS ),
			'quota'    => sprintf(
				'%s <a href="%s">%s</a>',
				// Translators: %d number of API requests kept free for new orders.
				sprintf( __( 'The selected orders do not fit into the remaining daily limit of SuperFaktúra API requests (%d requests are always kept for new orders).', 'woocommerce-superfaktura' ), $this->quota_reserve() ),
				'https://moja.superfaktura.sk/shoppings/index/api_limit_increase/recommended_increase:1000/recommended_duration:8',
				__( 'Increase the number of API requests', 'woocommerce-superfaktura' )
			),
		);

		if ( isset( $errors[ $flag ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'SuperFaktúra', 'woocommerce-superfaktura' ) . '</strong>: ' . wp_kses_post( $errors[ $flag ] ) . '</p></div>';
		}

		$batch = $this->get_batch();
		if ( ! $batch ) {
			return;
		}

		// The script fills in the texts, counters and buttons.
		// Filled in and kept up to date by assets/js/admin-bulk.js.
		echo '<div class="notice wc-sf-bulk-notice" role="status">';
		echo '<div class="wc-sf-bulk-head"><span class="wc-sf-bulk-icon" aria-hidden="true"></span><strong class="wc-sf-bulk-title"></strong><span class="wc-sf-bulk-count"></span></div>';
		echo '<div class="wc-sf-bulk-bar"><div class="wc-sf-bulk-bar-fill"></div></div>';
		echo '<p class="wc-sf-bulk-message" hidden></p>';
		echo '<div class="wc-sf-bulk-foot"><ul class="wc-sf-bulk-summary"></ul><div class="wc-sf-bulk-buttons"></div></div>';
		echo '<p class="wc-sf-bulk-failed" hidden></p>';
		echo '</div>';
	}

	/**
	 * AJAX: process the next order of the current user's batch.
	 */
	public function ajax_step() {
		check_ajax_referer( 'wc_sf_bulk', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$resume   = ! empty( $_POST['resume'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Only one step per user at a time: a second tab (or a double click) must not pick up
		// the same order while this request is still generating its document.
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'code' => 'busy' ), 409 );
		}

		// wp_send_json_*() exits, and PHP skips finally blocks on exit — so no response is sent
		// inside the try; the lock is released first and the outcome is reported afterwards.
		$error = '';
		$batch = null;

		try {
			$batch = $this->get_batch();
			if ( ! $batch || ( '' !== $batch_id && $batch['id'] !== $batch_id ) ) {
				$error = 'stale';
				$batch = null;
			}

			if ( $batch ) {
				$changed = false;

				// Keep the lock alive for as long as this request is demonstrably working: every
				// completed SuperFaktúra HTTP call renews it, so a long regeneration never
				// outlives its lease while a dead request still gets taken over.
				add_filter( 'pre_http_request', array( $this, 'renew_lock_on_api_call' ), 1, 3 );
				add_filter( 'pre_http_request', array( $this, 'abort_api_call_if_lock_lost' ), PHP_INT_MAX, 3 );
				add_filter( 'sf_clean_invoice_items_allowed', array( $this, 'check_quota_before_item_deletion' ), 10, 3 );

				// A paused batch only moves on when the admin explicitly clicks "Continue"; a tab
				// that merely polls (after a "busy" response) must not resume it.
				$process = 'paused' !== $batch['state'];
				if ( 'paused' === $batch['state'] && $resume ) {
					$batch['state']                = 'running';
					$batch['pause_reason']         = '';
					$batch['consecutive_failures'] = 0;
					$changed                       = true;
					$process                       = true;
				}

				if ( $process && ! empty( $batch['autostart'] ) ) {
					$batch['autostart'] = false;
					$changed            = true;
				}

				if ( $process && 'done' !== $batch['state'] && ! empty( $batch['pending'] ) ) {
					$changed  = true;
					$order_id = (int) $batch['pending'][0];

					// The API usage option is refreshed by every API response, so this reflects
					// the requests already spent by this batch. Regeneration deletes items one by
					// one, so running out halfway would leave a document without items.
					if ( ! $this->has_api_quota_for( $this->estimate_requests( $batch['type'], array( $order_id ) ) ) ) {
						$batch['state']        = 'paused';
						$batch['pause_reason'] = 'quota';
					} else {
						array_shift( $batch['pending'] );
						$result = $this->process_order( $batch['type'], $order_id );

						if ( 'quota' === $result['result'] ) {
							// Refused before touching the document: put the order back and pause.
							array_unshift( $batch['pending'], $order_id );
							$batch['state']        = 'paused';
							$batch['pause_reason'] = 'quota';
						} else {
							$batch['processed']++;
							$batch['counts'][ $result['result'] ]++;
						}

						if ( 'failed' === $result['result'] ) {
							$batch['failed_orders'][] = array( 'id' => $order_id, 'number' => $result['order_number'] );
							$batch['consecutive_failures']++;
							if ( $batch['consecutive_failures'] >= self::MAX_CONSECUTIVE_FAILURES && ! empty( $batch['pending'] ) ) {
								$batch['state']        = 'paused';
								$batch['pause_reason'] = 'failures';
							}
						} elseif ( 'quota' !== $result['result'] ) {
							$batch['consecutive_failures'] = 0;
						}
					}
				}

				if ( $process && empty( $batch['pending'] ) && 'done' !== $batch['state'] ) {
					$batch['state'] = 'done';
					$changed        = true;
				}

				remove_filter( 'pre_http_request', array( $this, 'renew_lock_on_api_call' ), 1 );
				remove_filter( 'pre_http_request', array( $this, 'abort_api_call_if_lock_lost' ), PHP_INT_MAX );
				remove_filter( 'sf_clean_invoice_items_allowed', array( $this, 'check_quota_before_item_deletion' ), 10 );

				// The batch may have been cancelled or replaced while the document was being
				// generated, or the lock may have been taken over by another tab; never
				// resurrect or overwrite newer progress with this request's copy.
				if ( $changed && ( $this->lock_lost || ! $this->owns_lock() || ! $this->save_batch_if_current( $batch ) ) ) {
					$error = 'stale';
				}
			}
		} finally {
			$this->release_lock();
		}

		if ( $error ) {
			wp_send_json_error( array( 'code' => $error ), 409 );
		}

		wp_send_json_success( $this->batch_summary( $batch ) );
	}

	/**
	 * AJAX: cancel (or dismiss a finished) batch.
	 */
	public function ajax_cancel() {
		check_ajax_referer( 'wc_sf_bulk', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';

		// A stale tab must not cancel a batch it never saw.
		$this->delete_batch( $batch_id );

		wp_send_json_success();
	}

	/**
	 * Process a single order of a batch.
	 *
	 * @param string $type     Batch type: regular, proforma or regen.
	 * @param int    $order_id Order ID.
	 * @return array Array with 'result' (a counter key) and 'order_number'.
	 */
	private function process_order( $type, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failed', 'order_number' => (string) $order_id );
		}

		$order_number = $order->get_order_number();

		if ( 'regen' === $type ) {
			return array( 'result' => $this->regenerate_documents( $order ), 'order_number' => $order_number );
		}

		if ( in_array( $order->get_status(), $this->skipped_statuses, true ) ) {
			return array( 'result' => 'skipped_status', 'order_number' => $order_number );
		}

		if ( '' !== (string) $order->get_meta( 'wc_sf_internal_' . $type . '_id', true ) ) {
			return array( 'result' => 'skipped_exists', 'order_number' => $order_number );
		}

		$result = $this->wc_sf->invoice_generator->generate_invoice( $order, $type );

		if ( is_wp_error( $result ) ) {
			return array( 'result' => 'duplicate_document' === $result->get_error_code() ? 'skipped_exists' : 'failed', 'order_number' => $order_number );
		}

		return array( 'result' => $result ? 'created' : 'failed', 'order_number' => $order_number );
	}

	/**
	 * Regenerate all existing documents of an order, mirroring the meta box "Regenerate" link.
	 *
	 * @param WC_Order $order Order.
	 * @return string Counter key.
	 */
	private function regenerate_documents( $order ) {
		$types = array();
		foreach ( array( 'proforma', 'regular', 'cancel' ) as $type ) {
			if ( '' !== (string) $order->get_meta( 'wc_sf_internal_' . $type . '_id', true ) ) {
				$types[] = $type;
			}
		}

		if ( empty( $types ) ) {
			return 'skipped_no_document';
		}

		if ( ! $this->wc_sf->invoice_generator->sf_can_regenerate( $order ) ) {
			return 'skipped_not_allowed';
		}

		$tag_requests = ( '' !== (string) get_option( 'woocommerce_sf_invoice_tag' ) ) ? 2 : 0;
		$has_proforma = ( '' !== (string) $order->get_meta( 'wc_sf_internal_proforma_id', true ) );

		foreach ( $types as $type ) {
			// An earlier document of this order may have cost more than estimated (more items on
			// SuperFaktúra than on the order), so re-check the current quota before every document.
			if ( ! $this->has_api_quota_for( $this->estimate_regen_document( $order, $type ) + ( $tag_requests ? 1 : 0 ) ) ) {
				return 'quota';
			}

			// What check_quota_before_item_deletion() must budget beyond the deletions and the edit.
			$this->regen_extra_requests = $tag_requests + ( ( 'regular' === $type && $has_proforma ) ? 1 : 0 );
			$this->quota_short          = false;

			$result = $this->wc_sf->invoice_generator->generate_invoice( $order, $type );

			if ( is_wp_error( $result ) && 'regeneration_refused' === $result->get_error_code() && $this->quota_short ) {
				return 'quota';
			}
			if ( ! $result || is_wp_error( $result ) ) {
				return 'failed';
			}
		}

		return 'regenerated';
	}

	/**
	 * Refuse to delete a document's items unless the deletions, the edit and the remaining
	 * requests of this document fit into the quota (sf_clean_invoice_items_allowed filter).
	 *
	 * The estimate before the step counted the order's current items; the document on
	 * SuperFaktúra may hold more. This is the first moment the real number is known and
	 * the last moment the document is still intact.
	 *
	 * @param bool  $allowed    Whether the deletion may proceed.
	 * @param int   $invoice_id Invoice ID.
	 * @param array $item_ids   IDs of the items on the document.
	 * @return bool
	 */
	public function check_quota_before_item_deletion( $allowed, $invoice_id, $item_ids ) {
		if ( ! $allowed ) {
			return $allowed;
		}

		$needed = (int) ceil( count( $item_ids ) / self::REGEN_DELETE_CHUNK ) + 1 + $this->regen_extra_requests;
		if ( $this->has_api_quota_for( $needed ) ) {
			return true;
		}

		$this->quota_short = true;

		return false;
	}

	/**
	 * Sort order IDs by creation date, oldest first, so documents are numbered chronologically.
	 *
	 * @param int[] $ids Order IDs.
	 * @return int[]
	 */
	private function sort_oldest_first( $ids ) {
		$dated = array();
		foreach ( $ids as $id ) {
			$order        = wc_get_order( $id );
			$dated[ $id ] = $order instanceof WC_Order && $order->get_date_created() ? $order->get_date_created()->getTimestamp() : PHP_INT_MAX;
		}

		// WooCommerce hands the selection over newest-first; ties within the same second
		// are broken by the order ID so the result is always chronological.
		uksort(
			$dated,
			function ( $a, $b ) use ( $dated ) {
				return ( $dated[ $a ] <=> $dated[ $b ] ) ?: ( $a <=> $b );
			}
		);

		return array_map( 'intval', array_keys( $dated ) );
	}

	/**
	 * Estimate the API requests a batch step needs for the given orders.
	 *
	 * Mirrors what generate_invoice() actually sends: one create (or, when regenerating,
	 * load + load items + edit plus the chunked item deletions) per document, one tag
	 * lookup per document when an invoice tag is configured, and one load of the proforma
	 * a regular invoice is linked to. Orders the step would skip without calling the API
	 * cost nothing. Two things cannot be known without an API call and are budgeted with a
	 * margin instead: a configured tag that does not exist yet is created once (one extra
	 * request per estimate), and the document on SuperFaktúra may hold more items than the
	 * order does now (one extra deletion request per regenerated document).
	 *
	 * @param string $type Batch type: regular, proforma or regen.
	 * @param int[]  $ids  Order IDs.
	 * @return int
	 */
	private function estimate_requests( $type, $ids ) {
		$tag_requests = ( '' !== (string) get_option( 'woocommerce_sf_invoice_tag' ) ) ? 1 : 0;
		$requests     = 0;

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$has_proforma = ( '' !== (string) $order->get_meta( 'wc_sf_internal_proforma_id', true ) );

			if ( 'regen' !== $type ) {
				if ( in_array( $order->get_status(), $this->skipped_statuses, true ) || '' !== (string) $order->get_meta( 'wc_sf_internal_' . $type . '_id', true ) ) {
					continue;
				}

				$requests += self::REQUESTS_PER_CREATE + $tag_requests + ( ( 'regular' === $type && $has_proforma ) ? 1 : 0 );
				continue;
			}

			if ( ! $this->wc_sf->invoice_generator->sf_can_regenerate( $order ) ) {
				continue;
			}

			foreach ( array( 'proforma', 'regular', 'cancel' ) as $doc_type ) {
				if ( '' !== (string) $order->get_meta( 'wc_sf_internal_' . $doc_type . '_id', true ) ) {
					$requests += $this->estimate_regen_document( $order, $doc_type );
				}
			}
		}

		// A configured tag that does not exist yet is created by the first document.
		if ( $requests > 0 && $tag_requests > 0 ) {
			$requests++;
		}

		return $requests;
	}

	/**
	 * Estimate the API requests regenerating one document of an order needs.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $doc_type Document type: proforma, regular or cancel.
	 * @return int
	 */
	private function estimate_regen_document( $order, $doc_type ) {
		$tag_requests = ( '' !== (string) get_option( 'woocommerce_sf_invoice_tag' ) ) ? 1 : 0;
		$has_proforma = ( '' !== (string) $order->get_meta( 'wc_sf_internal_proforma_id', true ) );

		// Items, shipping, fees and the discount line each become an invoice item, deleted in chunks;
		// one chunk more in case the document on SuperFaktúra holds more items than the order does now.
		$item_requests = (int) ceil( ( count( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) ) + 1 ) / self::REGEN_DELETE_CHUNK ) + 1;

		return self::REQUESTS_PER_REGEN + $item_requests + $tag_requests + ( ( 'regular' === $doc_type && $has_proforma ) ? 1 : 0 );
	}

	/**
	 * Whether the remaining daily API quota (as last reported by the API) covers the batch
	 * while still leaving the reserve free for checkout invoicing.
	 *
	 * @param int $count Number of API requests.
	 * @return bool
	 */
	private function has_api_quota_for( $count ) {
		if ( $count <= 0 ) {
			return true;
		}

		$usage = get_option( 'woocommerce_sf_api_usage', array() );
		if ( empty( $usage ) || ! isset( $usage['dailyremaining'], $usage['dailyreset'] ) ) {
			return true;
		}

		if ( time() >= strtotime( $usage['dailyreset'] ) ) {
			return true;
		}

		return (int) $usage['dailyremaining'] - $count >= $this->quota_reserve();
	}

	/**
	 * Daily API requests a batch never uses up, kept for the store's own checkout invoicing.
	 *
	 * @return int
	 */
	private function quota_reserve() {
		return max( 0, (int) apply_filters( 'sf_bulk_quota_reserve', self::QUOTA_RESERVE ) );
	}

	/**
	 * Whether the current admin screen is one of the order list screens.
	 *
	 * @return bool
	 */
	private function is_orders_list_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();

		return $screen && in_array( $screen->id, $this->screens, true );
	}

	/* ---------------------------------------------------------------------------------------
	 * Batch storage and step lock.
	 *
	 * Both live in the options table and are written with direct, atomic SQL: WordPress'
	 * option API is read-then-write (add_option() is an INSERT ... ON DUPLICATE KEY UPDATE),
	 * which is not safe against two admin tabs working on the same batch at once.
	 * ------------------------------------------------------------------------------------- */

	/**
	 * Option name of the current user's batch.
	 *
	 * @return string
	 */
	private function batch_key() {
		return 'wc_sf_bulk_' . get_current_user_id();
	}

	/**
	 * Load the current user's batch straight from the database, bypassing the request-local
	 * option cache so a batch replaced by another request is never read as stale data.
	 *
	 * @return array|null
	 */
	private function get_batch() {
		global $wpdb;

		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->batch_key() ) );
		$batch = $raw ? maybe_unserialize( $raw ) : null;

		if ( ! is_array( $batch ) || ! isset( $batch['id'], $batch['pending'], $batch['type'], $batch['version'] ) ) {
			return null;
		}

		if ( isset( $batch['expires'] ) && (int) $batch['expires'] < time() ) {
			$this->delete_batch( $batch['id'] );
			return null;
		}

		return $batch;
	}

	/**
	 * Store a new batch, replacing whatever the user had.
	 *
	 * @param array $batch Batch.
	 */
	private function save_batch( $batch ) {
		global $wpdb;

		$batch['expires'] = time() + self::BATCH_TTL;
		$name             = $this->batch_key();

		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => maybe_serialize( $batch ),
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
		$this->flush_option_cache( $name );
	}

	/**
	 * Persist the batch only while it is still the user's active batch at the version this
	 * request read.
	 *
	 * A single conditional UPDATE keyed on the stored batch id and version: if the batch was
	 * cancelled (row gone), replaced (different id) or advanced by another request that took
	 * over the lock (different version) while this request was generating a document, no
	 * row matches and nothing is written.
	 *
	 * @param array $batch Batch as read by this request, with the changes applied.
	 * @return bool Whether it was saved.
	 */
	private function save_batch_if_current( $batch ) {
		global $wpdb;

		$expected_version = (int) $batch['version'];
		$batch['version'] = $expected_version + 1;
		$batch['expires'] = time() + self::BATCH_TTL;
		$name             = $this->batch_key();

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s AND option_value LIKE %s",
				maybe_serialize( $batch ),
				$name,
				'%' . $wpdb->esc_like( $this->id_marker( $batch['id'] ) ) . '%',
				'%' . $wpdb->esc_like( serialize( 'version' ) . serialize( $expected_version ) ) . '%'
			)
		);
		$this->flush_option_cache( $name );

		return (bool) $updated;
	}

	/**
	 * Delete the user's batch — optionally only if it still has the given id.
	 *
	 * @param string $batch_id Batch id the caller knows about, or '' for any.
	 */
	private function delete_batch( $batch_id = '' ) {
		global $wpdb;

		$name = $this->batch_key();
		if ( '' === $batch_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $name, '%' . $wpdb->esc_like( $this->id_marker( $batch_id ) ) . '%' ) );
		}
		$this->flush_option_cache( $name );
	}

	/**
	 * The serialized fragment that identifies a batch inside the stored option value.
	 *
	 * @param string $batch_id Batch id.
	 * @return string
	 */
	private function id_marker( $batch_id ) {
		return serialize( 'id' ) . serialize( $batch_id );
	}

	/**
	 * Claim the per-user step lock.
	 *
	 * A plain INSERT into the options table: the unique key on option_name makes exactly one
	 * of two competing requests succeed. The value carries an ownership token and a
	 * timestamp; an abandoned lock is taken over with a compare-and-swap on its exact current
	 * value, so two takeovers cannot both succeed either.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		global $wpdb;

		$name             = $this->lock_name();
		$this->lock_token = wp_generate_uuid4();
		$value            = $this->lock_token . ':' . time();

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		$wpdb->suppress_errors( $suppress );
		$this->flush_option_cache( $name );

		if ( $inserted ) {
			return true;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! $current ) {
			return false;
		}

		$locked_at = (int) substr( strrchr( $current, ':' ), 1 );
		if ( time() - $locked_at <= $this->lock_ttl() ) {
			return false;
		}

		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) );
		$this->flush_option_cache( $name );

		return (bool) $taken;
	}

	/**
	 * Renew the step lock before each SuperFaktúra HTTP call (pre_http_request, priority 1),
	 * and refuse the call if the lock has been taken over.
	 *
	 * Every call is bounded by the HTTP timeout, which is far shorter than the lease, so a
	 * request that keeps talking to the API keeps its lock. The renewal is a compare-and-swap
	 * on the owner token. If ownership is gone, another request is already working on this
	 * batch, so this one must not send any further mutations (item deletions, edits): the
	 * call is short-circuited with an error, which the API client reports as a failed request.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value, passed through unchanged.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function renew_lock_on_api_call( $preempt, $args, $url ) {
		global $wpdb;

		if ( '' === $this->lock_token || false === strpos( (string) $url, 'superfaktura' ) ) {
			return $preempt;
		}

		if ( ! $this->lock_lost ) {
			$renewed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $this->lock_token . ':' . time(), $this->lock_name(), $wpdb->esc_like( $this->lock_token ) . ':%' ) );

			// A renewal within the same second changes nothing and reports zero rows, so zero
			// alone does not mean the lock is gone — only a failed ownership check does.
			if ( ! $renewed && ! $this->owns_lock() ) {
				$this->lock_lost = true;
			}
		}

		return $this->abort_api_call_if_lock_lost( $preempt, $args, $url );
	}

	/**
	 * Refuse a SuperFaktúra HTTP call once the lock is lost (pre_http_request, last priority).
	 *
	 * Runs after every other pre_http_request filter so that no later filter can turn the
	 * refusal back into a real request.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function abort_api_call_if_lock_lost( $preempt, $args, $url ) {
		if ( $this->lock_lost && false !== strpos( (string) $url, 'superfaktura' ) ) {
			return new WP_Error( 'sf_bulk_lock_lost', 'Bulk step lock was taken over by another request; API call aborted.' );
		}

		return $preempt;
	}

	/**
	 * Whether this request still holds the step lock (it may have been taken over).
	 *
	 * @return bool
	 */
	private function owns_lock() {
		global $wpdb;

		if ( '' === $this->lock_token ) {
			return false;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->lock_name() ) );

		return is_string( $current ) && 0 === strpos( $current, $this->lock_token . ':' );
	}

	/**
	 * Lease length of the step lock in seconds.
	 *
	 * @return int
	 */
	private function lock_ttl() {
		/**
		 * Filters how long a bulk step lock may go without renewal before another request
		 * may take it over.
		 *
		 * @param int $ttl Seconds.
		 */
		return (int) apply_filters( 'sf_bulk_lock_ttl', self::LOCK_TTL );
	}

	/**
	 * Release the per-user step lock — only if this request still owns it.
	 */
	private function release_lock() {
		global $wpdb;

		if ( '' === $this->lock_token ) {
			return;
		}

		$name = $this->lock_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $name, $wpdb->esc_like( $this->lock_token ) . ':%' ) );
		$this->flush_option_cache( $name );
		$this->lock_token = '';
	}

	/**
	 * Option name of the current user's step lock.
	 *
	 * @return string
	 */
	private function lock_name() {
		return 'wc_sf_bulk_lock_' . get_current_user_id();
	}

	/**
	 * Drop WordPress' option cache entries after writing the options table directly.
	 *
	 * @param string $name Option name.
	 */
	private function flush_option_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Data exposed to the script.
	 *
	 * @param array $batch Batch.
	 * @return array
	 */
	private function batch_summary( $batch ) {
		return array(
			'id'            => $batch['id'],
			'type'          => $batch['type'],
			'state'         => $batch['state'],
			'pause_reason'  => isset( $batch['pause_reason'] ) ? $batch['pause_reason'] : '',
			'autostart'     => ! empty( $batch['autostart'] ),
			'total'         => (int) $batch['total'],
			'processed'     => (int) $batch['processed'],
			'version'       => (int) $batch['version'],
			'counts'        => $batch['counts'],
			'failed_orders' => array_map( array( $this, 'failed_order_link' ), $batch['failed_orders'] ),
		);
	}

	/**
	 * Number and edit URL of a failed order for the notice.
	 *
	 * @param array $failed Stored as array( 'id' => int, 'number' => string ).
	 * @return array
	 */
	private function failed_order_link( $failed ) {
		if ( ! is_array( $failed ) ) {
			return array( 'number' => (string) $failed, 'url' => '' );
		}

		$order = isset( $failed['id'] ) ? wc_get_order( (int) $failed['id'] ) : false;

		return array(
			'number' => isset( $failed['number'] ) ? (string) $failed['number'] : '',
			'url'    => $order instanceof WC_Order ? $order->get_edit_order_url() : '',
		);
	}
}
