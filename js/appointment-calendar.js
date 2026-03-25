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

	function fetchBookedSlots(cal, startStr, endStr, adviserId, excludeId) {
		var start = startStr.substring(0, 10);
		var end = endStr.substring(0, 10);
		
		var url = '/appointment/booked-slots' +
			'?adviser_id=' + adviserId +
			'&start=' + start +
			'&end=' + end +
			'&exclude_id=' + (excludeId || 0);

		fetch(url)
			.then(function(r) { return r.json(); })
			.then(function(slots) {
				cal.getEvents().forEach(function(ev) {
					if (ev.extendedProps.type === 'slot' && ev.extendedProps.available === false) {
						ev.remove();
					}
				});
				slots.forEach(function (slot) {
					var exists = false;
					cal.getEvents().forEach(function(ev) {
						if (toLocalIso(ev.start) === slot.start && ev.extendedProps.type === 'slot') {
							exists = true;
						}
					});
					if (!exists) {
						cal.addEvent({
							start: slot.start,
							end: slot.end,
							allDay: false,
							title: Drupal.t('Unavailable'),
							color: '#c62828',
							extendedProps: { type: 'slot', available: false },
						});
					}
				});
			});
	}

	Drupal.behaviors.appointmentCalendar = {
		attach: function (context) {
			once('appointment-calendar', '#appointment-calendar', context).forEach(
				function (calEl) {
					var adviserId = calEl.getAttribute('data-adviser') || 0;
					var excludeId = calEl.getAttribute('data-exclude-id') || 0;
					var hiddenField = document.getElementById(
						'appointment-selected-date',
					);

					var today = new Date();
					today.setHours(0, 0, 0, 0);

					var cal = new FullCalendar.Calendar(calEl, {
						initialView: 'timeGridWeek',
						locale: 'fr',
						validRange: {
							start: today
						},
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

						datesSet: function (info) {
							fetchBookedSlots(cal, info.startStr, info.endStr, adviserId, excludeId);
						},

						// On empty cell click — create a blue selection by checking with the backend
						dateClick: function (info) {
							var clickedDate = info.date;

							if (clickedDate < new Date()) {
								clearError();
								getErrorEl(calEl).textContent = Drupal.t('You cannot select a past date or time.');
								return;
							}

							var endDateTime = new Date(clickedDate.getTime() + 30*60*1000);
							var datetimeStr = toLocalIso(clickedDate);
							
							var url = '/appointment/check-slot' +
								'?adviser_id=' + adviserId +
								'&datetime=' + datetimeStr +
								'&exclude_id=' + excludeId;

							clearError();

							fetch(url)
								.then(function(r) { return r.json(); })
								.then(function(data) {
									if (!data.available) {
										getErrorEl(calEl).textContent = data.message || Drupal.t('This slot is invalid.');
										return;
									}
									
									// Remove any previously selected blue slot
									cal.getEvents().forEach(function(ev) {
										if (ev.extendedProps.type === 'slot' && ev.extendedProps.available === true) {
											ev.remove();
										}
									});
									
									// Add the new selected blue slot
									cal.addEvent({
										start: clickedDate,
										end: endDateTime,
										allDay: false,
										title: Drupal.t('Selected'),
										color: '#1565c0',
										extendedProps: { type: 'slot', available: true, selected: true }
									});
									
									if (hiddenField) {
										hiddenField.value = datetimeStr;
									}
									getSummaryEl(calEl).innerHTML =
										'<strong>' +
										Drupal.t('Selected:') +
										'</strong> ' +
										formatSlotLabel(clickedDate, endDateTime);
								})
								.catch(function() {
									getErrorEl(calEl).textContent = Drupal.t('Could not verify slot. Please try again.');
								});
						},

						eventClick: function (info) {
							if (info.event.extendedProps.type !== 'slot') return;
							if (!info.event.extendedProps.available) {
								getErrorEl(calEl).textContent = Drupal.t(
									'This slot is already booked. Please choose another.',
								);
								return;
							}
							// If it's already a blue slot, do nothing
						},
					});

					cal.render();

					// If returning to step 4, restore previously selected date's slots.
					var existing = hiddenField ? hiddenField.value : '';
					if (existing.length >= 16) {
						var startDT = new Date(existing);
						var endDT = new Date(startDT.getTime() + 30*60*1000);
						cal.addEvent({
							start: startDT,
							end: endDT,
							allDay: false,
							title: Drupal.t('Selected'),
							color: '#1565c0',
							extendedProps: { type: 'slot', available: true, selected: true }
						});
						getSummaryEl(calEl).innerHTML =
							'<strong>' +
							Drupal.t('Selected:') +
							'</strong> ' +
							formatSlotLabel(startDT, endDT);
					}
				},
			);
		},
	};
})(Drupal, once);
