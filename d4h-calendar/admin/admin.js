(function () {
	'use strict';

	function init() {
	var cfg = window.d4hCalendarAdmin || {};
	var ajaxUrl = cfg.ajaxUrl || '';
	var nonce = cfg.nonce || '';
	var actionSync = cfg.actionSync || 'd4h_calendar_ajax_sync';
	var actionDelete = cfg.actionDelete || 'd4h_calendar_ajax_delete';
	var i18n = cfg.i18n || {};

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text || '';
		return div.innerHTML;
	}

	function showMessage(text, type) {
		var el = document.getElementById('d4h-admin-message');
		if (!el) return;
		el.className = 'notice notice-' + (type || 'info') + ' is-dismissible';
		el.innerHTML = '<p></p>';
		el.querySelector('p').textContent = text || '';
		el.style.display = 'block';
	}

	function hideMessage() {
		var el = document.getElementById('d4h-admin-message');
		if (el) el.style.display = 'none';
	}

	function setLastUpdated(text) {
		var el = document.getElementById('d4h-last-updated');
		if (el) el.textContent = text || 'Never';
	}

	function setLastSyncStatus(status, errorMessage) {
		var wrap = document.getElementById('d4h-last-sync-status');
		if (!wrap) return;
		var label = i18n.lastSyncStatus || 'Last sync status:';
		var successLabel = i18n.success || 'Success';
		var errorLabel = i18n.error || 'Error';
		var text = status === 'success' ? successLabel : (errorMessage || errorLabel);
		if (status === 'error' && errorMessage) {
			wrap.innerHTML = '<div class="notice notice-error inline"><p><strong>' + escapeHtml(label) + '</strong> <span id="d4h-last-sync-status-text">' + escapeHtml(errorMessage) + '</span></p></div>';
		} else {
			wrap.innerHTML = '<p><strong>' + escapeHtml(label) + '</strong> <span id="d4h-last-sync-status-text">' + escapeHtml(text) + '</span></p>';
		}
	}

	function prependSyncHistoryRow(entry) {
		var tbody = document.getElementById('d4h-sync-history-tbody');
		if (!tbody) return;
		var emptyRow = document.getElementById('d4h-sync-history-empty-row');
		if (emptyRow) emptyRow.remove();
		var successLabel = i18n.success || 'Success';
		var errorLabel = i18n.error || 'Error';
		var manualLabel = i18n.manual || 'Manual';
		var cronLabel = i18n.cron || 'Cron';
		var statusText = (entry.status === 'success') ? successLabel : errorLabel;
		var statusColor = (entry.status === 'success') ? '#00a32a' : '#d63638';
		var sourceText = (entry.source === 'cron') ? cronLabel : manualLabel;
		var durationText = (entry.duration_sec != null) ? Number(entry.duration_sec).toFixed(2) + ' s' : '—';
		var errorText = entry.error || '—';
		var timeText = entry.formatted_time || '—';
		var tr = document.createElement('tr');
		tr.innerHTML =
			'<td>' + escapeHtml(timeText) + '</td>' +
			'<td><span style="color:' + statusColor + ';">' + escapeHtml(statusText) + '</span></td>' +
			'<td>' + escapeHtml(sourceText) + '</td>' +
			'<td>' + escapeHtml(durationText) + '</td>' +
			'<td>' + escapeHtml(errorText) + '</td>';
		tbody.insertBefore(tr, tbody.firstChild);
	}

	function setButtonLoading(btnId, loading, loadingLabel) {
		var btn = document.getElementById(btnId);
		if (!btn) return;
		btn.disabled = !!loading;
		if (loading) {
			btn.dataset.originalHtml = btn.innerHTML;
			btn.innerHTML = '<span class="spinner is-active" style="float:none;display:inline-block;margin:0 4px 0 0;vertical-align:middle;"></span> ' + (loadingLabel || i18n.updating || '...');
		} else if (btn.dataset.originalHtml) {
			btn.innerHTML = btn.dataset.originalHtml;
			delete btn.dataset.originalHtml;
		}
	}

	// Update calendar (sync)
	var updateBtn = document.getElementById('d4h-update-now');
	if (updateBtn) {
		updateBtn.addEventListener('click', function () {
			hideMessage();
			setButtonLoading('d4h-update-now', true, i18n.updating);

			var formData = new FormData();
			formData.append('action', actionSync);
			formData.append('nonce', nonce);

			fetch(ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var payload = data.data || {};
					if (data.success) {
						setLastUpdated(payload.last_updated);
						setLastSyncStatus(payload.last_sync_status || 'success', payload.last_sync_error);
						if (payload.sync_history_entry) {
							prependSyncHistoryRow(payload.sync_history_entry);
						}
						showMessage(i18n.syncSuccess || 'Sync completed successfully.', 'success');
					} else {
						setLastSyncStatus(payload.last_sync_status || 'error', payload.last_sync_error || payload.message);
						if (payload.sync_history_entry) {
							prependSyncHistoryRow(payload.sync_history_entry);
						}
						showMessage(payload.message || 'Sync failed.', 'error');
					}
				})
				.catch(function () {
					showMessage('Request failed.', 'error');
				})
				.finally(function () {
					setButtonLoading('d4h-update-now', false);
				});
		});
	}

	// Sortable tag priority (order determines which tag color wins for events with multiple tags)
	var tagsSortable = document.getElementById('d4h-tags-sortable');
	if (tagsSortable && typeof jQuery !== 'undefined' && jQuery.fn.sortable) {
		jQuery('#d4h-tags-sortable').sortable({
			handle: '.d4h-drag-handle',
			placeholder: 'ui-sortable-placeholder'
		});
	}

	// Sync color picker with hex text display (bidirectional)
	document.querySelectorAll('input[type="color"]').forEach(function (picker) {
		picker.addEventListener('input', function () {
			var next = picker.nextElementSibling;
			if (next && next.classList.contains('d4h-hex-input')) {
				next.value = picker.value;
			}
		});
	});
	document.querySelectorAll('.d4h-hex-input').forEach(function (hexInput) {
		hexInput.addEventListener('input', function () {
			var pickerId = hexInput.getAttribute('data-color-for');
			var picker = pickerId ? document.getElementById(pickerId) : hexInput.previousElementSibling;
			if (!picker || picker.type !== 'color') return;
			var val = (hexInput.value || '').trim();
			if (/^#?[0-9a-fA-F]{6}$/.test(val)) {
				val = val.charAt(0) === '#' ? val : '#' + val;
				picker.value = val;
			}
		});
	});

	// Delete old data
	var deleteBtn = document.getElementById('d4h-delete-old');
	if (deleteBtn) {
		deleteBtn.addEventListener('click', function () {
			if (!confirm('Delete data older than the retention period?')) return;

			hideMessage();
			setButtonLoading('d4h-delete-old', true);

			var formData = new FormData();
			formData.append('action', actionDelete);
			formData.append('nonce', nonce);

			fetch(ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.success) {
						var n = data.data && data.data.deleted !== undefined ? data.data.deleted : 0;
						showMessage('Deleted ' + n + ' row(s).', 'success');
					} else {
						showMessage(data.data && data.data.message ? data.data.message : 'Delete failed.', 'error');
					}
				})
				.catch(function () {
					showMessage('Request failed.', 'error');
				})
				.finally(function () {
					setButtonLoading('d4h-delete-old', false);
				});
		});
	}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
