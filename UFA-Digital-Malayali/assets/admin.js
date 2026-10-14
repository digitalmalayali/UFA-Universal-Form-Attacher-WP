/* global jQuery, FTGSAdmin */
jQuery(function ($) {
	'use strict';
	var t = FTGSAdmin.i18n;

	function msg(data, fallback) {
		return data && data.data && data.data.message ? data.data.message : fallback;
	}

	/* Send test row -------------------------------------------------- */
	$(document).on('click', '.ftgs-test', function () {
		var $btn = $(this), id = $btn.data('id');
		var $out = $('.ftgs-test-result[data-for="' + id + '"]');
		$btn.prop('disabled', true);
		$out.removeClass('ok err').text(t.sending);
		$.post(FTGSAdmin.ajax, { action: 'ftgs_test', id: id, nonce: FTGSAdmin.nonce })
			.done(function (r) {
				$out.addClass(r && r.success ? 'ok' : 'err').text((r && r.success ? '✓ ' : '✗ ') + msg(r, ''));
			})
			.fail(function (x) { $out.addClass('err').text('✗ ' + msg(x.responseJSON, t.failed)); })
			.always(function () { $btn.prop('disabled', false); });
	});

	/* Confirmations --------------------------------------------------- */
	$(document).on('click', '.ftgs-delete', function (e) {
		if (!window.confirm(t.confirmDel)) { e.preventDefault(); }
	});
	$(document).on('click', '.ftgs-subs-delete', function (e) {
		if (!window.confirm(t.confirmSubs)) { e.preventDefault(); }
	});
	$('#ftgs-check-all').on('change', function () {
		$('#ftgs-subs-form input[name="ids[]"]').prop('checked', this.checked);
	});

	/* Copy script ----------------------------------------------------- */
	$(document).on('click', '.ftgs-copy', function () {
		var $btn = $(this), el = $($btn.data('target'))[0], label = $btn.text();
		var done = function () { $btn.text(t.copied); setTimeout(function () { $btn.text(label); }, 1500); };
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(el.value).then(done, function () { el.select(); document.execCommand('copy'); done(); });
		} else {
			el.select(); document.execCommand('copy'); done();
		}
	});

	/* Secret: regenerate and keep the script preview in sync ------------ */
	var $secret = $('#ftgs-secret'), $code = $('#ftgs-code');
	if ($code.length) {
		var template = $code.val(), shown = String($code.data('secret'));
		var syncCode = function () {
			var s = $secret.val().replace(/[^A-Za-z0-9_\-]/g, '');
			$code.val(template.split("'" + shown + "'").join("'" + s + "'"));
		};
		$('#ftgs-regen').on('click', function () {
			var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', s = '';
			var arr = new Uint32Array(24);
			window.crypto.getRandomValues(arr);
			for (var i = 0; i < 24; i++) { s += chars[arr[i] % chars.length]; }
			$secret.val(s).trigger('input');
		});
		$secret.on('input', syncCode);
	}

	/* Form picker ------------------------------------------------------ */
	var $picker = $('#ftgs-form-picker'), $source = $('#ftgs-source'), $formId = $('#ftgs-form-id');
	if ($picker.length) {
		var forms = $picker.data('forms') || {}, help = $picker.data('help') || {};
		var current = function () {
			return $formId.val().split(',').map(function (s) { return s.trim(); }).filter(Boolean);
		};
		var render = function () {
			var src = $source.val(), list = forms[src] || {}, ids = current();
			$('.ftgs-forms-row').toggle(src !== 'any');
			$('#ftgs-source-help').text(help[src] || '');
			$picker.empty();
			var keys = Object.keys(list);
			if (!keys.length) { return; }
			var all = ids.length === 0 || ids.indexOf('*') !== -1;
			$picker.append($('<button type="button" class="ftgs-chip" data-id="*"></button>').text(t.allForms).toggleClass('sel', all));
			keys.forEach(function (k) {
				$picker.append(
					$('<button type="button" class="ftgs-chip"></button>')
						.attr('data-id', k)
						.attr('title', k)
						.text(list[k] || k)
						.toggleClass('sel', !all && ids.indexOf(String(k)) !== -1)
				);
			});
		};
		$picker.on('click', '.ftgs-chip', function () {
			var id = String($(this).attr('data-id')), ids = current().filter(function (x) { return x !== '*'; });
			if (id === '*') { ids = []; }
			else if (ids.indexOf(id) !== -1) { ids.splice(ids.indexOf(id), 1); }
			else { ids.push(id); }
			$formId.val(ids.length ? ids.join(', ') : '*');
			render();
		});
		$source.on('change', function () { $formId.val('*'); render(); });
		$formId.on('input', render);
		render();
	}

	/* Unsaved changes guard for sync ------------------------------------ */
	var dirty = false;
	$('#ftgs-edit-form').on('change input', ':input', function () { dirty = true; }).on('submit', function () { dirty = false; });

	/* Sync existing entries ---------------------------------------------- */
	var $sync = $('#ftgs-sync');
	if ($sync.length) {
		var running = false, totals;
		var $status = $('#ftgs-sync-status'), $bar = $sync.find('.ftgs-progress'), $fill = $sync.find('.ftgs-progress-bar');
		var $start = $('#ftgs-sync-start'), $stop = $('#ftgs-sync-stop');
		var cursor = { offset: 0, after: 0 };
		var lastKey = '';

		var summary = function () {
			return totals.processed + ' checked · ' + totals.added + ' added · ' + totals.skipped + ' already in sheet' + (totals.filtered ? ' · ' + totals.filtered + ' did not match conditions' : '');
		};
		var finish = function (text, cls) {
			running = false;
			$start.prop('disabled', false);
			$stop.prop('hidden', true);
			$status.attr('class', 'ftgs-sync-status ' + (cls || '')).text(text);
		};
		var step = function () {
			if (!running) { finish(t.stopped + ' ' + summary() + '. ' + t.resume, 'err'); return; }
			$.post(FTGSAdmin.ajax, {
				action: 'ftgs_sync_step', nonce: FTGSAdmin.nonce, id: $sync.data('id'),
				mode: $('#ftgs-sync-mode').val(), from: $('#ftgs-sync-from').val(), to: $('#ftgs-sync-to').val(),
				offset: cursor.offset, after: cursor.after
			}).done(function (r) {
				if (!r || !r.success) {
					finish(t.stopped + ' ' + msg(r, t.failed) + ' — ' + t.resume, 'err');
					return;
				}
				var d = r.data;
				totals.processed += d.processed; totals.added += d.added; totals.skipped += d.skipped; totals.filtered += d.filtered;
				cursor.offset = d.offset; cursor.after = d.after;
				$fill.css('width', d.done ? '100%' : Math.min(95, 5 + totals.processed / 10) + '%');
				$status.attr('class', 'ftgs-sync-status').text(t.syncing + ' ' + summary());
				if (d.done) {
					cursor = { offset: 0, after: 0 };
					lastKey = '';
					finish('✓ ' + t.syncDone + ' ' + summary(), 'ok');
				} else {
					step();
				}
			}).fail(function (x) {
				finish(t.stopped + ' ' + msg(x.responseJSON, t.failed + ' (HTTP ' + x.status + ')') + ' — ' + t.resume, 'err');
			});
		};
		$start.on('click', function () {
			if (dirty) { window.alert(t.saveFirst); return; }
			var key = [$('#ftgs-sync-mode').val(), $('#ftgs-sync-from').val(), $('#ftgs-sync-to').val()].join('|');
			if (key !== lastKey) { cursor = { offset: 0, after: 0 }; lastKey = key; }
			totals = { processed: 0, added: 0, skipped: 0, filtered: 0 };
			running = true;
			$start.prop('disabled', true);
			$stop.prop('hidden', false);
			$bar.prop('hidden', false);
			$fill.css('width', '3%');
			$status.attr('class', 'ftgs-sync-status').text(t.syncing);
			step();
		});
		$stop.on('click', function () { running = false; });
	}
});
