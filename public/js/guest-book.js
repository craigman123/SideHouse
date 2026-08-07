document.addEventListener('DOMContentLoaded', () => {
    // ---------- Scroll-triggered fade-in ----------
    // Runs regardless of whether the booking widget below is present,
    // so the hero/features/footer still animate in on pages without it.
    const fadeEls = document.querySelectorAll('.fade-in');
    if (fadeEls.length > 0 && 'IntersectionObserver' in window) {
        const fadeObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { root: null, rootMargin: '0px', threshold: 0.15 });

        fadeEls.forEach((el) => fadeObserver.observe(el));
    } else {
        // No IntersectionObserver support — just show everything.
        fadeEls.forEach((el) => el.classList.add('visible'));
    }

    const grid = document.getElementById('bookNow');
    if (!grid) return;

    const availabilityUrl = grid.dataset.availabilityUrl;
    const equipmentUrl = grid.dataset.equipmentUrl;
    const storeUrl = grid.dataset.storeUrl;

    const OPEN_HOUR = parseInt(grid.dataset.openHour, 10);
    const CLOSE_HOUR = parseInt(grid.dataset.closeHour, 10);
    const MIN_DURATION = parseFloat(grid.dataset.minDuration);
    const MAX_DURATION = parseFloat(grid.dataset.maxDuration);
    const STEP_MINUTES = parseInt(grid.dataset.stepMinutes, 10);
    const OVERNIGHT = CLOSE_HOUR <= OPEN_HOUR;

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

    const equipmentModal = document.getElementById('equipmentModal');
    const paymentModal = document.getElementById('courtPaymentModal');
    const timePickerModal = document.getElementById('timePickerModal');
    const timePickerDateLabel = document.getElementById('timePickerDateLabel');
    const timePickerScrollBody = document.getElementById('timePickerScrollBody');

    const calMonthLabel = document.getElementById('calMonthLabel');
    const calendarGrid = document.getElementById('calendarGrid');
    const calPrev = document.getElementById('calPrev');
    const calNext = document.getElementById('calNext');

    const timeSection = document.getElementById('timeSection');
    const timeSlotGrid = document.getElementById('timeSlotGrid');
    const durationSection = document.getElementById('durationSection');
    const durationGrid = document.getElementById('durationGrid');
    const continueToEquipmentBtn = document.getElementById('continueToEquipment');

    const equipmentGrid = document.getElementById('equipmentGrid');
    const continueToGuestInfoBtn = document.getElementById('continueToGuestInfo');

    const guestNameInput = document.getElementById('guestName');
    const guestContactInput = document.getElementById('guestContact');
    const paymentGrid = document.getElementById('paymentGrid');
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDate = document.getElementById('summaryDate');
    const summaryTime = document.getElementById('summaryTime');
    const summaryEquipmentRow = document.getElementById('summaryEquipmentRow');
    const summaryEquipment = document.getElementById('summaryEquipment');
    const summaryPayment = document.getElementById('summaryPayment');
    const summaryTotal = document.getElementById('summaryTotal');
    const confirmBtn = document.getElementById('confirmBooking');

    const PAYMENT_LABELS = { arrival: 'Pay on Arrival', ewallet: 'E-Wallet' };

    let selectedCourt = null;
    let calendarCursor = new Date();
    let selectedDate = null;
    let selectedStart = null;
    let selectedDuration = null;
    let selectedPayment = null;
    let bookedRanges = [];
    let equipmentCatalog = []; // [{id, name, category, price, available}]
    let equipmentSelection = {}; // { [equipmentId]: quantity }

    const pad = (n) => n.toString().padStart(2, '0');
    const todayStr = () => new Date().toISOString().slice(0, 10);

    // ---------- Modal open/close (equipment + payment only — the calendar
    // is inline in the page now, not a modal) ----------

    function openEquipmentModal() {
        equipmentModal.classList.add('open');
        loadEquipment();
    }

    function closeEquipmentModal() {
        equipmentModal.classList.remove('open');
    }

    function openTimePickerModal() {
        timePickerModal.classList.add('open');
        if (timePickerScrollBody) timePickerScrollBody.scrollTop = 0;
    }

    function closeTimePickerModal() {
        timePickerModal.classList.remove('open');
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
        equipmentSelection = {};
        calendarCursor = new Date();
        durationSection.hidden = true;
        closeTimePickerModal();
    }

    // ---------- Wire up the (only) court from the database ----------
    // There's only one court, so there's nothing for a guest to pick —
    // its details come straight from data-court-* on #bookNow, and the
    // calendar renders immediately since it's inline on the page, not
    // behind a click or a modal.
    function initBooking() {
        if (!grid.dataset.courtId) return; // no active courts — nothing to wire up

        selectedCourt = {
            id: grid.dataset.courtId,
            name: grid.dataset.courtName,
            type: grid.dataset.courtType,
            length: grid.dataset.courtLength,
            width: grid.dataset.courtWidth,
            price: grid.dataset.courtPrice,
        };

        resetBookingState();
        renderCalendar();
    }

    document.getElementById('equipmentModalClose').addEventListener('click', closeEquipmentModal);
    equipmentModal.addEventListener('click', (e) => {
        if (e.target === equipmentModal) closeEquipmentModal();
    });

    document.getElementById('timePickerModalClose').addEventListener('click', closeTimePickerModal);
    timePickerModal.addEventListener('click', (e) => {
        if (e.target === timePickerModal) closeTimePickerModal();
    });
    document.getElementById('backToCalendar').addEventListener('click', closeTimePickerModal);
    document.getElementById('backToCalendar2').addEventListener('click', closeTimePickerModal);

    document.getElementById('courtPaymentModalClose').addEventListener('click', closePaymentModal);
    paymentModal.addEventListener('click', (e) => {
        if (e.target === paymentModal) closePaymentModal();
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
    });
    document.getElementById('backToBookingFromEquipment2').addEventListener('click', () => {
        closeEquipmentModal();
    });

    continueToGuestInfoBtn.addEventListener('click', () => {
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

        if (timePickerDateLabel) timePickerDateLabel.textContent = formatDate(dateStr);
        openTimePickerModal();
        renderTimeSlotSkeleton();

        // Guarantee the skeleton is visible for at least a beat — on a
        // fast connection fetchAvailability can resolve before it's even
        // perceptible. Racing it against a minimum delay avoids that
        // without adding lag on slower connections.
        const MIN_SKELETON_MS = 300;
        const [ranges] = await Promise.all([
            fetchAvailability(dateStr),
            new Promise((resolve) => setTimeout(resolve, MIN_SKELETON_MS)),
        ]);

        bookedRanges = ranges;
        renderTimeSlots();
    }

    function renderTimeSlotSkeleton() {
        timeSlotGrid.innerHTML = '';

        // Same slot-count math as renderTimeSlots() below, so the number
        // of skeleton placeholders matches the real grid that replaces it.
        const spanMinutes = OVERNIGHT ? (24 - OPEN_HOUR + CLOSE_HOUR) * 60 : (CLOSE_HOUR - OPEN_HOUR) * 60;
        const lastStart = spanMinutes - MIN_DURATION * 60;
        const slotCount = Math.floor(lastStart / STEP_MINUTES) + 1;

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

    function minutesSinceOpen(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        let mins = h * 60 + m - OPEN_HOUR * 60;
        if (mins < 0) mins += 1440;
        return mins;
    }

    function isSlotBooked(slotSinceOpen) {
        const slotEnd = slotSinceOpen + MIN_DURATION * 60;
        return bookedRanges.some((r) => {
            const rangeStart = minutesSinceOpen(r.start);
            let rangeEnd = minutesSinceOpen(r.end);
            if (rangeEnd <= rangeStart) rangeEnd += 1440;
            return slotSinceOpen < rangeEnd && slotEnd > rangeStart;
        });
    }

    function renderTimeSlots() {
        timeSlotGrid.innerHTML = '';

        const spanMinutes = OVERNIGHT ? (24 - OPEN_HOUR + CLOSE_HOUR) * 60 : (CLOSE_HOUR - OPEN_HOUR) * 60;
        const lastStart = spanMinutes - MIN_DURATION * 60;

        for (let offset = 0; offset <= lastStart; offset += STEP_MINUTES) {
            const totalMin = (OPEN_HOUR * 60 + offset) % 1440;
            const h = Math.floor(totalMin / 60);
            const m = totalMin % 60;
            const timeStr = `${pad(h)}:${pad(m)}`;

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

        // Auto-scroll within the modal so the duration options are
        // immediately visible — no manual scrolling needed on the user's end.
        durationSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ---------- Duration ----------

    function timeToMinutes(t) {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    }

    function renderDurationOptions() {
        durationGrid.innerHTML = '';

        const startMin = timeToMinutes(selectedStart);
        const startSinceOpen = minutesSinceOpen(selectedStart);
        const spanMinutes = OVERNIGHT ? (24 - OPEN_HOUR + CLOSE_HOUR) * 60 : (CLOSE_HOUR - OPEN_HOUR) * 60;

        for (let hrs = MIN_DURATION; hrs <= MAX_DURATION; hrs += STEP_MINUTES / 60) {
            const hours = Math.round(hrs * 100) / 100;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'duration-btn';
            btn.dataset.hours = hours;
            btn.textContent = hours === 1 ? '1 hr' : `${hours} hrs`;

            const endSinceOpen = startSinceOpen + hours * 60;
            const overrunsClose = endSinceOpen > spanMinutes;

            const endMinAbs = (startMin + hours * 60) % 1440;
            const overlapsBooking = bookedRanges.some((r) => {
                const rangeStart = minutesSinceOpen(r.start);
                let rangeEnd = minutesSinceOpen(r.end);
                if (rangeEnd <= rangeStart) rangeEnd += 1440;
                return startSinceOpen < rangeEnd && endSinceOpen > rangeStart;
            });

            btn.disabled = overrunsClose || overlapsBooking;
            if (hours === selectedDuration) btn.classList.add('selected');

            durationGrid.appendChild(btn);
        }
    }

    durationGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.duration-btn');
        if (!btn || btn.disabled) return;

        selectedDuration = parseFloat(btn.dataset.hours);
        durationGrid.querySelectorAll('.duration-btn').forEach((el) => el.classList.remove('selected'));
        btn.classList.add('selected');

        // Bring the Continue button into view too, same reasoning as the
        // auto-scroll to duration — keep the user from having to scroll.
        continueToEquipmentBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // ---------- Equipment rental ----------

    async function loadEquipment() {
        equipmentGrid.innerHTML = '<p class="loading-text">Loading equipment…</p>';

        try {
            const url = `${equipmentUrl}?date=${selectedDate}&start_time=${selectedStart}&duration=${selectedDuration}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
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

        updateSummary();
    });

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
        const startMin = timeToMinutes(selectedStart);
        const endMin = (startMin + selectedDuration * 60) % 1440;
        const endTimeStr = `${pad(Math.floor(endMin / 60))}:${pad(endMin % 60)}`;

        summaryDate.textContent = formatDate(selectedDate);
        summaryTime.textContent = `${formatTime(selectedStart)} – ${formatTime(endTimeStr)}`;
        summaryPayment.textContent = selectedPayment ? PAYMENT_LABELS[selectedPayment] : '—';

        const equipText = equipmentSummaryText();
        if (equipText) {
            summaryEquipmentRow.hidden = false;
            summaryEquipment.textContent = equipText;
        } else {
            summaryEquipmentRow.hidden = true;
        }

        const total = selectedCourt.price * selectedDuration + equipmentTotal();
        summaryTotal.textContent = `₱${total.toLocaleString()}`;
    }

    function getMissingBookingRequirements() {
        const missing = [];
        if (!selectedDate) missing.push('a date');
        if (!selectedStart) missing.push('a start time');
        if (!selectedDuration) missing.push('a duration');
        return missing;
    }

    function getMissingGuestRequirements() {
        const missing = [];
        if (!guestNameInput.value.trim()) missing.push('your name');
        if (!guestContactInput.value.trim()) missing.push('a contact number');
        if (!selectedPayment) missing.push('a payment method');
        return missing;
    }

    function formatList(items) {
        if (items.length === 1) return items[0];
        if (items.length === 2) return `${items[0]} and ${items[1]}`;
        return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
    }

    // ---------- Confirm ----------

    confirmBtn.addEventListener('click', async () => {
        const missing = getMissingGuestRequirements();
        if (missing.length > 0) {
            showToast(`Please provide ${formatList(missing)} to continue.`, 'error');
            guestNameInput.classList.toggle('field-invalid', !guestNameInput.value.trim());
            guestContactInput.classList.toggle('field-invalid', !guestContactInput.value.trim());
            return;
        }

        if (!selectedCourt || !selectedDate || !selectedStart || !selectedDuration) return;

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
                    date: selectedDate,
                    start_time: selectedStart,
                    duration: selectedDuration,
                    payment_method: selectedPayment,
                    guest_name: guestNameInput.value.trim(),
                    guest_contact: guestContactInput.value.trim(),
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
                showToast(data.message || 'That slot is no longer available.', 'error');

                selectedStart = null;
                selectedDuration = null;
                durationSection.hidden = true;

                closePaymentModal();
                bookedRanges = await fetchAvailability(selectedDate);
                renderTimeSlots();
                openTimePickerModal();

                confirmBtn.disabled = false;
                confirmBtn.textContent = originalLabel;
                return;
            }

            showToast(data.message || 'Booking confirmed! See you on the court.', 'success');
            closePaymentModal();

            // No dashboard to send a guest to — reset state so the widget
            // is ready for another booking instead of redirecting anywhere.
            guestNameInput.value = '';
            guestContactInput.value = '';
            equipmentSelection = {};
            resetBookingState();
            renderCalendar();
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

    // Render the calendar immediately — it's inline on the page now,
    // not behind a click or a modal.
    initBooking();
});