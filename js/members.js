/* Plain JS "Mitgliederregister" page - no build step, ships as-is. */
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

	function section(title) {
		var wrap = el('div', { class: 'birthdayreminder-section' });
		wrap.appendChild(el('h3', { text: title }));
		return wrap;
	}

	function showError(err) {
		console.error('[birthdayreminder]', err);
	}

	// ---- Übersicht: today / next 7 days / next 30 days ------------------

	function formatOverviewAge(age) {
		return (age !== null && age !== undefined) ? (' (' + t('wird') + ' ' + age + ')') : ' (' + t('Alter unbekannt') + ')';
	}

	function renderOverviewColumn(title, entries) {
		var col = el('div', { class: 'birthdayreminder-overview-column' });
		col.appendChild(el('h4', { text: title }));
		if (entries.length === 0) {
			col.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Keine Geburtstage.') }));
			return col;
		}
		var list = el('ul', { class: 'birthdayreminder-overview-list' });
		entries.forEach(function (e) {
			list.appendChild(el('li', { text: e.name + ' – ' + e.date + formatOverviewAge(e.age) }));
		});
		col.appendChild(list);
		return col;
	}

	// ---- Übersicht: pie chart of birthdays per month --------------------

	var MONTH_NAMES = [
		t('Januar'), t('Februar'), t('März'), t('April'), t('Mai'), t('Juni'),
		t('Juli'), t('August'), t('September'), t('Oktober'), t('November'), t('Dezember'),
	];

	function monthColor(i) {
		return 'hsl(' + (i * 30) + ', 65%, 55%)';
	}

	function svgEl(tag, attrs) {
		var node = document.createElementNS('http://www.w3.org/2000/svg', tag);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		return node;
	}

	function polarToCartesian(cx, cy, r, angleDeg) {
		var rad = (angleDeg - 90) * Math.PI / 180;
		return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
	}

	function describeArcPath(cx, cy, r, startAngle, endAngle) {
		var start = polarToCartesian(cx, cy, r, startAngle);
		var end = polarToCartesian(cx, cy, r, endAngle);
		var largeArcFlag = (endAngle - startAngle) > 180 ? '1' : '0';
		return ['M', cx, cy, 'L', start.x, start.y, 'A', r, r, 0, largeArcFlag, 1, end.x, end.y, 'Z'].join(' ');
	}

	function renderMonthChart(monthCounts) {
		var wrap = el('div', { class: 'birthdayreminder-chart-container' });
		var total = monthCounts.reduce(function (a, b) { return a + b; }, 0);

		if (total === 0) {
			wrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Keine Mitglieder mit hinterlegtem Geburtsdatum.') }));
			return wrap;
		}

		var size = 220;
		var r = size / 2;
		var svg = svgEl('svg', { viewBox: '0 0 ' + size + ' ' + size, class: 'birthdayreminder-piechart' });

		var monthsWithData = monthCounts.reduce(function (n, c) { return c > 0 ? n + 1 : n; }, 0);
		if (monthsWithData === 1) {
			var onlyIndex = monthCounts.findIndex(function (c) { return c > 0; });
			svg.appendChild(svgEl('circle', { cx: r, cy: r, r: r, fill: monthColor(onlyIndex) }));
		} else {
			var angle = 0;
			monthCounts.forEach(function (count, i) {
				if (count === 0) {
					return;
				}
				var slice = (count / total) * 360;
				svg.appendChild(svgEl('path', { d: describeArcPath(r, r, r, angle, angle + slice), fill: monthColor(i) }));
				angle += slice;
			});
		}
		wrap.appendChild(svg);

		var legend = el('ul', { class: 'birthdayreminder-chart-legend' });
		monthCounts.forEach(function (count, i) {
			if (count === 0) {
				return;
			}
			var swatch = el('span', { class: 'birthdayreminder-chart-swatch' });
			swatch.style.backgroundColor = monthColor(i);
			legend.appendChild(el('li', {}, [swatch, document.createTextNode(MONTH_NAMES[i] + ': ' + count)]));
		});
		wrap.appendChild(legend);

		return wrap;
	}

	function ageBucketLabel(index) {
		return index === 0 ? '0–10' : ((index * 10 + 1) + '–' + (index * 10 + 10));
	}

	function renderAgeChart(ageBuckets, unknownAge) {
		var labels = ageBuckets.map(function (_, i) { return ageBucketLabel(i); });
		var counts = ageBuckets.slice();
		if (unknownAge > 0) {
			labels.push(t('unbekannt'));
			counts.push(unknownAge);
		}

		var wrap = el('div', { class: 'birthdayreminder-chart-container' });
		var total = counts.reduce(function (a, b) { return a + b; }, 0);
		if (total === 0) {
			wrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Keine Mitglieder mit hinterlegtem Geburtsdatum.') }));
			return wrap;
		}

		var barWidth = 28;
		var gap = 12;
		var chartHeight = 160;
		var topPadding = 16;
		var bottomPadding = 26;
		var width = counts.length * (barWidth + gap) + gap;
		var height = topPadding + chartHeight + bottomPadding;
		var maxCount = Math.max.apply(null, counts);

		var svg = svgEl('svg', { viewBox: '0 0 ' + width + ' ' + height, class: 'birthdayreminder-barchart' });

		counts.forEach(function (count, i) {
			var x = gap + i * (barWidth + gap);
			var barHeight = maxCount > 0 ? (count / maxCount) * chartHeight : 0;
			var y = topPadding + chartHeight - barHeight;
			var isUnknown = unknownAge > 0 && i === counts.length - 1;

			if (count > 0) {
				svg.appendChild(svgEl('rect', {
					x: x, y: y, width: barWidth, height: barHeight,
					class: isUnknown ? 'birthdayreminder-chart-bar-unknown' : 'birthdayreminder-chart-bar',
				}));
				var valueLabel = svgEl('text', {
					x: x + barWidth / 2, y: y - 4, 'text-anchor': 'middle',
					class: 'birthdayreminder-chart-value',
				});
				valueLabel.textContent = String(count);
				svg.appendChild(valueLabel);
			}

			var axisLabel = svgEl('text', {
				x: x + barWidth / 2, y: topPadding + chartHeight + 16, 'text-anchor': 'middle',
				class: 'birthdayreminder-chart-axis-label',
			});
			axisLabel.textContent = labels[i];
			svg.appendChild(axisLabel);
		});

		wrap.appendChild(svg);
		return wrap;
	}

	function renderOverview(root) {
		var wrap = section(t('Übersicht'));
		wrap.appendChild(el('p', {
			text: t('Die anstehenden Geburtstage der aktiven Mitglieder, aufgeteilt nach Vorlaufzeit.'),
		}));
		var columnsWrap = el('div', { class: 'birthdayreminder-overview-columns' });
		wrap.appendChild(columnsWrap);

		var chartsRow = el('div', { class: 'birthdayreminder-charts-row' });

		var monthBlock = el('div', { class: 'birthdayreminder-chart-block' });
		monthBlock.appendChild(el('h4', { class: 'birthdayreminder-chart-heading', text: t('Geburtstage pro Monat') }));
		var monthChartWrap = el('div');
		monthBlock.appendChild(monthChartWrap);

		var ageBlock = el('div', { class: 'birthdayreminder-chart-block' });
		ageBlock.appendChild(el('h4', { class: 'birthdayreminder-chart-heading', text: t('Altersstruktur') }));
		var ageChartWrap = el('div');
		ageBlock.appendChild(ageChartWrap);

		chartsRow.appendChild(monthBlock);
		chartsRow.appendChild(ageBlock);
		wrap.appendChild(chartsRow);

		root.appendChild(wrap);

		function load() {
			columnsWrap.innerHTML = '';
			columnsWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Lade …') }));
			monthChartWrap.innerHTML = '';
			ageChartWrap.innerHTML = '';
			api('GET', '/admin/overview').then(function (data) {
				columnsWrap.innerHTML = '';
				columnsWrap.appendChild(renderOverviewColumn(t('Heute'), data.today));
				columnsWrap.appendChild(renderOverviewColumn(t('In den nächsten 7 Tagen'), data.next7));
				columnsWrap.appendChild(renderOverviewColumn(t('In den nächsten 30 Tagen'), data.next30));
				monthChartWrap.appendChild(renderMonthChart(data.monthCounts));
				ageChartWrap.appendChild(renderAgeChart(data.ageBuckets, data.unknownAge));
			}).catch(function (err) {
				columnsWrap.innerHTML = '';
				columnsWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: String(err.message || err) }));
			});
		}

		return { reload: load };
	}

	// ---- CSV import ------------------------------------------------------

	var TARGET_FIELDS = [
		{ key: 'firstName', label: t('Vorname'), required: true },
		{ key: 'lastName', label: t('Nachname'), required: true },
		{ key: 'birthdate', label: t('Geburtsdatum'), required: true },
		{ key: 'email', label: t('E-Mail-Adresse'), required: false },
	];

	function detectDelimiter(firstLine) {
		var semicolons = (firstLine.match(/;/g) || []).length;
		var commas = (firstLine.match(/,/g) || []).length;
		return semicolons >= commas ? ';' : ',';
	}

	function parseCsvLine(line, delimiter) {
		// Simple CSV split (no embedded newlines in fields, but handles quoted values).
		var result = [];
		var cur = '';
		var inQuotes = false;
		for (var i = 0; i < line.length; i++) {
			var ch = line[i];
			if (inQuotes) {
				if (ch === '"' && line[i + 1] === '"') { cur += '"'; i++; } else if (ch === '"') { inQuotes = false; } else { cur += ch; }
			} else if (ch === '"') {
				inQuotes = true;
			} else if (ch === delimiter) {
				result.push(cur);
				cur = '';
			} else {
				cur += ch;
			}
		}
		result.push(cur);
		return result.map(function (s) { return s.trim(); });
	}

	function renderCsvImportSection(root) {
		var wrap = section(t('CSV-Import'));
		wrap.appendChild(el('p', {
			text: t('CSV-Datei auswählen, Spalten den Feldern zuordnen und importieren. Neue Namen (Vorname+Nachname) werden angelegt, vorhandene bei Änderungen aktualisiert, und Mitglieder, die in der Datei fehlen, werden automatisch deaktiviert (mit Bemerkung „' + MemberSyncAutoRemark() + '“). Bereits deaktivierte Mitglieder werden dabei nicht automatisch wieder aktiviert.'),
		}));

		var templateLink = el('a', { class: 'button', href: url('/admin/members/import-template'), text: t('Vorlage herunterladen') });
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [templateLink]));

		var fileInput = el('input', { type: 'file', accept: '.csv,text/csv' });
		var mappingWrap = el('div', { class: 'birthdayreminder-add-grid' });
		var previewWrap = el('div');
		var importButton = el('button', { type: 'button', class: 'button primary', text: t('Import starten'), disabled: 'disabled' });
		var resultWrap = el('div', { class: 'birthdayreminder-status' });

		var csvHeader = [];
		var csvRows = [];
		var delimiter = ';';
		var selects = {};

		function updateImportButtonState() {
			var ready = TARGET_FIELDS.every(function (f) {
				return !f.required || (selects[f.key] && selects[f.key].value !== '');
			}) && csvRows.length > 0;
			if (ready) {
				importButton.removeAttribute('disabled');
			} else {
				importButton.setAttribute('disabled', 'disabled');
			}
		}

		function renderMapping() {
			mappingWrap.innerHTML = '';
			selects = {};
			TARGET_FIELDS.forEach(function (field) {
				var select = el('select');
				if (!field.required) {
					select.appendChild(el('option', { value: '', text: t('- nicht zuordnen -') }));
				} else {
					select.appendChild(el('option', { value: '', text: t('- bitte wählen -') }));
				}
				csvHeader.forEach(function (col) {
					var opt = el('option', { value: col, text: col });
					if (col.toLowerCase() === field.key.toLowerCase() || col.toLowerCase() === field.label.toLowerCase()) {
						opt.selected = true;
					}
					select.appendChild(opt);
				});
				select.addEventListener('change', updateImportButtonState);
				selects[field.key] = select;
				mappingWrap.appendChild(el('div', {}, [
					el('label', { class: 'birthdayreminder-field-label', text: field.label + (field.required ? ' *' : '') }),
					select,
				]));
			});
			updateImportButtonState();
		}

		function renderPreview() {
			previewWrap.innerHTML = '';
			if (csvRows.length === 0) {
				return;
			}
			var table = el('table', { class: 'birthdayreminder-table' });
			table.appendChild(el('tr', {}, csvHeader.map(function (h) { return el('th', { text: h }); })));
			csvRows.slice(0, 3).forEach(function (row) {
				table.appendChild(el('tr', {}, csvHeader.map(function (h, i) { return el('td', { text: row[i] || '' }); })));
			});
			previewWrap.appendChild(el('p', { class: 'birthdayreminder-hint', text: t('Vorschau (erste Zeilen):') }));
			previewWrap.appendChild(table);
		}

		var csvContentCache = '';

		fileInput.addEventListener('change', function () {
			var file = fileInput.files[0];
			if (!file) {
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				csvContentCache = String(reader.result).replace(/^﻿/, '');
				var lines = csvContentCache.split(/\r\n|\r|\n/).filter(function (l) { return l.trim() !== ''; });
				if (lines.length === 0) {
					resultWrap.textContent = t('Die Datei ist leer.');
					return;
				}
				delimiter = detectDelimiter(lines[0]);
				csvHeader = parseCsvLine(lines[0], delimiter);
				csvRows = lines.slice(1).map(function (l) { return parseCsvLine(l, delimiter); });
				resultWrap.textContent = '';
				renderMapping();
				renderPreview();
			};
			reader.readAsText(file, 'UTF-8');
		});

		importButton.addEventListener('click', function () {
			if (!csvContentCache) {
				return;
			}
			var mapping = {
				firstName: selects.firstName.value,
				lastName: selects.lastName.value,
				birthdate: selects.birthdate.value,
				email: selects.email.value,
			};
			resultWrap.textContent = t('Importiere …');
			api('POST', '/admin/members/import', { csvContent: csvContentCache, mapping: mapping, delimiter: delimiter }).then(function (res) {
				var msg = t('Fertig: ') + res.inserted + t(' neu, ') + res.updated + t(' aktualisiert, ') + res.unchanged + t(' unverändert, ') + res.disabled + t(' deaktiviert.');
				if (res.errors && res.errors.length > 0) {
					msg += ' ' + res.errors.length + t(' Zeile(n) mit Fehlern: ') + res.errors.join(' | ');
				}
				resultWrap.textContent = msg;
				loadMembersList();
			}).catch(function (err) {
				resultWrap.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [fileInput]));
		wrap.appendChild(mappingWrap);
		wrap.appendChild(previewWrap);
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [importButton]));
		wrap.appendChild(resultWrap);
		root.appendChild(wrap);
	}

	function renderCsvExportSection(root) {
		var wrap = section(t('CSV-Export'));
		wrap.appendChild(el('p', {
			text: t('Lädt alle Mitglieder (inklusive deaktivierter) als CSV-Datei herunter - im selben Format, das auch der CSV-Import erwartet.'),
		}));
		var link = el('a', { class: 'button primary', href: url('/admin/members/export-csv'), text: t('Mitgliederliste als CSV herunterladen') });
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [link]));
		root.appendChild(wrap);
	}

	function renderContactsSection(root) {
		var wrap = section(t('Kontakte'));
		wrap.appendChild(el('p', {
			text: t('Import aus den eigenen Kontakten: Vorname/Nachname werden aus dem Namensfeld übernommen, Kontakte ohne verwertbaren Namen oder ohne (lesbares) Geburtsdatum werden übersprungen. Export: alle aktiven Mitglieder werden in ein eigenes, beschreibbares Adressbuch geschrieben (Abgleich per vollständigem Namen, bereits vorhandene Kontakte werden aktualisiert statt doppelt angelegt).'),
		}));

		var importButton = el('button', { type: 'button', class: 'button', text: t('Aus Kontakten importieren') });
		var importStatus = el('div', { class: 'birthdayreminder-status' });
		importButton.addEventListener('click', function () {
			importStatus.textContent = t('Importiere …');
			api('POST', '/admin/members/import-contacts', {}).then(function (res) {
				var msg = t('Fertig: ') + res.inserted + t(' neu, ') + res.updated + t(' aktualisiert, ') + res.unchanged + t(' unverändert, ') + res.disabled + t(' deaktiviert.');
				if (res.errors && res.errors.length > 0) {
					msg += ' ' + res.errors.length + t(' Kontakt(e) übersprungen: ') + res.errors.join(' | ');
				}
				importStatus.textContent = msg;
				loadMembersList();
			}).catch(function (err) {
				importStatus.textContent = String(err.message || err);
			});
		});

		var exportButton = el('button', { type: 'button', class: 'button', text: t('Aktive Mitglieder in Kontakte exportieren') });
		var exportStatus = el('div', { class: 'birthdayreminder-status' });
		exportButton.addEventListener('click', function () {
			exportStatus.textContent = t('Exportiere …');
			api('POST', '/admin/members/export-contacts', {}).then(function (res) {
				exportStatus.textContent = t('Fertig: ') + res.created + t(' neu angelegt, ') + res.updated + t(' aktualisiert in „') + res.addressBookName + '“.';
			}).catch(function (err) {
				exportStatus.textContent = String(err.message || err);
			});
		});

		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [importButton, importStatus]));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [exportButton, exportStatus]));
		root.appendChild(wrap);
	}

	function renderImportExport(root) {
		renderCsvImportSection(root);
		renderCsvExportSection(root);
		renderContactsSection(root);
	}

	function MemberSyncAutoRemark() {
		return 'Deaktiviert da bei Import nicht mehr vorhanden';
	}

	// ---- Member list -------------------------------------------------

	var membersTbody;
	var allMembers = [];
	var memberFilters = { firstName: '', lastName: '', birthdate: '', email: '', disabled: 'all', remark: '' };

	function loadMembersList() {
		api('GET', '/admin/members').then(function (members) {
			allMembers = members;
			renderFilteredRows();
		}).catch(showError);
	}

	function birthdateFields(day, month, year) {
		var dayInput = el('input', { type: 'number', min: '1', max: '31', class: 'birthdayreminder-day-input', value: day !== undefined ? String(day) : '' });
		var monthInput = el('input', { type: 'number', min: '1', max: '12', class: 'birthdayreminder-day-input', value: month !== undefined ? String(month) : '' });
		var yearInput = el('input', { type: 'number', min: '1900', max: '2100', class: 'birthdayreminder-year-input', placeholder: t('Jahr (optional)'), value: year ? String(year) : '' });
		var wrap = el('div', { class: 'birthdayreminder-row' }, [
			dayInput, el('span', { text: '.' }), monthInput, el('span', { text: '.' }), yearInput,
		]);
		return {
			node: wrap,
			getValues: function () {
				return {
					day: parseInt(dayInput.value, 10),
					month: parseInt(monthInput.value, 10),
					year: yearInput.value ? parseInt(yearInput.value, 10) : null,
				};
			},
		};
	}

	function formatBirthdateForFilter(m) {
		var dd = String(m.birthDay).padStart(2, '0');
		var mm = String(m.birthMonth).padStart(2, '0');
		return m.birthYear ? (dd + '.' + mm + '.' + m.birthYear) : (dd + '.' + mm + '.');
	}

	function matchesFilters(m) {
		var f = memberFilters;
		if (f.firstName && m.firstName.toLowerCase().indexOf(f.firstName.toLowerCase()) === -1) { return false; }
		if (f.lastName && m.lastName.toLowerCase().indexOf(f.lastName.toLowerCase()) === -1) { return false; }
		if (f.birthdate && formatBirthdateForFilter(m).indexOf(f.birthdate) === -1) { return false; }
		if (f.email && (!m.email || m.email.toLowerCase().indexOf(f.email.toLowerCase()) === -1)) { return false; }
		if (f.disabled === 'active' && m.disabled) { return false; }
		if (f.disabled === 'disabled' && !m.disabled) { return false; }
		if (f.remark && (!m.remark || m.remark.toLowerCase().indexOf(f.remark.toLowerCase()) === -1)) { return false; }
		return true;
	}

	function renderFilterRow() {
		function textFilter(key, placeholder) {
			var input = el('input', { type: 'text', placeholder: placeholder });
			input.value = memberFilters[key];
			input.addEventListener('input', function () {
				memberFilters[key] = input.value;
				renderFilteredRows();
			});
			return input;
		}

		var disabledSelect = el('select');
		disabledSelect.appendChild(el('option', { value: 'all', text: t('Alle') }));
		disabledSelect.appendChild(el('option', { value: 'active', text: t('Nur aktive') }));
		disabledSelect.appendChild(el('option', { value: 'disabled', text: t('Nur deaktivierte') }));
		disabledSelect.addEventListener('change', function () {
			memberFilters.disabled = disabledSelect.value;
			renderFilteredRows();
		});

		return el('tr', { class: 'birthdayreminder-filter-row' }, [
			el('td', {}, [textFilter('firstName', t('Filter …'))]),
			el('td', {}, [textFilter('lastName', t('Filter …'))]),
			el('td', {}, [textFilter('birthdate', t('z.B. 08. oder .1990'))]),
			el('td', {}, [textFilter('email', t('Filter …'))]),
			el('td', {}, [disabledSelect]),
			el('td', {}, [textFilter('remark', t('Filter …'))]),
			el('td', {}),
		]);
	}

	function renderMemberRow(m) {
		var firstNameInput = el('input', { type: 'text', value: m.firstName });
		var lastNameInput = el('input', { type: 'text', value: m.lastName });
		var birthdate = birthdateFields(m.birthDay, m.birthMonth, m.birthYear);
		var emailInput = el('input', { type: 'email', value: m.email || '' });
		var disabledCheckbox = el('input', { type: 'checkbox' });
		disabledCheckbox.checked = !!m.disabled;
		var remarkInput = el('input', { type: 'text', value: m.remark || '' });

		var saveButton = el('button', { type: 'button', class: 'button', text: t('Speichern') });
		saveButton.addEventListener('click', function () {
			var bd = birthdate.getValues();
			api('POST', '/admin/members', {
				id: m.id,
				firstName: firstNameInput.value,
				lastName: lastNameInput.value,
				birthDay: bd.day,
				birthMonth: bd.month,
				birthYear: bd.year,
				email: emailInput.value,
				disabled: disabledCheckbox.checked,
				remark: remarkInput.value,
			}).then(loadMembersList).catch(showError);
		});

		var deleteButton = el('button', { type: 'button', class: 'button', text: t('Löschen') });
		deleteButton.addEventListener('click', function () {
			if (!window.confirm(t('Mitglied wirklich endgültig löschen?'))) {
				return;
			}
			api('DELETE', '/admin/members/' + m.id).then(loadMembersList).catch(showError);
		});

		return el('tr', { class: m.disabled ? 'birthdayreminder-row-disabled' : '' }, [
			el('td', {}, [firstNameInput]),
			el('td', {}, [lastNameInput]),
			el('td', {}, [birthdate.node]),
			el('td', {}, [emailInput]),
			el('td', {}, [disabledCheckbox]),
			el('td', {}, [remarkInput]),
			el('td', { class: 'birthdayreminder-actions' }, [saveButton, deleteButton]),
		]);
	}

	function renderFilteredRows() {
		membersTbody.innerHTML = '';
		var filtered = allMembers.filter(matchesFilters);

		if (allMembers.length === 0) {
			membersTbody.appendChild(el('tr', {}, [
				el('td', { colspan: '7', class: 'birthdayreminder-status', text: t('Noch keine Mitglieder eingetragen.') }),
			]));
			return;
		}
		if (filtered.length === 0) {
			membersTbody.appendChild(el('tr', {}, [
				el('td', { colspan: '7', class: 'birthdayreminder-status', text: t('Kein Mitglied entspricht den Filtern.') }),
			]));
			return;
		}

		filtered.forEach(function (m) {
			membersTbody.appendChild(renderMemberRow(m));
		});
	}

	function renderAddMemberPanel(root) {
		var firstNameInput = el('input', { type: 'text' });
		var lastNameInput = el('input', { type: 'text' });
		var birthdate = birthdateFields();
		var emailInput = el('input', { type: 'email' });
		var addButton = el('button', { type: 'button', class: 'button primary', text: t('Mitglied hinzufügen') });
		var status = el('span', { class: 'birthdayreminder-status' });

		addButton.addEventListener('click', function () {
			var bd = birthdate.getValues();
			api('POST', '/admin/members', {
				id: null,
				firstName: firstNameInput.value,
				lastName: lastNameInput.value,
				birthDay: bd.day,
				birthMonth: bd.month,
				birthYear: bd.year,
				email: emailInput.value,
				disabled: false,
				remark: null,
			}).then(function () {
				firstNameInput.value = '';
				lastNameInput.value = '';
				emailInput.value = '';
				status.textContent = t('Hinzugefügt.');
				loadMembersList();
			}).catch(function (err) {
				status.textContent = String(err.message || err);
			});
		});

		var panel = el('div', { class: 'birthdayreminder-add-panel birthdayreminder-add-panel-top' });
		panel.appendChild(el('h4', { text: t('Neues Mitglied hinzufügen') }));
		panel.appendChild(el('div', { class: 'birthdayreminder-add-grid' }, [
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Vorname') }), firstNameInput]),
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Nachname') }), lastNameInput]),
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('Geburtsdatum (Tag.Monat.Jahr)') }), birthdate.node]),
			el('div', {}, [el('label', { class: 'birthdayreminder-field-label', text: t('E-Mail') }), emailInput]),
		]));
		panel.appendChild(el('div', { class: 'birthdayreminder-row' }, [addButton, status]));
		root.appendChild(panel);
	}

	function renderMembersList(root) {
		var wrap = section(t('Mitgliederliste'));

		// Add-new-member form goes first, so it's reachable without scrolling
		// past the (potentially long) existing list.
		renderAddMemberPanel(wrap);

		var membersTableWrap = el('div', { class: 'birthdayreminder-table-wrap' });
		var membersTable = el('table', { class: 'birthdayreminder-table birthdayreminder-members-table' });
		var thead = el('thead', {}, [
			el('tr', {}, [
				el('th', { text: t('Vorname') }),
				el('th', { text: t('Nachname') }),
				el('th', { text: t('Geburtsdatum') }),
				el('th', { text: t('E-Mail') }),
				el('th', { text: t('Deaktiviert') }),
				el('th', { text: t('Bemerkung') }),
				el('th', { text: t('Aktionen') }),
			]),
			renderFilterRow(),
		]);
		membersTbody = el('tbody');
		membersTable.appendChild(thead);
		membersTable.appendChild(membersTbody);
		membersTableWrap.appendChild(membersTable);

		var listVisible = false;
		membersTableWrap.style.display = 'none';
		var toggleButton = el('button', { type: 'button', class: 'button', text: t('Liste anzeigen') });
		toggleButton.addEventListener('click', function () {
			listVisible = !listVisible;
			membersTableWrap.style.display = listVisible ? '' : 'none';
			toggleButton.textContent = listVisible ? t('Liste ausblenden') : t('Liste anzeigen');
		});

		wrap.appendChild(el('label', { class: 'birthdayreminder-field-label', text: t('Vorhandene Mitglieder') }));
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [toggleButton]));
		wrap.appendChild(membersTableWrap);

		root.appendChild(wrap);
		return { reload: loadMembersList };
	}

	// ---- Send log (Versand-Log) ---------------------------------------

	function formatLogType(reminderType) {
		if (reminderType === 'congrats') {
			return t('Glückwunsch ans Mitglied');
		}
		if (reminderType === 'none') {
			return t('Kein Versand (nichts fällig)');
		}
		return t('Erinnerung an Verantwortliche');
	}

	function formatLogOffset(daysBefore) {
		if (daysBefore === null) {
			return '–';
		}
		return daysBefore === 0 ? t('am Tag selbst') : daysBefore + ' ' + t('Tage vorher');
	}

	function formatLogSentAt(sentAt) {
		return new Date(sentAt * 1000).toLocaleString('de-DE');
	}

	function renderSendLog(root) {
		var wrap = section(t('Versand-Log'));
		wrap.appendChild(el('p', {
			text: t('Protokoll jeder tatsächlich verschickten Erinnerungs- oder Glückwunsch-Mail, ein Eintrag je Empfänger-E-Mail (die letzten 200 Einträge, neueste zuerst). Ein "Kein Versand"-Eintrag zeigt, dass an dem Tag geprüft, aber nichts gefunden wurde.'),
		}));

		var exportLink = el('a', { class: 'button', href: url('/admin/send-log/export-csv'), text: t('Als CSV exportieren') });
		wrap.appendChild(el('div', { class: 'birthdayreminder-row' }, [exportLink]));

		var contentWrap = el('div');

		function renderTable(entries) {
			contentWrap.innerHTML = '';
			if (entries.length === 0) {
				contentWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Noch keine Einträge im Versand-Log.') }));
				return;
			}
			var tableWrap = el('div', { class: 'birthdayreminder-table-wrap' });
			var table = el('table', { class: 'birthdayreminder-table birthdayreminder-log-table' });
			table.appendChild(el('tr', {}, [
				el('th', { text: t('Mitglied') }),
				el('th', { text: t('Art') }),
				el('th', { text: t('Vorlaufzeit') }),
				el('th', { text: t('Bezugsjahr') }),
				el('th', { text: t('Empfänger') }),
				el('th', { text: t('Erstellt am') }),
			]));
			entries.forEach(function (entry) {
				table.appendChild(el('tr', {}, [
					el('td', { text: entry.memberName }),
					el('td', { text: formatLogType(entry.reminderType) }),
					el('td', { text: formatLogOffset(entry.daysBefore) }),
					el('td', { text: String(entry.birthdayYear) }),
					el('td', { text: entry.recipientEmail || '–' }),
					el('td', { text: formatLogSentAt(entry.sentAt) }),
				]));
			});
			tableWrap.appendChild(table);
			contentWrap.appendChild(tableWrap);
		}

		function loadLog() {
			contentWrap.innerHTML = '';
			contentWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Lade …') }));
			api('GET', '/admin/send-log').then(function (entries) {
				renderTable(entries);
			}).catch(function (err) {
				contentWrap.innerHTML = '';
				contentWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: String(err.message || err) }));
			});
		}

		wrap.appendChild(contentWrap);
		root.appendChild(wrap);
		return { reload: loadLog };
	}

	// ---- Geschenke (read-only - editing stays on the Admin-Einstellungsseite) ----

	function renderGifts(root) {
		var wrap = section(t('Geschenke'));
		wrap.appendChild(el('p', {
			text: t('Geschenkvorschläge für runde Geburtstage - nur zur Ansicht. Bearbeitet werden sie auf der Admin-Einstellungsseite.'),
		}));

		var contentWrap = el('div');

		function renderTable(gifts) {
			contentWrap.innerHTML = '';
			if (gifts.length === 0) {
				contentWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Noch keine runden Geburtstage mit Geschenkvorschlag hinterlegt.') }));
				return;
			}
			var tableWrap = el('div', { class: 'birthdayreminder-table-wrap' });
			var table = el('table', { class: 'birthdayreminder-table birthdayreminder-gifts-table' });
			table.appendChild(el('tr', {}, [
				el('th', { text: t('Alter') }),
				el('th', { text: t('Geschenkvorschlag') }),
			]));
			gifts.forEach(function (g) {
				table.appendChild(el('tr', {}, [
					el('td', { text: String(g.age) }),
					el('td', { text: g.giftText }),
				]));
			});
			tableWrap.appendChild(table);
			contentWrap.appendChild(tableWrap);
		}

		function load() {
			contentWrap.innerHTML = '';
			contentWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: t('Lade …') }));
			api('GET', '/admin/gifts').then(function (gifts) {
				renderTable(gifts);
			}).catch(function (err) {
				contentWrap.innerHTML = '';
				contentWrap.appendChild(el('p', { class: 'birthdayreminder-status', text: String(err.message || err) }));
			});
		}

		wrap.appendChild(contentWrap);
		root.appendChild(wrap);
		return { reload: load };
	}

	// ---- Layout: left sidebar (nav + settings links) + content area -----

	var NAV_ITEMS = [
		{ key: 'overview', label: t('Übersicht') },
		{ key: 'members', label: t('Mitgliederliste') },
		{ key: 'import', label: t('Import/Export') },
		{ key: 'gifts', label: t('Geschenke') },
		{ key: 'logs', label: t('Logs') },
	];

	function renderLayout(root) {
		var layout = el('div', { class: 'birthdayreminder-layout' });
		var sidebar = el('div', { class: 'birthdayreminder-sidebar' });
		var content = el('div', { class: 'birthdayreminder-content' });

		sidebar.appendChild(el('a', {
			class: 'button birthdayreminder-sidebar-link',
			href: OC.generateUrl('/settings/user/birthdayreminder'),
			text: t('Persönliche Einstellungen'),
		}));

		var nav = el('nav', { class: 'birthdayreminder-nav' });
		var navButtons = {};
		NAV_ITEMS.forEach(function (item) {
			var btn = el('button', { type: 'button', class: 'birthdayreminder-nav-item', text: item.label });
			btn.addEventListener('click', function () { activate(item.key); });
			navButtons[item.key] = btn;
			nav.appendChild(btn);
		});
		sidebar.appendChild(nav);

		sidebar.appendChild(el('a', {
			class: 'button birthdayreminder-sidebar-link birthdayreminder-sidebar-link-bottom',
			href: OC.generateUrl('/settings/admin/birthdayreminder'),
			text: t('Admin-Einstellungen'),
		}));

		var panels = {
			overview: el('div', { class: 'birthdayreminder-panel' }),
			members: el('div', { class: 'birthdayreminder-panel' }),
			import: el('div', { class: 'birthdayreminder-panel' }),
			gifts: el('div', { class: 'birthdayreminder-panel' }),
			logs: el('div', { class: 'birthdayreminder-panel' }),
		};
		Object.keys(panels).forEach(function (key) {
			panels[key].style.display = 'none';
			content.appendChild(panels[key]);
		});

		var overviewHandle = renderOverview(panels.overview);
		var membersHandle = renderMembersList(panels.members);
		renderImportExport(panels.import);
		var giftsHandle = renderGifts(panels.gifts);
		var logsHandle = renderSendLog(panels.logs);

		var loadedOnce = {};
		var activeKey = null;

		function activate(key) {
			if (activeKey === key) {
				return;
			}
			activeKey = key;
			Object.keys(panels).forEach(function (k) {
				panels[k].style.display = (k === key) ? '' : 'none';
				navButtons[k].classList.toggle('active', k === key);
			});
			if (key === 'overview') {
				overviewHandle.reload();
			} else if (key === 'members' && !loadedOnce.members) {
				loadedOnce.members = true;
				membersHandle.reload();
			} else if (key === 'gifts' && !loadedOnce.gifts) {
				loadedOnce.gifts = true;
				giftsHandle.reload();
			} else if (key === 'logs' && !loadedOnce.logs) {
				loadedOnce.logs = true;
				logsHandle.reload();
			}
		}

		activate('overview');

		layout.appendChild(sidebar);
		layout.appendChild(content);
		root.appendChild(layout);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('birthdayreminder-members-page');
		if (!root) {
			return;
		}
		renderLayout(root);
	});
})();
