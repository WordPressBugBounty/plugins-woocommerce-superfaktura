<?php
/**
 * Keep company billing data in sync with WooCommerce Subscriptions.
 *
 * @package SuperFaktura WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_SF_Subscriptions class.
 *
 * WooCommerce Subscriptions copies only the standard address fields between orders, customers
 * and subscriptions. The plugin's company fields (IČO, DIČ, IČ DPH, "Buy as business") and, in the
 * block checkout, the company name itself live outside those fields, so without this class they
 * go stale or get lost on subscriptions, and later renewal invoices carry wrong company data.
 */
class WC_SF_Subscriptions {

	/**
	 * Prefix of the block checkout additional fields as stored by WooCommerce.
	 */
	const PERSISTED_PREFIX = '_wc_other/superfaktura/';

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

		// When a renewal order goes through the checkout (including early renewals), WooCommerce Subscriptions
		// overwrites the subscription's addresses with the checkout's standard fields. In the block checkout the
		// standard company field is hidden and arrives empty. Once the order is processed, copy the renewal
		// order's company data back onto the subscription.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'sync_subscriptions_from_renewal_order' ), 20 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_subscriptions_from_classic_renewal_order' ), 20 );

		// Pre-fill the checkout with the renewed subscription's company data instead of possibly stale customer values.
		add_action( 'wcs_after_renewal_setup_cart_subscription', array( $this, 'prefill_customer_from_subscription' ), 10, 2 );
		add_action( 'wcs_after_early_renewal_setup_cart_subscription', array( $this, 'prefill_customer_from_subscription' ), 10, 1 );

		// My Account address change with "update subscriptions": copy the company fields too. Runs before
		// WooCommerce Subscriptions, which redirects and exits when updating a single subscription.
		add_action( 'woocommerce_customer_save_address', array( $this, 'sync_subscriptions_from_saved_address' ), 5, 2 );

		// Editing one subscription's address in My Account: WooCommerce also saves the form to the profile. Keep the
		// profile's company fields as they were, so one subscription's data never becomes the customer's default.
		add_action( 'woocommerce_after_save_address_validation', array( $this, 'snapshot_profile_company_data' ), 10, 2 );

