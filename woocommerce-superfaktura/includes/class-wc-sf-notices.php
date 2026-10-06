<?php
/**
 * SuperFaktúra WooCommerce admin notices: eFaktúra announcement and failed document warnings.
 *
 * @package   SuperFaktúra WooCommerce
 * @author    2day.sk <superfaktura@2day.sk>
 * @copyright 2022 2day.sk s.r.o., Webikon s.r.o.
 * @license   GPL-2.0+
 * @link      https://www.superfaktura.sk/integracia/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WC_SF_Notices.
 *
 * Shows the eFaktúra announcement (a notice on the Dashboard, Plugins and WooCommerce screens and a banner on the plugin settings tab)
 * and a warning when documents could not be created or updated in SuperFaktúra. Each notice can be
 * closed per user; the failed document warning comes back when a newer failure appears.
 *
 * @package SuperFaktúra WooCommerce
 * @author  2day.sk <superfaktura@2day.sk>
 */
class WC_SF_Notices {

	/**
	 * User meta key holding the closed notices.
	 */
	const USER_META = 'wc_sf_dismissed_notices';

	/**
	 * Transient caching the unresolved document failures.
	 */
	const ERRORS_TRANSIENT = 'wc_sf_document_errors';

	/**
	 * The eFaktúra announcement is shown until this date (site time), because its text speaks about the start in the future.
	 */
	const EFAKTURA_UNTIL = '2027-01-01';

	/**
	 * How far back failed documents are reported.
	 */
	const ERRORS_DAYS = 30;

	/**
	 * How many failures are kept for the warning.
	 */
	const ERRORS_LIMIT = 50;

	/**
	 * Instance of WC_SuperFaktura.
	 *
	 * @var WC_SuperFaktura
	 */
	private $wc_sf;

	/**
	 * Failures for the current user, computed once per request.
	 *
	 * @var array|null
	 */
	private $user_errors = null;

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

		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'woocommerce_sections_superfaktura', array( $this, 'render_efaktura_hero' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wc_sf_dismiss_notice', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Forget the cached failures, so a new log entry is reflected right away.
	 */
	public static function flush_errors() {
		delete_transient( self::ERRORS_TRANSIENT );
	}

	/**
	 * Load styles and the script that remembers closed notices, only where a notice is shown.
	 */
	public function enqueue_scripts() {
		$errors = $this->get_user_errors();
		if ( ! $this->should_show_efaktura( 'notice' ) && ! $this->should_show_efaktura( 'hero' ) && ! $errors['errors'] ) {
			return;
		}

		wp_enqueue_style( 'wc-sf-admin-notices', plugins_url( 'assets/css/admin-notices.css', WC_SF_FILE_PATH ), array(), $this->wc_sf->version );
		wp_enqueue_script( 'wc-sf-admin-notices', plugins_url( 'assets/js/admin-notices.js', WC_SF_FILE_PATH ), array( 'jquery' ), $this->wc_sf->version, true );
		wp_localize_script(
			'wc-sf-admin-notices',
			'wc_sf_notices',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wc_sf_notices' ),
			)
		);
	}

	/**
	 * Print the plugin's admin notices.
	 */
	public function render_notices() {
		$this->render_document_errors();

		if ( $this->should_show_efaktura( 'notice' ) ) {
			$this->render_efaktura_notice();
		}
	}

	/**
	 * AJAX: remember that the current user closed a notice.
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'wc_sf_notices', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$notice = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : '';
		if ( ! in_array( $notice, array( 'efaktura_notice', 'efaktura_hero', 'document_errors' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown notice' ), 400 );
		}

		$dismissed = $this->get_dismissed();

		if ( 'document_errors' === $notice ) {
			// Remember the newest failure the user has seen, so only newer ones bring the warning back.
			$value  = isset( $_POST['value'] ) ? absint( wp_unslash( $_POST['value'] ) ) : 0;
			$stored = isset( $dismissed[ $notice ] ) ? (int) $dismissed[ $notice ] : 0;
			$data   = $this->get_errors();
			if ( $stored > $data['max_id'] ) {
				// The log numbering started over, the stored id no longer applies.
				$stored = 0;
			}
			$dismissed[ $notice ] = max( $value, $stored );
		} else {
			$dismissed[ $notice ] = time();
		}

		// Stored per site, because the failure ids come from each site's own log table.
		update_user_option( get_current_user_id(), self::USER_META, $dismissed );

		wp_send_json_success();
	}

	/**
	 * Notices the current user closed.
	 *
	 * @return array
	 */
	private function get_dismissed() {
		$dismissed = get_user_option( self::USER_META, get_current_user_id() );

		return is_array( $dismissed ) ? $dismissed : array();
	}

