(function () {
	'use strict';

	var cfg = window.d4hIncidentsAdmin || {};
	var ajaxUrl = cfg.ajaxUrl || '';
	var nonce = cfg.nonce || '';
	var actionFetch = cfg.actionFetch || 'd4h_incidents_ajax_fetch';
	var actionExportExcel = cfg.actionExportExcel || 'd4h_incidents_ajax_export_excel';
	var actionExportPng = cfg.actionExportPng || 'd4h_incidents_ajax_export_png';

	var charts = {};
	var lastProcessed = null;
	var currentPage = 1;
	var perPage = 10;
	var sortColumn = 'date';
	var sortAsc = false;

	function showMessage(text, type) {
		var el = document.getElementById('d4h-incidents-message');
		if (!el) return;
		el.className = 'notice notice-' + (type || 'info') + ' is-dismissible';
		el.innerHTML = '<p></p>';
		el.querySelector('p').textContent = text || '';
		el.style.display = 'block';
	}

	function hideMessage() {
		var el = document.getElementById('d4h-incidents-message');
		if (el) el.style.display = 'none';
	}

	function setLoading(loading) {
		var el = document.getElementById('d4h-incidents-loading');
		var btn = document.getElementById('d4h-incidents-fetch');
		if (el) el.style.display = loading ? 'block' : 'none';
		if (btn) btn.disabled = !!loading;
	}

	function escapeHtml(text) {
		return String(text || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function sortIncidentsList(list) {
		var sorted = list.slice();
		var key = sortColumn;
		var asc = sortAsc;
		sorted.sort(function (a, b) {
			var va = a[key];
			var vb = b[key];
			if (key === 'name' || key === 'title' || key === 'date' || key === 'location_coords') {
				va = String(va || '');
				vb = String(vb || '');
				return asc ? (va.localeCompare(vb)) : (vb.localeCompare(va));
			}
			va = key === 'duration' ? (a.duration_seconds != null ? a.duration_seconds : 0) : (Number(va) || 0);
			vb = key === 'duration' ? (b.duration_seconds != null ? b.duration_seconds : 0) : (Number(vb) || 0);
			return asc ? (va - vb) : (vb - va);
		});
		return sorted;
	}

	function renderIncidentsTable(processed) {
		var incidentsList = (processed && processed.stats && processed.stats.incidents_list) || [];
		var tableBody = document.getElementById('d4h-incidents-table-body');
		var perPageSelect = document.getElementById('d4h-incidents-per-page');
		if (perPageSelect) perPage = parseInt(perPageSelect.value, 10) || 10;

		incidentsList = sortIncidentsList(incidentsList);

		var totalItems = incidentsList.length;
		var totalPages = Math.max(1, Math.ceil(totalItems / perPage));
		currentPage = Math.min(Math.max(1, currentPage), totalPages);

		var startIndex = (currentPage - 1) * perPage;
		var pageItems = incidentsList.slice(startIndex, startIndex + perPage);

		if (tableBody) {
			if (pageItems.length === 0) {
				tableBody.innerHTML = '<tr><td colspan="6">' + (incidentsList.length === 0 ? 'No incidents.' : '') + '</td></tr>';
			} else {
				tableBody.innerHTML = pageItems.map(function (item) {
					var date = item.date || '';
					var locationCoords = item.location_coords || '';
					var locationUrl = item.location_url || '';
					var locationCell = locationUrl ? '<a href="' + escapeHtml(locationUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(locationCoords) + '</a>' : '-';
					var title = escapeHtml(item.title || item.name || '');
					var description = escapeHtml(item.description || '');
					var duration = item.duration || '';
					var participantsCount = (item.participants != null) ? Number(item.participants) : 0;
					return '<tr><td>' + date + '</td><td>' + locationCell + '</td><td>' + title + '</td><td>' + description + '</td><td>' + duration + '</td><td>' + participantsCount + '</td></tr>';
				}).join('');
			}
		}

		var pagingInfo = document.getElementById('d4h-incidents-paging-info');
		var pagingButtons = document.getElementById('d4h-incidents-paging-buttons');
		if (pagingInfo) {
			pagingInfo.textContent = totalItems > 0
				? ' ' + (startIndex + 1) + '–' + Math.min(startIndex + perPage, totalItems) + ' of ' + totalItems
				: '';
		}
		if (pagingButtons) {
			var prevDisabled = currentPage <= 1;
			var nextDisabled = currentPage >= totalPages || totalItems === 0;
			pagingButtons.innerHTML = '<button type="button" class="button button-small d4h-page-prev" ' + (prevDisabled ? 'disabled' : '') + '>← Previous</button> ' +
				'<button type="button" class="button button-small d4h-page-next" ' + (nextDisabled ? 'disabled' : '') + '>Next →</button>';
		}
	}

	function destroyCharts() {
		Object.keys(charts).forEach(function (id) {
			if (charts[id]) {
				charts[id].destroy();
				charts[id] = null;
			}
		});
		charts = {};
	}

	function renderCharts(processed) {
		destroyCharts();
		lastProcessed = processed;

		document.getElementById('d4h-stat-incidents').textContent = (processed.stats && processed.stats.total_incidents) || 0;
		document.getElementById('d4h-stat-participants').textContent = (processed.stats && processed.stats.total_participants) || 0;
		var durationEl = document.getElementById('d4h-stat-duration');
		if (durationEl) durationEl.textContent = (processed.stats && processed.stats.total_duration_formatted) || '0m';
		var avgParticipants = (processed.stats && processed.stats.average_participants_per_incident != null) ? processed.stats.average_participants_per_incident : 0;
		var avgEl = document.getElementById('d4h-stat-avg-participants');
		if (avgEl) avgEl.textContent = avgParticipants;
		var avgDurationEl = document.getElementById('d4h-stat-avg-duration');
		if (avgDurationEl) avgDurationEl.textContent = (processed.stats && processed.stats.average_duration_formatted) || '0m';
		document.getElementById('d4h-incidents-results').style.display = 'block';

		currentPage = 1;
		renderIncidentsTable(processed);

		var ctxTypes = document.getElementById('d4h-chart-types');
		if (ctxTypes && processed.chart_types_labels && processed.chart_types_data) {
			charts.types = new Chart(ctxTypes, {
				type: 'doughnut',
				data: {
					labels: processed.chart_types_labels,
					datasets: [{ data: processed.chart_types_data, backgroundColor: ['#3788d8', '#6c757d', '#28a745', '#ffc107', '#dc3545', '#17a2b8'] }]
				},
				options: { responsive: true, maintainAspectRatio: true }
			});
		}

		var ctxPart = document.getElementById('d4h-chart-participants');
		if (ctxPart && processed.chart_participants_labels && processed.chart_participants_data) {
			charts.participants = new Chart(ctxPart, {
				type: 'bar',
				data: {
					labels: processed.chart_participants_labels,
					datasets: [{ label: 'Incidents', data: processed.chart_participants_data, backgroundColor: '#3788d8' }]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					plugins: { legend: { display: false } },
					scales: { x: { beginAtZero: true } }
				}
			});
		}

		var mh = processed.chart_month_hour;
		var ctxMH = document.getElementById('d4h-chart-month-hour');
		if (ctxMH && mh && mh.data) {
			var months = mh.months || [];
			var incidentByMonth = [];
			var participantByMonth = [];
			var hourLabels = mh.hours ? mh.hours.map(function (h) { return h + ':00'; }) : [];
			var incidentByHour = new Array(24).fill(0);
			months.forEach(function (month) {
				var incTotal = 0;
				var partTotal = 0;
				(mh.hours || []).forEach(function (hour) {
					var key = month + '-' + hour;
					var d = mh.data[key] || {};
					var inc = d.incidents || 0;
					var part = d.participants || 0;
					incTotal += inc;
					partTotal += part;
					incidentByHour[hour] = (incidentByHour[hour] || 0) + inc;
				});
				incidentByMonth.push(incTotal);
				participantByMonth.push(partTotal);
			});
			charts['month-hour'] = new Chart(ctxMH, {
				type: 'bar',
				data: {
					labels: months.length ? months : hourLabels.slice(0, 24),
					datasets: [
						{ label: 'Incidents', data: months.length ? incidentByMonth : incidentByHour, backgroundColor: '#3788d8' },
						{ label: 'Participants', data: months.length ? participantByMonth : [], backgroundColor: '#28a745' }
					]
				},
				options: {
					responsive: true,
					scales: { x: { ticks: { maxRotation: 45 } }, y: { beginAtZero: true } }
				}
			});
		}
	}

	function fetchData() {
		var fromInput = document.getElementById('d4h_incidents_from');
		var toInput = document.getElementById('d4h_incidents_to');
		var from = fromInput ? fromInput.value : '';
		var to = toInput ? toInput.value : '';

		hideMessage();
		setLoading(true);

		var formData = new FormData();
		formData.append('action', actionFetch);
		formData.append('nonce', nonce);
		formData.append('from', from);
		formData.append('to', to);

		fetch(ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success && data.data) {
					renderCharts(data.data);
					showMessage('Data fetched successfully.', 'success');
				} else {
					showMessage((data.data && data.data.message) || 'Fetch failed.', 'error');
				}
			})
			.catch(function () {
				showMessage('Request failed.', 'error');
			})
			.finally(function () {
				setLoading(false);
			});
	}

	function exportExcel() {
		var formData = new FormData();
		formData.append('action', actionExportExcel);
		formData.append('nonce', nonce);

		fetch(ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success && data.data && data.data.csv && data.data.filename) {
					var blob = new Blob([data.data.csv], { type: 'text/csv;charset=utf-8;' });
					var link = document.createElement('a');
					link.href = URL.createObjectURL(blob);
					link.download = data.data.filename;
					link.click();
					URL.revokeObjectURL(link.href);
					showMessage('Excel (CSV) downloaded.', 'success');
				} else {
					showMessage((data.data && data.data.message) || 'Export failed.', 'error');
				}
			})
			.catch(function () {
				showMessage('Export request failed.', 'error');
			});
	}

	function exportChartPng(chartId) {
		var chart = charts[chartId];
		if (!chart) return;
		var link = document.createElement('a');
		link.download = 'd4h-incidents-' + chartId + '.png';
		link.href = chart.toBase64Image('image/png');
		link.click();
	}

	function escapeCsvCell(value) {
		var str = String(value);
		if (str.indexOf(',') >= 0 || str.indexOf('"') >= 0 || str.indexOf('\n') >= 0) {
			return '"' + str.replace(/"/g, '""') + '"';
		}
		return str;
	}

	function exportChartCsv(chartId) {
		var processed = lastProcessed;
		if (!processed) {
			showMessage('No data available. Fetch incidents first.', 'error');
			return;
		}
		var rows = [];
		var filename = 'd4h-incidents-' + chartId + '.csv';
		if (chartId === 'types') {
			var labels = processed.chart_types_labels || [];
			var data = processed.chart_types_data || [];
			rows.push(['Type', 'Count']);
			for (var i = 0; i < labels.length; i++) {
				rows.push([labels[i], data[i] || 0]);
			}
		} else if (chartId === 'participants') {
			var participantLabels = processed.chart_participants_labels || [];
			var participantData = processed.chart_participants_data || [];
			rows.push(['Participant', 'Incident count']);
			for (var j = 0; j < participantLabels.length; j++) {
				rows.push([participantLabels[j], participantData[j] || 0]);
			}
		} else if (chartId === 'month-hour') {
			var mh = processed.chart_month_hour;
			if (!mh || !mh.data) return;
			var months = mh.months || [];
			var hourLabels = mh.hours ? mh.hours.map(function (h) { return h + ':00'; }) : [];
			var labels = months.length ? months : hourLabels.slice(0, 24);
			var incidentData = [];
			var participantData = [];
			if (months.length) {
				labels.forEach(function (month) {
					var incTotal = 0;
					var partTotal = 0;
					(mh.hours || []).forEach(function (hour) {
						var key = month + '-' + hour;
						var d = mh.data[key] || {};
						incTotal += d.incidents || 0;
						partTotal += d.participants || 0;
					});
					incidentData.push(incTotal);
					participantData.push(partTotal);
				});
				rows.push(['Period', 'Incidents', 'Participants']);
				for (var k = 0; k < labels.length; k++) {
					rows.push([labels[k], incidentData[k], participantData[k]]);
				}
			} else {
				labels.forEach(function (label, idx) {
					var hour = idx;
					var incTotal = 0;
					Object.keys(mh.data || {}).forEach(function (key) {
						var parts = key.split('-');
						if (parts.length >= 3 && parseInt(parts[parts.length - 1], 10) === hour) {
							incTotal += (mh.data[key].incidents || 0);
						}
					});
					incidentData.push(incTotal);
				});
				rows.push(['Hour', 'Incidents']);
				for (var k = 0; k < labels.length; k++) {
					rows.push([labels[k], incidentData[k]]);
				}
			}
		} else {
			return;
		}
		var csvContent = '\uFEFF' + rows.map(function (row) {
			return row.map(escapeCsvCell).join(',');
		}).join('\n');
		var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = filename;
		link.click();
		URL.revokeObjectURL(link.href);
		showMessage('CSV downloaded.', 'success');
	}

	function init() {
		var fetchBtn = document.getElementById('d4h-incidents-fetch');
		if (fetchBtn) fetchBtn.addEventListener('click', fetchData);

		var perPageSelect = document.getElementById('d4h-incidents-per-page');
		if (perPageSelect) perPageSelect.addEventListener('change', function () {
			perPage = parseInt(perPageSelect.value, 10) || 10;
			currentPage = 1;
			if (lastProcessed) renderIncidentsTable(lastProcessed);
		});

		document.querySelectorAll('.d4h-sortable').forEach(function (th) {
			th.addEventListener('click', function () {
				var col = th.getAttribute('data-sort');
				if (col) {
					if (sortColumn === col) sortAsc = !sortAsc;
					else { sortColumn = col; sortAsc = true; }
					currentPage = 1;
					if (lastProcessed) renderIncidentsTable(lastProcessed);
				}
			});
		});

		document.addEventListener('click', function (event) {
			if (event.target && event.target.classList && event.target.classList.contains('d4h-page-prev')) {
				if (currentPage > 1 && lastProcessed) {
					currentPage--;
					renderIncidentsTable(lastProcessed);
				}
			} else if (event.target && event.target.classList && event.target.classList.contains('d4h-page-next')) {
				if (lastProcessed) {
					var list = lastProcessed.stats && lastProcessed.stats.incidents_list || [];
					var totalPages = Math.max(1, Math.ceil(list.length / perPage));
					if (currentPage < totalPages) {
						currentPage++;
						renderIncidentsTable(lastProcessed);
					}
				}
			}
		});

		document.querySelectorAll('.d4h-preset').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var days = parseInt(btn.getAttribute('data-days'), 10) || 365;
				var to = new Date();
				var from = new Date();
				from.setDate(from.getDate() - days);
				var fromInput = document.getElementById('d4h_incidents_from');
				var toInput = document.getElementById('d4h_incidents_to');
				if (fromInput) fromInput.value = from.toISOString().slice(0, 10);
				if (toInput) toInput.value = to.toISOString().slice(0, 10);
			});
		});

		var exportBtn = document.getElementById('d4h-incidents-export-excel');
		if (exportBtn) exportBtn.addEventListener('click', exportExcel);

		var exportCsvBtn = document.getElementById('d4h-incidents-export-csv');
		if (exportCsvBtn) exportCsvBtn.addEventListener('click', exportExcel);

		document.querySelectorAll('.d4h-export-png').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var chartId = btn.getAttribute('data-chart');
				if (chartId) exportChartPng(chartId);
			});
		});

		document.querySelectorAll('.d4h-export-csv').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var chartId = btn.getAttribute('data-chart');
				if (chartId) exportChartCsv(chartId);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
