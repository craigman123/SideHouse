/**
 * Manual Booking (admin Configuration page) — calendar modal, then a
 * time-picker modal for that date, same two-step feel as the guest
 * widget's own booking flow but with no equipment/payment steps: pick
 * hours, hit Add, and they land in the slot list on the form itself.
 *
 * I didn't have guest-book.js's full internals to import from directly,
 * so the calendar/time-slot rendering here is a fresh (smaller) version
 * of the same idea — no per-hour pricing, no peak-hour styling, no
 * overnight tail-session handling. If Side House's operating hours ever
 * cross midnight, the block below needs the same OVERNIGHT wraparound
 * logic guest-book.js uses in renderTimeSlots().
 */
(function () {
    const panel = document.getElementById('manualBookingPanel');
    if (!panel) return;

    const AVAILABILITY_URL = panel.dataset.availabilityUrl;
    const OPEN_HOUR = parseInt(panel.dataset.openHour, 10);
    const CLOSE_HOUR = parseInt(panel.dataset.closeHour, 10);
    const STEP_MINUTES = parseInt(panel.dataset.stepMinutes, 10);
    const OVERNIGHT = CLOSE_HOUR <= OPEN_HOUR;
    const CLOSED_WEEKDAYS = new Set(
        (panel.dataset.closedWeekdays || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean)
            .map(Number)
    );

    // One-off closed dates from the Closed Dates panel — each entry is
    // { date, court_id, reason }, court_id null meaning "all courts".
    // Only entries matching the currently-selected court (or with no
    // court at all) count as closed for that court's calendar.
    const CLOSURE_ENTRIES = (() => {
        try {
            return JSON.parse(panel.dataset.closureDates || '[]');
        } catch (err) {
            console.error('Bad closure-dates JSON', err);
            return [];
        }
    })();

    const courtSelect = document.getElementById('mb_court');
    const addSlotTrigger = document.getElementById('mbAddSlotTrigger');
    const slotList = document.getElementById('mbSlotList');
    const slotsError = document.getElementById('mbSlotsError');
    const hiddenSlotsContainer = document.getElementById('mbHiddenSlots');
    const form = document.getElementById('manual-booking-form');

    const calendarModal = document.getElementById('mbCalendarModal');
    const calMonthLabel = document.getElementById('mbCalMonthLabel');
    const calendarGrid = document.getElementById('mbCalendarGrid');
    const calPrev = document.getElementById('mbCalPrev');
    const calNext = document.getElementById('mbCalNext');
    const calClose = document.getElementById('mbCalendarClose');

    const timeModal = document.getElementById('mbTimeModal');
    const timeDateLabel = document.getElementById('mbTimeDateLabel');
    const timeSlotGrid = document.getElementById('mbTimeSlotGrid');
    const timeClose = document.getElementById('mbTimeClose');
    const timeCancel = document.getElementById('mbTimeCancel');
    const timeAdd = document.getElementById('mbTimeAdd');
    const backToCalendar = document.getElementById('mbBackToCalendar');
    const timePrevDayBtn = document.getElementById('mbTimePrevDay');
    const timeNextDayBtn = document.getElementById('mbTimeNextDay');

    let calendarCursor = new Date();
    let pickerDate = null;
    let bookedRanges = [];
    let selectedHours = []; // "HH:MM" strings picked in the currently-open time modal

    // Slots already committed to the booking: { date, start_time, end_time }
    let groups = [];

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function todayStr() {
        const d = new Date();
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    // Matching one-off closure entries for a date, scoped to whichever
    // court is currently selected (or "all courts" entries, which apply
    // no matter what's selected).
    function closureEntriesFor(dateStr) {
        return CLOSURE_ENTRIES.filter((entry) => {
            if (entry.date !== dateStr) return false;
            return entry.court_id === null || String(entry.court_id) === String(courtSelect.value);
        });
    }

    function isDateClosed(dateStr) {
        const weekday = new Date(`${dateStr}T00:00:00`).getDay();
        return CLOSED_WEEKDAYS.has(weekday) || closureEntriesFor(dateStr).length > 0;
    }

    function getClosureReason(dateStr) {
        const entries = closureEntriesFor(dateStr);
        if (entries.length > 0) {
            return entries[0].reason || 'Closed for the day.';
        }
        const weekday = new Date(`${dateStr}T00:00:00`).getDay();
        if (CLOSED_WEEKDAYS.has(weekday)) {
            return 'Closed every week on this day.';
        }
        return 'This date is closed.';
    }

    function timeToMinutes(t) {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    }

    function isSlotBookedAt(totalMin) {
        const slotEnd = totalMin + STEP_MINUTES;
        return bookedRanges.some((r) => {
            const rangeStart = timeToMinutes(r.start);
            const rangeEnd = timeToMinutes(r.end) || 1440;
            return totalMin < rangeEnd && slotEnd > rangeStart;
        });
    }

    // No 1-hour buffer here unlike the guest widget — an admin booking
    // someone in on the spot should be able to pick the current hour.
    function isSlotPastAt(dateStr, timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        const slotDate = new Date(`${dateStr}T00:00:00`);
        slotDate.setHours(h, m, 0, 0);
        return slotDate.getTime() < Date.now();
    }

    function formatHourLabel(totalMin) {
        const h = Math.floor(totalMin / 60) % 24;
        const m = totalMin % 60;
        const period = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 === 0 ? 12 : h % 12;
        return `${h12}:${pad(m)} ${period}`;
    }

    function formatSlotRange(totalMin) {
        return `${formatHourLabel(totalMin)} \u2013 ${formatHourLabel(totalMin + STEP_MINUTES)}`;
    }

    function formatDateLabel(dateStr) {
        return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
        });
    }

    // ---------- Calendar modal ----------

    function openCalendarModal() {
        if (!courtSelect.value) {
            courtSelect.focus();
            alert('Pick a court first.');
            return;
        }
        calendarCursor = new Date();
        renderCalendar();
        calendarModal.classList.add('open');
    }

    function closeCalendarModal() {
        calendarModal.classList.remove('open');
    }

    function renderCalendar() {
        const year = calendarCursor.getFullYear();
        const month = calendarCursor.getMonth();
        calMonthLabel.textContent = calendarCursor.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = todayStr();

        calendarGrid.innerHTML = '';

        for (let i = 0; i < firstDay; i++) {
            const blank = document.createElement('span');
            blank.className = 'mb-calendar-day mb-calendar-day-empty';
            calendarGrid.appendChild(blank);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${pad(month + 1)}-${pad(d)}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mb-calendar-day';
            btn.textContent = d;

            const isPast = dateStr < today;
            const isClosed = !isPast && isDateClosed(dateStr);

            if (isPast) {
                btn.disabled = true;
            } else if (isClosed) {
                const reason = getClosureReason(dateStr);
                btn.classList.add('mb-calendar-day-closed');
                btn.title = reason;
                btn.addEventListener('click', () => alert(reason));
            } else {
                btn.addEventListener('click', () => selectDate(dateStr));
            }

            if (dateStr === today) btn.classList.add('mb-calendar-day-today');
            calendarGrid.appendChild(btn);
        }
    }

    calPrev.addEventListener('click', () => {
        calendarCursor.setMonth(calendarCursor.getMonth() - 1);
        renderCalendar();
    });
    calNext.addEventListener('click', () => {
        calendarCursor.setMonth(calendarCursor.getMonth() + 1);
        renderCalendar();
    });
    calClose.addEventListener('click', closeCalendarModal);
    calendarModal.addEventListener('click', (e) => {
        if (e.target === calendarModal) closeCalendarModal();
    });
    addSlotTrigger.addEventListener('click', openCalendarModal);

    // ---------- Time picker modal ----------

    async function selectDate(dateStr) {
        pickerDate = dateStr;
        selectedHours = [];
        closeCalendarModal();
        timeDateLabel.textContent = formatDateLabel(dateStr);
        updateDayNavState();
        timeSlotGrid.innerHTML = '<p class="mb-loading">Loading availability\u2026</p>';
        timeModal.classList.add('open');

        const availability = await fetchAvailability(dateStr);
        bookedRanges = availability.booked;

        if (availability.closed) {
            timeSlotGrid.innerHTML = `<p class="mb-loading">${availability.closedReason || 'This date is closed.'}</p>`;
            return;
        }

        renderTimeSlots();
    }

    // ---------- Day switch (prev/next arrows beside the date, inside
    // the time picker modal) — lets the admin flip through days without
    // backing out to the calendar. Any hours picked but not yet added
    // for the day being left are discarded, same as closing the modal
    // without hitting Add would do.

    function addDaysToDateStr(dateStr, deltaDays) {
        const [y, m, d] = dateStr.split('-').map(Number);
        const next = new Date(y, m - 1, d + deltaDays);
        return `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`;
    }

    function updateDayNavState() {
        if (timePrevDayBtn) {
            timePrevDayBtn.disabled = !pickerDate || pickerDate <= todayStr();
        }
    }

    // Skips over closed days in either direction (up to 60 tries) so a
    // stretch of e.g. weekly-closed Mondays doesn't strand the admin on
    // a dead end.
    function shiftPickerDate(deltaDays) {
        if (!pickerDate) return;
        let next = pickerDate;
        for (let i = 0; i < 60; i++) {
            next = addDaysToDateStr(next, deltaDays);
            if (next < todayStr()) return;
            if (!isDateClosed(next)) {
                selectDate(next);
                return;
            }
        }
    }

    if (timePrevDayBtn) timePrevDayBtn.addEventListener('click', () => shiftPickerDate(-1));
    if (timeNextDayBtn) timeNextDayBtn.addEventListener('click', () => shiftPickerDate(1));

    async function fetchAvailability(dateStr) {
        try {
            const url = `${AVAILABILITY_URL}?court_id=${courtSelect.value}&date=${dateStr}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load availability');
            const data = await res.json();
            return {
                booked: (data.booked || []).map((b) => ({ start: b.start, end: b.end })),
                closed: !!data.closed,
                closedReason: data.closed_reason || null,
            };
        } catch (err) {
            console.error(err);
            return { booked: [], closed: false, closedReason: null };
        }
    }

    function renderTimeSlots() {
        timeSlotGrid.innerHTML = '';
        const spanMinutes = OVERNIGHT ? (24 - OPEN_HOUR + CLOSE_HOUR) * 60 : (CLOSE_HOUR - OPEN_HOUR) * 60;
        const startMin = OPEN_HOUR * 60;

        for (let offset = 0; offset + STEP_MINUTES <= spanMinutes; offset += STEP_MINUTES) {
            const totalMin = (startMin + offset) % 1440;
            const timeStr = `${pad(Math.floor(totalMin / 60))}:${pad(totalMin % 60)}`;

            const booked = isSlotBookedAt(totalMin);
            const past = isSlotPastAt(pickerDate, timeStr);
            const unavailable = booked || past;
            const isSelected = selectedHours.includes(timeStr);

            const row = document.createElement('div');
            row.className = 'mb-time-slot-row'
                + (isSelected ? ' selected' : '')
                + (unavailable ? ' disabled' : '');

            const label = document.createElement('span');
            label.textContent = formatSlotRange(totalMin);

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'mb-time-slot-toggle' + (isSelected ? ' selected' : '');
            toggle.textContent = booked ? 'Booked' : past ? 'Past' : (isSelected ? '\u2713 Selected' : 'Available');

            if (unavailable) {
                toggle.disabled = true;
            } else {
                toggle.addEventListener('click', () => toggleHour(timeStr));
            }

            row.appendChild(label);
            row.appendChild(toggle);
            timeSlotGrid.appendChild(row);
        }
    }

    function toggleHour(timeStr) {
        const idx = selectedHours.indexOf(timeStr);
        if (idx >= 0) {
            selectedHours.splice(idx, 1);
        } else {
            selectedHours.push(timeStr);
            selectedHours.sort();
        }
        renderTimeSlots();
    }

    function closeTimeModal() {
        timeModal.classList.remove('open');
    }

    timeClose.addEventListener('click', closeTimeModal);
    timeCancel.addEventListener('click', closeTimeModal);
    timeModal.addEventListener('click', (e) => {
        if (e.target === timeModal) closeTimeModal();
    });
    backToCalendar.addEventListener('click', () => {
        closeTimeModal();
        openCalendarModal();
    });

    // Collapses picked hours (e.g. 18:00, 19:00, 20:00) into contiguous
    // ranges — a gap (18:00 + 20:00, skipping 19:00) becomes two ranges,
    // each its own BookingSlot row once submitted.
    function collapseToRanges(hours) {
        const ranges = [];
        let rangeStart = null;
        let prev = null;

        hours.forEach((h) => {
            const min = timeToMinutes(h);
            if (rangeStart === null) {
                rangeStart = min;
            } else if (min !== prev + STEP_MINUTES) {
                ranges.push([rangeStart, prev + STEP_MINUTES]);
                rangeStart = min;
            }
            prev = min;
        });
        if (rangeStart !== null) ranges.push([rangeStart, prev + STEP_MINUTES]);

        return ranges.map(([s, e]) => ({
            start: `${pad(Math.floor(s / 60) % 24)}:${pad(s % 60)}`,
            end: `${pad(Math.floor(e / 60) % 24)}:${pad(e % 60)}`,
        }));
    }

    timeAdd.addEventListener('click', () => {
        if (selectedHours.length === 0) {
            alert('Pick at least one hour.');
            return;
        }
        collapseToRanges(selectedHours).forEach((range) => {
            groups.push({ date: pickerDate, start_time: range.start, end_time: range.end });
        });
        renderSlotList();
        closeTimeModal();
    });

    // ---------- Slot list on the form ----------

    function renderSlotList() {
        slotList.innerHTML = '';
        groups.forEach((g, i) => {
            const row = document.createElement('div');
            row.className = 'mb-slot-chip';
            row.innerHTML = `
                <span>${formatDateLabel(g.date)} \u00b7 ${g.start_time}\u2013${g.end_time}</span>
                <button type="button" class="mb-remove-chip" aria-label="Remove">&times;</button>
            `;
            row.querySelector('.mb-remove-chip').addEventListener('click', () => {
                groups.splice(i, 1);
                renderSlotList();
            });
            slotList.appendChild(row);
        });
        slotsError.hidden = true;
    }

    // Old slots were checked against the previous court's availability —
    // they don't mean anything once the court changes, so clear them.
    courtSelect.addEventListener('change', () => {
        if (groups.length > 0) {
            groups = [];
            renderSlotList();
        }
    });

    form.addEventListener('submit', (e) => {
        if (groups.length === 0) {
            e.preventDefault();
            slotsError.hidden = false;
            return;
        }
        hiddenSlotsContainer.innerHTML = '';
        groups.forEach((g, i) => {
            ['date', 'start_time', 'end_time'].forEach((field) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `slots[${i}][${field}]`;
                input.value = g[field];
                hiddenSlotsContainer.appendChild(input);
            });
        });
    });
})();