/* Plain JS admin settings page - no build step, ships as-is. */
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
		(children || []).forEach(function (child) {
			node.appendChild(child);
		});
		return node;
	}

	function section(title) {
		var wrap = el('div', { class: 'birthdayreminder-section' });
		wrap.appendChild(el('h3', { text: title }));
		return wrap;
	}

	function showError(err) {
		console.error('[birthdayreminder]', err);
	}

	// ---- Address book -------------------------------------------------

	function renderAddressBook(root) {
		var wrap = section(t('Adressbuch'));
		var ownerInput = el('input', { type: 'text', placeholder: t('Benutzername des Adressbuch-Besitzers') });
		var loadButton = el('button', { class: 'button', text: t('Adressbücher laden') });
		var select = el('select');
		select.appendChild(el('option', { value: '', text: t('- bitte laden -') }));
		var saveButton = el('button', { class: 'button primary', text: t('Speichern') });
		var status = el('span', { class: 'birthdayreminder-status' });

		function fillBooks(books, currentId) {
			select.innerHTML = '';
			books.forEach(function (book) {
				var opt = el('option', { value: String(book.id), text: book.displayName + ' (' + book.uri + ')' });
				if (currentId !== null && Number(book.id) === Number(currentId)) {
					opt.selected = true;
				}
				select.appendChild(opt);
			});
		}

		api('GET', '/admin/addressbooks').then(function (data) {
			if (data.currentOwner) {
				ownerInput.value = data.currentOwner;
			}
			fillBooks(data.books, data.currentId);
			if (data.books.length === 0 && data.currentOwner) {
				status.textContent = t('Kein Adressbuch geladen - "Adressbücher laden" klicken.');
			}
		}).catch(showError);

		loadButton.addEventListener('click', function () {
			var owner = ownerInput.value.trim();
			if (!owner) {
				return;
			}
			status.textContent = t('Lade …');
			api('GET', '/admin/addressbooks?owner=' + encodeURIComponent(owner)).then(function (data) {
				fillBooks(data.books, null);
				status.textContent = data.books.length + ' ' + t('Adressbuch/-bücher gefunden.');
			}).catch(function (err) {
				status.textContent = String(err.message || err);
			});
		});

		saveButton.addEventListener('click', function () {
			var owner = ownerInput.value.trim();
			var id = Number(select.value);
			if (!owner || !id) {
				status.textContent = t('Bitte Besitzer eingeben und Adressbuch auswählen.');
				return;
			}
			api('POST', '/admin/addressbook', { owner: owner, addressBookId: id }).then(function () {
				status.textContent = t('Gespeichert.');
			}).catch(function (err) {
				status.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [ownerInput, loadButton]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [select, saveButton, status]));
		root.appendChild(wrap);
	}

	// ---- Recipients -----------------------------------------------------

	function renderRecipients(root) {
		var wrap = section(t('Empfänger und Vorlauftage'));
		var table = el('table', { class: 'birthdayreminder-table' });
		var status = el('span', { class: 'birthdayreminder-status' });
		wrap.appendChild(table);

		var typeSelect = el('select');
		[['user', t('Nextcloud-Nutzer')], ['group', t('Gruppe')], ['email', t('E-Mail-Adresse')]].forEach(function (o) {
			typeSelect.appendChild(el('option', { value: o[0], text: o[1] }));
		});
		var valueInput = el('input', { type: 'text', placeholder: t('NC-Benutzer-ID / Gruppen-ID / E-Mail') });
		var offsetsInput = el('input', { type: 'text', placeholder: t('Tage vorher, z.B. 30,14,2,1,0'), value: '30,14,2,1,0' });
		var onlyMilestonesCheckbox = el('input', { type: 'checkbox', id: 'br-new-only-milestones' });
		var addButton = el('button', { class: 'button primary', text: t('Hinzufügen') });

		function parseOffsets(str) {
			return str.split(',').map(function (s) { return parseInt(s.trim(), 10); }).filter(function (n) { return !isNaN(n); });
		}

		function load() {
			api('GET', '/admin/recipients').then(function (recipients) {
				renderTable(recipients);
			}).catch(showError);
		}

		function renderTable(recipients) {
			table.innerHTML = '';
			var head = el('tr', {}, [
				el('th', { text: t('Typ') }),
				el('th', { text: t('Wert') }),
				el('th', { text: t('Tage vorher') }),
				el('th', { text: t('Nur runde Geburtstage') }),
				el('th', { text: '' }),
			]);
			table.appendChild(head);

			recipients.forEach(function (r) {
				var offsetsField = el('input', { type: 'text', value: r.offsets.join(',') });
				var milestoneCheckbox = el('input', { type: 'checkbox' });
				milestoneCheckbox.checked = !!r.onlyMilestones;

				var saveRowButton = el('button', { class: 'button', text: t('Speichern') });
				saveRowButton.addEventListener('click', function () {
					api('POST', '/admin/recipients', {
						id: r.id,
						type: r.type,
						value: r.value,
						onlyMilestones: milestoneCheckbox.checked,
						offsets: parseOffsets(offsetsField.value),
					}).then(load).catch(showError);
				});

				var deleteButton = el('button', { class: 'button', text: t('Entfernen') });
				deleteButton.addEventListener('click', function () {
					api('DELETE', '/admin/recipients/' + r.id).then(load).catch(showError);
				});

				table.appendChild(el('tr', {}, [
					el('td', { text: r.type }),
					el('td', { text: r.value }),
					el('td', {}, [offsetsField]),
					el('td', {}, [milestoneCheckbox]),
					el('td', {}, [saveRowButton, deleteButton]),
				]));
			});

			var newRow = el('tr', {}, [
				el('td', {}, [typeSelect]),
				el('td', {}, [valueInput]),
				el('td', {}, [offsetsInput]),
				el('td', {}, [onlyMilestonesCheckbox]),
				el('td', {}, [addButton]),
			]);
			table.appendChild(newRow);
		}

		addButton.addEventListener('click', function () {
			var value = valueInput.value.trim();
			if (!value) {
				return;
			}
			api('POST', '/admin/recipients', {
				id: null,
				type: typeSelect.value,
				value: value,
				onlyMilestones: onlyMilestonesCheckbox.checked,
				offsets: parseOffsets(offsetsInput.value),
			}).then(function () {
				valueInput.value = '';
				load();
			}).catch(function (err) {
				status.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(status);
		root.appendChild(wrap);
		load();
	}

	// ---- Milestones -------------------------------------------------------

	function renderMilestones(root) {
		var wrap = section(t('Runde Geburtstage / Geschenke'));
		var table = el('table', { class: 'birthdayreminder-table' });
		wrap.appendChild(table);

		var ageInput = el('input', { type: 'number', placeholder: t('Alter'), style: 'width:6em' });
		var giftInput = el('input', { type: 'text', placeholder: t('Geschenkvorschlag') });
		var addButton = el('button', { class: 'button primary', text: t('Hinzufügen') });

		function load() {
			api('GET', '/admin/milestones').then(renderTable).catch(showError);
		}

		function renderTable(milestones) {
			table.innerHTML = '';
			table.appendChild(el('tr', {}, [
				el('th', { text: t('Alter') }),
				el('th', { text: t('Geschenkvorschlag') }),
				el('th', { text: '' }),
			]));

			milestones.forEach(function (m) {
				var giftField = el('input', { type: 'text', value: m.giftText });
				var saveButton = el('button', { class: 'button', text: t('Speichern') });
				saveButton.addEventListener('click', function () {
					api('POST', '/admin/milestones', { id: m.id, age: m.age, giftText: giftField.value }).then(load).catch(showError);
				});
				var deleteButton = el('button', { class: 'button', text: t('Entfernen') });
				deleteButton.addEventListener('click', function () {
					api('DELETE', '/admin/milestones/' + m.id).then(load).catch(showError);
				});
				table.appendChild(el('tr', {}, [
					el('td', { text: String(m.age) }),
					el('td', {}, [giftField]),
					el('td', {}, [saveButton, deleteButton]),
				]));
			});

			table.appendChild(el('tr', {}, [
				el('td', {}, [ageInput]),
				el('td', {}, [giftInput]),
				el('td', {}, [addButton]),
			]));
		}

		addButton.addEventListener('click', function () {
			var age = parseInt(ageInput.value, 10);
			if (!age) {
				return;
			}
			api('POST', '/admin/milestones', { id: null, age: age, giftText: giftInput.value }).then(function () {
				ageInput.value = '';
				giftInput.value = '';
				load();
			}).catch(showError);
		});

		root.appendChild(wrap);
		load();
	}

	// ---- Congrats mail template --------------------------------------

	function renderCongratsTemplate(root) {
		var wrap = section(t('Glückwunsch-Mail an das Mitglied'));
		wrap.appendChild(el('p', { text: t('Platzhalter: {name}, {vorname}, {alter}, {datum}') }));

		var subjectInput = el('input', { type: 'text', style: 'width:100%' });
		var bodyInput = el('textarea', { rows: '6', style: 'width:100%' });
		var saveButton = el('button', { class: 'button primary', text: t('Speichern') });
		var status = el('span', { class: 'birthdayreminder-status' });

		api('GET', '/admin/congrats-template').then(function (data) {
			subjectInput.value = data.subject;
			bodyInput.value = data.body;
		}).catch(showError);

		saveButton.addEventListener('click', function () {
			api('POST', '/admin/congrats-template', { subject: subjectInput.value, body: bodyInput.value }).then(function () {
				status.textContent = t('Gespeichert.');
			}).catch(function (err) {
				status.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(el('label', { text: t('Betreff') }));
		wrap.appendChild(subjectInput);
		wrap.appendChild(el('label', { text: t('Text') }));
		wrap.appendChild(bodyInput);
		wrap.appendChild(el('div', {}, [saveButton, status]));
		root.appendChild(wrap);
	}

	function t(text) {
		return (window.t && window.OC) ? OC.L10N.translate('birthdayreminder', text) : text;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('birthdayreminder-admin-settings');
		if (!root) {
			return;
		}
		renderAddressBook(root);
		renderRecipients(root);
		renderMilestones(root);
		renderCongratsTemplate(root);
	});
})();
