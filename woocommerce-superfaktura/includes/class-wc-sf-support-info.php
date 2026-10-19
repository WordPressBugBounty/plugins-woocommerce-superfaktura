<?php
/**
 * Support information on the Help section.
 *
 * @package SuperFaktura WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_SF_Support_Info class.
 *
 * Plain text summary of the site, the plugin settings that matter most in support cases and the active plugins,
 * ready to copy into an e-mail. API credentials are never included, only whether they are filled in.
 */
class WC_SF_Support_Info {

	/**
	 * Main plugin class.
	 *
	 * @var WC_SuperFaktura
	 */
	protected $wc_sf;

	/**
	 * Constructor.
	 *
	 * @param WC_SuperFaktura $wc_sf Main plugin class.
	 */
	public function __construct( $wc_sf ) {
		$this->wc_sf = $wc_sf;

		add_action( 'woocommerce_admin_field_wc_sf_support_info', array( $this, 'output_field' ) );
	}

	/**
	 * Lines of the summary.
	 *
	 * @return string[]
	 */
	public function get_lines() {
		$yes    = __( 'yes', 'woocommerce-superfaktura' );
		$no     = __( 'no', 'woocommerce-superfaktura' );
		$filled = __( 'filled in', 'woocommerce-superfaktura' );
		$empty  = __( 'empty', 'woocommerce-superfaktura' );
		$lines  = array();

		/* translators: %s: site address. */
		$lines[] = sprintf( __( 'Site: %s', 'woocommerce-superfaktura' ), home_url() );

		$lines[] = sprintf(
			'SuperFaktúra WooCommerce %s, WordPress %s, WooCommerce %s, PHP %s',
			$this->wc_sf->version,
			get_bloginfo( 'version' ),
			defined( 'WC_VERSION' ) ? WC_VERSION : '',
			PHP_VERSION
		);

		$lines[] = sprintf(
			/* translators: 1: SuperFaktúra version (SK, CZ or AT), 2: yes or no, 3: filled in or empty, 4: filled in or empty. */
			__( 'SuperFaktúra: %1$s, sandbox %2$s, API e-mail and key %3$s, Company ID %4$s', 'woocommerce-superfaktura' ),
			strtoupper( (string) get_option( 'woocommerce_sf_lang', 'sk' ) ),
			'yes' === get_option( 'woocommerce_sf_sandbox', 'no' ) ? $yes : $no,
			get_option( 'woocommerce_sf_email' ) && get_option( 'woocommerce_sf_apikey' ) ? $filled : $empty,
			get_option( 'woocommerce_sf_company_id' ) ? $filled : $empty
		);

		$tax_based_on = array(
			'billing'  => __( 'tax based on billing address', 'woocommerce-superfaktura' ),
			'shipping' => __( 'tax based on shipping address', 'woocommerce-superfaktura' ),
			'base'     => __( 'tax based on store address', 'woocommerce-superfaktura' ),
		);
		$based_on     = (string) get_option( 'woocommerce_tax_based_on', 'shipping' );
		$lines[]      = sprintf(
			/* translators: 1: country code, 2: currency code, 3: what taxes are calculated by, 4: on or off. */
			__( 'Store: country %1$s, currency %2$s, %3$s, OSS %4$s', 'woocommerce-superfaktura' ),
			WC()->countries->get_base_country(),
			get_woocommerce_currency(),
			wc_tax_enabled() ? ( isset( $tax_based_on[ $based_on ] ) ? $tax_based_on[ $based_on ] : $based_on ) : __( 'taxes off', 'woocommerce-superfaktura' ),
			'yes' === get_option( 'woocommerce_sf_oss', 'no' ) ? __( 'on', 'woocommerce-superfaktura' ) : __( 'off', 'woocommerce-superfaktura' )
		);

		$lines[] = sprintf(
			/* translators: 1: yes or no, 2: type of the checkout page. */
			__( 'Orders: HPOS %1$s, %2$s', 'woocommerce-superfaktura' ),
			$this->hpos_enabled() ? $yes : $no,
			$this->checkout_type()
		);

		$statuses = array_map( 'wc_get_order_status_name', $this->wc_sf->get_sync_order_statuses() );
		$lines[]  = sprintf(
			/* translators: 1: whether the Secret Key is filled in, 2: list of order statuses. */
			__( 'Payment pairing: %1$s, statuses %2$s', 'woocommerce-superfaktura' ),
			get_option( 'woocommerce_sf_sync_secret_key' ) ? __( 'Secret Key filled in', 'woocommerce-superfaktura' ) : __( 'Secret Key empty', 'woocommerce-superfaktura' ),
			$statuses ? implode( ', ', $statuses ) : __( 'none', 'woocommerce-superfaktura' )
		);

		$lines[] = $this->errors_line();

		/* translators: %s: list of plugins with versions. */
		$lines[] = sprintf( __( 'Plugins: %s', 'woocommerce-superfaktura' ), $this->plugins() );

		$mu = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			// WordPress uses the file name when the plugin has no name.
			$mu[] = $data['Name'] && $data['Name'] !== $file ? trim( $data['Name'] . ' ' . $data['Version'] ) . ' (' . $file . ')' : $file;
		}
		/* translators: %s: list of must-use plugins. */
		$lines[] = sprintf( __( 'MU plugins: %s', 'woocommerce-superfaktura' ), $mu ? implode( ', ', $mu ) : __( 'none', 'woocommerce-superfaktura' ) );

		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$lines[] = sprintf(
			/* translators: %s: theme name and version. */
			__( 'Theme: %s', 'woocommerce-superfaktura' ),
			trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ) . ( $parent ? ' (' . sprintf(
				/* translators: %s: parent theme name and version. */
				__( 'child theme of %s', 'woocommerce-superfaktura' ),
				trim( $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) )
			) . ')' : '' )
		);

		$lines[] = sprintf(
			/* translators: 1: locale such as sk_SK, 2: time zone. */
			__( 'Site language %1$s, time zone %2$s', 'woocommerce-superfaktura' ),
			get_locale(),
			wp_timezone_string()
		);

		return $lines;
	}

	/**
	 * Output the summary with a copy button inside the WooCommerce settings form.
	 * It spans the whole table, so it sits under its heading like the rest of the Help section.
	 */
	public function output_field() {
		$text = implode( "\n", $this->get_lines() );
		$rows = count( $this->get_lines() ) + 2;
		?>
		<tr valign="top">
			<td colspan="2" style="padding: 0;">
				<h3><label for="wc_sf_support_info"><?php esc_html_e( 'Information for support', 'woocommerce-superfaktura' ); ?></label></h3>
				<textarea id="wc_sf_support_info" readonly rows="<?php echo esc_attr( $rows ); ?>" style="width: 100%; max-width: 60em; font-family: Menlo, Consolas, monospace; font-size: 12px;"><?php echo esc_textarea( $text ); ?></textarea>
				<p>
					<button type="button" class="button" id="wc_sf_support_info_copy"><?php esc_html_e( 'Copy', 'woocommerce-superfaktura' ); ?></button>
					<span id="wc_sf_support_info_status" aria-live="polite" style="margin-left: 0.5em;"></span>
				</p>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL of the Tools section. */
							__( 'It contains no API credentials. When we need the settings in detail, we will ask for an export from <a href="%s">Tools</a>.', 'woocommerce-superfaktura' ),
							esc_url( admin_url( 'admin.php?page=wc-settings&tab=superfaktura&section=tools' ) )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
				<script>
				( function () {
					var button = document.getElementById( 'wc_sf_support_info_copy' );
					var field  = document.getElementById( 'wc_sf_support_info' );
					var status = document.getElementById( 'wc_sf_support_info_status' );
					if ( ! button || ! field ) {
						return;
					}
					// Long lines wrap, so the field grows to show all of the text.
					field.style.height = ( field.scrollHeight + 4 ) + 'px';
					var done = function () {
						status.textContent = <?php echo wp_json_encode( __( 'Copied.', 'woocommerce-superfaktura' ) ); ?>;
					};
					// Older browsers and sites without HTTPS have no clipboard API; selecting the text and copying works there.
					var fallback = function () {
						field.focus();
						field.select();
						if ( document.execCommand( 'copy' ) ) {
							done();
						} else {
							status.textContent = <?php echo wp_json_encode( __( 'Select the text and copy it with Ctrl+C or Cmd+C.', 'woocommerce-superfaktura' ) ); ?>;
						}
					};
					button.addEventListener( 'click', function () {
						if ( navigator.clipboard && window.isSecureContext ) {
							navigator.clipboard.writeText( field.value ).then( done, fallback );
						} else {
							fallback();
						}
					} );
				}() );
				</script>
			</td>
		</tr>
		<?php
	}

	/**
	 * Whether orders are stored in the WooCommerce order tables (HPOS).
	 *
	 * @return bool
	 */
	private function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Type of the checkout page: block, classic (shortcode) or other (for example a page builder).
	 *
	 * @return string
	 */
	private function checkout_type() {
		$page = get_post( wc_get_page_id( 'checkout' ) );
		if ( ! $page ) {
			return __( 'checkout page not set', 'woocommerce-superfaktura' );
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $page ) ) {
			return __( 'block checkout', 'woocommerce-superfaktura' );
		}
		if ( has_shortcode( $page->post_content, 'woocommerce_checkout' ) ) {
			return __( 'classic checkout', 'woocommerce-superfaktura' );
		}
		return __( 'other checkout', 'woocommerce-superfaktura' );
	}

	/**
	 * Documents that could not be created or updated in the last 30 days, with the newest error.
	 *
	 * @return string
	 */
	private function errors_line() {
		$data   = $this->wc_sf->notices->get_document_errors();
		$errors = $data['errors'];
		if ( ! $errors ) {
			/* translators: %d: number of days. */
			return sprintf( __( 'Failed documents in the last %d days: none', 'woocommerce-superfaktura' ), WC_SF_Notices::ERRORS_DAYS );
		}

		$latest  = $errors[0];
		$time    = strtotime( $latest['time'] );
		$message = wp_strip_all_tags( (string) $latest['response_message'] );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $message ) > 160 ) {
			$message = mb_substr( $message, 0, 160 ) . '…';
		}

		return sprintf(
			/* translators: 1: number of days, 2: number of documents, 3: date, 4: order ID, 5: error code, 6: error message. */
			__( 'Failed documents in the last %1$d days: %2$s, newest %3$s, order ID %4$s: %5$s %6$s', 'woocommerce-superfaktura' ),
			WC_SF_Notices::ERRORS_DAYS,
			count( $errors ) . ( $data['more'] ? '+' : '' ),
			$time ? date_i18n( get_option( 'date_format' ), $time ) : $latest['time'],
			$latest['order_id'],
			$latest['response_status'],
			$message
		);
	}

	/**
	 * Active plugins with versions, without this plugin (its version is on its own line).
	 *
	 * @return string
	 */
	private function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$list = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! in_array( $file, $active, true ) || plugin_basename( WC_SF_FILE_PATH ) === $file ) {
				continue;
			}
			$list[] = trim( $data['Name'] . ' ' . $data['Version'] );
		}
		natcasesort( $list );

		return $list ? implode( ', ', $list ) : __( 'none', 'woocommerce-superfaktura' );
	}
}
