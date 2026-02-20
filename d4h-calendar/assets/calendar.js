(function () {
	'use strict';

	var cfg = window.d4hCalendar || {};
	var restUrl = cfg.restUrl || '';
	var defaultView = cfg.defaultView || 'dayGridMonth';

	function init() {
		if (typeof FullCalendar === 'undefined') return;

		var els = document.querySelectorAll('.d4h-calendar');
		els.forEach(function (el) {
			if (el.dataset.d4hInitialized) return;
			el.dataset.d4hInitialized = '1';

			var cal = new FullCalendar.Calendar(el, {
				initialView: defaultView,
				eventSources: restUrl ? [{ url: restUrl }] : []
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
