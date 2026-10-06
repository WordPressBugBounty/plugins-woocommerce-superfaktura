jQuery(function($) {
	// Remember closed notices per user. WordPress adds the .notice-dismiss button to notices with
	// the is-dismissible class and hides them itself; the banner on the settings tab has its own button.
	function wc_sf_dismiss($notice) {
		if (!window.wc_sf_notices || !$notice.length) {
			return;
		}

		$.post(window.wc_sf_notices.ajax_url, {
			action: 'wc_sf_dismiss_notice',
			nonce: window.wc_sf_notices.nonce,
			notice: $notice.data('wc-sf-notice'),
			value: $notice.data('wc-sf-value') || ''
		});
	}

	$(document).on('click', '[data-wc-sf-notice] .notice-dismiss', function() {
		wc_sf_dismiss($(this).closest('[data-wc-sf-notice]'));
	});

	$(document).on('click', '[data-wc-sf-notice] [data-wc-sf-dismiss]', function() {
		var $notice = $(this).closest('[data-wc-sf-notice]');
		var $next = $notice.next();
		var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		wc_sf_dismiss($notice);
		$notice.slideUp(reduceMotion ? 0 : 150, function() {
			$notice.remove();
			// Keep keyboard focus on the page instead of losing it with the removed button.
			if ($next.length) {
				$next.attr('tabindex', '-1').trigger('focus');
			}
		});
	});
});
