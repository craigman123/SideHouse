document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('courtGrid');
    if (!grid) return;

    const availabilityUrl = grid.dataset.availabilityUrl;
    const storeUrl = grid.dataset.storeUrl;

    // Prefer the <meta name="csrf-token"> tag if the layout has one, but
    // fall back to Laravel's XSRF-TOKEN cookie (set automatically on every
    // response by the VerifyCsrfToken middleware, no template changes
    // needed) so a missing/stale meta tag can't cause a 419 mismatch.
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function csrfHeaders() {
        const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (metaToken) {
            return { 'X-CSRF-TOKEN': metaToken };
        }

        const cookieToken = getCookie('XSRF-TOKEN');
        if (cookieToken) {
            return { 'X-XSRF-TOKEN': cookieToken };
        }

        return {};
    }

    // Operating hours now come from the server (see #courtGrid data attributes,
    // set from User_UserController::OPEN_HOUR / CLOSE_HOUR) so the frontend
    // can't drift out of sync with the backend's actual rules.
    const OPEN_HOUR = parseInt(grid.dataset.openHour, 10);
    const CLOSE_HOUR = parseInt(grid.dataset.closeHour, 10);
    const TIME_STEP_MINUTES = parseInt(grid.dataset.stepMinutes, 10);

    // Total minutes the court is open, expressed as a span starting at
    // OPEN_HOUR (e.g. open 16:00–close 07:00 next day = 15 hours = 900 min).
    const OPEN_SPAN_MINUTES = ((CLOSE_HOUR - OPEN_HOUR + 24) % 24 || 24) * 60;

    // Duration options, in hours, from min to max in server-driven steps.
    // Must match User_UserController::MIN_DURATION_HOURS / MAX_DURATION_HOURS.
    const DURATION_MIN = parseFloat(grid.dataset.minDuration);
    const DURATION_MAX = parseFloat(grid.dataset.maxDuration);
    const DURATION_STEP = TIME_STEP_MINUTES / 60;

    const infoModal = document.getElementById('courtInfoModal');
    const bookingModal = document.getElementById('courtBookingModal');
    const paymentModal = document.getElementById('courtPaymentModal');

    const modalCourtName = document.getElementById('modalCourtName');
    const modalCourtNameBooking = document.getElementById('modalCourtNameBooking');
    const modalCourtType = document.getElementById('modalCourtType');
    const modalCourtDim = document.getElementById('modalCourtDim');
    const modalCourtPrice = document.getElementById('modalCourtPrice');

    const calMonthLabel = document.getElementById('calMonthLabel');
    const calendarGrid = document.getElementById('calendarGrid');
    const calPrev = document.getElementById('calPrev');
    const calNext = document.getElementById('calNext');

    const timeSection = document.getElementById('timeSection');
    const timeSlotGrid = document.getElementById('timeSlotGrid');
    const durationSection = document.getElementById('durationSection');
    const durationGrid = document.getElementById('durationGrid');
    const continueToPaymentBtn = document.getElementById('continueToPayment');
    const paymentGrid = document.getElementById('paymentGrid');
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDate = document.getElementById('summaryDate');
    const summaryTime = document.getElementById('summaryTime');
    const summaryPayment = document.getElementById('summaryPayment');
    const summaryTotal = document.getElementById('summaryTotal');
    const confirmBtn = document.getElementById('confirmBooking');

    let selectedCourt = null;
    let calendarCursor = new Date();
    let selectedDate = null;     // 'YYYY-MM-DD'
    let selectedStart = null;    // 'HH:MM'
    let selectedDuration = null; // hours, e.g. 1.5
    let selectedPayment = null;  // 'arrival' | 'ewallet'
    let bookedRanges = [];       // [{ start: 'HH:MM', end: 'HH:MM' }]

    const PAYMENT_LABELS = {
        arrival: 'Pay on Arrival',
        ewallet: 'E-Wallet',
    };

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
        // Used when returning from the payment modal — keeps whatever
        // date/time/duration was already picked instead of wiping it.
        bookingModal.classList.add('open');
    }

    function openPaymentModal() {
        selectedPayment = null;
        paymentGrid.querySelectorAll('.payment-btn').forEach((el) => el.classList.remove('selected'));
        updateSummary();
        paymentModal.classList.add('open');
    }

    function closePaymentModal() {
        paymentModal.classList.remove('open');
    }

    function resetBookingState() {
        selectedDate = null;
        selectedStart = null;
        selectedDuration = null;
        selectedPayment = null;
        bookedRanges = [];
        calendarCursor = new Date();
        timeSection.hidden = true;
        durationSection.hidden = true;
    }

    // ---------- Duration button setup (15-minute increments) ----------

    function formatDuration(hours) {
        const wholeHours = Math.floor(hours);
        const minutes = Math.round((hours - wholeHours) * 60);

        const parts = [];
        if (wholeHours > 0) parts.push(`${wholeHours} hr${wholeHours > 1 ? 's' : ''}`);
        if (minutes > 0) parts.push(`${minutes} min`);

        return parts.join(' ');
    }

    function buildDurationButtons() {
        durationGrid.innerHTML = '';

        for (let hours = DURATION_MIN; hours <= DURATION_MAX + 1e-9; hours += DURATION_STEP) {
            const rounded = Math.round(hours * 100) / 100;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'duration-btn';
            btn.dataset.hours = rounded;
            btn.textContent = formatDuration(rounded);
            durationGrid.appendChild(btn);
        }
    }

    buildDurationButtons();

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

    continueToPaymentBtn.addEventListener('click', () => {
        const missing = getMissingBookingRequirements();
        if (missing.length > 0) {
            showToast(`Please select ${formatList(missing)} to continue.`, 'error');
            return;
        }

        closeBookingModal();
        openPaymentModal();
    });

    document.getElementById('backToBooking').addEventListener('click', () => {
        closePaymentModal();
        reopenBookingModal();
    });
    document.getElementById('backToBooking2').addEventListener('click', () => {
        closePaymentModal();
        reopenBookingModal();
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
        calMonthLabel.textContent = calendarCursor.toLocaleDateString('en-US', {
            month: 'long',
            year: 'numeric',
        });

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

            if (dateStr < today) {
                btn.disabled = true;
            } else {
                btn.addEventListener('click', () => selectDate(dateStr, btn));
            }

            if (dateStr === today) btn.classList.add('calendar-day-today');
            if (dateStr === selectedDate) btn.classList.add('selected');

            calendarGrid.appendChild(btn);
        }
    }

    async function selectDate(dateStr, btn) {
        selectedDate = dateStr;
        selectedStart = null;
        selectedDuration = null;
        durationSection.hidden = true;

        calendarGrid.querySelectorAll('.calendar-day').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');

        timeSection.hidden = false;
        renderTimeSlotSkeleton();

        bookedRanges = await fetchAvailability(dateStr);
        renderTimeSlots();
    }

    function renderTimeSlotSkeleton() {
        timeSlotGrid.innerHTML = '';
        const lastUsableOffset = OPEN_SPAN_MINUTES - DURATION_MIN * 60;
        const slotCount = Math.floor(lastUsableOffset / TIME_STEP_MINUTES) + 1;

        for (let i = 0; i < slotCount; i++) {
            const skeleton = document.createElement('div');
            skeleton.className = 'time-slot-skeleton';
            skeleton.style.animationDelay = `${(i % 8) * 0.05}s`;
            timeSlotGrid.appendChild(skeleton);
        }
    }

    async function fetchAvailability(dateStr) {
        if (!availabilityUrl) return [];

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

    // ---------- Time slots ----------

    function timeToMinutes(t) {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    }

    // Maps a clock time (e.g. "02:00" when the court opens at 16:00) onto a
    // 0..1439 scale measured from OPEN_HOUR, so overnight-wrapping courts
    // can be compared with simple arithmetic instead of date math.
    function minutesSinceOpen(timeStr) {
        const raw = timeToMinutes(timeStr) - OPEN_HOUR * 60;
        return ((raw % 1440) + 1440) % 1440;
    }

    function isSlotBooked(slotSinceOpen) {
        // Reject the slot if the *shortest possible* booking starting here
        // (DURATION_MIN hours) would overlap an existing booking — not just
        // if this exact instant falls inside one. Otherwise a start time
        // could be "available" while every valid duration from it is
        // actually blocked (e.g. picking 6:45 PM next to a 7:00 PM booking).
        const slotEnd = slotSinceOpen + DURATION_MIN * 60;

        return bookedRanges.some((r) => {
            const rangeStart = minutesSinceOpen(r.start);
            let rangeEnd = minutesSinceOpen(r.end);
            if (rangeEnd <= rangeStart) rangeEnd += 1440; // range wraps past midnight
            return slotSinceOpen < rangeEnd && slotEnd > rangeStart;
        });
    }

    function renderTimeSlots() {
        timeSlotGrid.innerHTML = '';

        // Don't offer a start time that can't fit at least the shortest
        // booking (DURATION_MIN) before closing — e.g. if the court closes
        // at 7:00, the last offered slot is 6:00, not 6:15/6:30/6:45.
        const lastUsableOffset = OPEN_SPAN_MINUTES - DURATION_MIN * 60;

        for (let offset = 0; offset <= lastUsableOffset; offset += TIME_STEP_MINUTES) {
            const minuteOfDay = (OPEN_HOUR * 60 + offset) % 1440;
            const timeStr = `${pad(Math.floor(minuteOfDay / 60))}:${pad(minuteOfDay % 60)}`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'time-slot-btn';
            btn.textContent = formatTime(timeStr);

            if (isSlotBooked(offset)) {
                btn.disabled = true;
                btn.dataset.tooltip = 'Reserved — already booked by another player';
            } else {
                btn.addEventListener('click', () => selectStart(timeStr, btn));
            }

            if (timeStr === selectedStart) btn.classList.add('selected');

            timeSlotGrid.appendChild(btn);
        }
    }

    function selectStart(timeStr, btn) {
        selectedStart = timeStr;
        selectedDuration = null;

        timeSlotGrid.querySelectorAll('.time-slot-btn').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');

        durationSection.hidden = false;
        renderDurationOptions();
    }

    // ---------- Duration ----------

    function renderDurationOptions() {
        const startSinceOpen = minutesSinceOpen(selectedStart);

        durationGrid.querySelectorAll('.duration-btn').forEach((btn) => {
            const hours = parseFloat(btn.dataset.hours);
            const endSinceOpen = startSinceOpen + hours * 60;
            const overrunsClose = endSinceOpen > OPEN_SPAN_MINUTES;
            const overlapsBooking = bookedRanges.some((r) => {
                const rangeStart = minutesSinceOpen(r.start);
                let rangeEnd = minutesSinceOpen(r.end);
                if (rangeEnd <= rangeStart) rangeEnd += 1440;
                return startSinceOpen < rangeEnd && endSinceOpen > rangeStart;
            });

            btn.disabled = overrunsClose || overlapsBooking;
            btn.classList.toggle('selected', hours === selectedDuration);
        });
    }

    durationGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.duration-btn');
        if (!btn || btn.disabled) return;

        selectedDuration = parseFloat(btn.dataset.hours);
        durationGrid.querySelectorAll('.duration-btn').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');
    });

    // ---------- Payment method ----------

    paymentGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.payment-btn');
        if (!btn || btn.disabled) return;

        selectedPayment = btn.dataset.method;
        paymentGrid.querySelectorAll('.payment-btn').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');

        updateSummary();
    });

    // ---------- Summary + confirm ----------

    function formatTime(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12}:${pad(m)} ${period}`;
    }

    function formatDate(dateStr) {
        return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    }

    function updateSummary() {
        const startMin = timeToMinutes(selectedStart);
        const endMin = (startMin + selectedDuration * 60) % 1440;
        const endTimeStr = `${pad(Math.floor(endMin / 60))}:${pad(endMin % 60)}`;

        summaryDate.textContent = formatDate(selectedDate);
        summaryTime.textContent = `${formatTime(selectedStart)} – ${formatTime(endTimeStr)}`;
        summaryPayment.textContent = selectedPayment ? PAYMENT_LABELS[selectedPayment] : '—';
        summaryTotal.textContent = `₱${(selectedCourt.price * selectedDuration).toLocaleString()}`;
    }

    // ---------- Requirements validation + confirm ----------

    function getMissingBookingRequirements() {
        const missing = [];
        if (!selectedDate) missing.push('a date');
        if (!selectedStart) missing.push('a start time');
        if (!selectedDuration) missing.push('a duration');
        return missing;
    }

    function formatList(items) {
        if (items.length === 1) return items[0];
        if (items.length === 2) return `${items[0]} and ${items[1]}`;
        return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
    }

    confirmBtn.addEventListener('click', async () => {
        if (!selectedPayment) {
            showToast('Please select a payment method to continue.', 'error');
            return;
        }

        if (!selectedCourt || !selectedDate || !selectedStart || !selectedDuration) return;

        confirmBtn.disabled = true;
        const originalLabel = confirmBtn.textContent;
        confirmBtn.textContent = 'Booking…';

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
                    date: selectedDate,
                    start_time: selectedStart,
                    duration: selectedDuration,
                    payment_method: selectedPayment,
                }),
            });

            const data = await res.json();

            if (res.status === 419) {
                showToast('Your session expired. Reloading the page…', 'error');
                setTimeout(() => window.location.reload(), 1200);
                return;
            }

            if (!res.ok) {
                showToast(data.message || 'That slot is no longer available.', 'error');

                selectedStart = null;
                selectedDuration = null;
                selectedPayment = null;
                durationSection.hidden = true;

                closePaymentModal();
                bookedRanges = await fetchAvailability(selectedDate);
                renderTimeSlots();
                reopenBookingModal();

                confirmBtn.disabled = false;
                confirmBtn.textContent = originalLabel;
                return;
            }

            showToast(data.message || 'Booking confirmed!', 'success');
            closePaymentModal();
            setTimeout(() => window.location.reload(), 900);
        } catch (err) {
            console.error(err);
            showToast('Something went wrong. Please try again.', 'error');
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalLabel;
        }
    });

    // ---------- Toasts (reuses .toast markup/classes from app.css) ----------

    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-message"></span>
            <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>
        `;
        toast.querySelector('.toast-message').textContent = message;

        container.appendChild(toast);

        const remove = () => {
            toast.classList.add('toast-out');
            setTimeout(() => toast.remove(), 300);
        };

        toast.querySelector('.toast-close').addEventListener('click', remove);
        setTimeout(remove, 4000);
    }

    // ---------- Deep link from the dashboard (?court=ID) ----------

    const preselectId = new URLSearchParams(window.location.search).get('court');
    if (preselectId) {
        const card = grid.querySelector(`.court-card-clickable[data-id="${preselectId}"]`);
        if (card) card.click();
    }
});