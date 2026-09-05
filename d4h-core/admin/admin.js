/**
 * Filter and row-limit controls for D4H Core log tables.
 */
(function () {
	'use strict';

	var settings = window.d4hCoreSettings || {};
	var labels = settings.i18n || {};

	/**
	 * Show matching rows up to the selected row count.
	 */
	function applyLogFilters(controls) {
		var tableId = controls.getAttribute('data-table');
		var table = tableId ? document.getElementById(tableId) : null;
		if (!table) {
			return;
		}

		var rowsSelect = controls.querySelector('.d4h-log-rows');
		var sourceSelect = controls.querySelector('.d4h-log-source');
		var statusSelect = controls.querySelector('.d4h-log-status');
		var periodSelect = controls.querySelector('.d4h-log-period');
		var summary = controls.querySelector('.d4h-log-summary');
		var rowLimit = parseInt(rowsSelect && rowsSelect.value ? rowsSelect.value : '10', 10);
		var sourceFilter = sourceSelect ? sourceSelect.value : '';
		var statusFilter = statusSelect ? statusSelect.value : '';
		var periodDays = periodSelect && periodSelect.value ? parseInt(periodSelect.value, 10) : 0;
		var cutoff = periodDays > 0 ? (Date.now() / 1000) - (periodDays * 24 * 60 * 60) : 0;
		var rows = table.querySelectorAll('tbody tr.d4h-log-row');
		var matched = 0;
		var shown = 0;

		rows.forEach(function (row) {
			var matches = true;
			if (sourceFilter && row.getAttribute('data-source') !== sourceFilter) {
				matches = false;
			}
			if (statusFilter && row.getAttribute('data-status') !== statusFilter) {
				matches = false;
			}
			if (cutoff > 0) {
				var rowTime = parseInt(row.getAttribute('data-time') || '0', 10);
				if (rowTime < cutoff) {
					matches = false;
				}
			}

			if (!matches) {
				row.style.display = 'none';
				return;
			}

			matched += 1;
			if (shown < rowLimit) {
				row.style.display = 'table-row';
				shown += 1;
			} else {
				row.style.display = 'none';
			}
		});

		if (!summary) {
			return;
		}

		var showingTemplate = labels.showingRows || 'Showing %1$d of %2$d matching rows.';
		summary.textContent = showingTemplate.replace('%1$d', String(shown)).replace('%2$d', String(matched));
		if (matched > shown) {
			var truncatedTemplate = labels.truncated || 'Only the first %d are listed. Choose 100 rows to see more.';
			summary.textContent += ' ' + truncatedTemplate.replace('%d', String(rowLimit));
		}
	}

	/**
	 * Set filters to errors from the last 60 days and show up to 100 rows.
	 */
	function showRecentErrors(controls) {
		var rowsSelect = controls.querySelector('.d4h-log-rows');
		var statusSelect = controls.querySelector('.d4h-log-status');
		var periodSelect = controls.querySelector('.d4h-log-period');
		if (statusSelect) {
			statusSelect.value = 'error';
		}
		if (periodSelect) {
			var sixtyDays = periodSelect.querySelector('option[value="60"]') || periodSelect.querySelector('option:not([value=""])');
			if (sixtyDays) {
				periodSelect.value = sixtyDays.value;
			}
		}
		if (rowsSelect) {
			rowsSelect.value = '100';
		}
		applyLogFilters(controls);
	}

	/**
	 * Bind filter controls for every log table on the page.
	 */
	function initLogTables() {
		document.querySelectorAll('.d4h-log-controls').forEach(function (controls) {
			['change', 'input'].forEach(function (eventName) {
				controls.addEventListener(eventName, function (event) {
					if (event.target && event.target.closest && event.target.closest('select')) {
						applyLogFilters(controls);
					}
				});
			});
			var errorsButton = controls.querySelector('.d4h-log-errors-60');
			if (errorsButton) {
				errorsButton.addEventListener('click', function () {
					showRecentErrors(controls);
				});
			}
			applyLogFilters(controls);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initLogTables);
	} else {
		initLogTables();
	}
})();
