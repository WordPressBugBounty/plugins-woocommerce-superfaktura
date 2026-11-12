<?php
/**
 * SuperFaktúra company lists: number sequences, logos and bank accounts.
 *
 * @package WooCommerce SuperFaktúra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Number sequences, logos and bank accounts of a SuperFaktúra company, used by the settings and the setup wizard.
 */
class WC_SF_Company_Lists {

	/**
	 * How long the settings keep the loaded lists, in seconds.
	 */
	const CACHE_TTL = 300;

	/**
	 * How long the settings keep lists when some of them could not be loaded, in seconds.
	 */
	const PARTIAL_CACHE_TTL = 60;

	/**
	 * Lists loaded in this request, by cache key.
	 *
	 * @var array
	 */
	private static $loaded = array();

	/**
	 * Number sequences from the /sequences/index.json response.
	 *
	 * @param mixed $response API response.
	 * @return array Items by document type ('regular', 'proforma', 'cancel'), each with id, label and default.
	 */
	public static function sequences( $response ) {
		$result = array();

		foreach ( array( 'regular', 'proforma', 'cancel' ) as $type ) {
			$result[ $type ] = array();
			foreach ( is_object( $response ) && isset( $response->$type ) ? (array) $response->$type : array() as $sequence ) {
				if ( ! is_object( $sequence ) || ! isset( $sequence->id ) ) {
					continue;
				}
				$label = trim( (string) ( $sequence->name ?? '' ) );
				if ( ! empty( $sequence->sequence_formatted ) ) {
					$label .= ' (' . $sequence->sequence_formatted . ')';
				}
				$result[ $type ][] = array(
					'id'      => (string) $sequence->id,
					'label'   => wp_strip_all_tags( trim( $label ) ),
					'default' => ! empty( $sequence->default ),
				);
			}
		}

		return $result;
	}

	/**
	 * Logos from the /users/logo response.
	 *
	 * @param mixed $response API response.
	 * @return array Items with id, label and default.
	 */
	public static function logos( $response ) {
		$result = array();

		foreach ( is_array( $response ) ? $response : array() as $logo ) {
			if ( ! is_object( $logo ) || ! isset( $logo->id ) ) {
				continue;
			}
			$result[] = array(
				'id'      => (string) $logo->id,
				'label'   => wp_strip_all_tags( (string) ( $logo->basename ?? $logo->id ) ),
				'default' => ! empty( $logo->default ),
			);
		}

		return $result;
	}

	/**
	 * Bank accounts from the /bank_accounts/index response.
	 *
	 * @param mixed $response API response.
	 * @return array Items with id, label and default.
	 */
	public static function banks( $response ) {
		$result = array();

		foreach ( is_object( $response ) && isset( $response->BankAccounts ) ? (array) $response->BankAccounts : array() as $bank ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$bank = (array) $bank;
			$bank = isset( $bank['BankAccount'] ) ? (array) $bank['BankAccount'] : $bank;
			if ( empty( $bank['id'] ) ) {
				continue;
			}
			$number   = ! empty( $bank['iban'] ) ? $bank['iban'] : trim( ( $bank['account'] ?? '' ) . ( ! empty( $bank['bank_code'] ) ? '/' . $bank['bank_code'] : '' ) );
			$result[] = array(
				'id'      => (string) $bank['id'],
				'label'   => wp_strip_all_tags( trim( ( $bank['bank_name'] ?? '' ) . ( '' !== $number ? ', ' . $number : '' ) . ( ! empty( $bank['currency'] ) ? ' (' . $bank['currency'] . ')' : '' ), ', ' ) ),
				'default' => ! empty( $bank['default'] ),
			);
		}

		return $result;
	}

