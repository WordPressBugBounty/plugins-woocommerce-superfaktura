<?php
/**
 * SuperFaktúra WooCommerce Email Handling.
 *
 * @package   SuperFaktúra WooCommerce
 * @author    2day.sk <superfaktura@2day.sk>
 * @copyright 2022 2day.sk s.r.o., Webikon s.r.o.
 * @license   GPL-2.0+
 * @link      https://www.superfaktura.sk/integracia/
 */

class WC_SF_Email {

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
     * Initialize hooks.
     */
    public function init() {
        add_action('woocommerce_email_customer_details', array($this, 'sf_invoice_business_data_email'), 30, 3);
        add_action('woocommerce_email_order_meta', array($this, 'sf_payment_link_email'), 10, 2);
        add_action('woocommerce_email_order_meta', array($this, 'sf_invoice_link_email'), 10, 2);
        add_filter('woocommerce_email_attachments', array($this, 'sf_invoice_attachment_email'), 10, 3);
    }

    /**
     * Add company information to customer data in emails.
     *
     * @param WC_Order $order Order.
     * @param boolean  $sent_to_admin True if sent to admin.
     * @param boolean  $plain_text True if email is in plain text format.
     */
    public function sf_invoice_business_data_email($order, $sent_to_admin, $plain_text) {
        if ('no' === get_option('woocommerce_sf_email_billing_details', 'no')) {
            return;
        }

        if ($this->wc_sf->wc_nastavenia_skcz_activated) {
            $plugin  = Webikon\Woocommerce_Plugin\WC_Nastavenia_SKCZ\Plugin::get_instance();
            $details = $plugin->get_customer_details($order->get_id());
            $ico     = $details->get_company_id();
            $ic_dph  = $details->get_company_vat_id();
            $dic     = $details->get_company_tax_id();
        } else {
            $ico    = $order->get_meta('billing_company_wi_id', true);
            $ic_dph = $order->get_meta('billing_company_wi_vat', true);
            $dic    = $order->get_meta('billing_company_wi_tax', true);
        }

        $result = '';

        if ($ico) {
            $result .= sprintf('%s: %s<br>', __('ID #', 'woocommerce-superfaktura'), $ico);
        }

        if ($ic_dph) {
            $result .= sprintf('%s: %s<br>', __('VAT #', 'woocommerce-superfaktura'), $ic_dph);
        }

        if ($dic) {
            $result .= sprintf('%s: %s<br>', __('TAX ID #', 'woocommerce-superfaktura'), $dic);
        }

        if ($result) {
            echo wp_kses('<p>' . $result . '</p>', $this->wc_sf->allowed_tags);
        }
    }

    /**
     * Add payment link to emails.
     *
     * @param WC_Order $order Order.
     * @param boolean  $sent_to_admin True if sent to admin.
     */
    public function sf_payment_link_email($order, $sent_to_admin = false) {
        if (in_array($order->get_status(), array('cancelled', 'refunded', 'failed'), true)) {
            return;
        }

        $payment_link = $order->get_meta('wc_sf_payment_link', true);
        if (!$payment_link) {
            return;
        }

        if ('yes' === get_option('woocommerce_sf_email_payment_link', 'yes')) {
            echo wp_kses('<h2>' . __('Online payment link', 'woocommerce-superfaktura') . '</h2>', $this->wc_sf->allowed_tags);
            echo wp_kses('<p><a href="' . esc_url($payment_link) . '">' . esc_url($payment_link) . '</a></p>', $this->wc_sf->allowed_tags);
        }
    }

