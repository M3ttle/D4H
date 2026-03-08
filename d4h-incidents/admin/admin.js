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
		document.getElementById('d4h-incidents-results').style.display = 'block';

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

	document.getElementById('d4h-incidents-fetch') && document.getElementById('d4h-incidents-fetch').addEventListener('click', fetchData);

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

	document.getElementById('d4h-incidents-export-excel') && document.getElementById('d4h-incidents-export-excel').addEventListener('click', exportExcel);

	document.querySelectorAll('.d4h-export-png').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var chartId = btn.getAttribute('data-chart');
			if (chartId) exportChartPng(chartId);
		});
	});
})();