	/**
	 * Load the lists of the company in the plugin settings.
	 *
	 * Each list is null when it could not be loaded. Lists are kept for a few minutes, so saving the settings and
	 * showing them again does not load them twice.
	 *
	 * @param WC_SuperFaktura $wc_sf Plugin instance.
	 * @return array|WP_Error Array with 'sequences', 'logos', 'banks' and 'error' (null, or the message when a list
	 *                        could not be loaded), or WP_Error without API credentials or when nothing could be loaded.
	 */
	public static function load( $wc_sf ) {
		$key = self::cache_key();

		if ( null === $key ) {
			return new WP_Error( 'no_credentials', '' );
		}

		if ( isset( self::$loaded[ $key ] ) ) {
			return self::$loaded[ $key ];
		}

		$lists = get_transient( $key );
		if ( is_array( $lists ) ) {
			self::$loaded[ $key ] = $lists;
			return $lists;
		}

		$api   = $wc_sf->sf_api();
		$lists = array(
			'sequences' => null,
			'logos'     => null,
			'banks'     => null,
			'error'     => null,
		);
		$error = '';

		$sequences = $api->getSequences();
		if ( is_object( $sequences ) && empty( $sequences->error ) ) {
			$lists['sequences'] = self::sequences( $sequences );
		} else {
			$error = self::api_error( $api, $sequences );
		}

		$logos = $api->getLogos();
		if ( is_array( $logos ) ) {
			$lists['logos'] = self::logos( $logos );
		} elseif ( '' === $error ) {
			$error = self::api_error( $api, $logos );
		}

		$banks = $api->getBankAccounts();
		if ( is_object( $banks ) && isset( $banks->BankAccounts ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$lists['banks'] = self::banks( $banks );
		} elseif ( '' === $error ) {
			$error = self::api_error( $api, $banks );
		}

		$loaded = array_filter( array( $lists['sequences'], $lists['logos'], $lists['banks'] ), 'is_array' );

		if ( ! $loaded ) {
			$result = new WP_Error( 'api_error', $error );
		} elseif ( count( $loaded ) < 3 ) {
			$lists['error'] = $error;
			$result         = $lists;
			set_transient( $key, $lists, self::PARTIAL_CACHE_TTL );
		} else {
			$result = $lists;
			set_transient( $key, $lists, self::CACHE_TTL );
		}

		self::$loaded[ $key ] = $result;

		return $result;
	}

	/**
	 * Forget the lists kept for the current API credentials, so the next settings screen loads them again.
	 */
	public static function forget() {
		$key = self::cache_key();

		if ( null !== $key ) {
			delete_transient( $key );
			unset( self::$loaded[ $key ] );
		}
	}

	/**
	 * Cache key of the lists for the API credentials in the settings.
	 *
	 * @return string|null Key, or null without API credentials.
	 */
	private static function cache_key() {
		$credentials = array(
			get_option( 'woocommerce_sf_lang', 'sk' ),
			get_option( 'woocommerce_sf_email' ),
			get_option( 'woocommerce_sf_apikey' ),
			get_option( 'woocommerce_sf_company_id', '' ),
			get_option( 'woocommerce_sf_sandbox', 'no' ),
		);

		if ( empty( $credentials[1] ) || empty( $credentials[2] ) ) {
			return null;
		}

		return 'wc_sf_company_lists_' . md5( wp_json_encode( $credentials ) );
	}

	/**
	 * Options for a select of one list.
	 *
	 * The first option is the default of SuperFaktúra (empty value). A saved ID the list does not contain stays among
	 * the options, so saving the settings never changes it.
	 *
	 * @param array  $items   Items with id, label and default.
	 * @param string $current Saved ID.
	 * @return array Options (value => label).
	 */
	public static function options( $items, $current ) {
		$default = null;
		foreach ( $items as $item ) {
			if ( $item['default'] ) {
				$default = $item;
				break;
			}
		}

		/* translators: %s: name of the default item in SuperFaktúra */
		$options = array( '' => $default ? sprintf( __( 'Default in SuperFaktúra: %s', 'woocommerce-superfaktura' ), '' !== $default['label'] ? $default['label'] : $default['id'] ) : __( 'Default in SuperFaktúra', 'woocommerce-superfaktura' ) );

		foreach ( $items as $item ) {
			$options[ $item['id'] ] = '' !== $item['label'] ? $item['label'] : $item['id'];
		}

		// The saved value is compared as it is, so a value that differs from every ID (e.g. with spaces) stays its own
		// option and saving keeps it.
		$current = (string) $current;
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			/* translators: %s: ID saved in the settings */
			$options[ $current ] = sprintf( __( 'ID %s: not found in your SuperFaktúra company', 'woocommerce-superfaktura' ), $current );
		}

		return $options;
	}

	/**
	 * Whether a saved ID is missing from a list.
	 *
	 * @param array  $items   Items with id, label and default.
	 * @param string $current Saved ID.
	 * @return bool
	 */
	public static function is_missing( $items, $current ) {
		$current = (string) $current;

		return '' !== $current && ! in_array( $current, array_column( $items, 'id' ), true );
	}

	/**
	 * Error message of a failed request.
	 *
	 * @param WC_SF_Api $api      API client.
	 * @param mixed     $response API response.
	 * @return string
	 */
	private static function api_error( $api, $response ) {
		foreach ( array( 'error_message', 'message' ) as $property ) {
			if ( is_object( $response ) && ! empty( $response->$property ) && is_scalar( $response->$property ) ) {
				return (string) $response->$property;
			}
		}

		$error = $api->getLastError();

		return is_array( $error ) && ! empty( $error['message'] ) ? (string) $error['message'] : '';
	}
}