    /**
     * Add invoice link to emails.
     *
     * @param WC_Order $order Order.
     * @param boolean  $sent_to_admin True if sent to admin.
     */
    public function sf_invoice_link_email($order, $sent_to_admin = false) {
        // Filter allows to cancel invoice link.
        $skip_link = apply_filters('sf_skip_email_link', false, $order);
        if ($skip_link) {
            return;
        }

        if (in_array($order->get_status(), array('cancelled', 'refunded', 'failed'), true)) {
            return;
        }

        if ('completed' === $order->get_status() && 'yes' === get_option('woocommerce_sf_completed_email_skip_invoice', 'no')) {
            return;
        }

        if ('cod' === $order->get_payment_method() && 'yes' === get_option('woocommerce_sf_cod_email_skip_invoice', 'no')) {
            return;
        }

        $invoice_data = $this->wc_sf->get_invoice_data($order->get_id());
        if (!$invoice_data) {
            return;
        }

        // Check if proforma was already paid.
        if ('proforma' === $invoice_data['type']) {
            $proforma = $this->wc_sf->sf_api()->invoice($invoice_data['invoice_id']);
            if (isset($proforma->Invoice) && 1 != $proforma->Invoice->status) {
                return;
            }
        }

        if ('yes' === get_option('woocommerce_sf_email_invoice_link', 'yes')) {
            echo wp_kses('<h2>' . (('regular' === $invoice_data['type']) ? __('Download invoice', 'woocommerce-superfaktura') : __('Download proforma invoice', 'woocommerce-superfaktura')) . "</h2>\n\n", $this->wc_sf->allowed_tags);
            echo wp_kses('<p><a href="' . esc_url($invoice_data['pdf']) . '">' . $invoice_data['pdf'] . "</a></p>\n\n", $this->wc_sf->allowed_tags);

            // Mark invoice as sent only if email is sent to the customer.
            if (!empty($invoice_data['invoice_id']) && !$sent_to_admin) {
                try {
                    $this->wc_sf->sf_api()->markAsSent($invoice_data['invoice_id'], $order->get_billing_email());
                } catch (Exception $e) {
                    // Do not report anything.
                    return;
                }
            }
        }
    }

    /**
     * Add invoice attachment to emails.
     *
     * @param array    $attachments Attachments.
     * @param int      $email_id Email ID.
     * @param WC_Order $order Order.
     */
    public function sf_invoice_attachment_email($attachments, $email_id, $order) {
        // Filter allows to cancel pdf attachment.
        $skip_attachment = apply_filters('sf_skip_email_attachment', false, $order);
        if ($skip_attachment) {
            return $attachments;
        }

        if (!($order instanceof WC_Order)) {
            return $attachments;
        }

        if (in_array($order->get_status(), array('cancelled', 'refunded', 'failed'), true)) {
            return $attachments;
        }

        if ('completed' === $order->get_status() && 'yes' === get_option('woocommerce_sf_completed_email_skip_invoice', 'no')) {
            return $attachments;
        }

        if ('cod' === $order->get_payment_method() && 'yes' === get_option('woocommerce_sf_cod_email_skip_invoice', 'no')) {
            return $attachments;
        }

        $invoice_data = $this->wc_sf->get_invoice_data($order->get_id());
        if (!$invoice_data) {
            return $attachments;
        }

        // Check if proforma was already paid.
        if ('proforma' === $invoice_data['type']) {
            $proforma = $this->wc_sf->sf_api()->invoice($invoice_data['invoice_id']);
            if (isset($proforma->Invoice) && 1 != $proforma->Invoice->status) {
                return $attachments;
            }
        }

        if ('yes' === get_option('woocommerce_sf_invoice_pdf_attachment', 'no')) {
            $pdf_resource = wp_safe_remote_get($invoice_data['pdf']);
            if (is_wp_error($pdf_resource) || 200 != $pdf_resource['response']['code'] || 'application/pdf' != $pdf_resource['headers']['content-type']) {
                return $attachments;
            }

            $pdf_path = get_temp_dir() . $invoice_data['invoice_id'] . '.pdf';
            $pdf_path = str_replace("\0", "", $pdf_path); // Remove null bytes (error reported by users).
            file_put_contents($pdf_path, $pdf_resource['body']);
            $attachments[] = $pdf_path;

            // Mark invoice as sent only if email is sent to the customer and invoice wasn't marked as sent in "sf_invoice_link_email()" already.
            if (!empty($invoice_data['invoice_id']) && 0 === strpos($email_id, 'customer') && 'no' === get_option('woocommerce_sf_email_invoice_link', 'yes')) {
                try {
                    $this->wc_sf->sf_api()->markAsSent($invoice_data['invoice_id'], $order->get_billing_email());
                } catch (Exception $e) {
                    // Do not report anything.
                    return $attachments;
                }
            }
        }

        return $attachments;
    }
}
