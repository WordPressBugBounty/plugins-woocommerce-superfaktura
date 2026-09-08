/**
 * SuperFaktúra bulk document actions in the orders list.
 *
 * 1. Confirms the plugin's bulk actions before the list form is submitted.
 * 2. Processes a stored batch one order per AJAX request and renders live progress.
 */
jQuery(document).ready(function ($) {
	var cfg = window.wc_sf_bulk || {};
	var i18n = cfg.i18n || {};

	function t(key) {
		return i18n[key] || key;
	}

	function sprintf(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return text.replace(/%(\d+\$)?[ds]/g, function (match, position) {
			var index = position ? parseInt(position, 10) - 1 : i++;
			return args[index];
		});
	}

	/* 1. Confirmation before submitting one of our bulk actions. */
	$('form').on('submit', function (e) {
		var $form = $(this);
		var action = $form.find('select[name="action"]').val();
		var action2 = $form.find('select[name="action2"]').val();
		var selected = (cfg.actions || []).indexOf(action) !== -1 ? action : ((cfg.actions || []).indexOf(action2) !== -1 ? action2 : null);
		if (!selected) {
			return;
		}

		var count = $form.find('input[name="post[]"]:checked, input[name="id[]"]:checked').length;
		if (count > cfg.max_orders) {
			e.preventDefault();
			window.alert(sprintf(t('too_many'), cfg.max_orders));
			return;
		}

		if (!window.confirm(sprintf(t('confirm'), count))) {
			e.preventDefault();
		}
	});

	/* 2. Batch processing. */
	var $notice = $('.wc-sf-bulk-notice');
	if (!$notice.length || !cfg.batch) {
		return;
	}

	var batch = cfg.batch;
	var running = false;
	var stopRequested = false;
	var resumeRequested = false;

	var chipClass = {
		created: 'wc-sf-bulk-chip--ok',
		regenerated: 'wc-sf-bulk-chip--ok',
		failed: 'wc-sf-bulk-chip--err'
	};

	function setMessage(text) {
		$notice.find('.wc-sf-bulk-message').text(text).prop('hidden', !text);
	}

	function render(state) {
		// The visual state: a batch stored as "running" that this page is not processing was interrupted.
		var visual = state.state;
		if (visual === 'running' && !running) {
			visual = 'idle';
		}
		$notice
			.removeClass('wc-sf-bulk-notice--running wc-sf-bulk-notice--paused wc-sf-bulk-notice--done wc-sf-bulk-notice--idle')
			.addClass('wc-sf-bulk-notice--' + visual);

		var title = { running: 'title_running', paused: 'title_paused', done: 'title_done', idle: 'title_unfinished' }[visual];
		$notice.find('.wc-sf-bulk-title').text(t(title));

		var $icon = $notice.find('.wc-sf-bulk-icon').empty();
		if (visual === 'running') {
			$('<span class="wc-sf-bulk-spinner">').appendTo($icon);
		} else {
			$('<span class="dashicons">').addClass({ paused: 'dashicons-controls-pause', done: 'dashicons-yes', idle: 'dashicons-clock' }[visual]).appendTo($icon);
		}

		var progress = sprintf(t('progress'), state.processed, state.total);
		$notice.find('.wc-sf-bulk-count').empty().attr('title', progress)
			.append($('<b>').text(state.processed)).append(document.createTextNode(' / ' + state.total));
		$notice.find('.wc-sf-bulk-bar').attr({ role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': state.total, 'aria-valuenow': state.processed, 'aria-label': progress });
		$notice.find('.wc-sf-bulk-bar-fill').css('width', state.total ? Math.round((state.processed / state.total) * 100) + '%' : '0');

		var $summary = $notice.find('.wc-sf-bulk-summary').empty();
		$.each(['created', 'regenerated', 'skipped_exists', 'skipped_no_document', 'skipped_not_allowed', 'skipped_status', 'failed'], function (_, key) {
			if (state.counts && state.counts[key]) {
				$('<li class="wc-sf-bulk-chip">').addClass(chipClass[key] || '')
					.append($('<b>').text(state.counts[key])).append(document.createTextNode(' ' + t(key)))
					.appendTo($summary);
			}
		});

		var $failed = $notice.find('.wc-sf-bulk-failed').empty();
		if (state.failed_orders && state.failed_orders.length) {
			$failed.append(document.createTextNode(t('failed_orders') + ' '));
			$.each(state.failed_orders, function (index, failed) {
				var number = typeof failed === 'string' ? failed : failed.number;
				var url = typeof failed === 'string' ? '' : failed.url;
				if (index) {
					$failed.append(document.createTextNode(', '));
				}
				$failed.append(url ? $('<a>').attr('href', url).text('#' + number) : document.createTextNode('#' + number));
			});
			$failed.append(document.createTextNode('. ')).append($('<span>').html(t('api_log')));
		}
		$failed.prop('hidden', !state.failed_orders || !state.failed_orders.length);

		setMessage(visual === 'paused' ? t(state.pause_reason === 'quota' ? 'paused_quota' : 'paused') : '');

		var $buttons = $notice.find('.wc-sf-bulk-buttons').empty();
		if (visual === 'done') {
			$('<button type="button" class="button">').text(t('dismiss')).on('click', cancel).appendTo($buttons);
		} else if (visual === 'running') {
			$('<button type="button" class="button button-link button-link-delete">').text(t('cancel')).on('click', function () { stopRequested = true; cancel(); }).appendTo($buttons);
		} else {
			$('<button type="button" class="button button-primary">').text(t('continue')).on('click', function () { resumeRequested = true; start(); }).appendTo($buttons);
			$('<button type="button" class="button button-link button-link-delete">').text(t('cancel')).on('click', cancel).appendTo($buttons);
		}
	}

	function step() {
		if (stopRequested) {
			return;
		}
		var resume = resumeRequested ? 1 : 0;
		resumeRequested = false;
		$.post(cfg.ajax_url, { action: 'wc_sf_bulk_step', nonce: cfg.nonce, batch_id: batch.id, resume: resume })
			.done(function (response) {
				if (!response || !response.success) {
					running = false;
					render(batch);
					setMessage(t('request_failed'));
					return;
				}
				batch = response.data;
				if (batch.state === 'running') {
					render(batch);
					step();
				} else {
					running = false;
					render(batch);
				}
			})
			.fail(function (xhr) {
				var code = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.code;
				if (code === 'busy') {
					// Another tab is processing this batch; poll it instead of competing.
					window.setTimeout(step, 1500);
					return;
				}
				if (code === 'stale') {
					// Cancelled or replaced elsewhere: show whatever the server has now.
					window.location.reload();
					return;
				}
				running = false;
				render(batch);
				setMessage(t('request_failed'));
			});
	}

	function start() {
		if (running) {
			return;
		}
		running = true;
		stopRequested = false;
		batch.state = 'running';
		render(batch);
		step();
	}

	function cancel() {
		$.post(cfg.ajax_url, { action: 'wc_sf_bulk_cancel', nonce: cfg.nonce, batch_id: batch.id }).always(function () {
			$notice.remove();
		});
	}

	if (batch.state === 'running' && batch.autostart) {
		start();
	} else {
		render(batch);
	}
});
