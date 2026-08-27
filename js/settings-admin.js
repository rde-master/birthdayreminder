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

	// ---- Reusable: offset ("Vorlauftage") chip editor ------------------

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

	// ---- Recipients -----------------------------------------------------

	var RECIPIENT_TYPE_LABELS = {
		user: t('Nextcloud-Nutzer'),
		group: t('Gruppe'),
		email: t('E-Mail-Adresse'),
	};

	function renderRecipients(root) {
		var wrap = section(t('Empfänger und Vorlauftage'));
		wrap.appendChild(el('p', {
			text: t('Hier legst du zentral für alle Nutzer fest, wer zu welchen Vorlaufzeiten Erinnerungs-Mails bekommt. Das gilt auch für einzelne Nextcloud-Nutzer - du musst nicht warten, bis sie es sich selbst unter "Persönliche Einstellungen" einrichten.'),
		}));

		var tableWrap = el('div', { class: 'birthdayreminder-table-wrap' });
		var table = el('table', { class: 'birthdayreminder-table birthdayreminder-recipients-table' });
		tableWrap.appendChild(table);
		wrap.appendChild(tableWrap);
		wrap.appendChild(el('p', {
			class: 'birthdayreminder-hint',
			text: t('Typ: Ob der Empfänger ein einzelner Nextcloud-Nutzer, eine ganze Nextcloud-Gruppe (alle Mitglieder bekommen die Mail) oder eine feste E-Mail-Adresse (z.B. für Personen ohne eigenes Nextcloud-Konto) ist.'),
		}));

		var addStatus = el('span', { class: 'birthdayreminder-status' });

		var typeSelect = el('select');
		Object.keys(RECIPIENT_TYPE_LABELS).forEach(function (key) {
			typeSelect.appendChild(el('option', { value: key, text: RECIPIENT_TYPE_LABELS[key] }));
		});
		var valueInput = el('input', { type: 'text', class: 'birthdayreminder-value-input', placeholder: t('NC-Benutzer-ID / Gruppen-ID / E-Mail') });
		var newOffsetEditor = createOffsetEditor([30, 14, 2, 1, 0]);
		var newMilestoneSelect = createMilestoneSelect(false);
		var addButton = el('button', { type: 'button', class: 'button primary', text: t('Hinzufügen') });

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
				el('th', { text: t('Vorlaufzeiten') }),
				el('th', { text: t('Umfang') }),
				el('th', { text: t('Aktionen') }),
			]);
			table.appendChild(head);

			if (recipients.length === 0) {
				table.appendChild(el('tr', {}, [
					el('td', { colspan: '5', class: 'birthdayreminder-status', text: t('Noch keine Empfänger eingetragen.') }),
				]));
			}

			recipients.forEach(function (r) {
				var offsetEditor = createOffsetEditor(r.offsets);
				var milestoneSelect = createMilestoneSelect(r.onlyMilestones);

				var saveRowButton = el('button', { type: 'button', class: 'button', text: t('Speichern') });
				saveRowButton.addEventListener('click', function () {
					api('POST', '/admin/recipients', {
						id: r.id,
						type: r.type,
						value: r.value,
						onlyMilestones: milestoneSelect.value === 'milestones',
						offsets: offsetEditor.getOffsets(),
					}).then(load).catch(showError);
				});

				var deleteButton = el('button', { type: 'button', class: 'button', text: t('Entfernen') });
				deleteButton.addEventListener('click', function () {
					api('DELETE', '/admin/recipients/' + r.id).then(load).catch(showError);
				});

				table.appendChild(el('tr', {}, [
					el('td', { text: RECIPIENT_TYPE_LABELS[r.type] || r.type }),
					el('td', { text: r.value }),
					el('td', {}, [offsetEditor.node]),
					el('td', {}, [milestoneSelect]),
					el('td', { class: 'birthdayreminder-actions' }, [saveRowButton, deleteButton]),
				]));
			});
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
				onlyMilestones: newMilestoneSelect.value === 'milestones',
				offsets: newOffsetEditor.getOffsets(),
			}).then(function () {
				valueInput.value = '';
				addStatus.textContent = t('Hinzugefügt.');
				load();
			}).catch(function (err) {
				addStatus.textContent = String(err.message || err);
			});
		});

		var addPanel = el('div', { class: 'birthdayreminder-add-panel' });
		addPanel.appendChild(el('h4', { text: t('Neuen Empfänger hinzufügen') }));
		addPanel.appendChild(el('div', { class: 'birthdayreminder-add-grid' }, [
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Typ') }), typeSelect]),
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Wert') }), valueInput]),
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Vorlaufzeiten') }), newOffsetEditor.node]),
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Umfang') }), newMilestoneSelect]),
		]));
		addPanel.appendChild(el('div', { class: 'birthdayreminder-row' }, [addButton, addStatus]));
		wrap.appendChild(addPanel);

		root.appendChild(wrap);
		load();
	}

	// ---- Milestones -------------------------------------------------------

	function renderMilestones(root) {
		var wrap = section(t('Runde Geburtstage / Geschenke'));
		var table = el('table', { class: 'birthdayreminder-table birthdayreminder-milestones-table' });
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
		wrap.appendChild(el('p', { text: t('Platzhalter: {name}, {vorname}, {alter}, {datum}, {wochentag}') }));

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

	// ---- Schedule & manual sending -------------------------------------

	function renderSchedule(root) {
		var wrap = section(t('Zeitplan & manueller Versand'));
		wrap.appendChild(el('p', {
			text: t('Zu welcher Uhrzeit die tägliche Prüfung laufen soll (ob heute Erinnerungen oder Glückwünsche fällig sind). Die Prüfung selbst läuft über Nextclouds eigenen Cron und kann sich dadurch um bis zu einer Stunde verzögern.'),
		}));

		var timeInput = el('input', { type: 'time' });
		var saveButton = el('button', { type: 'button', class: 'button primary', text: t('Speichern') });
		var scheduleStatus = el('span', { class: 'birthdayreminder-status' });

		var remindersEnabledCheckbox = el('input', { type: 'checkbox', id: 'birthdayreminder-reminders-enabled' });
		var remindersEnabledLabel = el('label', { for: 'birthdayreminder-reminders-enabled' }, [
			remindersEnabledCheckbox, document.createTextNode(' ' + t('Erinnerungs-Mails an Verantwortliche aktiv')),
		]);
		var congratsEnabledCheckbox = el('input', { type: 'checkbox', id: 'birthdayreminder-congrats-enabled' });
		var congratsEnabledLabel = el('label', { for: 'birthdayreminder-congrats-enabled' }, [
			congratsEnabledCheckbox, document.createTextNode(' ' + t('Glückwunsch-Mails an Mitglieder aktiv')),
		]);

		function loadSchedule() {
			api('GET', '/admin/schedule').then(function (data) {
				timeInput.value = data.dailyRunTime;
				remindersEnabledCheckbox.checked = data.remindersEnabled;
				congratsEnabledCheckbox.checked = data.congratsEnabled;
				scheduleStatus.textContent = data.lastRunDate
					? t('Letzter automatischer Lauf: ') + data.lastRunDate
					: t('Bisher noch kein automatischer Lauf erfolgt.');
			}).catch(showError);
		}

		saveButton.addEventListener('click', function () {
			if (!timeInput.value) {
				return;
			}
			api('POST', '/admin/schedule', {
				dailyRunTime: timeInput.value,
				remindersEnabled: remindersEnabledCheckbox.checked,
				congratsEnabled: congratsEnabledCheckbox.checked,
			}).then(function () {
				scheduleStatus.textContent = t('Gespeichert.');
			}).catch(function (err) {
				scheduleStatus.textContent = String(err.message || err);
			});
		});

		loadSchedule();

		wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('Tägliche Prüfzeit') }));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [timeInput, saveButton]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [scheduleStatus]));

		wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('E-Mail-Versand') }));
		wrap.appendChild(el('p', { class: 'birthdayreminder-hint', text: t('Schaltet jeweils den kompletten Versand ab - automatisch wie manuell -, ohne die hinterlegten Empfänger, Vorlaufzeiten oder Mitglieder zu verändern.') }));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [remindersEnabledLabel]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [congratsEnabledLabel]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [saveButton]));

		wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('Manuell auslösen') }));
		wrap.appendChild(el('p', { class: 'birthdayreminder-hint', text: t('Verschickt sofort, was heute laut Vorlaufzeiten/Geburtstagen fällig ist - unabhängig von der oben eingestellten Uhrzeit. Bereits heute verschickte Mails werden dabei nicht doppelt versendet.') }));

		var remindersButton = el('button', { type: 'button', class: 'button', text: t('Erinnerungen jetzt versenden') });
		var remindersStatus = el('span', { class: 'birthdayreminder-status' });
		remindersButton.addEventListener('click', function () {
			if (!window.confirm(t('Jetzt alle heute fälligen Erinnerungs-Mails an die Verantwortlichen versenden?'))) {
				return;
			}
			remindersStatus.textContent = t('Wird versendet …');
			api('POST', '/admin/trigger-reminders', {}).then(function (res) {
				remindersStatus.textContent = res.skippedDisabled
					? t('Übersprungen: Erinnerungs-Mails sind aktuell deaktiviert.')
					: t('Erledigt.');
			}).catch(function (err) {
				remindersStatus.textContent = String(err.message || err);
			});
		});

		var congratsButton = el('button', { type: 'button', class: 'button', text: t('Glückwünsche jetzt versenden') });
		var congratsStatus = el('span', { class: 'birthdayreminder-status' });
		congratsButton.addEventListener('click', function () {
			if (!window.confirm(t('Jetzt alle heute fälligen Glückwunsch-Mails an Mitglieder versenden?'))) {
				return;
			}
			congratsStatus.textContent = t('Wird versendet …');
			api('POST', '/admin/trigger-congrats', {}).then(function (res) {
				congratsStatus.textContent = res.skippedDisabled
					? t('Übersprungen: Glückwunsch-Mails sind aktuell deaktiviert.')
					: t('Erledigt.');
			}).catch(function (err) {
				congratsStatus.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [remindersButton, remindersStatus]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [congratsButton, congratsStatus]));

		wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('Versand-Log') }));
		wrap.appendChild(el('p', { class: 'birthdayreminder-hint', text: t('Das Versand-Log (einsehbar auf der Mitgliederseite) protokolliert, was bereits verschickt wurde, und verhindert dadurch doppelte Mails am selben Tag. Wird es gelöscht, gilt für den Rest des heutigen Tages nichts mehr als "bereits verschickt" - der nächste automatische oder manuelle Versand könnte dadurch Mails erneut verschicken, die heute schon rausgegangen sind.') }));

		var clearLogButton = el('button', { type: 'button', class: 'button', text: t('Log löschen') });
		var clearLogStatus = el('span', { class: 'birthdayreminder-status' });
		clearLogButton.addEventListener('click', function () {
			if (!window.confirm(t('Versand-Log wirklich vollständig löschen? Dadurch könnten heute bereits verschickte Erinnerungs- oder Glückwunsch-Mails beim nächsten Versand erneut verschickt werden.'))) {
				return;
			}
			clearLogStatus.textContent = t('Wird gelöscht …');
			api('DELETE', '/admin/log').then(function (res) {
				clearLogStatus.textContent = res.deleted + ' ' + t('Einträge gelöscht.');
			}).catch(function (err) {
				clearLogStatus.textContent = String(err.message || err);
			});
		});
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [clearLogButton, clearLogStatus]));

		root.appendChild(wrap);
	}

	function t(text) {
		return (window.t && window.OC) ? OC.L10N.translate('birthdayreminder', text) : text;
	}

	function renderMembersLink(root) {
		var wrap = section(t('Mitgliederregister'));
		wrap.appendChild(el('p', {
			text: t('Die Mitglieder (Vorname, Nachname, Geburtsdatum, E-Mail) werden auf der eigenen Mitgliederseite gepflegt - dort auch Import/Export, Geschenke und Logs.'),
		}));
		var link = el('a', { class: 'button primary', href: OC.generateUrl('/apps/birthdayreminder/'), text: t('Zur Mitgliederseite') });
		wrap.appendChild(link);
		root.appendChild(wrap);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('birthdayreminder-admin-settings');
		if (!root) {
			return;
		}
		renderMembersLink(root);
		renderRecipients(root);
		renderMilestones(root);
		renderCongratsTemplate(root);
		renderSchedule(root);
	});
})();
