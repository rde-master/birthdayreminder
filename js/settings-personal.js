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

	// ---- Reusable: offset ("Vorlauftage") chip editor -------------------

	function createOffsetEditor(initialOffsets) {
		var offsets = initialOffsets.slice().sort(function (a, b) { return a - b; });
		var wrap = el('div', { class: 'birthdayreminder-offset-editor' });
		var chips = el('div', { class: 'birthdayreminder-offset-chips' });
		var dayInput = el('input', { type: 'number', min: '0', class: 'birthdayreminder-day-input', placeholder: t('Tage') });
		var addBtn = el('button', { type: 'button', class: 'button', text: t('+ Tag hinzufügen') });

		function label(days) {
			return days === 0 ? t('am Tag selbst') : days + ' ' + t('Tage vorher');
		}

		function render() {
			chips.innerHTML = '';
			if (offsets.length === 0) {
				chips.appendChild(el('span', { class: 'birthdayreminder-status', text: t('Keine Vorlaufzeiten eingetragen') }));
			}
			offsets.forEach(function (days) {
				var removeBtn = el('button', { type: 'button', class: 'birthdayreminder-chip-remove', text: '×', title: t('Entfernen') });
				removeBtn.addEventListener('click', function () {
					offsets = offsets.filter(function (d) { return d !== days; });
					render();
				});
				chips.appendChild(el('span', { class: 'birthdayreminder-chip' }, [
					document.createTextNode(label(days)),
					removeBtn,
				]));
			});
		}

		function addFromInput() {
			var val = parseInt(dayInput.value, 10);
			if (isNaN(val) || val < 0) {
				return;
			}
			if (offsets.indexOf(val) === -1) {
				offsets.push(val);
				offsets.sort(function (a, b) { return a - b; });
				render();
			}
			dayInput.value = '';
		}

		addBtn.addEventListener('click', addFromInput);
		dayInput.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') {
				ev.preventDefault();
				addFromInput();
			}
		});

		render();
		wrap.appendChild(chips);
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [dayInput, addBtn]));

		return { node: wrap, getOffsets: function () { return offsets.slice(); } };
	}

	// ---- Reusable: "alle Geburtstage" / "nur runde" dropdown -----------

	function createMilestoneSelect(onlyMilestones) {
		var select = el('select');
		select.appendChild(el('option', { value: 'all', text: t('Alle Geburtstage') }));
		select.appendChild(el('option', { value: 'milestones', text: t('Nur runde Geburtstage') }));
		select.value = onlyMilestones ? 'milestones' : 'all';
		return select;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('birthdayreminder-personal-settings');
		if (!root) {
			return;
		}

		var wrap = el('div', { class: 'birthdayreminder-section' });
		wrap.appendChild(el('h3', { text: t('Geburtstagserinnerung') }));
		wrap.appendChild(el('p', { text: t('Lege fest, wann du per E-Mail an Geburtstage von Mitgliedern erinnert werden möchtest.') }));
		root.appendChild(wrap);

		var loadingNote = el('span', { class: 'birthdayreminder-status', text: t('Lade …') });
		wrap.appendChild(loadingNote);

		api('GET', '/personal/settings').then(function (data) {
			loadingNote.remove();

			var offsetEditor = createOffsetEditor(data.offsets);
			var milestoneSelect = createMilestoneSelect(data.onlyMilestones);
			var saveButton = el('button', { class: 'button primary', text: t('Speichern') });
			var status = el('span', { class: 'birthdayreminder-status' });

			saveButton.addEventListener('click', function () {
				api('POST', '/personal/settings', {
					onlyMilestones: milestoneSelect.value === 'milestones',
					offsets: offsetEditor.getOffsets(),
				}).then(function () {
					status.textContent = t('Gespeichert.');
				}).catch(function (err) {
					status.textContent = String(err.message || err);
				});
			});

			wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('Vorlaufzeiten') }));
			wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [offsetEditor.node]));

			wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('Umfang') }));
			wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [milestoneSelect]));
			wrap.appendChild(el('p', { class: 'birthdayreminder-hint', text: t('"Nur runde Geburtstage" beschränkt die Erinnerung auf die in den Admin-Einstellungen hinterlegten Jubiläumsalter (z.B. 18, 30, 50).') }));
			wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [saveButton, status]));
		}).catch(function (err) {
			loadingNote.textContent = String(err.message || err);
		});
	});
})();
