document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('courtGrid');
    if (!grid) return;

    const availabilityUrl = grid.dataset.availabilityUrl;
    const storeUrl = grid.dataset.storeUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    // Must match BookCourtController::OPEN_HOUR / CLOSE_HOUR on the backend.
    const OPEN_HOUR = 6;
    const CLOSE_HOUR = 22;

    const modal = document.getElementById('courtModal');
    const stepInfo = document.getElementById('stepInfo');
    const stepBooking = document.getElementById('stepBooking');

    const modalCourtName = document.getElementById('modalCourtName');
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
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDate = document.getElementById('summaryDate');
    const summaryTime = document.getElementById('summaryTime');
    const summaryTotal = document.getElementById('summaryTotal');
    const confirmBtn = document.getElementById('confirmBooking');

    let selectedCourt = null;
    let calendarCursor = new Date();
    let selectedDate = null;     // 'YYYY-MM-DD'
    let selectedStart = null;    // 'HH:MM'
    let selectedDuration = null; // hours, e.g. 1.5
    let bookedRanges = [];       // [{ start: 'HH:MM', end: 'HH:MM' }]

    const pad = (n) => n.toString().padStart(2, '0');
    const todayStr = () => new Date().toISOString().slice(0, 10);

    // ---------- Modal open/close ----------

    function openModal(court) {
        selectedCourt = court;
        modalCourtName.textContent = court.name;
        modalCourtType.textContent = court.type;
        modalCourtDim.textContent = `${court.length}m × ${court.width}m`;
        modalCourtPrice.textContent = `₱${Number(court.price).toLocaleString()} / hour`;

        showStep('info');
        modal.classList.add('open');
    }

    function closeModal() {
        modal.classList.remove('open');
        resetBookingState();
    }

    function showStep(step) {
        stepInfo.hidden = step !== 'info';
        stepBooking.hidden = step !== 'booking';
    }

    function resetBookingState() {
        selectedDate = null;
        selectedStart = null;
        selectedDuration = null;
        bookedRanges = [];
        calendarCursor = new Date();
        timeSection.hidden = true;
        durationSection.hidden = true;
        bookingSummary.hidden = true;
        confirmBtn.disabled = true;
    }

    grid.addEventListener('click', (e) => {
        const card = e.target.closest('.court-card-clickable');
        if (!card) return;

        openModal({
            id: card.dataset.id,
            name: card.dataset.name,
            type: card.dataset.type,
            length: card.dataset.length,
            width: card.dataset.width,
            price: card.dataset.price,
        });
    });

    document.getElementById('courtModalClose').addEventListener('click', closeModal);
    document.getElementById('stepInfoClose').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.getElementById('goToBooking').addEventListener('click', () => {
        resetBookingState();
        showStep('booking');
        renderCalendar();
    });

    document.getElementById('backToInfo').addEventListener('click', () => showStep('info'));
    document.getElementById('backToInfo2').addEventListener('click', () => showStep('info'));

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
        bookingSummary.hidden = true;
        confirmBtn.disabled = true;

        calendarGrid.querySelectorAll('.calendar-day').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');

        timeSection.hidden = false;
        timeSlotGrid.innerHTML = '<p class="loading-text">Loading available times…</p>';

        bookedRanges = await fetchAvailability(dateStr);
        renderTimeSlots();
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

    function isSlotBooked(startMin) {
        return bookedRanges.some(
            (r) => startMin >= timeToMinutes(r.start) && startMin < timeToMinutes(r.end)
        );
    }

    function renderTimeSlots() {
        timeSlotGrid.innerHTML = '';

        for (let h = OPEN_HOUR; h < CLOSE_HOUR; h++) {
            const timeStr = `${pad(h)}:00`;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'time-slot-btn';
            btn.textContent = formatTime(timeStr);

            if (isSlotBooked(timeToMinutes(timeStr))) {
                btn.disabled = true;
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
        bookingSummary.hidden = true;
        confirmBtn.disabled = true;

        timeSlotGrid.querySelectorAll('.time-slot-btn').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');

        durationSection.hidden = false;
        renderDurationOptions();
    }

    // ---------- Duration ----------

    function renderDurationOptions() {
        const startMin = timeToMinutes(selectedStart);

        durationGrid.querySelectorAll('.duration-btn').forEach((btn) => {
            const hours = parseFloat(btn.dataset.hours);
            const endMin = startMin + hours * 60;
            const overrunsClose = endMin > CLOSE_HOUR * 60;
            const overlapsBooking = bookedRanges.some(
                (r) => startMin < timeToMinutes(r.end) && endMin > timeToMinutes(r.start)
            );

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
        const endMin = startMin + selectedDuration * 60;
        const endTimeStr = `${pad(Math.floor(endMin / 60))}:${pad(endMin % 60)}`;

        summaryDate.textContent = formatDate(selectedDate);
        summaryTime.textContent = `${formatTime(selectedStart)} – ${formatTime(endTimeStr)}`;
        summaryTotal.textContent = `₱${(selectedCourt.price * selectedDuration).toLocaleString()}`;

        bookingSummary.hidden = false;
        confirmBtn.disabled = false;
    }

    confirmBtn.addEventListener('click', async () => {
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
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    court_id: selectedCourt.id,
                    date: selectedDate,
                    start_time: selectedStart,
                    duration: selectedDuration,
                }),
            });

            const data = await res.json();

            if (!res.ok) {
                showToast(data.message || 'That slot is no longer available.', 'error');
                bookedRanges = await fetchAvailability(selectedDate);
                renderTimeSlots();
                durationSection.hidden = true;
                bookingSummary.hidden = true;
                selectedStart = null;
                selectedDuration = null;
                confirmBtn.disabled = true;
                confirmBtn.textContent = originalLabel;
                return;
            }

            showToast(data.message || 'Booking confirmed!', 'success');
            closeModal();
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
});
