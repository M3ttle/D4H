(function () {
	'use strict';

	var cfg = window.d4hCalendar || {};
	var restUrl = cfg.restUrl || '';
	var defaultView = cfg.defaultView || 'dayGridMonth';
	var locale = cfg.locale || 'is';
	var contentHeight = cfg.contentHeight != null ? cfg.contentHeight : 700;

	function formatEventDate(d, allDay) {
		d = toNativeDate(d);
		if (!d) return '';
		var dd = String(d.getDate()).padStart(2, '0');
		var mm = String(d.getMonth() + 1).padStart(2, '0');
		var yyyy = d.getFullYear();
		var dateStr = dd + '/' + mm + '/' + yyyy;
		if (allDay) return dateStr;
		var hh = String(d.getHours()).padStart(2, '0');
		var min = String(d.getMinutes()).padStart(2, '0');
		return dateStr + ' ' + hh + ':' + min;
	}

	function showEventModal(event) {
		var props = event.extendedProps || {};
		var title = event.title || (props.resourceType === 'exercise' ? 'Æfing' : 'Viðburður');
		var startStr = event.start ? formatEventDate(event.start, event.allDay) : '';
		var endStr = event.end ? formatEventDate(event.end, event.allDay) : '';
		var desc = props.description || props.referenceDescription || props.reference || '';

		var html = '<div class="d4h-calendar-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="d4h-calendar-modal-title">';
		html += '<div class="d4h-calendar-modal">';
		html += '<h3 id="d4h-calendar-modal-title">' + escapeHtml(title) + '</h3>';
		html += '<dl>';
		if (startStr || endStr) {
			html += '<dt>Tími</dt><dd>' + escapeHtml(startStr) + (endStr ? ' – ' + escapeHtml(endStr) : '') + '</dd>';
		}
		html += '<dt>Tegund</dt><dd>' + escapeHtml(props.resourceType === 'exercise' ? 'Æfing' : 'Viðburður') + '</dd>';
		if (desc) {
			html += '<dt>Lýsing</dt><dd class="d4h-calendar-modal-description">' + escapeHtml(desc) + '</dd>';
		}
		html += '</dl>';
		html += '<button type="button" class="d4h-calendar-modal-close" aria-label="Loka">Loka</button>';
		html += '</div></div>';

		var overlay = document.createElement('div');
		overlay.innerHTML = html;
		overlay = overlay.firstElementChild;
		document.body.appendChild(overlay);

		function close() {
			document.body.removeChild(overlay);
			document.removeEventListener('keydown', onKey);
		}

		function onKey(e) {
			if (e.key === 'Escape') close();
		}

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) close();
		});
		overlay.querySelector('.d4h-calendar-modal-close').addEventListener('click', close);
		document.addEventListener('keydown', onKey);
	}

	function escapeHtml(s) {
		var div = document.createElement('div');
		div.textContent = s;
		return div.innerHTML;
	}

	var icelandicMonths = [
		'janúar', 'febrúar', 'mars', 'apríl', 'maí', 'júní',
		'júlí', 'ágúst', 'september', 'október', 'nóvember', 'desember',
	];

	function toNativeDate(d) {
		if (!d) return null;
		if (typeof d.getMonth === 'function') return d;
		if (typeof d.toDate === 'function') return d.toDate();
		if (typeof d.toISOString === 'function') return new Date(d.toISOString());
		if (typeof d === 'string' || typeof d === 'number') return new Date(d);
		return null;
	}

	function formatTitle(dateLike) {
		var d = toNativeDate(dateLike);
		if (!d) return '';
		var m = d.getMonth();
		var y = d.getFullYear();
		return icelandicMonths[m] + ' ' + y;
	}

	function init() {
		if (typeof FullCalendar === 'undefined') return;

		var els = document.querySelectorAll('.d4h-calendar');
		els.forEach(function (el) {
			if (el.dataset.d4hInitialized) return;
			el.dataset.d4hInitialized = '1';

			var cal = new FullCalendar.Calendar(el, {
				locale: locale,
				initialView: defaultView,
				contentHeight: contentHeight,
				headerToolbar: {
					left: 'prev,next today',
					center: 'title',
					right: 'dayGridMonth,timeGridWeek,timeGridDay',
				},
				eventSources: restUrl ? [{ url: restUrl }] : [],
				dateClick: function (info) {
					cal.changeView('timeGridDay', info.dateStr);
				},
				eventClick: function (info) {
					info.jsEvent.preventDefault();
					showEventModal(info.event);
				},
				dayHeaderContent: function (arg) {
					var labels = { 0: 'Sun', 1: 'Mán', 2: 'Þri', 3: 'Mið', 4: 'Fim', 5: 'Fös', 6: 'Lau' };
					var d = toNativeDate(arg && arg.date);
					return d ? labels[d.getDay()] : '';
				},
				eventTimeFormat: {
					hour: '2-digit',
					minute: '2-digit',
					hour12: false,
				},
				slotLabelFormat: {
					hour: '2-digit',
					minute: '2-digit',
					hour12: false,
				},
				titleFormat: { year: 'numeric', month: 'long' },
			});
			cal.render();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
