(function () {
	'use strict';

	var cfg = window.d4hCalendarAdmin || {};
	var ajaxUrl = cfg.ajaxUrl || '';
	var nonce = cfg.nonce || '';
	var actionSync = cfg.actionSync || 'd4h_calendar_ajax_sync';
	var actionDelete = cfg.actionDelete || 'd4h_calendar_ajax_delete';

	function showMessage(text, type) {
		var el = document.getElementById('d4h-admin-message');
		if (!el) return;
		el.className = 'notice notice-' + (type || 'info') + ' is-dismissible';
		el.innerHTML = '<p>' + (text || '') + '</p>';
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

	function setButtonLoading(btnId, loading) {
		var btn = document.getElementById(btnId);
		if (!btn) return;
		btn.disabled = !!loading;
		if (loading) {
			btn.dataset.originalText = btn.textContent;
			btn.textContent = '...';
		} else if (btn.dataset.originalText) {
			btn.textContent = btn.dataset.originalText;
		}
	}

	// Update now
	var updateBtn = document.getElementById('d4h-update-now');
	if (updateBtn) {
		updateBtn.addEventListener('click', function () {
			hideMessage();
			setButtonLoading('d4h-update-now', true);

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
					if (data.success && data.data && data.data.last_updated) {
						setLastUpdated(data.data.last_updated);
						showMessage('Sync completed successfully.', 'success');
					} else {
						showMessage(data.data && data.data.message ? data.data.message : 'Sync failed.', 'error');
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
})();
