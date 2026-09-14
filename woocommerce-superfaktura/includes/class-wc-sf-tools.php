<?php
/**
 * Settings export, import and reset.
 *
 * @package SuperFaktura WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_SF_Tools class.
 *
 * Adds the "Tools" section to the plugin settings with export, import and reset of all plugin options.
 * All three actions are submitted through the WooCommerce settings form, so WooCommerce's capability
 * check (manage_woocommerce) and nonce verification apply before this class does anything.
 */
class WC_SF_Tools {

	/**
	 * Prefix shared by all plugin options.
	 */
	const OPTION_PREFIX = 'woocommerce_sf_';

	/**
	 * Export file format identifier.
	 */
	const FORMAT = 'woocommerce-superfaktura-settings';

	/**
	 * Maximum accepted import file size in bytes.
	 */
	const MAX_IMPORT_SIZE = 1048576;

	/**
	 * Options that hold runtime state rather than settings. Never exported, never imported, not touched by reset.
	 *
	 * @var string[]
	 */
	private $runtime_options = array(
		'woocommerce_sf_api_usage',
		'woocommerce_sf_admin_notices',
	);

	/**
	 * Live document counters used by custom numbering. Exported for reference, but never overwritten on import
	 * when a value already exists and never deleted by reset, so restoring a backup cannot reuse document numbers.
	 *
	 * @var string[]
	 */
	private $counter_options = array(
		'woocommerce_sf_invoice_regular_count',
		'woocommerce_sf_invoice_proforma_count',
		'woocommerce_sf_invoice_cancel_count',
	);

	/**
	 * Options whose stored value is an array. Every other option holds a string.
	 *
	 * @var string[]
	 */
	private $array_options = array(
		'woocommerce_sf_invoice_set_as_paid_statuses',
	);

	/**
	 * Plugin options stored without the common prefix. Exported, imported and reset like the rest.
	 *
	 * @var string[]
	 */
	private $extra_options = array(
		'wc_sf_order_number_notice_hidden',
	);

	/**
	 * Account-specific options. Exported only on request, so a settings file can be moved to another site safely.
	 *
	 * @var string[]
	 */
	private $credential_options = array(
		'woocommerce_sf_email',
		'woocommerce_sf_apikey',
		'woocommerce_sf_company_id',
		'woocommerce_sf_sync_secret_key',
	);

	/**
	 * Options that reference objects in the SuperFaktúra account (number sequences, bank account, cash registers,
	 * logo, per-country overrides). Left out on request, so a file can be used with a different account.
	 *
	 * @var string[]
	 */
	private $account_options = array(
		'woocommerce_sf_invoice_sequence_id',
		'woocommerce_sf_proforma_invoice_sequence_id',
		'woocommerce_sf_cancel_sequence_id',
		'woocommerce_sf_bank_account_id',
		'woocommerce_sf_logo_id',
		'woocommerce_sf_country_settings',
	);

	/**
	 * Prefix of the per-gateway cash register options, which also reference the SuperFaktúra account.
	 */
	const CASH_REGISTER_PREFIX = 'woocommerce_sf_cash_register_';

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

