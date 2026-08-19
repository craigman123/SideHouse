document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('courtGrid');
    if (!grid) return;

    const availabilityUrl = grid.dataset.availabilityUrl;
    const equipmentUrl = grid.dataset.equipmentUrl;
    const storeUrl = grid.dataset.storeUrl;
    const statusUrlTemplate = grid.dataset.statusUrlTemplate;
    const cancelUrlTemplate = grid.dataset.cancelUrlTemplate;
    const userPhone = grid.dataset.userPhone || '';

    const OPEN_HOUR = parseInt(grid.dataset.openHour, 10);
    const CLOSE_HOUR = parseInt(grid.dataset.closeHour, 10);
    // Slot *counts* (not hours) — e.g. MIN_DURATION=2 with 30-min steps
    // means "at least 1 hour selected".
    const MIN_DURATION = parseFloat(grid.dataset.minDuration);
    const MAX_DURATION = parseFloat(grid.dataset.maxDuration);
    const STEP_MINUTES = parseInt(grid.dataset.stepMinutes, 10);
    const OVERNIGHT = CLOSE_HOUR <= OPEN_HOUR;

    const CLOSED_WEEKDAYS = new Set(
        (grid.dataset.closedWeekdays || '')
            .split(',')
            .map((s) => s.trim())
            .filter((s) => s !== '')
            .map(Number)
    );
    const CLOSURE_DATES = new Set(
        (grid.dataset.closureDates || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean)
    );
    const COURT_CLOSURES = new Set(
        (grid.dataset.courtClosures || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean)
    );

    function isDateClosed(dateStr) {
        if (CLOSURE_DATES.has(dateStr)) return true;
        const weekday = new Date(`${dateStr}T00:00:00`).getDay();
        if (CLOSED_WEEKDAYS.has(weekday)) return true;
        if (selectedCourt && COURT_CLOSURES.has(`${selectedCourt.id}:${dateStr}`)) return true;
        return false;
    }

    // Slot must start at least 1 hour from now. dateStr is the row's own
    // real calendar date — every row on a picker page is a real hour on
    // `selectedDate` itself now (see renderTimeSlots), whether it's the
    // tail of last night's session or the start of today's.
    function isSlotPastAt(dateStr, timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        const slotDate = new Date(`${dateStr}T00:00:00`);
        slotDate.setHours(h, m, 0, 0);
        return slotDate.getTime() <= Date.now() + 60 * 60 * 1000;
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function csrfHeaders() {
        const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (metaToken) return { 'X-CSRF-TOKEN': metaToken };

        const cookieToken = getCookie('XSRF-TOKEN');
        if (cookieToken) return { 'X-XSRF-TOKEN': cookieToken };

        return {};
    }

    // ---------- Element refs ----------

    const infoModal = document.getElementById('courtInfoModal');
    const bookingModal = document.getElementById('courtBookingModal');
    const timePickerModal = document.getElementById('timePickerModal');
    const equipmentModal = document.getElementById('equipmentModal');
    const paymentModal = document.getElementById('courtPaymentModal');
    const gcashWaitModal = document.getElementById('gcashWaitModal');

    const modalCourtName = document.getElementById('modalCourtName');
    const modalCourtNameBooking = document.getElementById('modalCourtNameBooking');
    const modalCourtType = document.getElementById('modalCourtType');
    const modalCourtDim = document.getElementById('modalCourtDim');
    const modalCourtPrice = document.getElementById('modalCourtPrice');

    const calMonthLabel = document.getElementById('calMonthLabel');
    const calendarGrid = document.getElementById('calendarGrid');
    const calPrev = document.getElementById('calPrev');
    const calNext = document.getElementById('calNext');

    const timePickerDateLabel = document.getElementById('timePickerDateLabel');
    const timePickerPrevDay = document.getElementById('timePickerPrevDay');
    const timePickerNextDay = document.getElementById('timePickerNextDay');
    const timeSlotGrid = document.getElementById('timeSlotGrid');
    const timePickerFeeRanges = document.getElementById('timePickerFeeRanges');
    const timePickerFeeTotal = document.getElementById('timePickerFeeTotal');
    const continueToEquipmentBtn = document.getElementById('continueToEquipment');

    const equipmentGrid = document.getElementById('equipmentGrid');
    const continueToPaymentBtn = document.getElementById('continueToPayment');

    const contactNumberInput = document.getElementById('contactNumber');
    const paymentGrid = document.getElementById('paymentGrid');
    const gcashQrPanel = document.getElementById('gcashQrPanel');
    const gcashRefInput = document.getElementById('gcashRefNumber');
    const gcashProofBlock = document.getElementById('gcashProofBlock');
    const landbankQrPanel = document.getElementById('landbankQrPanel');
    const landbankRefInput = document.getElementById('landbankRefNumber');
    const landbankProofBlock = document.getElementById('landbankProofBlock');
    const gcashWaitTitle = document.getElementById('gcashWaitTitle');
    const gcashWaitAmount = document.getElementById('gcashWaitAmount');
    const gcashWaitStatus = document.getElementById('gcashWaitStatus');
    const gcashWaitCountdown = document.getElementById('gcashWaitCountdown');
    const gcashWaitCancel = document.getElementById('gcashWaitCancel');
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDate = document.getElementById('summaryDate');
    const summaryTime = document.getElementById('summaryTime');
    const summaryEquipmentRow = document.getElementById('summaryEquipmentRow');
    const summaryEquipment = document.getElementById('summaryEquipment');
    const summaryPayment = document.getElementById('summaryPayment');
    const summaryTotal = document.getElementById('summaryTotal');
    const confirmBtn = document.getElementById('confirmBooking');

    const PAYMENT_LABELS = { gcash: 'GCash', landbank: 'Landbank' };
    const QR_PANELS = { gcash: gcashQrPanel, landbank: landbankQrPanel };
    const REF_INPUTS = { gcash: gcashRefInput, landbank: landbankRefInput };
    const PROOF_BLOCKS = { gcash: gcashProofBlock, landbank: landbankProofBlock };

    function getActiveRefInput() {
        return selectedPayment ? REF_INPUTS[selectedPayment] || null : null;
    }

    function getActiveProofBlock() {
        return selectedPayment ? PROOF_BLOCKS[selectedPayment] || null : null;
    }

    function syncPaymentQrPanels() {
        Object.entries(QR_PANELS).forEach(([method, panel]) => {
            if (panel) panel.classList.toggle('open', selectedPayment === method);
        });
    }

    let selectedCourt = null;
    let calendarCursor = new Date();
    let selectedDate = null;
    let selectedSlots = []; // e.g. ['16:00', '17:00'], always sorted
    // The real `date` submitted with the booking — the session's own
    // opening date, which for an overnight court can differ from
    // selectedDate (the picker page being viewed). See guest-book.js for
    // the fuller rationale; mirrored here for the signed-in flow.
    let activeSessionDate = null;
    let selectedPayment = null;
    let bookedRanges = [];
    let equipmentCatalog = [];
    let equipmentSelection = {};

    const pad = (n) => n.toString().padStart(2, '0');
    const todayStr = () => new Date().toISOString().slice(0, 10);

    // ---------- Modal open/close ----------

    function openInfoModal(court) {
        selectedCourt = court;
        modalCourtName.textContent = court.name;
        modalCourtType.textContent = court.type;
        modalCourtDim.textContent = `${court.length}m × ${court.width}m`;
        modalCourtPrice.textContent = `₱${Number(court.price).toLocaleString()} / hour`;
        infoModal.classList.add('open');
    }

    function closeInfoModal() {
        infoModal.classList.remove('open');
    }

    function openBookingModal() {
        if (modalCourtNameBooking && selectedCourt) {
            modalCourtNameBooking.textContent = selectedCourt.name;
        }
        resetBookingState();
        bookingModal.classList.add('open');
        renderCalendar();
    }

    function closeBookingModal() {
        bookingModal.classList.remove('open');
    }

    function reopenBookingModal() {
        bookingModal.classList.add('open');
    }

    function openTimePickerModal() {
        timePickerModal.classList.add('open');
        renderTimePickerDateLabel();
        updateDayNavState();
    }

    function closeTimePickerModal() {
        timePickerModal.classList.remove('open');
    }

    function openEquipmentModal() {
        equipmentModal.classList.add('open');
        loadEquipment();
    }

    function closeEquipmentModal() {
        equipmentModal.classList.remove('open');
    }

    function openPaymentModal() {
        selectedPayment = null;
        paymentGrid.querySelectorAll('.payment-btn').forEach((el) => el.classList.remove('selected'));
        syncPaymentQrPanels();

        // Prefill from the saved profile number, but only if the field is
        // still empty — never overwrite something the person already
        // typed (e.g. they went back and forth between steps).
        if (userPhone && !contactNumberInput.value.trim()) {
            contactNumberInput.value = userPhone;
        }

        updateSummary();
        paymentModal.classList.add('open');
    }

    function closePaymentModal() {
        paymentModal.classList.remove('open');
    }

    function resetBookingState() {
        selectedDate = null;
        selectedSlots = [];
        activeSessionDate = null;
        selectedPayment = null;
        bookedRanges = [];
        equipmentSelection = {};
        calendarCursor = new Date();
    }

    // ---------- Court grid ----------

    grid.addEventListener('click', (e) => {
        const card = e.target.closest('.court-card-clickable');
        if (!card) return;

        openInfoModal({
            id: card.dataset.id,
            name: card.dataset.name,
            type: card.dataset.type,
            length: card.dataset.length,
            width: card.dataset.width,
            price: card.dataset.price,
        });
    });

    document.getElementById('courtInfoModalClose').addEventListener('click', closeInfoModal);
    document.getElementById('stepInfoClose').addEventListener('click', closeInfoModal);
    infoModal.addEventListener('click', (e) => {
        if (e.target === infoModal) closeInfoModal();
    });

    document.getElementById('courtBookingModalClose').addEventListener('click', closeBookingModal);
    bookingModal.addEventListener('click', (e) => {
        if (e.target === bookingModal) closeBookingModal();
    });

    document.getElementById('timePickerModalClose').addEventListener('click', closeTimePickerModal);
    timePickerModal.addEventListener('click', (e) => {
        if (e.target === timePickerModal) closeTimePickerModal();
    });

    document.getElementById('equipmentModalClose').addEventListener('click', closeEquipmentModal);
    equipmentModal.addEventListener('click', (e) => {
        if (e.target === equipmentModal) closeEquipmentModal();
    });

    document.getElementById('courtPaymentModalClose').addEventListener('click', closePaymentModal);
    paymentModal.addEventListener('click', (e) => {
        if (e.target === paymentModal) closePaymentModal();
    });

    document.getElementById('goToBooking').addEventListener('click', () => {
        closeInfoModal();
        openBookingModal();
    });

    document.getElementById('backToInfo').addEventListener('click', () => {
        closeBookingModal();
        openInfoModal(selectedCourt);
    });
    document.getElementById('backToInfo2').addEventListener('click', () => {
        closeBookingModal();
        openInfoModal(selectedCourt);
    });

    document.getElementById('backToCalendar').addEventListener('click', () => {
        closeTimePickerModal();
        reopenBookingModal();
    });
    document.getElementById('backToCalendar2').addEventListener('click', () => {
        closeTimePickerModal();
        reopenBookingModal();
    });

    continueToEquipmentBtn.addEventListener('click', () => {
        const missing = getMissingBookingRequirements();
        if (missing.length > 0) {
            showToast(`Please select ${formatList(missing)} to continue.`, 'error');
            return;
        }
        closeTimePickerModal();
        openEquipmentModal();
    });

    document.getElementById('backToBookingFromEquipment').addEventListener('click', () => {
        closeEquipmentModal();
        openTimePickerModal();
    });
    document.getElementById('backToBookingFromEquipment2').addEventListener('click', () => {
        closeEquipmentModal();
        openTimePickerModal();
    });

    continueToPaymentBtn.addEventListener('click', () => {
        closeEquipmentModal();
        openPaymentModal();
    });

    document.getElementById('backToEquipment').addEventListener('click', () => {
        closePaymentModal();
        openEquipmentModal();
    });
    document.getElementById('backToEquipment2').addEventListener('click', () => {
        closePaymentModal();
        openEquipmentModal();
    });

    // ---------- Calendar ----------

    calPrev.addEventListener('click', () => {
        calendarCursor.setMonth(calendarCursor.getMonth() - 1);
        renderCalendar();
    });
    calNext.addEventListener('click', () => {
        calendarCursor.setMonth(calendarCursor.getMonth() + 1);
        renderCalendar();
    });

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
            blank.className = 'calendar-day calendar-day-empty';
            calendarGrid.appendChild(blank);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${pad(month + 1)}-${pad(d)}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'calendar-day';
            btn.textContent = d;

            const isPast = dateStr < today;
            const isClosed = !isPast && isDateClosed(dateStr);

            if (isPast) {
                btn.disabled = true;
            } else if (isClosed) {
                btn.classList.add('calendar-day-closed');
                btn.setAttribute('aria-label', `${dateStr} — closed`);
                btn.addEventListener('click', () => {
                    showToast('This date is closed.', 'error');
                });
            } else {
                btn.addEventListener('click', () => selectDate(dateStr));
            }

            if (dateStr === today) btn.classList.add('calendar-day-today');
            if (dateStr === selectedDate) btn.classList.add('selected');

            calendarGrid.appendChild(btn);
        }
    }

    async function selectDate(dateStr) {
        selectedDate = dateStr;
        selectedSlots = [];
        activeSessionDate = null;

        closeBookingModal();
        openTimePickerModal();
        await changeDateInModal(dateStr);
    }

    function renderTimePickerDateLabel() {
        if (!timePickerDateLabel || !selectedDate) return;
        timePickerDateLabel.textContent = formatDate(selectedDate);
    }

    function updateDayNavState() {
        if (timePickerPrevDay) {
            timePickerPrevDay.disabled = !selectedDate || selectedDate <= todayStr();
        }
    }

    function addDaysToDateStr(dateStr, deltaDays) {
        const d = new Date(`${dateStr}T00:00:00`);
        d.setDate(d.getDate() + deltaDays);
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    function shiftSelectedDate(deltaDays) {
        if (!selectedDate) return;
        let next = selectedDate;
        for (let i = 0; i < 60; i++) {
            next = addDaysToDateStr(next, deltaDays);
            if (next < todayStr()) return;
            if (!isDateClosed(next)) {
                selectedDate = next;
                // Keep selectedSlots + activeSessionDate so overnight
                // selections (e.g. 11 PM on the 19th + 12 AM on the 20th)
                // survive flipping between the two calendar days.
                changeDateInModal(next);
                return;
            }
        }
    }

    timePickerPrevDay?.addEventListener('click', () => shiftSelectedDate(-1));
    timePickerNextDay?.addEventListener('click', () => shiftSelectedDate(1));

    async function changeDateInModal(dateStr) {
        // Intentionally keep selectedSlots + activeSessionDate across
        // day-to-day navigation. Selections are still cleared when
        // picking a fresh date from the calendar, on full reset, or
        // after a conflict error.
        renderTimePickerDateLabel();
        updateDayNavState();
        renderTimeSlotSkeleton();

        const MIN_SKELETON_MS = 300;
        const [ranges] = await Promise.all([
            fetchAvailability(dateStr),
            new Promise((resolve) => setTimeout(resolve, MIN_SKELETON_MS)),
        ]);

        bookedRanges = ranges;
        renderTimeSlots();
        updateTimePickerFee();
    }

    function renderTimeSlotSkeleton() {
        timeSlotGrid.innerHTML = '';

        const spanMinutes = OVERNIGHT ? (24 - OPEN_HOUR + CLOSE_HOUR) * 60 : (CLOSE_HOUR - OPEN_HOUR) * 60;
        const lastStart = spanMinutes - STEP_MINUTES;
        const slotCount = Math.floor(lastStart / STEP_MINUTES) + 1;

        for (let i = 0; i < slotCount; i++) {
            const skeleton = document.createElement('div');
            skeleton.className = 'time-slot-skeleton';
            skeleton.style.animationDelay = `${(i % 8) * 0.05}s`;
            timeSlotGrid.appendChild(skeleton);
        }
    }

    async function fetchAvailability(dateStr) {
        if (!availabilityUrl || !selectedCourt) return [];
        try {
            const url = `${availabilityUrl}?court_id=${selectedCourt.id}&date=${dateStr}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load availability');
            const data = await res.json();
            return (data.booked || []).map((b) => ({ start: b.start, end: b.end }));
        } catch (err) {
            console.error(err);
            return [];
        }
    }

    // ---------- Time slots (multi-select hours) ----------

    function timeToMinutes(t) {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    }

    // Every row rendered for a picker page is a real hour ON that page's
    // selectedDate, and bookedRanges came from fetchAvailability(selectedDate)
    // — which already keys off booking_slots' own real `date` column — so
    // this can compare plain minutes-since-midnight directly, no
    // open-hour-relative wraparound needed. A range's end of "00:00" means
    // midnight/end-of-day (1440), not the very start of the day.
    function isSlotBookedAt(totalMin) {
        const slotEnd = totalMin + STEP_MINUTES;
        return bookedRanges.some((r) => {
            const rangeStart = timeToMinutes(r.start);
            const rangeEnd = timeToMinutes(r.end) || 1440;
            return totalMin < rangeEnd && slotEnd > rangeStart;
        });
    }

    function slotPrice() {
        return selectedCourt ? Number(selectedCourt.price) * (STEP_MINUTES / 60) : 0;
    }

    // 12-hour "4:00 PM" style label for a bare hour (0-23), used only by
    // the closed-divider row.
    function formatHourLabel(hour) {
        const period = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 === 0 ? 12 : hour % 12;
        return `${hour12}:00 ${period}`;
    }

    function appendClosedDivider() {
        const divider = document.createElement('div');
        divider.className = 'time-slot-closed-divider';
        divider.textContent = `Closes at ${formatHourLabel(CLOSE_HOUR)} · Opens at ${formatHourLabel(OPEN_HOUR)}`;
        timeSlotGrid.appendChild(divider);
    }

    // Builds one contiguous run of hourly rows from startMin up to (but
    // not including) endMin, all real times on `selectedDate` itself.
    // `sessionDate` is what actually gets submitted as the booking's
    // `date` if a row in this run gets picked — see activeSessionDate's
    // comment above for why that can differ from selectedDate.
    function renderTimeSlotBlock(startMin, endMin, sessionDate) {
        // Once a slot's been picked, the OTHER block (the other side of
        // the closed gap) gets locked — a single booking can't span a
        // closed period, since that's really two separate sessions as
        // far as the backend (and the court) is concerned.
        const blockLocked = activeSessionDate !== null && activeSessionDate !== sessionDate;

        for (let totalMin = startMin; totalMin + STEP_MINUTES <= endMin; totalMin += STEP_MINUTES) {
            const h = Math.floor(totalMin / 60);
            const m = totalMin % 60;
            const timeStr = `${pad(h)}:${pad(m)}`;

            const booked = isSlotBookedAt(totalMin);
            const past = isSlotPastAt(selectedDate, timeStr);
            const unavailable = booked || past || blockLocked;
            const isSelected = selectedSlots.includes(timeStr);

            // The row itself is just a container — the time label on the
            // left is plain text, not clickable. Only the button on the
            // right (the price pill) actually toggles the selection.
            const row = document.createElement('div');
            row.className = 'time-slot-row' + (isSelected ? ' selected' : '') + (unavailable ? ' disabled' : '');

            const label = document.createElement('span');
            label.className = 'time-slot-row-time';
            label.textContent = formatTime(timeStr);

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'time-slot-row-toggle' + (isSelected ? ' selected' : '');

            const check = document.createElement('span');
            check.className = 'time-slot-row-check';
            check.textContent = '✓ ';
            check.setAttribute('aria-hidden', 'true');

            const priceLabel = document.createElement('span');
            if (booked) {
                priceLabel.textContent = 'Booked';
            } else if (past) {
                priceLabel.textContent = 'Past';
            } else if (blockLocked) {
                priceLabel.textContent = '—';
            } else {
                priceLabel.textContent = `₱${slotPrice().toLocaleString()}`;
            }

            toggle.appendChild(check);
            toggle.appendChild(priceLabel);

            if (unavailable) {
                toggle.disabled = true;
                row.dataset.tooltip = booked
                    ? 'Reserved — already booked by another player'
                    : past
                        ? 'This time has already passed'
                        : "Clear your current selection first — a booking can't span the closed hours";
            } else {
                toggle.setAttribute('aria-pressed', String(isSelected));
                toggle.addEventListener('click', () => toggleSlot(timeStr, sessionDate));
            }

            row.appendChild(label);
            row.appendChild(toggle);

            timeSlotGrid.appendChild(row);
        }
    }

    function renderTimeSlots() {
        timeSlotGrid.innerHTML = '';
        if (!selectedDate) return;

        // The session that opened the PREVIOUS calendar day and is still
        // running past midnight into this one (e.g. viewing Aug 20's page
        // shows the 1-7 AM tail of the session Aug 19 opened at 4 PM).
        // Only real if the business was actually open the day before.
        const tailSessionDate = OVERNIGHT ? addDaysToDateStr(selectedDate, -1) : null;
        const hasTail = OVERNIGHT && !isDateClosed(tailSessionDate);

        if (hasTail) {
            renderTimeSlotBlock(0, CLOSE_HOUR * 60, tailSessionDate);
            appendClosedDivider();
        }

        // The session selectedDate opens itself, running from open_hour
        // up to (but not past) midnight — anything past midnight belongs
        // to tomorrow's page as ITS tail block, not this one.
        renderTimeSlotBlock(OPEN_HOUR * 60, OVERNIGHT ? 1440 : CLOSE_HOUR * 60, selectedDate);
    }

    // Toggles one hour on or off. Selected hours don't need to be
    // contiguous, but they do all have to belong to the same session
    // (see activeSessionDate).
    function toggleSlot(timeStr, sessionDate) {
        const idx = selectedSlots.indexOf(timeStr);

        if (idx !== -1) {
            selectedSlots.splice(idx, 1);
            if (selectedSlots.length === 0) activeSessionDate = null;
        } else {
            if (selectedSlots.length >= MAX_DURATION) {
                showToast(`You can book up to ${MAX_DURATION * STEP_MINUTES / 60} hours per booking.`, 'error');
                return;
            }
            selectedSlots.push(timeStr);
            activeSessionDate = sessionDate;
            selectedSlots.sort((a, b) => timeToMinutes(a) - timeToMinutes(b));
        }

        renderTimeSlots();
        updateTimePickerFee();
    }

    function formatSelectedSlotsSummary(separator = ', ') {
        if (selectedSlots.length === 0) return '';

        const ranges = [];
        let rangeStart = selectedSlots[0];
        let rangeEnd = selectedSlots[0];

        for (let i = 1; i < selectedSlots.length; i++) {
            const prevMin = timeToMinutes(rangeEnd);
            const curMin = timeToMinutes(selectedSlots[i]);

            if (curMin === prevMin + STEP_MINUTES) {
                rangeEnd = selectedSlots[i];
            } else {
                ranges.push([rangeStart, rangeEnd]);
                rangeStart = selectedSlots[i];
                rangeEnd = selectedSlots[i];
            }
        }

        ranges.push([rangeStart, rangeEnd]);

        return ranges.map(([start, end]) => {
            const endMin = (timeToMinutes(end) + STEP_MINUTES) % 1440;
            const endStr = `${pad(Math.floor(endMin / 60))}:${pad(endMin % 60)}`;
            return `${formatTime(start)} – ${formatTime(endStr)}`;
        }).join(separator);
    }

    function updateTimePickerFee() {
        if (!timePickerFeeRanges || !timePickerFeeTotal) return;

        if (selectedSlots.length === 0) {
            timePickerFeeRanges.textContent = 'Select at least one hour';
            timePickerFeeTotal.textContent = '₱0';
        } else {
            timePickerFeeRanges.textContent = formatSelectedSlotsSummary();
            timePickerFeeTotal.textContent = `₱${(slotPrice() * selectedSlots.length).toLocaleString()}`;
        }
    }

    // ---------- Equipment rental ----------

    async function loadEquipment() {
        equipmentGrid.innerHTML = '<p class="loading-text">Loading equipment…</p>';

        try {
            const params = new URLSearchParams();
            // equipment load
            params.append('date', activeSessionDate || selectedDate);
            selectedSlots.forEach((timeStr) => params.append('slots[]', timeStr));

            const res = await fetch(`${equipmentUrl}?${params.toString()}`, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            equipmentCatalog = data.equipment || [];
            renderEquipment();
        } catch (err) {
            console.error(err);
            equipmentGrid.innerHTML = '<p class="loading-text">Couldn\'t load equipment right now.</p>';
        }
    }

    function renderEquipment() {
        if (equipmentCatalog.length === 0) {
            equipmentGrid.innerHTML = '<p class="loading-text">No equipment available to rent right now.</p>';
            return;
        }

        equipmentGrid.innerHTML = '';
        let lastCategory = null;

        equipmentCatalog.forEach((item) => {
            if (item.category !== lastCategory) {
                const label = document.createElement('p');
                label.className = 'equipment-category-label';
                label.textContent = item.category === 'padel' ? 'Padel' : 'Pickleball';
                equipmentGrid.appendChild(label);
                lastCategory = item.category;
            }

            equipmentGrid.appendChild(buildEquipmentCard(item));
        });
    }

    function buildEquipmentCard(item) {
        const qty = equipmentSelection[item.id] || 0;

        const card = document.createElement('div');
        card.className = 'equipment-card' + (qty > 0 ? ' has-qty' : '');

        const info = document.createElement('div');
        info.innerHTML = `
            <p class="equipment-card-name">${item.name}</p>
            <p class="equipment-card-meta${item.available === 0 ? ' equipment-sold-out' : ''}">
                ₱${Number(item.price).toLocaleString()} each &middot;
                ${item.available === 0 ? 'Sold out for this time' : `${item.available} available`}
            </p>
        `;

        const stepper = document.createElement('div');
        stepper.className = 'equipment-qty-stepper';

        const decBtn = document.createElement('button');
        decBtn.type = 'button';
        decBtn.className = 'qty-btn';
        decBtn.textContent = '−';
        decBtn.disabled = qty === 0;

        const qtyValue = document.createElement('span');
        qtyValue.className = 'qty-value';
        qtyValue.textContent = qty;

        const incBtn = document.createElement('button');
        incBtn.type = 'button';
        incBtn.className = 'qty-btn';
        incBtn.textContent = '+';
        incBtn.disabled = qty >= item.available;

        decBtn.addEventListener('click', () => {
            const current = equipmentSelection[item.id] || 0;
            if (current <= 0) return;
            const next = current - 1;
            if (next === 0) delete equipmentSelection[item.id];
            else equipmentSelection[item.id] = next;
            renderEquipment();
        });

        incBtn.addEventListener('click', () => {
            const current = equipmentSelection[item.id] || 0;
            if (current >= item.available) return;
            equipmentSelection[item.id] = current + 1;
            renderEquipment();
        });

        stepper.appendChild(decBtn);
        stepper.appendChild(qtyValue);
        stepper.appendChild(incBtn);

        card.appendChild(info);
        card.appendChild(stepper);
        return card;
    }

    function equipmentTotal() {
        return Object.entries(equipmentSelection).reduce((sum, [id, qty]) => {
            const item = equipmentCatalog.find((e) => String(e.id) === String(id));
            return item ? sum + item.price * qty : sum;
        }, 0);
    }

    function equipmentSummaryText() {
        const parts = Object.entries(equipmentSelection).map(([id, qty]) => {
            const item = equipmentCatalog.find((e) => String(e.id) === String(id));
            return item ? `${item.name} ×${qty}` : null;
        }).filter(Boolean);
        return parts.join(', ');
    }

    // ---------- Payment method ----------

    paymentGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.payment-btn');
        if (!btn || btn.disabled) return;

        selectedPayment = btn.dataset.method;
        paymentGrid.querySelectorAll('.payment-btn').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');
        syncPaymentQrPanels();

        updateSummary();
    });

    // ---------- Payment confirmation polling ----------

    const POLL_INTERVAL_MS = 4000;
    let pollTimer = null;
    let countdownTimer = null;
    let activeCancelUrl = null;

    function stopPolling() {
        if (pollTimer) clearTimeout(pollTimer);
        if (countdownTimer) clearInterval(countdownTimer);
        pollTimer = null;
        countdownTimer = null;
    }

    function closeWaitModal() {
        stopPolling();
        gcashWaitModal.classList.remove('open');
    }

    function formatCountdown(msRemaining) {
        const totalSeconds = Math.max(0, Math.floor(msRemaining / 1000));
        const mins = Math.floor(totalSeconds / 60);
        const secs = totalSeconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    function watchPaymentConfirmation(bookingId, expiresAtIso, amountLabel, method) {
        const statusUrl = statusUrlTemplate.replace('__ID__', bookingId);
        const cancelUrl = cancelUrlTemplate.replace('__ID__', bookingId);
        activeCancelUrl = cancelUrl;
        const expiresAt = new Date(expiresAtIso).getTime();
        const methodLabel = PAYMENT_LABELS[method] || 'the payment provider';

        if (gcashWaitTitle) gcashWaitTitle.textContent = `Waiting for ${methodLabel} Payment`;
        gcashWaitAmount.textContent = amountLabel;
        gcashWaitStatus.textContent = `We'll confirm automatically the moment ${methodLabel} notifies us — usually within a minute or two.`;
        gcashWaitModal.classList.add('open');

        countdownTimer = setInterval(() => {
            const remaining = expiresAt - Date.now();
            gcashWaitCountdown.textContent = formatCountdown(remaining);
            if (remaining <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                handleCountdownExpired();
            }
        }, 1000);
        gcashWaitCountdown.textContent = formatCountdown(expiresAt - Date.now());

        async function handleCountdownExpired() {
            if (pollTimer) clearTimeout(pollTimer);
            pollTimer = null;

            try {
                const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();

                    if (data.status === 'paid') {
                        closeWaitModal();
                        showToast('Payment confirmed — see you on the court!', 'success');
                        finishBookingReset();
                        return;
                    }

                    if (data.status === 'cancelled') {
                        closeWaitModal();
                        showToast("We didn't receive that payment in time, so the slot was released. Please rebook when you're ready to pay.", 'error');
                        finishBookingReset();
                        return;
                    }
                }
            } catch (err) {
                console.error(err);
            }

            try {
                await fetch(cancelUrl, { method: 'POST', headers: { Accept: 'application/json', ...csrfHeaders() } });
            } catch (err) {
                console.error(err);
            }

            closeWaitModal();
            showToast("We didn't receive that payment in time, so the slot was released. Please rebook when you're ready to pay.", 'error');
            finishBookingReset();
        }

        async function poll() {
            try {
                const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();

                    if (data.status === 'paid') {
                        stopPolling();
                        gcashWaitStatus.textContent = 'Payment confirmed!';
                        setTimeout(() => {
                            closeWaitModal();
                            showToast('Payment confirmed — see you on the court!', 'success');
                            finishBookingReset();
                        }, 900);
                        return;
                    }

                    if (data.status === 'cancelled') {
                        stopPolling();
                        closeWaitModal();
                        showToast("We didn't receive that payment in time, so the slot was released. Please rebook when you're ready to pay.", 'error');
                        finishBookingReset();
                        return;
                    }
                }
            } catch (err) {
                console.error(err);
            }

            pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
        }

        poll();
    }

    gcashWaitCancel?.addEventListener('click', async () => {
        const cancelUrl = activeCancelUrl;
        closeWaitModal();
        if (cancelUrl) {
            try {
                await fetch(cancelUrl, { method: 'POST', headers: { Accept: 'application/json', ...csrfHeaders() } });
            } catch (err) {
                console.error(err);
            }
        }
        showToast('Booking cancelled.', 'success');
        finishBookingReset();
    });

    function finishBookingReset() {
        // Reset back to the saved profile number (if any) rather than
        // blanking it out — so it's still prefilled next time the person
        // books, instead of making them retype it every visit.
        contactNumberInput.value = userPhone || '';
        equipmentSelection = {};
        if (gcashRefInput) gcashRefInput.value = '';
        if (landbankRefInput) landbankRefInput.value = '';
        resetBookingState();
    }

    // ---------- Summary + validation ----------

    function formatTime(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12}:${pad(m)} ${period}`;
    }

    function formatDate(dateStr) {
        return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric',
        });
    }

    function updateSummary() {
        // updateSummary
        summaryDate.textContent = formatDate(activeSessionDate || selectedDate);
        summaryTime.textContent = formatSelectedSlotsSummary();
        summaryPayment.textContent = selectedPayment ? PAYMENT_LABELS[selectedPayment] : '—';

        const equipText = equipmentSummaryText();
        if (equipText) {
            summaryEquipmentRow.hidden = false;
            summaryEquipment.textContent = equipText;
        } else {
            summaryEquipmentRow.hidden = true;
        }

        const total = slotPrice() * selectedSlots.length + equipmentTotal();
        summaryTotal.textContent = `₱${total.toLocaleString()}`;
    }

    function getMissingBookingRequirements() {
        const missing = [];
        if (!selectedDate) missing.push('a date');
        if (selectedSlots.length < MIN_DURATION) {
            missing.push(`at least ${MIN_DURATION * STEP_MINUTES / 60} hour${MIN_DURATION * STEP_MINUTES / 60 === 1 ? '' : 's'}`);
        }
        return missing;
    }

    function getMissingPaymentRequirements() {
        const missing = [];
        if (!contactNumberInput.value.trim()) missing.push('a contact number');
        if (!selectedPayment) missing.push('a payment method');
        const activeRefInput = getActiveRefInput();
        if (selectedPayment && !(activeRefInput && activeRefInput.value.trim())) {
            missing.push(`your ${PAYMENT_LABELS[selectedPayment] || 'payment'} reference number`);
        }
        return missing;
    }

    function formatList(items) {
        if (items.length === 1) return items[0];
        if (items.length === 2) return `${items[0]} and ${items[1]}`;
        return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
    }

    // ---------- Confirm ----------

    confirmBtn.addEventListener('click', async () => {
        const missing = getMissingPaymentRequirements();
        if (missing.length > 0) {
            showToast(`Please provide ${formatList(missing)} to continue.`, 'error');
            contactNumberInput.classList.toggle('field-invalid', !contactNumberInput.value.trim());
            const invalidRefInput = getActiveRefInput();
            getActiveProofBlock()?.classList.toggle(
                'payment-proof-invalid',
                !!selectedPayment && !(invalidRefInput && invalidRefInput.value.trim())
            );
            return;
        }
        gcashProofBlock?.classList.remove('payment-proof-invalid');
        landbankProofBlock?.classList.remove('payment-proof-invalid');

        if (!selectedCourt || !selectedDate || selectedSlots.length < MIN_DURATION) return;

        confirmBtn.disabled = true;
        const originalLabel = confirmBtn.textContent;
        confirmBtn.textContent = 'Booking…';

        const equipmentPayload = Object.entries(equipmentSelection).map(([id, quantity]) => ({
            id: Number(id),
            quantity,
        }));

        try {
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({
                    court_id: selectedCourt.id,
                    date: activeSessionDate || selectedDate,
                    slots: selectedSlots,
                    payment_method: selectedPayment,
                    contact_number: contactNumberInput.value.trim(),
                    payment_reference: getActiveRefInput()?.value.trim() || '',
                    equipment: equipmentPayload,
                }),
            });

            const data = await res.json().catch(() => ({}));

            if (res.status === 419) {
                showToast('Your session expired. Reloading the page…', 'error');
                setTimeout(() => window.location.reload(), 1200);
                return;
            }

            if (!res.ok) {
                if (res.status === 422 && data.errors) {
                    const firstError = Object.values(data.errors)[0]?.[0];
                    showToast(firstError || 'Please check the highlighted field.', 'error');

                    contactNumberInput.classList.toggle('field-invalid', !!data.errors.contact_number);
                    getActiveProofBlock()?.classList.toggle(
                        'payment-proof-invalid',
                        !!data.errors.payment_reference
                    );

                    confirmBtn.disabled = false;
                    confirmBtn.textContent = originalLabel;
                    return;
                }

                showToast(data.message || 'That slot is no longer available.', 'error');

                selectedSlots = [];
                activeSessionDate = null;

                closePaymentModal();
                bookedRanges = await fetchAvailability(selectedDate);
                renderTimeSlots();
                updateTimePickerFee();
                openTimePickerModal();

                confirmBtn.disabled = false;
                confirmBtn.textContent = originalLabel;
                return;
            }

            closePaymentModal();

            if (data.booking && data.booking.status === 'paid') {
                showToast('Payment already matched — you\'re all set!', 'success');
                finishBookingReset();
            } else {
                watchPaymentConfirmation(data.booking_id, data.expires_at, `₱${Number(data.amount).toFixed(2)}`, selectedPayment);
            }
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalLabel;
        }
    });

    // ---------- Toasts ----------

    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message"></span>
            <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>
        `;
        toast.querySelector('.toast-message').textContent = message;

        toastContainer.appendChild(toast);

        const remove = () => {
            toast.classList.add('toast-out');
            setTimeout(() => toast.remove(), 300);
        };

        toast.querySelector('.toast-close').addEventListener('click', remove);
        setTimeout(remove, 4000);
    }
});