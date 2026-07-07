jQuery(document).ready(function() {

    var toggle_fields = function(show) {
        if (show) {
            jQuery('#billing_company_field').fadeIn();
            jQuery('#billing_company_wi_id_field').fadeIn();
            jQuery('#billing_company_wi_vat_field').fadeIn();
            jQuery('#billing_company_wi_tax_field').fadeIn();
        }
        else {
            jQuery('#billing_company_field').fadeOut();
            jQuery('#billing_company_wi_id_field').fadeOut();
            jQuery('#billing_company_wi_vat_field').fadeOut();
            jQuery('#billing_company_wi_tax_field').fadeOut();
        }
    }

    // Ask WooCommerce to recalculate the order totals so the reverse-charge VAT exemption
    // is reflected in the summary when the business status or VAT # changes. Without this the
    // update_checkout AJAX (which re-applies the exemption server-side) never fires for these fields.
    var refresh_totals = function() {
        jQuery(document.body).trigger('update_checkout');
    };

    jQuery('#wi_as_company').change(function() {
       toggle_fields(jQuery(this).is(':checked'));
       refresh_totals();
    });

    // Delegated so it keeps working after WooCommerce refreshes the checkout. 'change' fires on blur,
    // avoiding a recalculation (and VIES lookup) on every keystroke.
    jQuery(document).on('change', '#billing_company_wi_vat', refresh_totals);

    toggle_fields(jQuery('#wi_as_company').is(':checked'))
});