		add_action( 'woocommerce_admin_field_wc_sf_tools', array( $this, 'output_field' ) );
	}

	/**
	 * Settings definition for the Tools section.
	 *
	 * @return array
	 */
	public function get_settings() {
		return array(
			array(
				'title' => __( 'Export and import settings', 'woocommerce-superfaktura' ),
				'type'  => 'title',
				'desc'  => __( 'Transfer the plugin settings to another site, keep a backup before making changes, or start over with default settings.', 'woocommerce-superfaktura' ),
				'id'    => 'woocommerce_sf_tools_title',
			),
			array(
				'type' => 'wc_sf_tools',
				'id'   => 'woocommerce_sf_tools',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'woocommerce_sf_tools_title',
			),
		);
	}

	/**
	 * Render the notice for a finished import or reset.
	 *
	 * @return string HTML or empty string.
	 */
	public function get_result_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, no state change.
		$result = isset( $_GET['wc_sf_tools'] ) ? sanitize_key( wp_unslash( $_GET['wc_sf_tools'] ) ) : '';
		$count  = isset( $_GET['count'] ) ? absint( wp_unslash( $_GET['count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $result ) {
			case 'imported':
				/* translators: %d: number of imported settings. */
				return '<div class="sf-notice-info">' . sprintf( esc_html( _n( '%d setting was imported.', '%d settings were imported.', $count, 'woocommerce-superfaktura' ) ), $count ) . '</div>';

			case 'reset':
				return '<div class="sf-notice-info">' . esc_html__( 'All plugin settings were reset to their defaults.', 'woocommerce-superfaktura' ) . '</div>';

			case 'error':
				$messages = array(
					'no_file'  => __( 'No file was uploaded.', 'woocommerce-superfaktura' ),
					'too_big'  => __( 'The file is too large.', 'woocommerce-superfaktura' ),
					'invalid'  => __( 'The file is not a SuperFaktúra WooCommerce settings export.', 'woocommerce-superfaktura' ),
					'empty'    => __( 'The file contains no settings that could be imported.', 'woocommerce-superfaktura' ),
				);
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( $_GET['code'] ) ) : '';
				$text = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'The import failed.', 'woocommerce-superfaktura' );
				return '<div class="sf-notice-error">' . esc_html( $text ) . '</div>';
		}

		return '';
	}

	/**
	 * Output the export, import and reset controls inside the WooCommerce settings form.
	 */
	public function output_field() {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Export', 'woocommerce-superfaktura' ); ?></th>
			<td class="forminp">
				<p>
					<label for="wc_sf_export_credentials">
						<input type="checkbox" name="wc_sf_export_credentials" id="wc_sf_export_credentials" value="1">
						<?php esc_html_e( 'Include API credentials (e-mail, API key, company ID and secret key)', 'woocommerce-superfaktura' ); ?>
					</label>
				</p>
				<p>
					<label for="wc_sf_export_account">
						<input type="checkbox" name="wc_sf_export_account" id="wc_sf_export_account" value="1" checked="checked">
						<?php esc_html_e( 'Include references to the SuperFaktúra account (number sequences, bank account, cash registers, logo, country overrides)', 'woocommerce-superfaktura' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" name="save" value="wc_sf_export" class="button wc-sf-tools-action"><?php esc_html_e( 'Export settings', 'woocommerce-superfaktura' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Downloads a JSON file with all plugin settings. Leave the credentials out when the file is meant for a different site, and leave the account references out when it is meant for a different SuperFaktúra account, because their IDs exist only in the account they were created in.', 'woocommerce-superfaktura' ); ?></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Import', 'woocommerce-superfaktura' ); ?></th>
			<td class="forminp">
				<p>
					<input type="file" name="wc_sf_import_file" id="wc_sf_import_file" accept=".json,application/json">
				</p>
				<p>
					<button type="submit" name="save" value="wc_sf_import" class="button wc-sf-tools-action" data-confirm="<?php esc_attr_e( 'Import the settings from the selected file? Settings present in the file will be overwritten.', 'woocommerce-superfaktura' ); ?>"><?php esc_html_e( 'Import settings', 'woocommerce-superfaktura' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Overwrites the current settings with the values from the file. Settings that are not in the file stay unchanged. Export the current settings first if you may need them again. Settings that refer to payment gateways, shipping methods or order statuses only take effect when the same ones exist on this site, so review the Invoice Creation, Payment and Shipping sections after importing from a different site. Document counters used for custom numbering are never overwritten.', 'woocommerce-superfaktura' ); ?></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Reset', 'woocommerce-superfaktura' ); ?></th>
			<td class="forminp">
				<p>
					<button type="submit" name="save" value="wc_sf_reset" class="button wc-sf-tools-action" data-confirm="<?php esc_attr_e( 'Reset all plugin settings, including the API credentials? This cannot be undone.', 'woocommerce-superfaktura' ); ?>"><?php esc_html_e( 'Reset all settings', 'woocommerce-superfaktura' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Deletes all plugin settings including the API credentials, so the plugin behaves like a fresh installation. Useful after copying a site. Documents already created and the API log are not affected.', 'woocommerce-superfaktura' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle the submitted action. Called from the settings save hook, before any output is sent.
	 */
	public function handle_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by WC_Admin_Settings::save() before this hook runs.
		$action = isset( $_POST['save'] ) ? sanitize_key( wp_unslash( $_POST['save'] ) ) : '';

		switch ( $action ) {
			case 'wc_sf_export':
				$this->export( ! empty( $_POST['wc_sf_export_credentials'] ), ! empty( $_POST['wc_sf_export_account'] ) );
				break;

			case 'wc_sf_import':
				$this->import();
				break;

			case 'wc_sf_reset':
				$this->reset();
				break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Names of all stored plugin options, without runtime state.
	 *
	 * @return string[]
	 */
	private function get_option_names() {
		global $wpdb;

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		$names = array_diff( (array) $names, $this->runtime_options );

		foreach ( $this->extra_options as $name ) {
			if ( false !== get_option( $name, false ) ) {
				$names[] = $name;
			}
		}

		return array_values( $names );
	}

	/**
	 * Collect the settings to export.
	 *
	 * @param bool $include_credentials Whether to include the API credentials.
	 * @param bool $include_account     Whether to include references to SuperFaktúra account objects.
	 * @return array Option name => value.
	 */
	private function collect_options( $include_credentials, $include_account ) {
		$options = array();
		foreach ( $this->get_option_names() as $name ) {
			if ( ! $include_credentials && in_array( $name, $this->credential_options, true ) ) {
				continue;
			}
			if ( ! $include_account && ( in_array( $name, $this->account_options, true ) || 0 === strpos( $name, self::CASH_REGISTER_PREFIX ) ) ) {
				continue;
			}
			$options[ $name ] = get_option( $name );
		}

		return $options;
	}

	/**
	 * Send the settings as a JSON download and stop.
	 *
	 * @param bool $include_credentials Whether to include the API credentials.
	 * @param bool $include_account     Whether to include references to SuperFaktúra account objects.
	 */
	private function export( $include_credentials, $include_account ) {
		$options = $this->collect_options( $include_credentials, $include_account );

		$data = array(
			'format'   => self::FORMAT,
			'version'  => $this->wc_sf->version,
			'site'     => home_url(),
			'exported' => gmdate( 'c' ),
			'options'  => $options,
		);

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = sprintf( 'superfaktura-settings-%s-%s.json', sanitize_file_name( $host ? $host : 'site' ), gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Import settings from the uploaded file and redirect back with the result.
	 */
	private function import() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['wc_sf_import_file'] ) || ! is_array( $_FILES['wc_sf_import_file'] ) ) {
			$this->redirect( 'error', array( 'code' => 'no_file' ) );
		}

		$file = array_map( 'wp_unslash', $_FILES['wc_sf_import_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] || empty( $file['tmp_name'] ) ) {
			$this->redirect( 'error', array( 'code' => 'no_file' ) );
		}
		if ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] || $file['size'] > self::MAX_IMPORT_SIZE ) {
			$this->redirect( 'error', array( 'code' => 'too_big' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->redirect( 'error', array( 'code' => 'no_file' ) );
		}

		$data = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || ! isset( $data['format'], $data['options'] ) || self::FORMAT !== $data['format'] || ! is_array( $data['options'] ) ) {
			$this->redirect( 'error', array( 'code' => 'invalid' ) );
		}

		$imported = $this->import_options( $data['options'] );

		if ( 0 === $imported ) {
			$this->redirect( 'error', array( 'code' => 'empty' ) );
		}

		$this->redirect( 'imported', array( 'count' => $imported ) );
	}

	/**
	 * Write the importable options from a decoded export file.
	 *
	 * @param array $options Option name => value.
	 * @return int Number of options written.
	 */
	private function import_options( $options ) {
		$imported = 0;
		foreach ( $options as $name => $value ) {
			if ( ! $this->is_importable_option( $name ) ) {
				continue;
			}
			// Keep the live counter of a site that already numbers documents; only a site without one takes the value.
			if ( in_array( $name, $this->counter_options, true ) && false !== get_option( $name, false ) ) {
				continue;
			}
			$value = $this->sanitize_value( $name, $value );
			if ( null === $value ) {
				continue;
			}
			update_option( $name, $value );
			$imported++;
		}

		return $imported;
	}

	/**
	 * Delete all plugin options and redirect back with the result.
	 */
	private function reset() {
		$count = 0;
		foreach ( $this->get_option_names() as $name ) {
			if ( in_array( $name, $this->counter_options, true ) ) {
				continue;
			}
			if ( delete_option( $name ) ) {
				$count++;
			}
		}

		$this->redirect( 'reset', array( 'count' => $count ) );
	}

	/**
	 * Whether an option name from an import file may be written.
	 *
	 * @param mixed $name Option name.
	 * @return bool
	 */
	private function is_importable_option( $name ) {
		if ( ! is_string( $name ) || strlen( $name ) > 191 ) {
			return false;
		}
		if ( in_array( $name, $this->extra_options, true ) ) {
			return true;
		}
		if ( 0 !== strpos( $name, self::OPTION_PREFIX ) || ! preg_match( '/^[A-Za-z0-9_:\-]+$/', $name ) ) {
			return false;
		}

		return ! in_array( $name, $this->runtime_options, true );
	}

	/**
	 * Validate and sanitize an imported option value against the type the option is stored with.
	 *
	 * Options hold strings, except the few that hold a list of strings. Strings are kept as they are, so
	 * values like "R&D" or URLs survive a round trip; only strings containing markup are filtered the way
	 * the settings page filters them. Anything of another type is rejected.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value from the import file.
	 * @return string|array|null Sanitized value or null when rejected.
	 */
	private function sanitize_value( $name, $value ) {
		if ( 'woocommerce_sf_country_settings' === $name ) {
			return $this->sanitize_country_settings( $value );
		}

		if ( in_array( $name, $this->array_options, true ) ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			$clean = array();
			foreach ( $value as $item ) {
				$item = $this->sanitize_string( $item );
				if ( null !== $item ) {
					$clean[] = $item;
				}
			}
			return array_values( array_unique( $clean ) );
		}

		return $this->sanitize_string( $value );
	}

	/**
	 * Validate the per-country override table, stored as a JSON string of rows built by the settings page.
	 *
	 * Every row must be an object with the country code and the string fields the settings page writes;
	 * the checkbox field is normalized to a boolean. Anything else is rejected so the invoice generator and
	 * the settings page never see a malformed table.
	 *
	 * @param mixed $value Value from the import file.
	 * @return string|null JSON string of normalized rows, or null when rejected.
	 */
	private function sanitize_country_settings( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( '' === $value ) {
			return '';
		}

		// The table is a JSON list; "{}" decodes to the same empty array as "[]", so check the text itself.
		$rows = json_decode( $value, true );
		if ( ! is_array( $rows ) || '[' !== substr( ltrim( $value ), 0, 1 ) || array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) && array() !== $rows ) {
			return null;
		}

		$string_fields = array( 'vat_id', 'tax_id', 'bank_account_id', 'proforma_sequence_id', 'invoice_sequence_id', 'cancel_sequence_id' );
		$clean         = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['country'] ) || ! is_string( $row['country'] ) ) {
				return null;
			}
			$country = strtoupper( trim( $row['country'] ) );
			if ( '*' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				return null;
			}

			// Same key order as the settings page writes, so an unchanged table survives export and import byte for byte.
			$clean_row = array(
				'country'                    => $country,
				'vat_id'                     => '',
				'vat_id_only_final_consumer' => ! empty( $row['vat_id_only_final_consumer'] ) && 'false' !== $row['vat_id_only_final_consumer'],
			);
			foreach ( $string_fields as $field ) {
				$field_value = isset( $row[ $field ] ) ? $row[ $field ] : '';
				if ( is_int( $field_value ) || is_float( $field_value ) ) {
					$field_value = (string) $field_value;
				}
				if ( ! is_string( $field_value ) ) {
					return null;
				}
				$clean_row[ $field ] = sanitize_text_field( $field_value );
			}
			$clean[] = $clean_row;
		}

		return wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Sanitize a scalar option value into the string WordPress stores for it.
	 *
	 * @param mixed $value Value from the import file.
	 * @return string|null Sanitized string or null when the value is not a scalar.
	 */
	private function sanitize_string( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = str_replace( "\0", '', wp_check_invalid_utf8( $value ) );

		// Only values that contain markup get the HTML filter; everything else is stored verbatim.
		if ( false !== strpos( $value, '<' ) ) {
			$value = wp_kses_post( $value );
		}

		return $value;
	}

	/**
	 * Redirect back to the Tools section with a result flag and stop.
	 *
	 * @param string $result Result flag.
	 * @param array  $args   Extra query arguments.
	 */
	private function redirect( $result, $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'        => 'wc-settings',
					'tab'         => 'superfaktura',
					'section'     => 'tools',
					'wc_sf_tools' => $result,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