	/**
	 * Is the current admin screen the SuperFaktúra settings tab?
	 *
	 * @return bool
	 */
	private function is_settings_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'superfaktura' === $_GET['tab'];
	}

	/**
	 * Is the current admin screen one where the eFaktúra notice may appear (Dashboard, Plugins, WooCommerce screens)?
	 *
	 * @return bool
	 */
	private function is_notice_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		$screen_ids = array( 'dashboard', 'plugins' );
		if ( function_exists( 'wc_get_screen_ids' ) ) {
			$screen_ids = array_merge( $screen_ids, wc_get_screen_ids() );
		}

		return in_array( $screen->id, $screen_ids, true );
	}

	/**
	 * Should the eFaktúra announcement be shown to the current user?
	 *
	 * @param string $variant 'notice' (Dashboard, Plugins and WooCommerce screens) or 'hero' (on the plugin settings tab).
	 * @return bool
	 */
	private function should_show_efaktura( $variant ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return false;
		}

		$show = true;

		// eFaktúra is Slovak legislation, so the announcement is only for Slovak SuperFaktúra accounts.
		if ( 'sk' !== get_option( 'woocommerce_sf_lang', 'sk' ) ) {
			$show = false;
		}

		if ( current_time( 'Y-m-d' ) >= self::EFAKTURA_UNTIL ) {
			$show = false;
		}

		$dismissed = $this->get_dismissed();
		if ( ! empty( $dismissed[ 'efaktura_' . $variant ] ) ) {
			$show = false;
		}

		// The banner on the settings tab takes the place of the notice there.
		if ( 'notice' === $variant && $this->is_settings_tab() ) {
			$show = false;
		}

		// Elsewhere the notice appears only on the Dashboard, the Plugins screen and WooCommerce screens.
		if ( 'notice' === $variant && ! $this->is_notice_screen() ) {
			$show = false;
		}
		if ( 'hero' === $variant && ! $this->is_settings_tab() ) {
			$show = false;
		}

		// Keep the API log free for troubleshooting.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'hero' === $variant && isset( $_GET['section'] ) && 'api_log' === $_GET['section'] ) {
			$show = false;
		}

		/**
		 * Filter whether the eFaktúra announcement is shown.
		 *
		 * @since 1.56.0
		 *
		 * @param bool   $show    Whether to show it.
		 * @param string $variant 'notice' (Dashboard, Plugins and WooCommerce screens) or 'hero' (on the plugin settings tab).
		 */
		return (bool) apply_filters( 'sf_show_efaktura_banner', $show, $variant );
	}

	/**
	 * URL of the eFaktúra badge image.
	 *
	 * @return string
	 */
	private function badge_url() {
		return add_query_arg( 'ver', $this->wc_sf->version, plugins_url( 'assets/images/efaktura-badge.png', WC_SF_FILE_PATH ) );
	}

	/**
	 * Print the eFaktúra notice for the Dashboard, Plugins and WooCommerce screens.
	 *
	 * The texts are in Slovak on purpose: the announcement is shown only for Slovak SuperFaktúra accounts,
	 * and translations from WordPress.org language packs would arrive later than the update itself.
	 */
	private function render_efaktura_notice() {
		?>
		<div class="notice notice-info is-dismissible wc-sf-efaktura-notice" data-wc-sf-notice="efaktura_notice">
			<img class="wc-sf-efaktura-badge" src="<?php echo esc_url( $this->badge_url() ); ?>" width="130" height="93" alt="U nás má eFaktúra zelenú!">
			<div class="wc-sf-efaktura-notice__body">
				<p class="wc-sf-efaktura-eyebrow">Nová legislatívna povinnosť od 1. 1. 2027</p>
				<p class="wc-sf-efaktura-notice__title"><strong>So SuperFaktúrou sú e-shopy na eFaktúru pripravené.</strong></p>
				<p>Od 1. 1. 2027 budú platitelia DPH pri fakturácii firmám a verejnej správe povinne vystavovať a prijímať eFaktúry. So SuperFaktúrou máte na túto zmenu pripravené riešenie – od vystavenia cez odoslanie cez Peppol až po prijatie eFaktúry.</p>
				<p>Zatiaľ stačí si <a href="https://www.superfaktura.sk/blog/superfaktura-zabezpecuje-dorucovanie-efaktur/" target="_blank" rel="noopener">vybrať SuperFaktúru ako svojho digitálneho poštára<span class="screen-reader-text"> (otvorí sa v novej karte)</span></a>, o ďalších krokoch Vás budeme informovať <span aria-hidden="true">☺️</span></p>
				<p class="wc-sf-efaktura-actions">
					<a class="button button-primary" href="https://vpds.financnasprava.sk/vyber-certifikovaneho-poskytovatela?id=100" target="_blank" rel="noopener">Aktivovať SuperFaktúru ako digitálneho poštára<span class="screen-reader-text"> (otvorí sa v novej karte)</span></a>
					<a href="https://www.superfaktura.sk/efaktura/" target="_blank" rel="noopener">Čo je eFaktúra?<span class="screen-reader-text"> (otvorí sa v novej karte)</span></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the eFaktúra banner under the section links of the plugin settings tab.
	 */
	public function render_efaktura_hero() {
		if ( ! $this->should_show_efaktura( 'hero' ) ) {
			return;
		}
		?>
		<div class="wc-sf-efaktura-hero" data-wc-sf-notice="efaktura_hero">
			<div class="wc-sf-efaktura-hero__body">
				<p class="wc-sf-efaktura-hero__eyebrow">Nová legislatívna povinnosť od 1. 1. 2027</p>
				<h2 class="wc-sf-efaktura-hero__title">So SuperFaktúrou sú e-shopy na eFaktúru pripravené.</h2>
				<p>Od 1. 1. 2027 budú platitelia DPH pri fakturácii firmám a verejnej správe povinne vystavovať a prijímať eFaktúry. <strong>So SuperFaktúrou máte na túto zmenu pripravené riešenie – eFaktúru u nás vystavíte, odošlete cez sieť Peppol aj prijmete. Všetko priamo v prostredí SuperFaktúry, ktoré už poznáte a používate.</strong></p>
				<p>Zatiaľ stačí jediné – <a href="https://www.superfaktura.sk/blog/superfaktura-zabezpecuje-dorucovanie-efaktur/" target="_blank" rel="noopener">vybrať si SuperFaktúru ako svojho digitálneho poštára<span class="screen-reader-text"> (otvorí sa v novej karte)</span></a>. O ďalších krokoch Vás budeme priebežne informovať <span aria-hidden="true">☺️</span></p>
				<p class="wc-sf-efaktura-hero__actions">
					<a class="wc-sf-efaktura-hero__cta" href="https://vpds.financnasprava.sk/vyber-certifikovaneho-poskytovatela?id=100" target="_blank" rel="noopener">Aktivovať SuperFaktúru ako digitálneho poštára<span class="screen-reader-text"> (otvorí sa v novej karte)</span></a>
					<a class="wc-sf-efaktura-hero__link" href="https://www.superfaktura.sk/efaktura/" target="_blank" rel="noopener">Čo je eFaktúra?<span class="screen-reader-text"> (otvorí sa v novej karte)</span></a>
				</p>
			</div>
			<img class="wc-sf-efaktura-hero__badge" src="<?php echo esc_url( $this->badge_url() ); ?>" width="260" height="186" alt="U nás má eFaktúra zelenú!">
			<button type="button" class="wc-sf-efaktura-hero__close" data-wc-sf-dismiss aria-label="Skryť banner">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Unresolved document failures from the last days, newest first.
	 *
	 * A failure counts until the same document of the same order is created or updated successfully later,
	 * or the document is attached to the order in another way. Failures of orders that are deleted, trashed,
	 * cancelled or failed are left out, and so is a failed proforma invoice when the invoice already exists.
	 *
	 * @return array With 'errors' (rows with id, order_id, document_type, request_type, response_status,
	 *               response_message and time), 'more' (whether more failures exist than kept) and 'max_id'
	 *               (the newest log entry id).
	 */
	private function get_errors() {
		$cached = get_transient( self::ERRORS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['errors'], $cached['max_id'], $cached['more'] ) ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'wc_sf_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::ERRORS_DAYS * DAY_IN_SECONDS );

		// A site of a network may not have the log table yet; do not print database errors on admin pages.
		$suppress = $wpdb->suppress_errors( true );

		// Look only at the newest entries, so a large log does not slow down the admin.
		$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, document_type, request_type, response_status, response_message, time FROM {$table} WHERE id > %d AND id <= %d AND time >= %s AND request_type IN ('create', 'edit') ORDER BY id DESC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 0, $max_id - 20000 ),
				$max_id,
				$cutoff
			),
			ARRAY_A
		);

		$wpdb->suppress_errors( $suppress );

		$errors = array();
		$more   = false;
		$seen   = array();
		foreach ( (array) $rows as $row ) {
			$key = $row['order_id'] . '|' . $row['document_type'];
			if ( isset( $seen[ $key ] ) ) {
				// A newer entry for this document already decided it.
				continue;
			}
			$seen[ $key ] = true;

			if ( empty( $row['response_status'] ) ) {
				// The newest attempt succeeded.
				continue;
			}

			$order = $row['order_id'] ? wc_get_order( $row['order_id'] ) : false;
			if ( ! $order || in_array( $order->get_status(), array( 'trash', 'cancelled', 'failed' ), true ) ) {
				continue;
			}

			// A retry or the related invoice from SuperFaktúra can attach the document without a log entry.
			if ( 'create' === $row['request_type'] && $order->get_meta( 'wc_sf_internal_' . $row['document_type'] . '_id', true ) ) {
				continue;
			}

			// The proforma invoice is no longer needed once the invoice exists.
			if ( 'proforma' === $row['document_type'] && $order->get_meta( 'wc_sf_internal_regular_id', true ) ) {
				continue;
			}

			if ( count( $errors ) >= self::ERRORS_LIMIT ) {
				$more = true;
				break;
			}

			$errors[] = $row;
		}

		$cached = array(
			'errors' => $errors,
			'more'   => $more,
			'max_id' => $max_id,
		);
		set_transient( self::ERRORS_TRANSIENT, $cached, HOUR_IN_SECONDS );

		return $cached;
	}

	/**
	 * Unresolved failures the current user has not closed yet.
	 *
	 * @return array With 'errors' (newest first) and 'more' (whether more failures exist than listed).
	 */
	private function get_user_errors() {
		if ( null !== $this->user_errors ) {
			return $this->user_errors;
		}

		$this->user_errors = array(
			'errors' => array(),
			'more'   => false,
		);

		if ( ! current_user_can( 'manage_woocommerce' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $this->user_errors;
		}

		$dismissed = $this->get_dismissed();
		$seen      = isset( $dismissed['document_errors'] ) ? (int) $dismissed['document_errors'] : 0;
		$data      = $this->get_errors();

		// The log numbering started over (e.g. the table was emptied), so the stored id no longer applies.
		if ( $seen > $data['max_id'] ) {
			$seen = 0;
		}

		$errors = array_values(
			array_filter(
				$data['errors'],
				function ( $row ) use ( $seen ) {
					return (int) $row['id'] > $seen;
				}
			)
		);

		// Failures beyond the kept ones are older than all kept ones, so they matter only if the oldest kept one is new to the user.
		$last = end( $data['errors'] );
		$more = $data['more'] && $last && (int) $last['id'] > $seen;

		/**
		 * Filter the failed documents reported in the admin.
		 *
		 * @since 1.56.0
		 *
		 * @param array $errors Unresolved failures, newest first, each with id, order_id, document_type, request_type,
		 *                      response_status, response_message and time. Return an empty array to hide the warning.
		 */
		$errors = (array) apply_filters( 'sf_admin_document_errors', $errors );

		// Keep only complete rows of orders that still exist, whatever a filter returned.
		$errors = array_values(
			array_filter(
				$errors,
				function ( $row ) {
					return is_array( $row ) && isset( $row['id'], $row['order_id'], $row['document_type'], $row['time'] ) && array_key_exists( 'response_message', $row );
				}
			)
		);

		$this->user_errors = array(
			'errors' => $errors,
			'more'   => $more && $errors,
		);

		return $this->user_errors;
	}

	/**
	 * Print the warning about documents that could not be created or updated.
	 */
	private function render_document_errors() {
		$data   = $this->get_user_errors();
		$errors = $data['errors'];
		if ( ! $errors ) {
			return;
		}

		$types = array(
			'regular'  => __( 'Invoice', 'woocommerce-superfaktura' ),
			'proforma' => __( 'Proforma invoice', 'woocommerce-superfaktura' ),
			'cancel'   => __( 'Credit note', 'woocommerce-superfaktura' ),
		);

		$count   = count( $errors );
		$shown   = array_slice( $errors, 0, 3 );
		$newest  = max( array_map( 'intval', wp_list_pluck( $errors, 'id' ) ) );
		$log_url = admin_url( 'admin.php?page=wc-settings&tab=superfaktura&section=api_log' );
		?>
		<div class="notice notice-error is-dismissible wc-sf-document-errors" data-wc-sf-notice="document_errors" data-wc-sf-value="<?php echo esc_attr( $newest ); ?>">
			<p>
				<strong><?php esc_html_e( 'SuperFaktúra', 'woocommerce-superfaktura' ); ?></strong>:
				<?php
				if ( $data['more'] ) {
					// Translators: %d Number of documents.
					echo esc_html( sprintf( __( 'More than %d documents could not be created or updated in SuperFaktúra in the last 30 days.', 'woocommerce-superfaktura' ), $count ) );
				} else {
					// Translators: %d Number of documents.
					echo esc_html( sprintf( _n( '%d document could not be created or updated in SuperFaktúra in the last 30 days.', '%d documents could not be created or updated in SuperFaktúra in the last 30 days.', $count, 'woocommerce-superfaktura' ), $count ) );
				}
				?>
			</p>
			<ul class="wc-sf-document-errors__list">
				<?php
				foreach ( $shown as $row ) :
					$order = wc_get_order( $row['order_id'] );
					if ( ! $order ) {
						continue;
					}
					$type = isset( $types[ $row['document_type'] ] ) ? $types[ $row['document_type'] ] : $row['document_type'];
					?>
					<li>
						<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
							<?php
							// Translators: %s Order number.
							echo esc_html( sprintf( __( 'Order #%s', 'woocommerce-superfaktura' ), $order->get_order_number() ) );
							?>
						</a>
						– <?php echo esc_html( $type ); ?>: <?php echo esc_html( wp_strip_all_tags( (string) $row['response_message'] ) ); ?>
						<span class="wc-sf-document-errors__time">(<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['time'] ) ); ?>)</span>
					</li>
				<?php endforeach; ?>
				<?php if ( $data['more'] ) : ?>
					<li><?php esc_html_e( 'and more', 'woocommerce-superfaktura' ); ?></li>
				<?php elseif ( $count > count( $shown ) ) : ?>
					<li>
						<?php
						$more = $count - count( $shown );
						// Translators: %d Number of further documents.
						echo esc_html( sprintf( _n( 'and %d more', 'and %d more', $more, 'woocommerce-superfaktura' ), $more ) );
						?>
					</li>
				<?php endif; ?>
			</ul>
			<p><a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Open API log', 'woocommerce-superfaktura' ); ?></a></p>
		</div>
		<?php
	}
}
