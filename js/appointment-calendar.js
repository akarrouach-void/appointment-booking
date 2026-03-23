(function (Drupal, once) {
	'use strict';

	function pad(n) {
		return String(n).padStart(2, '0');
	}

	function toLocalIso(date) {
		return (
			date.getFullYear() +
			'-' +
			pad(date.getMonth() + 1) +
			'-' +
			pad(date.getDate()) +
			'T' +
			pad(date.getHours()) +
			':' +
			pad(date.getMinutes()) +
			':' +
			pad(date.getSeconds())
		);
	}

	function formatSlotLabel(start, end) {
		var dateFmt = new Intl.DateTimeFormat('fr-FR', {
			weekday: 'long',
			day: 'numeric',
			month: 'long',
			year: 'numeric',
		});
		var timeFmt = new Intl.DateTimeFormat('fr-FR', {
			hour: '2-digit',
			minute: '2-digit',
		});
		return (
			dateFmt.format(start) +
			' ' +
			timeFmt.format(start) +
			' - ' +
			timeFmt.format(end)
		);
	}

	function getSummaryEl(calEl) {
		var el = document.getElementById('slot-summary');
		if (!el) {
			el = document.createElement('p');
			el.id = 'slot-summary';
			el.className = 'appointment-slot-selected';
			calEl.insertAdjacentElement('afterend', el);
		}
		return el;
	}

	function getErrorEl(calEl) {
		var el = document.getElementById('slot-error');
		if (!el) {
			el = document.createElement('p');
			el.id = 'slot-error';
			el.className = 'messages messages--error';
			calEl.insertAdjacentElement('afterend', el);
		}
		return el;
	}

	function clearError() {
		var el = document.getElementById('slot-error');
		if (el) el.remove();
	}

	function clearSlotEvents(cal) {
		cal.getEvents().forEach(function (ev) {
			if (ev.extendedProps.type === 'slot') {
				ev.remove();
			}
		});
	}

	function resetColors(cal) {
		cal.getEvents().forEach(function (ev) {
			if (ev.extendedProps.type === 'slot') {
				ev.setProp('color', ev.extendedProps.available ? '#2e7d32' : '#c62828');
				ev.setExtendedProp('selected', false);
			}
		});
	}

	function fetchSlotsForDate(
		cal,
		calEl,
		date,
		adviserId,
		slotsUrl,
		excludeId,
		hiddenField,
	) {
		clearSlotEvents(cal);
		if (
			hiddenField &&
			hiddenField.value &&
			hiddenField.value.substring(0, 10) !== date
		) {
			hiddenField.value = '';
		}
		getSummaryEl(calEl).textContent = '';

		var url =
			slotsUrl +
			'?adviser_id=' +
			adviserId +
			'&date=' +
			date +
			'&exclude_id=' +
			(excludeId || 0);

		clearError();

		fetch(url)
			.then(function (r) {
				if (!r.ok) throw new Error();
				return r.json();
			})
			.then(function (slots) {
				if (!slots.length) {
					getErrorEl(calEl).textContent = Drupal.t(
						'No available slots on this date.',
					);
					return;
				}

				slots.forEach(function (slot) {
					cal.addEvent({
						start: slot.start,
						end: slot.end,
						allDay: false,
						title: slot.available
							? Drupal.t('Available')
							: Drupal.t('Unavailable'),
						color: slot.available ? '#2e7d32' : '#c62828',
						extendedProps: { type: 'slot', available: slot.available },
					});
				});

				// Restore saved selection.
				var existing = hiddenField ? hiddenField.value : '';
				if (existing && existing.startsWith(date)) {
					cal.getEvents().forEach(function (ev) {
						if (
							ev.extendedProps.type === 'slot' &&
							toLocalIso(ev.start) === existing &&
							ev.extendedProps.available
						) {
							ev.setProp('color', '#1565c0');
							ev.setExtendedProp('selected', true);
							getSummaryEl(calEl).innerHTML =
								'<strong>' +
								Drupal.t('Selected:') +
								'</strong> ' +
								formatSlotLabel(ev.start, ev.end);
						}
					});
				}
			})
			.catch(function () {
				getErrorEl(calEl).textContent = Drupal.t(
					'Could not load slots. Please try again.',
				);
			});
	}

	Drupal.behaviors.appointmentCalendar = {
		attach: function (context) {
			once('appointment-calendar', '#appointment-calendar', context).forEach(
				function (calEl) {
					var adviserId = calEl.getAttribute('data-adviser') || 0;
					var slotsUrl =
						calEl.getAttribute('data-slots-url') || '/appointment/slots';
					var excludeId = calEl.getAttribute('data-exclude-id') || 0;
					var hiddenField = document.getElementById(
						'appointment-selected-date',
					);

					var cal = new FullCalendar.Calendar(calEl, {
						initialView: 'timeGridWeek',
						locale: 'fr',
						slotMinTime: '08:00:00',
						slotMaxTime: '18:00:00',
						slotDuration: '00:30:00',
						allDaySlot: false,
						nowIndicator: true,
						headerToolbar: {
							left: 'prev,next today',
							center: 'title',
							right: '',
						},
						events: [],

						// On day click — load slots for that specific day only.
						dateClick: function (info) {
							var date = info.dateStr.substring(0, 10);
							fetchSlotsForDate(
								cal,
								calEl,
								date,
								adviserId,
								slotsUrl,
								excludeId,
								hiddenField,
							);
						},

						eventClick: function (info) {
							if (info.event.extendedProps.type !== 'slot') return;
							if (!info.event.extendedProps.available) {
								getErrorEl(calEl).textContent = Drupal.t(
									'This slot is already booked. Please choose another.',
								);
								return;
							}
							clearError();
							resetColors(cal);
							info.event.setProp('color', '#1565c0');
							info.event.setExtendedProp('selected', true);

							if (hiddenField) {
								hiddenField.value = toLocalIso(info.event.start);
							}
							getSummaryEl(calEl).innerHTML =
								'<strong>' +
								Drupal.t('Selected:') +
								'</strong> ' +
								formatSlotLabel(info.event.start, info.event.end);
						},
					});

					cal.render();

					// If returning to step 4, restore previously selected date's slots.
					var existing = hiddenField ? hiddenField.value : '';
					if (existing.length >= 10) {
						fetchSlotsForDate(
							cal,
							calEl,
							existing.substring(0, 10),
							adviserId,
							slotsUrl,
							excludeId,
							hiddenField,
						);
					}
				},
			);
		},
	};
})(Drupal, once);