		// The classic checkout reads the company fields from the saved profile, not from the session customer.
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'classic_checkout_value_for_renewal' ), 10, 2 );

		// Show the right company data in the My Account address form: the subscription's when editing one
		// subscription's address, otherwise the block checkout's values when the profile fields are stale.
		add_filter( 'woocommerce_my_account_edit_address_field_value', array( $this, 'my_account_field_value' ), 10, 3 );
	}

	/**
	 * Fill the My Account billing form from the values the block checkout stored, when needed.
	 *
	 * The block checkout stores its fields under WooCommerce's additional-field keys; older plugin versions
	 * never copied them to the profile keys the My Account form reads. The business checkbox follows the
	 * block checkout's stored choice; the other fields fall back to it only when the profile value is empty.
	 *
	 * @param mixed  $value        Field value.
	 * @param string $key          Field key.
	 * @param string $load_address Address type.
	 * @return mixed
	 */
	public function my_account_field_value( $value, $key, $load_address ) {
		$map = array(
			'wi_as_company'          => 'wi-as-company',
			'billing_company'        => 'billing-company',
			'billing_company_wi_id'  => 'billing-company-wi-id',
			'billing_company_wi_tax' => 'billing-company-wi-tax',
			'billing_company_wi_vat' => 'billing-company-wi-vat',
		);
		if ( 'billing' !== $load_address || ! isset( $map[ $key ] ) || ! $this->is_enabled() ) {
			return $value;
		}

		// Editing the address of a single subscription: WooCommerce Subscriptions fills the standard fields from the
		// subscription, so fill the company fields from it too, otherwise saving would copy the profile's data onto it.
		$subscription = $this->get_subscription_being_edited();
		if ( $subscription ) {
			if ( 'billing_company' === $key ) {
				return $value; // Set by WooCommerce Subscriptions from the subscription.
			}
			$state = $this->get_company_state( $subscription );
			if ( 'wi_as_company' === $key ) {
				return ( null !== $state && $state['is_company'] ) ? '1' : '0';
			}
			$field = str_replace( 'billing_company_wi_', '', $key );
			return ( null !== $state && $state['is_company'] ) ? $state[ $field ] : '';
		}

		$user_id = get_current_user_id();
		$meta    = self::PERSISTED_PREFIX . $map[ $key ];
		if ( ! $user_id || ! metadata_exists( 'user', $user_id, $meta ) ) {
			return $value;
		}

		$persisted = (string) get_user_meta( $user_id, $meta, true );
		if ( 'wi_as_company' === $key ) {
			return in_array( $persisted, array( '1', 'true', 'yes' ), true ) ? '1' : '0';
		}

		// Fall back to the stored values only while the stored choice is a company; after a private purchase they
		// are outdated and must not be shown (and saved) again.
		$stored_choice = (string) get_user_meta( $user_id, self::PERSISTED_PREFIX . 'wi-as-company', true );
		if ( ! in_array( $stored_choice, array( '1', 'true', 'yes' ), true ) ) {
			return $value;
		}

		return '' !== (string) $value ? $value : $persisted;
	}

	/**
	 * The subscription whose address is being edited in My Account, if the user may edit it.
	 *
	 * @return WC_Subscription|null
	 */
	private function get_subscription_being_edited() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup, ownership checked below.
		$subscription_id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0;
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}

		// Called once per form field; resolve the subscription and the permission once.
		static $cache = array();
		if ( ! array_key_exists( $subscription_id, $cache ) ) {
			$subscription               = wcs_get_subscription( $subscription_id );
			$cache[ $subscription_id ] = ( $subscription && current_user_can( 'view_order', $subscription_id ) ) ? $subscription : null;
		}

		return $cache[ $subscription_id ];
	}

	/**
	 * Fill the classic checkout's company fields from the subscription being renewed.
	 *
	 * @param mixed  $value Field value.
	 * @param string $input Field name.
	 * @return mixed
	 */
	public function classic_checkout_value_for_renewal( $value, $input ) {
		if ( ! in_array( $input, array( 'wi_as_company', 'billing_company_wi_id', 'billing_company_wi_tax', 'billing_company_wi_vat' ), true ) ) {
			return $value;
		}
		if ( ! $this->is_enabled() || ! function_exists( 'wcs_cart_contains_renewal' ) ) {
			return $value;
		}

		$item = wcs_cart_contains_renewal();
		if ( ! $item || empty( $item['subscription_renewal']['subscription_id'] ) ) {
			return $value;
		}

		$subscription = wcs_get_subscription( $item['subscription_renewal']['subscription_id'] );
		if ( ! $subscription || (int) $subscription->get_customer_id() !== get_current_user_id() ) {
			return $value;
		}

		$state = $this->get_company_state( $subscription );
		if ( null === $state || ! $state['is_company'] ) {
			return $value;
		}

		return 'wi_as_company' === $input ? 1 : $state[ str_replace( 'billing_company_wi_', '', $input ) ];
	}

	/**
	 * Whether the plugin's company billing fields are in use on this site.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		if ( 'yes' !== get_option( 'woocommerce_sf_add_company_billing_fields', 'yes' ) ) {
			return false;
		}

		// The WooCommerce nastavenia SK/CZ plugin provides its own company fields; ours are disabled then.
		return ! class_exists( 'Webikon\Woocommerce_Plugin\WC_Nastavenia_SKCZ\Plugin', false );
	}

	/**
	 * Copy company data from a renewal order processed by the classic checkout to its subscriptions.
	 *
	 * The business choice is taken from the submitted form: the order may carry a stale block checkout flag
	 * inherited from the subscription.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sync_subscriptions_from_classic_renewal_order( $order_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before creating the order.
		if ( ! isset( $_POST['woocommerce-process-checkout-nonce'] ) ) {
			return;
		}

		// Text inputs are always posted, so their absence means the form did not contain the company fields at all
		// (e.g. an express payment button); that is not a private choice.
		if ( ! isset( $_POST['billing_company_wi_id'] ) && ! isset( $_POST['billing_company_wi_tax'] ) && ! isset( $_POST['billing_company_wi_vat'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$is_company = isset( $_POST['wi_as_company'] ) && '1' == $_POST['wi_as_company'];
		$state      = array(
			'is_company'          => $is_company,
			'company'             => $order->get_billing_company(),
			'shipping'            => $order->get_shipping_company(),
			'shipping_from_order' => true,
		);
		foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
			$state[ $field ] = (string) $order->get_meta( 'billing_company_wi_' . $field, true );
		}

		$this->sync_subscriptions( $order, $state );
	}

	/**
	 * Copy company data from a renewal order processed by the block checkout to its subscriptions.
	 *
	 * Runs once the order is processed, after WooCommerce Subscriptions has copied the checkout address
	 * onto the subscription.
	 *
	 * @param int|WC_Order $order Order or order ID.
	 */
	public function sync_subscriptions_from_renewal_order( $order ) {
		$order = wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$state = $this->get_company_state( $order );
		if ( null === $state ) {
			return;
		}
		$state['shipping'] = $order->get_shipping_company();

		$this->sync_subscriptions( $order, $state );
	}

	/**
	 * Write a company state to all subscriptions of a renewal order.
	 *
	 * @param WC_Order $order Renewal order.
	 * @param array    $state Company state.
	 */
	private function sync_subscriptions( $order, $state ) {
		if ( ! $this->is_enabled() || ! function_exists( 'wcs_order_contains_renewal' ) || ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			return;
		}
		if ( ! wcs_order_contains_renewal( $order ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
			$this->write_company_state( $subscription, $state, true );
		}
	}

	/**
	 * Pre-fill the customer's checkout values from the subscription being renewed.
	 *
	 * Only company subscriptions are handled; the customer's stored values are left alone otherwise.
	 *
	 * @param WC_Subscription $subscription Subscription being renewed.
	 * @param WC_Order        $order        Renewal order.
	 */
	public function prefill_customer_from_subscription( $subscription, $order = null ) {
		if ( ! $this->is_enabled() || ! $subscription instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->customer || ! is_user_logged_in() ) {
			return;
		}
		if ( (int) WC()->customer->get_id() !== (int) $subscription->get_customer_id() ) {
			return;
		}

		$state = $this->get_company_state( $subscription );
		if ( null === $state || ! $state['is_company'] ) {
			return;
		}

		$customer = WC()->customer;
		$customer->update_meta_data( 'wi_as_company', '1' );
		$customer->update_meta_data( self::PERSISTED_PREFIX . 'wi-as-company', '1' );
		if ( '' !== $state['company'] ) {
			$customer->update_meta_data( self::PERSISTED_PREFIX . 'billing-company', $state['company'] );
		}
		foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
			$customer->update_meta_data( 'billing_company_wi_' . $field, $state[ $field ] );
			$customer->update_meta_data( self::PERSISTED_PREFIX . 'billing-company-wi-' . $field, $state[ $field ] );
		}
		$customer->save();
	}

	/**
	 * Profile company fields taken before WooCommerce saves a single subscription's address form.
	 *
	 * @var array|null
	 */
	private $profile_snapshot = null;

	/**
	 * Profile keys (business choice and IDs) protected when a single subscription's address is edited.
	 *
	 * @return string[]
	 */
	private function profile_company_keys() {
		// The company name is left to WooCommerce Subscriptions, which tells the customer the default address is
		// updated too; only the business choice and the IDs are protected.
		$keys = array( 'wi_as_company', 'billing_company_wi_id', 'billing_company_wi_tax', 'billing_company_wi_vat' );
		foreach ( array( 'wi-as-company', 'billing-company-wi-id', 'billing-company-wi-tax', 'billing-company-wi-vat' ) as $field ) {
			$keys[] = self::PERSISTED_PREFIX . $field;
		}
		return $keys;
	}

	/**
	 * Remember the profile's company fields before WooCommerce saves a single subscription's address form.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $address_type Address type.
	 */
	public function snapshot_profile_company_data( $user_id, $address_type ) {
		$this->profile_snapshot = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the form nonce before this hook.
		if ( 'billing' !== $address_type || ! isset( $_POST['update_subscription_address'] ) || ! $this->is_enabled() ) {
			return;
		}

		$this->profile_snapshot = array();
		foreach ( $this->profile_company_keys() as $key ) {
			$this->profile_snapshot[ $key ] = metadata_exists( 'user', $user_id, $key ) ? get_user_meta( $user_id, $key, true ) : null;
		}
	}

	/**
	 * Restore the profile's company fields remembered before a single subscription's address was saved.
	 *
	 * @param int $user_id User ID.
	 */
	private function restore_profile_company_data( $user_id ) {
		if ( ! is_array( $this->profile_snapshot ) ) {
			return;
		}

		foreach ( $this->profile_snapshot as $key => $value ) {
			if ( null === $value ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, $value );
			}
		}
		$this->profile_snapshot = null;
	}

	/**
	 * Keep the profile and subscriptions in sync when the customer saves the billing address in My Account.
	 *
	 * WooCommerce has verified its own nonce before this hook runs. The profile part always applies; the
	 * subscription part mirrors WooCommerce Subscriptions: its nonce, the "update all" and "update this
	 * subscription" options, active or on-hold subscriptions only, and only subscriptions the user may edit.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $address_type Address type (billing or shipping).
	 */
	public function sync_subscriptions_from_saved_address( $user_id, $address_type ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the form nonce; the subscription part checks its own below.
		// Front-end My Account form only. The wp-admin user profile fires the same hook without the validation hook
		// and without the business checkbox, which must not be read as a private choice.
		if ( 'billing' !== $address_type || ! $this->is_enabled() || ! did_action( 'woocommerce_after_save_address_validation' ) ) {
			return;
		}

		$single_subscription = isset( $_POST['update_subscription_address'] );

		// One subscription's address was edited: put back the profile's company choice and IDs that WooCommerce
		// just overwrote with the subscription's values.
		if ( $single_subscription ) {
			$this->restore_profile_company_data( $user_id );
		}

		// Act only when the plugin's company fields were part of the form.
		if ( ! isset( $_POST['billing_company_wi_id'] ) && ! isset( $_POST['billing_company_wi_tax'] ) && ! isset( $_POST['billing_company_wi_vat'] ) ) {
			return;
		}

		$is_company = ! empty( $_POST['wi_as_company'] );
		$state      = array(
			'is_company' => $is_company,
			'company'    => isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '',
		);
		// A field the site has disabled is not posted; null leaves the stored value untouched.
		foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
			$key             = 'billing_company_wi_' . $field;
			$state[ $field ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : null;
		}

		// WooCommerce saves the form's fields, including the business checkbox, to the profile. Keep the block
		// checkout's stored copy in step, so the form and the next block checkout show the same data.
		if ( ! $single_subscription && metadata_exists( 'user', $user_id, self::PERSISTED_PREFIX . 'wi-as-company' ) ) {
			update_user_meta( $user_id, self::PERSISTED_PREFIX . 'wi-as-company', $is_company ? '1' : '0' );
			if ( $is_company ) {
				update_user_meta( $user_id, self::PERSISTED_PREFIX . 'billing-company', $state['company'] );
				foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
					if ( null !== $state[ $field ] ) {
						update_user_meta( $user_id, self::PERSISTED_PREFIX . 'billing-company-wi-' . $field, $state[ $field ] );
					}
				}
			}
		}

		// With "update all", only a company address is copied: unticking never cleared subscription company data
		// before, and an out-of-date profile must not wipe it now. When a single subscription is edited, its form
		// shows that subscription's own data, so unticking there is a deliberate private choice for it.
		if ( ( ! $is_company && ! $single_subscription ) || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return;
		}
		if ( ! isset( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcsnonce'] ) ), 'wcs_edit_address' ) ) {
			return;
		}
		if ( function_exists( 'wc_notice_count' ) && wc_notice_count( 'error' ) > 0 ) {
			return;
		}

		$subscriptions = array();
		if ( isset( $_POST['update_all_subscriptions_addresses'] ) ) {
			foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
				if ( $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
					$subscriptions[] = $subscription;
				}
			}
		} elseif ( $single_subscription ) {
			$subscription = wcs_get_subscription( absint( $_POST['update_subscription_address'] ) );
			if ( $subscription && user_can( $user_id, 'view_order', $subscription->get_id() ) ) {
				$subscriptions[] = $subscription;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		foreach ( $subscriptions as $subscription ) {
			// The company name itself is copied by WooCommerce Subscriptions.
			$this->write_company_state( $subscription, $state, false );
		}

		// A private choice for a single subscription: WooCommerce Subscriptions copies the posted company name right
		// after this handler, so empty it first, as the checkout does for private purchases.
		if ( ! $is_company && $single_subscription && $subscriptions ) {
			$_POST['billing_company'] = ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * Read the company state of an order or subscription.
	 *
	 * The plugin's own keys are written only for company purchases. The block checkout additionally keeps
	 * WooCommerce's persisted checkbox, which also records an explicit "not a company" choice.
	 *
	 * @param WC_Order $order Order or subscription.
	 * @return array|null Array with is_company, company, id, tax and vat, or null when nothing is known.
	 */
	private function get_company_state( $order ) {
		$state = array(
			'company' => $order->get_billing_company(),
			'id'      => (string) $order->get_meta( 'billing_company_wi_id', true ),
			'tax'     => (string) $order->get_meta( 'billing_company_wi_tax', true ),
			'vat'     => (string) $order->get_meta( 'billing_company_wi_vat', true ),
		);

		$has_ids   = '' !== $state['id'] || '' !== $state['tax'] || '' !== $state['vat'];
		$persisted = (string) $order->get_meta( self::PERSISTED_PREFIX . 'wi-as-company', true );

		if ( '' !== $persisted ) {
			$state['is_company'] = in_array( $persisted, array( '1', 'true', 'yes' ), true );
		} elseif ( $has_ids ) {
			$state['is_company'] = true;
		} else {
			return null;
		}

		if ( $state['is_company'] && '' === $state['company'] ) {
			$state['company'] = sanitize_text_field( (string) $order->get_meta( self::PERSISTED_PREFIX . 'billing-company', true ) );
		}

		return $state;
	}

	/**
	 * Write a company state to a subscription: the plugin's keys, the block checkout's stored copy when the
	 * subscription has one (so later repairs never resurrect outdated values), and optionally the company names.
	 *
	 * A field whose value is null is left untouched.
	 *
	 * @param WC_Order $subscription  Subscription.
	 * @param array    $state         Company state.
	 * @param bool     $write_company Whether to set the billing (and shipping) company name as well.
	 */
	private function write_company_state( $subscription, $state, $write_company ) {
		$is_company = ! empty( $state['is_company'] );

		// A private choice is recorded explicitly, so the renewal repair never falls back to the company data
		// of the original order. A company state leaves subscriptions without the stored copy as they are.
		$has_persisted = $subscription->meta_exists( self::PERSISTED_PREFIX . 'wi-as-company' ) || ! $is_company;

		foreach ( array( 'id', 'tax', 'vat' ) as $field ) {
			if ( ! array_key_exists( $field, $state ) || null === $state[ $field ] ) {
				continue;
			}
			$value = $is_company ? (string) $state[ $field ] : '';
			foreach ( array( 'billing_company_wi_' . $field, '_billing_company_wi_' . $field ) as $key ) {
				if ( '' === $value ) {
					$subscription->delete_meta_data( $key );
				} else {
					$subscription->update_meta_data( $key, $value );
				}
			}
			if ( $has_persisted ) {
				$subscription->update_meta_data( self::PERSISTED_PREFIX . 'billing-company-wi-' . $field, $value );
			}
		}

		if ( $has_persisted ) {
			$subscription->update_meta_data( self::PERSISTED_PREFIX . 'wi-as-company', $is_company ? '1' : '0' );
			$subscription->update_meta_data( self::PERSISTED_PREFIX . 'billing-company', $is_company ? (string) $state['company'] : '' );
		}

		if ( $write_company ) {
			$subscription->set_billing_company( $is_company ? (string) $state['company'] : '' );
			// The block checkout sends an empty company for the hidden field, so only a known name is restored there;
			// the classic checkout's order already holds the final shipping company.
			if ( ! empty( $state['shipping'] ) || ! empty( $state['shipping_from_order'] ) ) {
				$subscription->set_shipping_company( (string) $state['shipping'] );
			}
		}

		$subscription->save();
	}
}
