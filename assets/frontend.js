/* Forms to Google Sheets – mirrors plain HTML form submissions to the site's REST endpoint. */
(function () {
	'use strict';
	if (typeof window.FTGS === 'undefined' || !window.fetch || !window.JSON) { return; }

	var loadedAt = Date.now();
	var SKIP_TYPES = ['password', 'submit', 'button', 'reset', 'image'];

	function cssEscape(s) {
		return (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
	}

	function labelFor(form, el) {
		var text = el.getAttribute('data-label') || '';
		if (!text && el.id) {
			var l = form.querySelector('label[for="' + cssEscape(el.id) + '"]');
			if (l) { text = l.textContent; }
		}
		if (!text && el.closest) {
			var parent = el.closest('label');
			if (parent) { text = parent.textContent; }
		}
		text = text || el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.name;
		return String(text).replace(/\s+/g, ' ').replace(/[*:]\s*$/, '').trim();
	}

	function collect(form) {
		var map = {}, order = [];
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el.name || el.disabled || SKIP_TYPES.indexOf(el.type) !== -1) { return; }
			if (el.hasAttribute('data-ftgs-ignore') || el.name === 'ftgs_hp') { return; }
			var name = el.name.replace(/\[\]$/, '');
			if (!map[name]) {
				var isChoice = el.type === 'checkbox' || el.type === 'radio';
				var lbl = isChoice ? (el.getAttribute('data-label') || (function () {
					var fs = el.closest && el.closest('fieldset');
					var lg = fs && fs.querySelector('legend');
					return lg ? lg.textContent.replace(/\s+/g, ' ').trim() : name;
				})()) : labelFor(form, el);
				map[name] = { key: name, label: lbl, value: [] };
				order.push(name);
			}
			if (el.type === 'checkbox' || el.type === 'radio') {
				if (el.checked) { map[name].value.push(el.value); }
			} else if (el.type === 'file') {
				Array.prototype.forEach.call(el.files || [], function (f) { map[name].value.push(f.name); });
			} else if (el.tagName === 'SELECT' && el.multiple) {
				Array.prototype.forEach.call(el.options, function (o) { if (o.selected) { map[name].value.push(o.value); } });
			} else {
				map[name].value.push(el.value);
			}
		});
		return order.map(function (k) {
			var f = map[k];
			f.value = f.value.length === 1 ? f.value[0] : f.value;
			return f;
		});
	}

	function send(form) {
		var hp = form.querySelector('[name="ftgs_hp"]');
		var body = JSON.stringify({
			form: form.getAttribute('data-ftgs-form') || form.getAttribute('id') || form.getAttribute('name') || 'html-form',
			page: window.location.href,
			hp: hp ? hp.value : '',
			elapsed: Date.now() - loadedAt,
			fields: collect(form)
		});
		try {
			fetch(window.FTGS.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function () {});
		} catch (e) { /* never block the real submission */ }
	}

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || !form.matches || !form.matches(window.FTGS.selector)) { return; }
		if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }
		send(form);
	}, true);

	// Manual trigger for JavaScript-driven forms: window.FTGSsend(formElement)
	window.FTGSsend = send;
})();
