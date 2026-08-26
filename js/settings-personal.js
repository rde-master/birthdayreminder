/* Plain JS personal settings page - no build step, ships as-is. */
(function () {
	'use strict';

	function requestToken() {
		var head = document.head.querySelector('[data-requesttoken]');
		return head ? head.dataset.requesttoken : (window.OC ? OC.requestToken : '');
	}

	function url(path) {
		return OC.generateUrl('/apps/birthdayreminder' + path);
	}

	function api(method, path, body) {
		return fetch(url(path), {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken(),
			},
			body: body !== undefined ? JSON.stringify(body) : undefined,
		}).then(function (res) {
			if (!res.ok) {
				return res.json().catch(function () { return {}; }).then(function (data) {
					throw new Error(data.error || ('HTTP ' + res.status));
				});
			}
			return res.status === 204 ? null : res.json();
		});
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key.indexOf('on') === 0) {
				node.addEventListener(key.slice(2), attrs[key]);
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) { node.appendChild(child); });
		return node;
	}

	function t(text) {
		return text;
	}

	function parseOffsets(str) {
		return str.split(',').map(function (s) { return parseInt(s.trim(), 10); }).filter(function (n) { return !isNaN(n); });
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('birthdayreminder-personal-settings');
		if (!root) {
			return;
		}

		var wrap = el('div', { class: 'birthdayreminder-section' });
		wrap.appendChild(el('h3', { text: t('Geburtstagserinnerung') }));
		wrap.appendChild(el('p', { text: t('Zu welchen Vorlaufzeiten möchtest du an Geburtstage von Vereinsmitgliedern erinnert werden?') }));

		var offsetsInput = el('input', { type: 'text', placeholder: t('Tage vorher, z.B. 30,14,2,1,0'), style: 'width:100%;max-width:20em' });
		var onlyMilestonesCheckbox = el('input', { type: 'checkbox', id: 'br-personal-only-milestones' });
		var onlyMilestonesLabel = el('label', { for: 'br-personal-only-milestones', text: t('Nur bei runden Geburtstagen erinnern') });
		var saveButton = el('button', { class: 'button primary', text: t('Speichern') });
		var status = el('span', { class: 'birthdayreminder-status' });

		api('GET', '/personal/settings').then(function (data) {
			offsetsInput.value = data.offsets.join(',');
			onlyMilestonesCheckbox.checked = !!data.onlyMilestones;
		});

		saveButton.addEventListener('click', function () {
			api('POST', '/personal/settings', {
				onlyMilestones: onlyMilestonesCheckbox.checked,
				offsets: parseOffsets(offsetsInput.value),
			}).then(function () {
				status.textContent = t('Gespeichert.');
			}).catch(function (err) {
				status.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [offsetsInput]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [onlyMilestonesCheckbox, onlyMilestonesLabel]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [saveButton, status]));
		root.appendChild(wrap);
	});
})();
