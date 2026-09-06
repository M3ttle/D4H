/**
 * Admin UI for D4H Create Activity: paste → review → send.
 */
(function () {
	'use strict';

	var cfg = window.d4hCreateActivity || {};
	var i18n = cfg.i18n || {};
	var rows = [];
	var tags = Array.isArray(cfg.tags) ? cfg.tags.slice() : [];

	var pasteEl = document.getElementById('d4h-ca-paste');
	var proceedBtn = document.getElementById('d4h-ca-proceed');
	var backBtn = document.getElementById('d4h-ca-back');
	var sendBtn = document.getElementById('d4h-ca-send');
	var againBtn = document.getElementById('d4h-ca-again');
	var stepPaste = document.getElementById('d4h-ca-step-paste');
	var stepReview = document.getElementById('d4h-ca-step-review');
	var stepResults = document.getElementById('d4h-ca-step-results');
	var reviewBody = document.getElementById('d4h-ca-review-body');
	var resultsBody = document.getElementById('d4h-ca-results-body');
	var resultsSummary = document.getElementById('d4h-ca-results-summary');
	var parseStatus = document.getElementById('d4h-ca-parse-status');
	var sendStatus = document.getElementById('d4h-ca-send-status');

	if (!proceedBtn || !pasteEl || !reviewBody) {
		return;
	}

	/**
	 * Show a short status message next to a control.
	 */
	function setStatus(el, text, isError) {
		if (!el) {
			return;
		}
		el.textContent = text || '';
		el.className = 'd4h-ca-status' + (isError ? ' d4h-ca-status--error' : '');
	}

	/**
	 * Escape text for safe HTML insertion.
	 */
	function escapeHtml(value) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(value == null ? '' : String(value)));
		return div.innerHTML;
	}

	/**
	 * Shorten long cell text for the review table.
	 */
	function shorten(value, maxLen) {
		var text = value == null ? '' : String(value);
		if (text.length <= maxLen) {
			return text;
		}
		return text.slice(0, maxLen) + '…';
	}

	/**
	 * Label for Full-Team attendance based on activity type.
	 */
	function attendanceLabel(type) {
		return type === 'event'
			? (i18n.attendanceEvent || 'Full-Team Event')
			: (i18n.attendanceExercise || 'Full-Team Exercise');
	}

	/**
	 * Build Exercise/Event dropdown for one review row.
	 */
	function buildTypeSelect(rowIndex, selectedType) {
		var exerciseLabel = i18n.typeExercise || 'Exercise';
		var eventLabel = i18n.typeEvent || 'Event';
		var typeInvalid = i18n.typeInvalid || 'Type must be Exercise or Event.';
		return '<select class="d4h-ca-type-select" data-row="' + rowIndex + '" title="' + escapeHtml(typeInvalid) + '">' +
			'<option value="">—</option>' +
			'<option value="exercise"' + (selectedType === 'exercise' ? ' selected' : '') + '>' + escapeHtml(exerciseLabel) + '</option>' +
			'<option value="event"' + (selectedType === 'event' ? ' selected' : '') + '>' + escapeHtml(eventLabel) + '</option>' +
			'</select>';
	}

	/**
	 * Build checkboxes of Core tags so several can be chosen on one row.
	 */
	function buildTagSelect(rowIndex, selectedIds) {
		var selected = {};
		(selectedIds || []).forEach(function (id) {
			selected[String(id)] = true;
		});

		if (!tags.length) {
			return '<span class="d4h-ca-muted">' + escapeHtml(i18n.updateTagsHint || i18n.noTags || 'No tags') + '</span>';
		}

		var html = '<div class="d4h-ca-tag-checks" data-row="' + rowIndex + '">';
		tags.forEach(function (tag) {
			var id = String(tag.id);
			var checked = selected[id] ? ' checked' : '';
			html += '<label class="d4h-ca-tag-check">' +
				'<input type="checkbox" value="' + escapeHtml(id) + '"' + checked + '> ' +
				escapeHtml(tag.name) +
				'</label>';
		});
		html += '</div>';
		return html;
	}

	/**
	 * After the user picks type or tags, keep field errors and require a valid type.
	 */
	function refreshRowValidity(row) {
		var typeInvalid = i18n.typeInvalid || 'Type must be Exercise or Event.';
		var fieldErrors = Array.isArray(row.field_errors) ? row.field_errors.slice() : [];
		fieldErrors = fieldErrors.filter(function (error) {
			return error !== typeInvalid;
		});
		if (row.activity_type !== 'exercise' && row.activity_type !== 'event') {
			fieldErrors.unshift(typeInvalid);
		}
		row.field_errors = fieldErrors;
		row.unmatched_tags = [];
		row.tag_errors = [];
		row.errors = fieldErrors;
		row.valid = fieldErrors.length === 0;
	}

	/**
	 * Copy Exercise/Event choice from the DOM back into rows[].
	 */
	function syncTypesFromDom() {
		reviewBody.querySelectorAll('.d4h-ca-type-select').forEach(function (select) {
			var index = parseInt(select.getAttribute('data-row'), 10);
			if (!rows[index]) {
				return;
			}
			var value = select.value === 'event' ? 'event' : (select.value === 'exercise' ? 'exercise' : '');
			rows[index].activity_type = value;
			rows[index].activity_label = value === 'event'
				? (i18n.typeEvent || 'Event')
				: (i18n.typeExercise || 'Exercise');
			rows[index].attendance_type = attendanceLabel(value);
			refreshRowValidity(rows[index]);
		});
	}

	/**
	 * Copy checked tag IDs from the DOM back into rows[].
	 */
	function syncTagsFromDom() {
		var groups = reviewBody.querySelectorAll('.d4h-ca-tag-checks');
		groups.forEach(function (group) {
			var index = parseInt(group.getAttribute('data-row'), 10);
			if (!rows[index]) {
				return;
			}
			var chosen = [];
			group.querySelectorAll('input[type="checkbox"]:checked').forEach(function (box) {
				var id = parseInt(box.value, 10);
				if (id > 0) {
					chosen.push(id);
				}
			});
			rows[index].tag_ids = chosen;
			refreshRowValidity(rows[index]);
		});
	}

	/**
	 * Update one review row's highlight and status without rebuilding tag checkboxes.
	 */
	function applyRowStatus(index) {
		var row = rows[index];
		var tr = reviewBody.querySelectorAll('tr')[index];
		if (!row || !tr) {
			return;
		}
		tr.className = row.valid ? '' : 'd4h-ca-row-invalid';
		var statusCell = tr.querySelector('.d4h-ca-status-cell');
		if (!statusCell) {
			return;
		}
		statusCell.innerHTML = row.valid
			? '<span class="d4h-ca-ok">OK</span>'
			: '<span class="d4h-ca-bad">' + escapeHtml((row.errors || []).join(' ')) + '</span>';
	}

	/**
	 * Draw the review table from rows[].
	 */
	function renderReview() {
		reviewBody.innerHTML = '';
		rows.forEach(function (row, index) {
			var tr = document.createElement('tr');
			if (!row.valid) {
				tr.className = 'd4h-ca-row-invalid';
			}
			var statusHtml = row.valid
				? '<span class="d4h-ca-ok">OK</span>'
				: '<span class="d4h-ca-bad">' + escapeHtml((row.errors || []).join(' ')) + '</span>';

			tr.innerHTML =
				'<td>' + buildTypeSelect(index, row.activity_type) + '</td>' +
				'<td>' + escapeHtml(row.title) + '</td>' +
				'<td>' + escapeHtml(row.starts_at) + '</td>' +
				'<td>' + escapeHtml(row.ends_at) + '</td>' +
				'<td class="d4h-ca-attendance-cell">' + escapeHtml(row.attendance_type || attendanceLabel(row.activity_type)) + '</td>' +
				'<td title="' + escapeHtml(row.plan || '') + '">' + escapeHtml(shorten(row.plan, 80)) + '</td>' +
				'<td title="' + escapeHtml(row.description || '') + '">' + escapeHtml(shorten(row.description, 80)) + '</td>' +
				'<td>' + buildTagSelect(index, row.tag_ids) + '</td>' +
				'<td class="d4h-ca-status-cell">' + statusHtml + '</td>';
			reviewBody.appendChild(tr);
		});

		reviewBody.querySelectorAll('.d4h-ca-type-select').forEach(function (select) {
			select.addEventListener('change', function () {
				syncTypesFromDom();
				var index = parseInt(select.getAttribute('data-row'), 10);
				applyRowStatus(index);
				var tr = reviewBody.querySelectorAll('tr')[index];
				if (tr && rows[index]) {
					var attendanceCell = tr.querySelector('.d4h-ca-attendance-cell');
					if (attendanceCell) {
						attendanceCell.textContent = rows[index].attendance_type || attendanceLabel(rows[index].activity_type);
					}
				}
				var stillInvalid = rows.some(function (row) { return !row.valid; });
				setStatus(sendStatus, stillInvalid ? (i18n.fixInvalid || 'Fix invalid rows before sending.') : '', stillInvalid);
			});
		});

		reviewBody.querySelectorAll('.d4h-ca-tag-checks').forEach(function (group) {
			group.addEventListener('change', function () {
				syncTagsFromDom();
				var index = parseInt(group.getAttribute('data-row'), 10);
				applyRowStatus(index);
				var stillInvalid = rows.some(function (row) { return !row.valid; });
				setStatus(sendStatus, stillInvalid ? (i18n.fixInvalid || 'Fix invalid rows before sending.') : '', stillInvalid);
			});
		});
	}

	/**
	 * POST to WordPress admin-ajax.php.
	 */
	function postAjax(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(fields || {}).forEach(function (key) {
			body.append(key, fields[key]);
		});
		return fetch(cfg.ajaxUrl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json();
		});
	}

	/**
	 * Show one wizard step.
	 */
	function showStep(name) {
		if (stepPaste) {
			stepPaste.style.display = name === 'paste' ? '' : 'none';
		}
		if (stepReview) {
			stepReview.style.display = name === 'review' ? '' : 'none';
		}
		if (stepResults) {
			stepResults.style.display = name === 'results' ? '' : 'none';
		}
	}

	proceedBtn.addEventListener('click', function () {
		var paste = pasteEl.value || '';
		if (!paste.trim()) {
			setStatus(parseStatus, i18n.pasteEmpty || 'Paste at least one activity row first.', true);
			return;
		}
		proceedBtn.disabled = true;
		setStatus(parseStatus, i18n.parsing || 'Parsing…', false);

		postAjax(cfg.actionParse, { paste: paste })
			.then(function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || i18n.parseError || 'Could not parse the paste.';
					setStatus(parseStatus, msg, true);
					return;
				}
				rows = res.data.rows || [];
				if (Array.isArray(res.data.tags) && res.data.tags.length) {
					tags = res.data.tags;
				}
				renderReview();
				setStatus(parseStatus, '', false);
				showStep('review');
				if (!res.data.all_valid) {
					setStatus(sendStatus, i18n.fixInvalid || 'Fix invalid rows before sending.', true);
				} else {
					setStatus(sendStatus, '', false);
				}
			})
			.catch(function () {
				setStatus(parseStatus, i18n.parseError || 'Could not parse the paste.', true);
			})
			.finally(function () {
				proceedBtn.disabled = false;
			});
	});

	if (backBtn) {
		backBtn.addEventListener('click', function () {
			showStep('paste');
			setStatus(sendStatus, '', false);
		});
	}

	if (sendBtn) {
		sendBtn.addEventListener('click', function () {
			syncTypesFromDom();
			syncTagsFromDom();

			if (!rows.length) {
				setStatus(sendStatus, i18n.pasteEmpty || 'Nothing to send.', true);
				return;
			}
			if (rows.some(function (row) { return !row.valid; })) {
				setStatus(sendStatus, i18n.fixInvalid || 'Fix invalid rows before sending.', true);
				return;
			}
			if (!window.confirm(i18n.confirmSend || 'Send these activities to D4H?')) {
				return;
			}

			var payload = rows.map(function (row) {
				return {
					activity_type: row.activity_type,
					title: row.title,
					starts_at: row.starts_at,
					ends_at: row.ends_at,
					plan: row.plan,
					description: row.description,
					tag_ids: row.tag_ids || []
				};
			});

			sendBtn.disabled = true;
			if (backBtn) {
				backBtn.disabled = true;
			}
			setStatus(sendStatus, i18n.sending || 'Sending to D4H…', false);

			postAjax(cfg.actionSend, { rows: JSON.stringify(payload) })
				.then(function (res) {
					if (!res || !res.success) {
						var msg = (res && res.data && res.data.message) || i18n.sendError || 'Could not send activities.';
						setStatus(sendStatus, msg, true);
						if (res && res.data && Array.isArray(res.data.rows)) {
							rows = res.data.rows;
							renderReview();
						}
						return;
					}

					var created = res.data.created || 0;
					var failed = res.data.failed || 0;
					if (resultsBody) {
						resultsBody.innerHTML = '';
						(res.data.results || []).forEach(function (item) {
							var tr = document.createElement('tr');
							if (!item.success) {
								tr.className = 'd4h-ca-row-invalid';
							}
							tr.innerHTML =
								'<td>' + escapeHtml(item.activity_label || '') + '</td>' +
								'<td>' + escapeHtml(item.title) + '</td>' +
								'<td>' + (item.success
									? '<span class="d4h-ca-ok">' + escapeHtml(item.message || i18n.success || 'Success') + '</span>'
									: '<span class="d4h-ca-bad">' + escapeHtml(item.message || i18n.failed || 'Failed') + '</span>') +
								'</td>';
							resultsBody.appendChild(tr);
						});
					}

					if (resultsSummary) {
						resultsSummary.style.display = '';
						resultsSummary.className = 'notice ' + (failed ? 'notice-warning' : 'notice-success');
						resultsSummary.innerHTML = '<p><strong>' +
							escapeHtml((i18n.success || 'Success') + ': ' + created) +
							'</strong> · ' +
							escapeHtml((i18n.failed || 'Failed') + ': ' + failed) +
							'</p>';
					}

					setStatus(sendStatus, '', false);
					showStep('results');
				})
				.catch(function () {
					setStatus(sendStatus, i18n.sendError || 'Could not send activities.', true);
				})
				.finally(function () {
					sendBtn.disabled = false;
					if (backBtn) {
						backBtn.disabled = false;
					}
				});
		});
	}

	if (againBtn) {
		againBtn.addEventListener('click', function () {
			rows = [];
			pasteEl.value = '';
			setStatus(parseStatus, '', false);
			setStatus(sendStatus, '', false);
			showStep('paste');
		});
	}
})();
