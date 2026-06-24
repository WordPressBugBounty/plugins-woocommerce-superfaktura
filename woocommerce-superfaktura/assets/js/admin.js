jQuery(document).ready(function($) {
	var wcSfI18n = (window.wc_sf && window.wc_sf.i18n) || {};

	function wc_sf_t(key, fallback) {
		return wcSfI18n[key] || fallback;
	}

	function wc_sf_toggle_settings(selector, show) {
		var $items = $(selector).closest('tr');
		if (show) {
			$items.show();
		}
		else {
			$items.hide();
		}
	}

	function wc_sf_find_secret_input(fieldName) {
		return $('input[name="' + fieldName + '"]');
	}

	function wc_sf_sync_secret_toggle_label($input, hidden) {
		var $toggle = $input.siblings('.wc-sf-secret-actions').find('.wc-sf-secret-toggle');

		$toggle.text(wc_sf_t(hidden ? 'show' : 'hide', hidden ? 'Show' : 'Hide'));
	}

	function wc_sf_sync_secret_input($input, hidden) {
		if (hidden) {
			$input.attr('type', 'password');
		}
		else {
			$input.attr('type', 'text');
		}

		wc_sf_sync_secret_toggle_label($input, hidden);
	}

	function wc_sf_copy_secret_fallback($input, $copy) {
		var wasHidden = $input.attr('type') === 'password';
		if (wasHidden) {
			wc_sf_sync_secret_input($input, false);
		}

		$input.trigger('focus').trigger('select');
		document.execCommand('copy');

		if (wasHidden) {
			wc_sf_sync_secret_input($input, true);
		}

		$copy.text(wc_sf_t('copied', 'Copied'));
		window.setTimeout(function() {
			$copy.text(wc_sf_t('copy', 'Copy'));
		}, 1500);
	}

	function wc_sf_init_secret_field(fieldName) {
		var $input = wc_sf_find_secret_input(fieldName);
		if (!$input.length || $input.data('wcSfSecretReady')) {
			return;
		}

		var $toggle = $('<button type="button" class="button button-secondary wc-sf-secret-toggle">' + wc_sf_t('show', 'Show') + '</button>');
		var $copy = $('<button type="button" class="button button-secondary wc-sf-secret-copy">' + wc_sf_t('copy', 'Copy') + '</button>');
		var $actions = $('<span class="wc-sf-secret-actions"></span>');

		$input.data('wcSfSecretReady', true);
		$input.attr('autocomplete', 'new-password');
		$input.attr('spellcheck', 'false');
		$input.attr('data-1p-ignore', 'true');
		$input.attr('data-lpignore', 'true');

		$actions.append($toggle).append($copy);
		$input.after($actions);
		wc_sf_sync_secret_toggle_label($input, $input.attr('type') === 'password');

		$toggle.on('click', function() {
			var isHidden = $input.attr('type') === 'password';
			wc_sf_sync_secret_input($input, !isHidden);

			if (isHidden) {
				$input.trigger('focus');
			}
		});

		$copy.on('click', function() {
			var actual = $input.val();
			if (!actual) {
				return;
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(actual).then(function() {
					$copy.text(wc_sf_t('copied', 'Copied'));
					window.setTimeout(function() {
						$copy.text(wc_sf_t('copy', 'Copy'));
					}, 1500);
				}).catch(function() {
					wc_sf_copy_secret_fallback($input, $copy);
				});
				return;
			}

			wc_sf_copy_secret_fallback($input, $copy);
		});
	}

	wc_sf_init_secret_field('woocommerce_sf_apikey');
	wc_sf_init_secret_field('woocommerce_sf_sync_secret_key');

	// custom invoice numbering
	$('input[name=woocommerce_sf_invoice_custom_num]').on('click', function(e) {
		wc_sf_toggle_settings('.custom-invoice-numbering-item', $(this).prop('checked'));
	});
	wc_sf_toggle_settings('.custom-invoice-numbering-item', $('input[name=woocommerce_sf_invoice_custom_num]').prop('checked'));

	// custom comment
	$('input[name=woocommerce_sf_comments]').on('click', function(e) {
		wc_sf_toggle_settings('.custom-comment-item', $(this).prop('checked'));
	});
	wc_sf_toggle_settings('.custom-comment-item', $('input[name=woocommerce_sf_comments]').prop('checked'));

	// validate eu vat id (and its dependent "behavior" setting)
	function wc_sf_update_vat_validation_visibility() {
		var companyOn = $('input[name=woocommerce_sf_add_company_billing_fields]').prop('checked');
		var vatFieldVal = $('select[name=woocommerce_sf_add_company_billing_fields_vat]').val();
		var validateVisible = companyOn && ('optional' == vatFieldVal || 'required' == vatFieldVal);
		var behaviorVisible = validateVisible && $('input[name=woocommerce_sf_validate_eu_vat_number]').prop('checked');

		wc_sf_toggle_settings('#woocommerce_sf_validate_eu_vat_number', validateVisible);
		wc_sf_toggle_settings('#woocommerce_sf_validate_eu_vat_number_behavior', behaviorVisible);
		wc_sf_toggle_settings('#woocommerce_sf_exempt_vat_on_valid_vat_number', behaviorVisible);
	}

	// company billing fields
	$('input[name=woocommerce_sf_add_company_billing_fields]').on('click', function(e) {
		wc_sf_toggle_settings('.company-billing-fields-item', $(this).prop('checked'));
		wc_sf_update_vat_validation_visibility();
	});
	wc_sf_toggle_settings('.company-billing-fields-item', $('input[name=woocommerce_sf_add_company_billing_fields]').prop('checked'));

	$('select[name=woocommerce_sf_add_company_billing_fields_vat]').on('change', wc_sf_update_vat_validation_visibility);
	$('input[name=woocommerce_sf_validate_eu_vat_number]').on('click', wc_sf_update_vat_validation_visibility);
	wc_sf_update_vat_validation_visibility();



	// add country settings
	$('body').on('click', 'a.sf-add-country-settings', function(e) {
		e.preventDefault();

		$('tbody#sf-countries').append(
			'<tr>' + $('tbody#sf-countries tr[data-name=template]').html() + '</tr>'
		);
	});



	// delete country settings
	$('body').on('click', 'a.sf-delete-country-settings', function(e) {
		e.preventDefault();

		$(this).closest('tr').remove();
	});



	// process country settings
	if ($('input[name=woocommerce_sf_country_settings]').length) {
		$('body').on('submit', 'form', function(e) {
			var country_settings = [];

			$('tbody#sf-countries tr:not([data-name=template])').each(function() {
				country_settings.push({
					'country': $(this).find('select[name=_country_country]').val(),
					'vat_id': $(this).find('input[name=_country_vat]').val(),
					'vat_id_only_final_consumer': $(this).find('input[name=_country_vat_id_only_final_consumer]').prop('checked'),
					'tax_id': $(this).find('input[name=_country_tax]').val(),
					'bank_account_id': $(this).find('input[name=_country_bank_account_id]').val(),
					'proforma_sequence_id': $(this).find('input[name=_country_proforma_invoice_sequence_id]').val(),
					'invoice_sequence_id': $(this).find('input[name=_country_invoice_sequence_id]').val(),
					'cancel_sequence_id': $(this).find('input[name=_country_cancel_sequence_id]').val()
				});
			});

			$('input[name=woocommerce_sf_country_settings]').val(JSON.stringify(country_settings));
		});
	}



	// test api connection
	$('a.wc-sf-api-test').on('click', function(e) {
		e.preventDefault();

		$('span.wc-sf-api-test-loading').show();
		$('span.wc-sf-api-test-ok').hide();
		$('span.wc-sf-api-test-fail').hide();
		$('span.wc-sf-api-test-fail-message').hide();

		var data = {
			'action': 'wc_sf_api_test',
			'woocommerce_sf_lang': $('input[name=woocommerce_sf_lang]:checked').val(),
			'woocommerce_sf_email': $('input[name=woocommerce_sf_email]').val(),
			'woocommerce_sf_apikey': $('input[name=woocommerce_sf_apikey]').val(),
			'woocommerce_sf_company_id': $('input[name=woocommerce_sf_company_id]').val(),
			'woocommerce_sf_sandbox': $('input[name=woocommerce_sf_sandbox]').prop('checked') ? 'yes' : 'no'
		};

		jQuery.post(ajaxurl, data, function(response) {
			$('span.wc-sf-api-test-loading').hide();

			if ('OK' == $.trim(response)) {
				$('span.wc-sf-api-test-ok').show();
			}
			else {
				$('span.wc-sf-api-test-fail').show();
				$('span.wc-sf-api-test-fail-message').text(response).show();
			}
		});
	});




	// check document pdf url
	$('a.sf-url-check').on('click', function(e) {
		e.preventDefault();

		var $this = $(this);
		var url = $this.attr('href');
		var data = {
			'action': 'wc_sf_url_check',
			'security': wc_sf.ajaxnonce,
			'url': url
		};

		jQuery.post(ajaxurl, data, function(response) {
			if (false == response.includes('200')) {

				// show error
				$this.replaceWith('<p class="wc-sf-url-error">' + ($this.attr('data-error') ?? 'Error') + '</p>');
			}
			else {

				// continue to url
				if ('_blank' == $this.attr('target')) {
					window.open($this.attr('href'), '_blank');
				}
				else {
					window.location = $this.attr('href');
				}
			}
		});
	});



	// prevent double-clicking on links
	$('a.sf-prevent-duplicity').on('click', function(e) {
		$(this).css('pointer-events', 'none');
	});

});
