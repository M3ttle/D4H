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

	// Retrieve Calendar data (sync)
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

	// GitHub token show/hide toggle (default hidden)
	var githubToggle = document.getElementById('d4h-github-token-toggle');
	var githubForm = document.getElementById('d4h-github-token-form');
	if (githubToggle && githubForm) {
		var showLabel = githubToggle.querySelector('.d4h-toggle-show');
		var hideLabel = githubToggle.querySelector('.d4h-toggle-hide');
		githubToggle.addEventListener('click', function () {
			var hidden = githubForm.style.display === 'none';
			githubForm.style.display = hidden ? 'block' : 'none';
			githubForm.setAttribute('aria-hidden', hidden ? 'false' : 'true');
			githubToggle.setAttribute('aria-expanded', hidden ? 'true' : 'false');
			if (showLabel) showLabel.style.display = hidden ? 'none' : 'inline';
			if (hideLabel) hideLabel.style.display = hidden ? 'inline' : 'none';
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
})();
