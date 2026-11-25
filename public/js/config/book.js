document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('courtGrid');
    if (!grid) return;

    const availabilityUrl = grid.dataset.availabilityUrl;
    const equipmentUrl = grid.dataset.equipmentUrl;
    const storeUrl = grid.dataset.storeUrl;
    const waitingUrlTemplate = grid.dataset.waitingUrlTemplate;
    const receiptUrlTemplate = grid.dataset.receiptUrlTemplate || '';
    const userPhone = grid.dataset.userPhone || '';

    // Credit balance injected server-side — empty string when the user
    // has no wallet or zero balance, so the credit panel stays hidden.
    const CREDIT_GIVEN    = grid.dataset.creditGiven    !== '' ? Number(grid.dataset.creditGiven)    : null;
    const CREDIT_RESERVED = grid.dataset.creditReserved !== '' ? Number(grid.dataset.creditReserved) : null;
    const CREDIT_TOTAL    = grid.dataset.creditTotal    !== '' ? Number(grid.dataset.creditTotal)    : null;
    const HAS_CREDITS = CREDIT_TOTAL !== null && CREDIT_TOTAL > 0;

    const MIN_DURATION = parseFloat(grid.dataset.minDuration);
    const MAX_DURATION = parseFloat(grid.dataset.maxDuration);
    const STEP_MINUTES = parseInt(grid.dataset.stepMinutes, 10);
    const OPEN_HOUR = parseInt(grid.dataset.openHour, 10);
    const RAW_CLOSE_HOUR = parseInt(grid.dataset.closeHour, 10);
    const CLOSE_HOUR = (OPEN_HOUR === 0 && RAW_CLOSE_HOUR === 0) ? 24 : RAW_CLOSE_HOUR;
    const OVERNIGHT = CLOSE_HOUR <= OPEN_HOUR;

    // Peak/night pricing (admin Configuration page) — display-only
    // preview of the surcharge storeBooking() actually applies
    // server-side. Blank attributes (no window configured, or it was
    // left zero-length) mean peak pricing is off, same as
    // BusinessSetting::hasPeakPricing() on the backend.
    const PEAK_START_HOUR = grid.dataset.peakStartHour === '' ? null : parseInt(grid.dataset.peakStartHour, 10);
    const PEAK_END_HOUR = grid.dataset.peakEndHour === '' ? null : parseInt(grid.dataset.peakEndHour, 10);
    const PEAK_ADJUSTMENT_TYPE = grid.dataset.peakAdjustmentType || null;
    const PEAK_ADJUSTMENT_VALUE = grid.dataset.peakAdjustmentValue === '' ? null : parseFloat(grid.dataset.peakAdjustmentValue);
    const HAS_PEAK_PRICING = PEAK_START_HOUR !== null
        && PEAK_END_HOUR !== null
        && PEAK_START_HOUR !== PEAK_END_HOUR
        && (PEAK_ADJUSTMENT_TYPE === 'flat' || PEAK_ADJUSTMENT_TYPE === 'percent')
        && PEAK_ADJUSTMENT_VALUE > 0;

    // Mirrors BusinessSetting::isPeakHour() — same crosses-midnight
    // convention as OPEN_HOUR/CLOSE_HOUR (peak end <= peak start means
    // the window wraps past midnight).
    function isPeakHour(hour) {
        if (!HAS_PEAK_PRICING) return false;
        return PEAK_END_HOUR > PEAK_START_HOUR
            ? hour >= PEAK_START_HOUR && hour < PEAK_END_HOUR
            : hour >= PEAK_START_HOUR || hour < PEAK_END_HOUR;
    }

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
    const deepLinkNotice = document.getElementById('bookingDeepLinkNotice');
    const timePickerFeeRanges = document.getElementById('timePickerFeeRanges');
    const timePickerFeeTotal = document.getElementById('timePickerFeeTotal');
    const continueToEquipmentBtn = document.getElementById('continueToEquipment');

    const equipmentGrid = document.getElementById('equipmentGrid');
    const continueToPaymentBtn = document.getElementById('continueToPayment');

    const contactNumberInput = document.getElementById('contactNumber');
    const paymentGrid = document.getElementById('paymentGrid');
    const bookingSummary = document.getElementById('bookingSummary');
    const summaryDate = document.getElementById('summaryDate');
    const summaryTime = document.getElementById('summaryTime');
    const summaryEquipmentRow = document.getElementById('summaryEquipmentRow');
    const summaryEquipment = document.getElementById('summaryEquipment');
    const summaryPayment = document.getElementById('summaryPayment');
    const summaryTotal = document.getElementById('summaryTotal');
    const confirmBtn = document.getElementById('confirmBooking');
    const confirmLabel = confirmBtn.textContent;
    const paymentExitButtons = [
        document.getElementById('backToEquipment2'),
        document.getElementById('courtPaymentModalClose'),
    ];

    let paymentSubmissionLocked = false;
    let paymentRedirecting = false;

    // Idempotency token — generated once per checkout session, reset when
    // the user starts a new booking. Mirrors GuestBookingController's
    // createCheckoutKey() pattern so a double-click or a network retry
    // cannot create a duplicate booking or charge credits twice.
    let checkoutIdempotencyToken = null;

    function getOrCreateIdempotencyToken() {
        if (checkoutIdempotencyToken) return checkoutIdempotencyToken;
        if (window.crypto?.getRandomValues) {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            checkoutIdempotencyToken = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        } else {
            checkoutIdempotencyToken = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        }
        return checkoutIdempotencyToken;
    }

    // ---------- Credit UI ----------

    // Elements — IDs match the guest payment page exactly so CSS and the
    // same updateCreditSummary() logic covers both flows without a second
    // set of selectors. Present only when the user has a non-zero wallet.
    const useCreditEl    = document.getElementById('useGuestCredit');
    const creditSectionEl = document.getElementById('userCreditSection');

    // Minimum QR Ph charge — mirrors config/credit.php qr_minimum_php.
    // The server enforces the real value; this is display-only sync.
    const CREDIT_MIN_CASH = 1;

    // Inline SVG icons for given/reserved credits — same artwork as guest
    // payment.js CREDIT_ICONS so the two pages look identical. Using
    // currentColor means the icon follows whatever CSS color is set on the
    // parent element (brand green, muted, etc.) without hardcoded fills.
    const CREDIT_ICONS = {
        given: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 8.5 A6.5 6.5 0 1 0 16.5 15.5"/><path d="M11 12 H18"/><path d="M11 2.5 V5.5"/><path d="M11 18.5 V21.5"/></svg>',
        reserved: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6 L18 12 L12 18 L6 12 Z"/><path d="M3.5 12 H20.5"/><path d="M12 2 V6"/><path d="M12 18 V22"/></svg>',
    };

    /**
     * Renders an amount into el with a leading credit-type icon.
     * Mirrors setCredit() from guest payment.js — same class name,
     * same injection technique so the CSS rules are shared.
     * @param {HTMLElement|null} el   - Target element.
     * @param {'given'|'reserved'} kind - Which icon to use.
     * @param {number} amount         - Peso amount (non-negative integer).
     * @param {boolean} [minus=false] - Prefix a minus sign.
     */
    function setCredit(el, kind, amount, minus = false) {
        if (!el) return;
        const n = Math.max(0, Number(amount) || 0);
        el.replaceChildren();
        if (minus && n > 0) el.append('-');
        const icon = document.createElement('span');
        icon.className = 'credit-icon';
        icon.innerHTML = CREDIT_ICONS[kind === 'reserved' ? 'reserved' : 'given'];
        el.append(icon, `₱${n.toLocaleString()}`);
        if (n > 0) el.classList.add('is-used');
        else el.classList.remove('is-used');
    }

    /**
     * Recomputes the credit split client-side and updates every element in
     * the credit breakdown panel. Mirrors quoteAndSpendForGuestCheckout()
     * on the server exactly (given first, reserved second, minimum-cash
     * top-up). The server recomputes authoritatively on submit — this is
     * display-only so the user sees what they will owe before confirming.
     */
    function updateCreditSummary() {
        if (!HAS_CREDITS) return;

        const total = Math.round(selectedSlotsTotal() + equipmentTotal());
        const useCredit = useCreditEl?.checked !== false;

        let givenUsed    = useCredit ? Math.min(CREDIT_GIVEN,    total)                : 0;
        let remaining    = useCredit ? total - givenUsed                               : total;
        let reservedUsed = useCredit ? Math.min(CREDIT_RESERVED, remaining)             : 0;
        remaining       -= reservedUsed;

        // Minimum-cash top-up: never leave a QR payment below 1 peso.
        if (useCredit && remaining > 0 && remaining < CREDIT_MIN_CASH) {
            const needed       = CREDIT_MIN_CASH - remaining;
            const fromReserved = Math.min(CREDIT_RESERVED - reservedUsed, needed);
            reservedUsed += fromReserved;
            remaining    -= fromReserved;
            if (remaining > 0) {
                const fromGiven = Math.min(CREDIT_GIVEN - givenUsed, remaining);
                givenUsed += fromGiven;
                remaining -= fromGiven;
            }
        }

        const cashDue = Math.max(0, useCredit ? total - givenUsed - reservedUsed : total);

        // Balance chips — set plain amounts (no icons, same as guest chips)
        const givenBalEl    = document.getElementById('guestGivenBalance');
        const reservedBalEl = document.getElementById('guestReservedBalance');
        const totalBalEl    = document.getElementById('guestCreditTotal');
        if (givenBalEl)    givenBalEl.textContent    = `₱${Number(CREDIT_GIVEN).toLocaleString()}`;
        if (reservedBalEl) reservedBalEl.textContent = `₱${Number(CREDIT_RESERVED).toLocaleString()}`;
        if (totalBalEl)    totalBalEl.textContent    = `₱${Number(CREDIT_TOTAL).toLocaleString()}`;

        // Split breakdown — given/reserved rows get credit icons + is-used class
        const checkoutTotalEl = document.getElementById('guestCreditCheckoutTotal');
        const givenUsedEl     = document.getElementById('guestGivenUsed');
        const reservedUsedEl  = document.getElementById('guestReservedUsed');
        const discountEl      = document.getElementById('guestCreditDiscount');
        const cashDueEl       = document.getElementById('guestCashDue');

        if (checkoutTotalEl) checkoutTotalEl.textContent = `₱${total.toLocaleString()}`;

        if (givenUsedEl) {
            if (useCredit && givenUsed > 0) setCredit(givenUsedEl, 'given', givenUsed, true);
            else { givenUsedEl.replaceChildren('—'); givenUsedEl.classList.remove('is-used'); }
        }
        if (reservedUsedEl) {
            if (useCredit && reservedUsed > 0) setCredit(reservedUsedEl, 'reserved', reservedUsed, true);
            else { reservedUsedEl.replaceChildren('—'); reservedUsedEl.classList.remove('is-used'); }
        }

        const totalUsed = givenUsed + reservedUsed;
        if (discountEl) {
            if (useCredit && totalUsed > 0) {
                // Discount row: show a simple minus-amount (no specific credit type icon)
                discountEl.textContent = `-₱${totalUsed.toLocaleString()}`;
                discountEl.classList.add('is-used');
            } else {
                discountEl.textContent = '—';
                discountEl.classList.remove('is-used');
            }
        }
        if (cashDueEl) cashDueEl.textContent = `₱${cashDue.toLocaleString()}`;

        // Final review total — shows what is actually left to pay
        if (summaryTotal) {
            summaryTotal.textContent = useCredit
                ? `₱${cashDue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                : `₱${total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
    }

    // Re-run the breakdown whenever the checkbox flips.
    useCreditEl?.addEventListener('change', updateCreditSummary);

    function setPaymentSubmissionLocked(locked) {
        paymentSubmissionLocked = locked;
        if (locked) paymentModal.setAttribute('aria-busy', 'true');
        else paymentModal.removeAttribute('aria-busy');

        paymentExitButtons.forEach((button) => {
            if (!button) return;
            button.disabled = locked;
            if (locked) button.setAttribute('aria-disabled', 'true');
            else button.removeAttribute('aria-disabled');
        });

        contactNumberInput.disabled = locked;
        paymentGrid.querySelector('.payment-btn-qrph')?.classList.toggle('is-busy', locked);

        confirmBtn.disabled = locked;
        if (locked) {
            confirmBtn.setAttribute('aria-disabled', 'true');
            confirmBtn.textContent = 'Processing...';
        } else {
            confirmBtn.removeAttribute('aria-disabled');
            confirmBtn.textContent = confirmLabel;
        }
    }

    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        paymentRedirecting = false;
        setPaymentSubmissionLocked(false);
    });

    window.addEventListener('keydown', (event) => {
        if (!paymentSubmissionLocked || event.key !== 'Escape') return;
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);


    let selectedCourt = null;
    let calendarCursor = new Date();
    let selectedDate = null;
    let selectedSlots = []; // e.g. ['2026-08-19 23:00', '2026-08-20 00:00'], sorted
    // The real `date` submitted with the booking — the session's own
    // opening date, which for an overnight court can differ from
    // selectedDate (the picker page being viewed). See guest-book.js for
    // the fuller rationale; mirrored here for the signed-in flow.
    const slotSessions = new Map(); // slot key -> the booking `date` it belongs to
    let activeSessionDate = null;
    const selectedPayment = 'qrph';
    let bookedRanges = [];
    let timedClosureRanges = [];
    let openPlayRanges = [];
    const openPlayEventsByDate = {};
    let equipmentCatalog = [];
    let equipmentSelection = {};

    // Validates a same-site path before navigating — waitingUrlTemplate
    // itself comes from our own page dataset (server-rendered), but the
    // final URL still gets checked after the booking ID is substituted
    // in, so this stays correct even if that template ever changes shape.
    // route() renders a full absolute URL (scheme + host included), so we
    // resolve it against window.location and only trust it if the origin
    // matches — otherwise we'd never leave the fallback.
    function safeRedirectPath(path, fallback = '/') {
        if (typeof path !== 'string' || !path) return fallback;
        try {
            const url = new URL(path, window.location.origin);
            if (url.origin === window.location.origin) {
                return `${url.pathname}${url.search}${url.hash}`;
            }
        } catch (err) {
            // fall through to fallback below
        }
        return fallback;
    }

    const pad = (n) => n.toString().padStart(2, '0');

    const slotKey = (dateStr, timeStr) => `${dateStr} ${timeStr}`;
    const keyDate = (key) => key.slice(0, 10);
    const keyTime = (key) => key.slice(11);
    const keyStamp = (key) => new Date(`${keyDate(key)}T${keyTime(key)}:00`).getTime();
    const todayStr = () => {
        const d = new Date();
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    };

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
        updateBookingProgress(1);
        updateSelectionCards();
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
        updateBookingProgress(1);
        updateSelectionCards();
        timePickerModal.classList.add('open');
        renderTimePickerDateLabel();
        updateDayNavState();
    }

    function closeTimePickerModal() {
        timePickerModal.classList.remove('open');
    }

    function openEquipmentModal() {
        updateBookingProgress(2);
        equipmentModal.classList.add('open');
        loadEquipment();
    }

    function closeEquipmentModal() {
        equipmentModal.classList.remove('open');
    }

    function openPaymentModal() {

        // Prefill from the saved profile number, but only if the field is
        // still empty — never overwrite something the person already
        // typed (e.g. they went back and forth between steps).
        if (userPhone && !contactNumberInput.value.trim()) {
            contactNumberInput.value = userPhone;
        }

        updateSummary();
        updateCreditSummary(); // refreshes the credit panel and summaryTotal
        updateBookingProgress(3);
        paymentModal.classList.add('open');
    }

    function closePaymentModal() {
        paymentModal.classList.remove('open');
    }

    function resetBookingState() {
        selectedDate = null;
        selectedSlots = [];
        slotSessions.clear();
        activeSessionDate = null;
        bookedRanges = [];
        equipmentSelection = {};
        calendarCursor = new Date();
        // A new booking session gets a fresh idempotency token so the old
        // checkout key cannot accidentally deduplicate a genuinely new booking.
        checkoutIdempotencyToken = null;
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

    document.getElementById('timePickerModalClose')?.addEventListener('click', closeTimePickerModal);
    timePickerModal.addEventListener('click', (e) => {
        if (e.target === timePickerModal) closeTimePickerModal();
    });

    document.getElementById('equipmentModalClose')?.addEventListener('click', closeEquipmentModal);
    equipmentModal.addEventListener('click', (e) => {
        if (e.target === equipmentModal) closeEquipmentModal();
    });

    document.getElementById('courtPaymentModalClose')?.addEventListener('click', () => {
        if (!paymentSubmissionLocked) closePaymentModal();
    });
    paymentModal.addEventListener('click', (e) => {
        if (!paymentSubmissionLocked && e.target === paymentModal) closePaymentModal();
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

    document.getElementById('backToBookingFromEquipment')?.addEventListener('click', () => {
        closeEquipmentModal();
        openTimePickerModal();
    });
    document.getElementById('backToBookingFromEquipment2')?.addEventListener('click', () => {
        closeEquipmentModal();
        openTimePickerModal();
    });

    continueToPaymentBtn?.addEventListener('click', () => {
        closeEquipmentModal();
        openPaymentModal();
    });

    document.getElementById('backToEquipment')?.addEventListener('click', () => {
        if (paymentSubmissionLocked) return;
        closePaymentModal();
        openEquipmentModal();
    });
    document.getElementById('backToEquipment2')?.addEventListener('click', () => {
        if (paymentSubmissionLocked) return;
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
        // Re-opening the same day after "Back" keeps what the user already
        // picked. Picking a different day still starts fresh.
        const keepSelection = dateStr === selectedDate && selectedSlots.length > 0;

        selectedDate = dateStr;
        if (!keepSelection) {
            selectedSlots = [];
            slotSessions.clear();
            activeSessionDate = null;
        }

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

        bookedRanges = ranges.booked || [];
        openPlayRanges = ranges.open_play || [];
        timedClosureRanges = ranges.closed_blocks || [];
        openPlayEventsByDate[dateStr] = openPlayRanges;
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
        if (!availabilityUrl || !selectedCourt) return { booked: [], open_play: [], closed_blocks: [] };
        try {
            const url = `${availabilityUrl}?court_id=${selectedCourt.id}&date=${dateStr}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load availability');
            const data = await res.json();
            return { booked: data.booked || [], open_play: data.open_play || [], closed_blocks: data.closed_blocks || [] };
        } catch (err) {
            console.error(err);
            return { booked: [], open_play: [], closed_blocks: [] };
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

    function isSlotClosedAt(totalMin) {
        const slotEnd = totalMin + STEP_MINUTES;
        return timedClosureRanges.some((range) => totalMin < (timeToMinutes(range.end) || 1440)
            && slotEnd > timeToMinutes(range.start));
    }

    function closureAt(totalMin) {
        const end = totalMin + STEP_MINUTES;
        return timedClosureRanges.find((r) => totalMin < (timeToMinutes(r.end) || 1440)
            && end > timeToMinutes(r.start));
    }

    function openPlayAt(totalMin, dateStr = selectedDate) {
        const end = totalMin + STEP_MINUTES;
        return (openPlayEventsByDate[dateStr] || openPlayRanges).find((event) => totalMin >= timeToMinutes(event.start)
            && end <= (timeToMinutes(event.end) || 1440));
    }

    // `hour` (0-23) is the slot's real clock hour — omit it to get the
    // flat, un-adjusted rate (used where no specific hour applies).
    // Mirrors BusinessSetting::applyPeakAdjustment(): the surcharge is
    // applied to the hourly rate first, then scaled down to the actual
    // slot length.
    function slotPrice(hour, dateStr = selectedDate) {
        if (!selectedCourt) return 0;
        const openPlay = openPlayAt(Number(hour || 0) * 60, dateStr);
        if (openPlay) return Number(openPlay.price_per_player);

        let hourlyRate = Number(selectedCourt.price);
        if (hour !== undefined && isPeakHour(hour)) {
            hourlyRate = PEAK_ADJUSTMENT_TYPE === 'percent'
                ? hourlyRate * (1 + PEAK_ADJUSTMENT_VALUE / 100)
                : hourlyRate + PEAK_ADJUSTMENT_VALUE;
        }

        return hourlyRate * (STEP_MINUTES / 60);
    }

    // Sums the actual per-slot (peak-aware) price across every selected
    // slot key ("YYYY-MM-DD HH:MM"), instead of assuming every slot
    // costs the same flat amount.
    function selectedSlotsTotal() {
        const seen = new Set();
        return selectedSlots.reduce((sum, key) => {
            const hour = Number(keyTime(key).slice(0, 2));
            const ev = openPlayAt(hour * 60, keyDate(key));
            if (ev) {
                const id = `${keyDate(key)} ${ev.start}`;
                if (seen.has(id)) return sum;
                seen.add(id);
            }
            return sum + slotPrice(hour, keyDate(key));
        }, 0);
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
        divider.innerHTML = `<p class="time-slot-closed-divider-label">Closes</p> at ${formatHourLabel(CLOSE_HOUR)} · <p class="time-slot-open-divider-label">Opens</p> at ${formatHourLabel(OPEN_HOUR)}`;
        timeSlotGrid.appendChild(divider);
    }

    // Builds one contiguous run of hourly rows from startMin up to (but
    // not including) endMin, all real times on `selectedDate` itself.
    // `sessionDate` is what actually gets submitted as the booking's
    // `date` if a row in this run gets picked — see activeSessionDate's
    // comment above for why that can differ from selectedDate.

    // ---------- Date-aware slot selection ----------
    // Selections are stored as "YYYY-MM-DD HH:MM" keys (the slot's REAL
    // calendar date + clock time) instead of bare times, so an overnight
    // session that crosses midnight keeps each hour attached to the day
    // it actually falls on, and the same clock hour on two different days
    // can never be confused for one another.
    const sortSlotKeys = () => selectedSlots.sort((a, b) => keyStamp(a) - keyStamp(b));

    // "11PM", "12AM", "1:30AM" — compact 12-hour label.
    function formatCompactTime(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return m === 0 ? `${hour12}${period}` : `${hour12}:${pad(m)}${period}`;
    }

    function formatLongDate(dateStr, withYear) {
        return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US',
            withYear
                ? { month: 'long', day: 'numeric', year: 'numeric' }
                : { month: 'long', day: 'numeric' });
    }

    const SLOT_ICONS = {
            arrowUp: `<svg  xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" ><path d="M12.88 2.53c-.35-.66-1.42-.66-1.77 0l-8.99 17c-.16.31-.15.68.03.98s.51.48.86.48h18c.35 0 .68-.18.86-.48s.19-.67.03-.98zM12 5.13l3.07 5.81-1.07.81-1.4-1.05a.99.99 0 0 0-1.2 0L10 11.75l-1.07-.81zM4.66 18.99l3.32-6.27 1.42 1.07c.36.27.84.27 1.2 0l1.4-1.05 1.4 1.05c.18.13.39.2.6.2s.42-.07.6-.2l1.42-1.07 3.32 6.27z"></path></svg>`,  // peak
            padel:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg"><g transform="rotate(35 12 12)"><ellipse cx="12" cy="8.5" rx="5.5" ry="7"/><path d="M12 15.5V21.5"/><circle cx="9.7" cy="5.5" r="0.9" fill="currentColor" stroke="none"/><circle cx="14.3" cy="5.5" r="0.9" fill="currentColor" stroke="none"/><circle cx="12" cy="8.5" r="0.9" fill="currentColor" stroke="none"/><circle cx="9.7" cy="11.5" r="0.9" fill="currentColor" stroke="none"/><circle cx="14.3" cy="11.5" r="0.9" fill="currentColor" stroke="none"/></g></svg>`, // open play
            wrench:  `<svg  xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" ><path d="M20.71 6.04a.99.99 0 0 0-.9.27l-3.18 3.18-2.12-2.12 3.18-3.18a.98.98 0 0 0 .27-.9c-.07-.33-.29-.6-.6-.73A7.47 7.47 0 0 0 9.2 4.19a7.49 7.49 0 0 0-1.86 7.52L2.3 16.75c-.19.19-.29.44-.29.71s.11.52.29.71l3.54 3.54c.19.19.44.29.71.29s.52-.11.71-.29l5.04-5.04c2.64.82 5.53.12 7.52-1.86a7.47 7.47 0 0 0 1.63-8.16c-.13-.31-.4-.53-.73-.6Zm-2.32 7.34a5.51 5.51 0 0 1-5.98 1.2c-.37-.15-.8-.07-1.09.22l-4.78 4.78-2.12-2.12 4.78-4.78c.29-.29.37-.71.22-1.09a5.47 5.47 0 0 1 1.2-5.98 5.5 5.5 0 0 1 4.41-1.59l-2.65 2.65a.996.996 0 0 0 0 1.41l3.54 3.54c.19.19.44.29.71.29s.52-.11.71-.29l2.65-2.65c.16 1.61-.4 3.23-1.59 4.42Z"></path></svg>`,  // maintenance
            lock:    `<svg  xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" ><path d="M6 22h12c1.1 0 2-.9 2-2v-9c0-1.1-.9-2-2-2h-1V7c0-2.76-2.24-5-5-5S7 4.24 7 7v2H6c-1.1 0-2 .9-2 2v9c0 1.1.9 2 2 2M9 7c0-1.65 1.35-3 3-3s3 1.35 3 3v2H9zm-3 4h12v9H6z"></path></svg>`,  // closed
            book:    `<svg  xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" ><path d="M8 6h9v2H8z"></path><path d="M20 2H6C4.35 2 3 3.35 3 5v14c0 1.65 1.35 3 3 3h15v-2H6c-.55 0-1-.45-1-1s.45-1 1-1h14c.55 0 1-.45 1-1V3c0-.55-.45-1-1-1m-6 14H6c-.35 0-.69.07-1 .18V5c0-.55.45-1 1-1h13v12z"></path></svg>`,  // bookable
        };

    function slotIconEl(kind) {
        if (!kind || !SLOT_ICONS[kind]) return null;
        const el = document.createElement('span');
        el.className = 'time-slot-icon';
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = SLOT_ICONS[kind]; // your own static SVG, not user data
        return el;
    }

    function renderOpenPlayBlock(openPlay, evStart, evEnd, sessionDate) {
        const keys = openPlayKeys(openPlay);
        const isSelected = keys.length > 0 && keys.every((k) => selectedSlots.includes(k));
        const full = Number(openPlay.joined) >= Number(openPlay.max_players);
        const past = keys.some((k) => isSlotPastAt(keyDate(k), keyTime(k)));
        const closed = keys.some((k) => isSlotClosedAt(timeToMinutes(keyTime(k))));
        const unavailable = full || past || closed;
        const spotsLeft = Math.max(0, Number(openPlay.max_players) - Number(openPlay.joined));

        const fmt = (min) => {
            const mm = min % 1440;
            const h = Math.floor(mm / 60);
            const m = mm % 60;
            const period = h >= 12 ? 'PM' : 'AM';
            return `${h % 12 === 0 ? 12 : h % 12}:${pad(m)}${period}`;
        };

        const row = document.createElement('div');
        row.className = 'time-slot-row time-slot-row-openplay'
            + (isSelected ? ' selected' : '') + (unavailable ? ' disabled' : '');
        row.dataset.slotDate = sessionDate;
        row.dataset.slotTime = openPlay.start;
        if (unavailable) row.dataset.unavailableReason = full ? 'full' : past ? 'past' : 'closed';

        const times = document.createElement('div');
        times.className = 'time-slot-openplay-times';
        for (let t = evStart; t + STEP_MINUTES <= evEnd; t += STEP_MINUTES) {
            const hourLabel = document.createElement('span');
            hourLabel.className = 'time-slot-row-time';
            hourLabel.textContent = formatSlotRange(t);
            times.appendChild(hourLabel);
        }

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'time-slot-row-toggle time-slot-openplay-toggle' + (isSelected ? ' selected' : '');

        const check = document.createElement('span');
        check.className = 'time-slot-row-check';
        check.textContent = '✓ ';
        check.setAttribute('aria-hidden', 'true');

        const body = document.createElement('span');
        body.className = 'time-slot-openplay-body';

        const title = document.createElement('strong');
        title.className = 'time-slot-openplay-title';
        title.textContent = openPlay.title || 'Open play';

        const meta = document.createElement('span');
        meta.className = 'time-slot-openplay-meta';
        meta.textContent = full
            ? 'Full'
            : `${spotsLeft} of ${openPlay.max_players} spots left · ₱${Number(openPlay.price_per_player).toLocaleString()} per player`;

        body.appendChild(title);
        body.appendChild(meta);

        const icon = slotIconEl('padel');
        toggle.appendChild(check);
        if (icon) toggle.appendChild(icon);
        toggle.appendChild(body);

        if (unavailable) {
            toggle.disabled = true;
            const reason = full ? 'This open play is full'
                : past ? 'This open play has already started'
                : closed ? 'This open play overlaps a closed time'
                : 'This open play is unavailable';
            toggle.title = reason;
            row.dataset.tooltip = reason;
        } else {
            toggle.setAttribute('aria-pressed', String(isSelected));
            toggle.addEventListener('click', () => toggleOpenPlayEvent(openPlay, sessionDate));
        }

        row.appendChild(times);
        row.appendChild(toggle);
        timeSlotGrid.appendChild(row);
    }

    function renderTimeSlotBlock(startMin, endMin, sessionDate) {
        // Slots stay pickable on every block and every day — the only
        // limit is MAX_DURATION, enforced in toggleSlot().

        for (let totalMin = startMin; totalMin + STEP_MINUTES <= endMin; totalMin += STEP_MINUTES) {
            const h = Math.floor(totalMin / 60);
            const m = totalMin % 60;
            const timeStr = `${pad(h)}:${pad(m)}`;

            const openPlay = openPlayAt(totalMin);

            if (openPlay) {
                const evStart = timeToMinutes(openPlay.start);
                const evEnd = timeToMinutes(openPlay.end) || 1440;
                renderOpenPlayBlock(openPlay, evStart, evEnd, sessionDate);
                totalMin = evEnd - STEP_MINUTES; // loop adds STEP_MINUTES, skipping the rest of the event
                continue;
            }

            const booked = isSlotBookedAt(totalMin) && !openPlay;
            const timedClosed = isSlotClosedAt(totalMin);
            const full = openPlay && Number(openPlay.joined) >= Number(openPlay.max_players);
            const past = isSlotPastAt(selectedDate, timeStr);
            const unavailable = booked || full || timedClosed || past;

            const closure = timedClosed ? closureAt(totalMin) : null;
            const unavailableReason = booked
                ? 'Reserved — already booked by another player'
                : timedClosed
                    ? (closure?.type === 'maintenance'
                        ? 'Court under maintenance during this time'
                        : 'Court closed during this time')
                    : past ? 'This time has already passed' : '';

            const isSelected = selectedSlots.includes(slotKey(selectedDate, timeStr));

            // The row itself is just a container — the time label on the
            // left is plain text, not clickable. Only the button on the
            // right (the price pill) actually toggles the selection.
            const row = document.createElement('div');
            row.className = 'time-slot-row' + (isSelected ? ' selected' : '') + (unavailable ? ' disabled' : '');
            row.dataset.slotDate = sessionDate;
            row.dataset.slotTime = timeStr;
            if (unavailable) {
                row.dataset.unavailableReason = booked ? 'taken' : timedClosed ? 'closed' : past ? 'past' : 'full';
            }

            const label = document.createElement('span');
            label.className = 'time-slot-row-time';
            label.textContent = formatSlotRange(totalMin);

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'time-slot-row-toggle' + (isSelected ? ' selected' : '');

            const check = document.createElement('span');
            check.className = 'time-slot-row-check';
            check.textContent = '✓ ';
            check.setAttribute('aria-hidden', 'true');

            const priceLabel = document.createElement('span');
            if (full) {
                priceLabel.textContent = 'Full';
            } else if (openPlay) {
                priceLabel.textContent = `Open play · ${Math.max(0, Number(openPlay.max_players) - Number(openPlay.joined))}/${openPlay.max_players} spots · ₱${Number(openPlay.price_per_player).toLocaleString()}`;
            } else if (booked) {
                priceLabel.textContent = 'Booked';
            } else if (timedClosed) {
                priceLabel.textContent = 'Closed';
            } else if (past) {
                priceLabel.textContent = 'Past';
            } else {
                priceLabel.textContent = `₱${slotPrice(h).toLocaleString()}`;
            }

            let iconKind = null;
            if (timedClosed) {
                iconKind = closure?.type === 'maintenance' ? 'wrench' : 'lock';
            } else if (!booked && !past) {
                iconKind = isPeakHour(h) ? 'arrowUp' : 'book';
            }
            const icon = slotIconEl(iconKind);

            if (!booked && !timedClosed && !past && isPeakHour(h)) {
                row.classList.add('time-slot-row-peak');
                row.dataset.tooltip = 'Peak pricing applies to this hour';
            }

            toggle.appendChild(check);
            if (icon) toggle.appendChild(icon);
                toggle.appendChild(priceLabel);

            if (unavailable) {
                toggle.disabled = true;
                row.dataset.tooltip = booked
                    ? 'Reserved — already booked by another player'
                    : timedClosed
                        ? (closure?.type === 'maintenance' ? 'Court under maintenance during this time' : 'Court closed during this time')
                        : 'This time has already passed';
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
        const hasTail = OVERNIGHT && CLOSE_HOUR * 60 > 0 && !isDateClosed(tailSessionDate);

        if (hasTail) {
            renderTimeSlotBlock(0, CLOSE_HOUR * 60, tailSessionDate);
            appendClosedDivider();
        }

        // The session selectedDate opens itself, running from open_hour
        // up to (but not past) midnight — anything past midnight belongs
        // to tomorrow's page as ITS tail block, not this one.
        renderTimeSlotBlock(OPEN_HOUR * 60, OVERNIGHT ? 1440 : CLOSE_HOUR * 60, selectedDate);
    }

    function openPlayKeys(event) {
        const start = timeToMinutes(event.start);
        const end = timeToMinutes(event.end) || 1440;
        const keys = [];
        for (let t = start; t + STEP_MINUTES <= end; t += STEP_MINUTES) {
            keys.push(slotKey(selectedDate, `${pad(Math.floor(t / 60))}:${pad(t % 60)}`));
        }
        return keys;
    }

    function toggleOpenPlayEvent(event, sessionDate) {
        const keys = openPlayKeys(event);
        const allSelected = keys.every((k) => selectedSlots.includes(k));

        if (allSelected) {
            selectedSlots = selectedSlots.filter((k) => !keys.includes(k));
            keys.forEach((k) => slotSessions.delete(k));
        } else {
            const blocked = keys.some((k) => isSlotPastAt(keyDate(k), keyTime(k)));
            if (blocked) {
                showToast('This open play has already started, so it can’t be joined.', 'error');
                return;
            }

            const toAdd = keys.filter((k) => !selectedSlots.includes(k));
            if (selectedSlots.length + toAdd.length > MAX_DURATION) {
                showToast(`This open play is longer than the ${MAX_DURATION}-hour booking limit.`, 'error');
                return;
            }

            toAdd.forEach((k) => {
                selectedSlots.push(k);
                slotSessions.set(k, sessionDate);
            });
            sortSlotKeys();
        }

        activeSessionDate = selectedSlots.length === 0
            ? null
            : slotSessions.get(selectedSlots[0]) || sessionDate;

        renderTimeSlots();
        updateTimePickerFee();
    }

    // Toggles one hour on or off. Selected hours don't need to be
    // contiguous, but they do all have to belong to the same session
    // (see activeSessionDate).
    function toggleSlot(timeStr, sessionDate) {
        const key = slotKey(selectedDate, timeStr);
        const idx = selectedSlots.indexOf(key);
        const openPlayEvent = openPlayAt(timeToMinutes(timeStr));
        if (openPlayEvent) {
            toggleOpenPlayEvent(openPlayEvent, sessionDate);
            return;
        }

        if (idx >= 0) {
            selectedSlots.splice(idx, 1);
            slotSessions.delete(key);
            activeSessionDate = selectedSlots.length === 0
                ? null
                : slotSessions.get(selectedSlots[0]) || null;
        } else {
            if (selectedSlots.length >= MAX_DURATION) {
                showToast(`You can book up to ${(MAX_DURATION * STEP_MINUTES / 60)} hour${(MAX_DURATION * STEP_MINUTES / 60) === 1 ? '' : 's'} per booking.`, 'error');
                return;
            }
            selectedSlots.push(key);
            slotSessions.set(key, sessionDate);
            sortSlotKeys();
            activeSessionDate = slotSessions.get(selectedSlots[0]) || sessionDate;
        }

        renderTimeSlots();
        updateTimePickerFee();
    }

    // "August 19, 2026 11PM - 12AM || August 20 1AM - 2AM | 6AM - 7AM"
    // Contiguous hours collapse into one range (even across midnight),
    // ranges are grouped under the calendar day they start on, and only
    // the first day carries the year.
    function formatSelectedSlotsSummary(separator = ' | ', dateSeparator = ' || ') {
        if (selectedSlots.length === 0) return '';

        sortSlotKeys();

        const ranges = [];
        let rangeStart = selectedSlots[0];
        let rangeEnd = selectedSlots[0];

        for (let i = 1; i < selectedSlots.length; i++) {
            if (keyStamp(selectedSlots[i]) === keyStamp(rangeEnd) + STEP_MINUTES * 60000) {
                rangeEnd = selectedSlots[i];
            } else {
                ranges.push([rangeStart, rangeEnd]);
                rangeStart = selectedSlots[i];
                rangeEnd = selectedSlots[i];
            }
        }
        ranges.push([rangeStart, rangeEnd]);

        const groups = [];
        ranges.forEach(([start, end]) => {
            const endDate = new Date(keyStamp(end) + STEP_MINUTES * 60000);
            const endStr = `${pad(endDate.getHours())}:${pad(endDate.getMinutes())}`;
            const label = `${formatCompactTime(keyTime(start))} - ${formatCompactTime(endStr)}`;
            const day = keyDate(start);
            const group = groups.find((g) => g.date === day);
            if (group) group.ranges.push(label);
            else groups.push({ date: day, ranges: [label] });
        });

        return groups
            .map((g, i) => `${formatLongDate(g.date, i === 0)} ${g.ranges.join(separator)}`)
            .join(dateSeparator);
    }

    function updateTimePickerFee() {
        if (timePickerFeeRanges && timePickerFeeTotal) {
            if (selectedSlots.length === 0) {
                timePickerFeeRanges.textContent = 'Select at least one hour';
                timePickerFeeTotal.textContent = '₱0';
            } else {
                timePickerFeeRanges.textContent = formatSelectedSlotsSummary();
                timePickerFeeTotal.textContent = `₱${selectedSlotsTotal().toLocaleString()}`;
            }
        }
        updateSelectionCards();
    }

    // ---------- Equipment rental ----------

    async function loadEquipment() {
        equipmentGrid.setAttribute('aria-busy', 'true');
        equipmentGrid.innerHTML = '<span class="visually-hidden">Loading equipment</span><div class="user-equipment-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>';

        try {
            const params = new URLSearchParams();
            // equipment load — backend validates slots.* as Y-m-d H:i,
            // so the full key has to go, not just the time portion.
            // (Previously sent keyTime(key), e.g. "23:00" — that always
            // failed date_format validation, the endpoint 422'd, and
            // this silently rendered as "no equipment" below since the
            // response was never checked for res.ok.)
            params.append('date', activeSessionDate || selectedDate);
            if (selectedCourt?.id) params.append('court_id', selectedCourt.id);
            selectedSlots.forEach((key) => params.append('slots[]', key));

            const res = await fetch(`${equipmentUrl}?${params.toString()}`, { headers: { Accept: 'application/json' } });

            if (!res.ok) {
                console.error('equipment load failed', res.status, await res.text().catch(() => ''));
                equipmentGrid.setAttribute('aria-busy', 'false');
                equipmentGrid.innerHTML = '<p class="loading-text">Couldn\'t load equipment right now.</p>';
                return;
            }

            const data = await res.json();
            equipmentCatalog = data.equipment || [];
            renderEquipment();
        } catch (err) {
            console.error(err);
            equipmentGrid.setAttribute('aria-busy', 'false');
            equipmentGrid.innerHTML = '<p class="loading-text">Couldn\'t load equipment right now.</p>';
        }
    }

    function renderEquipment() {
        if (equipmentCatalog.length === 0) {
            equipmentGrid.setAttribute('aria-busy', 'false');
            equipmentGrid.innerHTML = '<p class="loading-text">No equipment available to rent right now.</p>';
            updateSelectionCards();
            return;
        }

        equipmentGrid.setAttribute('aria-busy', 'false');
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
        updateSelectionCards();
    }

    function buildEquipmentCard(item) {
        const qty = equipmentSelection[item.id] || 0;

        const card = document.createElement('div');
        card.className = 'equipment-card' + (qty > 0 ? ' has-qty' : '');

        const info = document.createElement('div');

        const nameEl = document.createElement('p');
        nameEl.className = 'equipment-card-name';
        nameEl.textContent = item.name;

        const metaEl = document.createElement('p');
        metaEl.className = 'equipment-card-meta' + (item.available === 0 ? ' equipment-sold-out' : '');
        metaEl.textContent = `₱${Number(item.price).toLocaleString()} each · ${item.available === 0 ? 'Sold out for this time' : `${item.available} available`}`;

        info.appendChild(nameEl);
        info.appendChild(metaEl);

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



    function finishBookingReset() {
        // Reset back to the saved profile number (if any) rather than
        // blanking it out — so it's still prefilled next time the person
        // books, instead of making them retype it every visit.
        contactNumberInput.value = userPhone || '';
        equipmentSelection = {};
        resetBookingState();
    }

    // ---------- Summary + validation ----------

    // "1:00 - 2:00 PM" / "11:00 PM - 12:00 AM" — the slot's own span, using
    // the business setting's step size so 30-min steps read correctly too.
    function formatSlotRange(totalMin) {
        const endMin = (totalMin + STEP_MINUTES) % 1440;
        const startH = Math.floor(totalMin / 60);
        const startM = totalMin % 60;
        const endH = Math.floor(endMin / 60);
        const endM = endMin % 60;

        const formatPart = (h, m) => {
            const period = h >= 12 ? 'PM' : 'AM';
            const hour12 = h % 12 === 0 ? 12 : h % 12;
            return `${hour12}:${pad(m)}${period}`;
        };

        return `${formatPart(startH, startM)} - ${formatPart(endH, endM)}`;
    }

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

    function updateBookingProgress(activeStep) {
        document.querySelectorAll('.user-book-court-page [data-booking-step]').forEach((step) => {
            const number = Number(step.dataset.bookingStep);
            step.classList.toggle('active', number === activeStep);
            step.classList.toggle('complete', number < activeStep);
            if (number === activeStep) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });
    }

    function updateSelectionCards() {
        const dateTime = selectedDate
            ? `${formatDate(activeSessionDate || selectedDate)}${selectedSlots.length ? ` · ${formatSelectedSlotsSummary()}` : ''}`
            : 'Choose a date and time';
        const equipment = equipmentSummaryText() || 'None selected';
        const total = `₱${(selectedSlotsTotal() + equipmentTotal()).toLocaleString()}`;

        document.querySelectorAll('.user-book-court-page .user-booking-selection').forEach((card) => {
            card.querySelectorAll('.selection-court').forEach((el) => { el.textContent = selectedCourt?.name || 'Choose a court'; });
            card.querySelectorAll('.selection-date-time').forEach((el) => { el.textContent = dateTime; });
            card.querySelectorAll('.selection-equipment').forEach((el) => { el.textContent = equipment; });
            card.querySelectorAll('.selection-total').forEach((el) => { el.textContent = total; });
        });
    }

    function updateSummary() {
        // updateSummary
        summaryDate.textContent = '';
        summaryTime.textContent = formatSelectedSlotsSummary();
        summaryPayment.textContent = 'QR Ph';

        const equipText = equipmentSummaryText();
        if (equipText) {
            summaryEquipmentRow.hidden = false;
            summaryEquipment.textContent = equipText;
        } else {
            summaryEquipmentRow.hidden = true;
        }

        const total = selectedSlotsTotal() + equipmentTotal();
        summaryTotal.textContent = `₱${total.toLocaleString()}`;
        updateSelectionCards();
        // Credit panel: recalculate after the base total is known so the
        // summary and breakdown stay in sync.
        updateCreditSummary();
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
        return missing;
    }

    function formatList(items) {
        if (items.length === 1) return items[0];
        if (items.length === 2) return `${items[0]} and ${items[1]}`;
        return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
    }

    // ---------- Confirm ----------

    confirmBtn.addEventListener('click', async () => {
        if (paymentSubmissionLocked) return;
        const missing = getMissingPaymentRequirements();
        if (missing.length > 0) {
            showToast(`Please provide ${formatList(missing)} to continue.`, 'error');
            contactNumberInput.classList.toggle('field-invalid', !contactNumberInput.value.trim());
            return;
        }
        if (!selectedCourt || !selectedDate || selectedSlots.length < MIN_DURATION) return;

        updateBookingProgress(4);
        setPaymentSubmissionLocked(true);

        const equipmentPayload = Object.entries(equipmentSelection).map(([id, quantity]) => ({
            id: Number(id),
            quantity,
        }));

        let requestTimedOut = false;

    try {
        const controller = new AbortController();
        let res;
        let data;
        const timeoutId = window.setTimeout(() => {
            requestTimedOut = true;
            controller.abort();
        }, 30_000);

        try {
            res = await fetch(storeUrl, {
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
                    payment_method: 'qrph',
                    contact_number: contactNumberInput.value.trim(),
                    equipment: equipmentPayload,
                    // Server recomputes the actual split from the wallet —
                    // the browser only sends the user's intent toggle.
                    use_credit: HAS_CREDITS && useCreditEl?.checked ? 1 : 0,
                    // One token per checkout session. The server hashes it
                    // as credit_checkout_key so a double-click or network
                    // retry returns the existing booking without debiting
                    // credits a second time.
                    idempotency_token: getOrCreateIdempotencyToken(),
                }),
                signal: controller.signal,
            });

            data = await res.json().catch((err) => {
                if (requestTimedOut) throw err;
                return {};
            });
        } finally {
            window.clearTimeout(timeoutId);
        }

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

                    return;
                }

                showToast(data.message || 'That slot is no longer available.', 'error');

                selectedSlots = [];
        slotSessions.clear();
                activeSessionDate = null;

                closePaymentModal();
                const availability = await fetchAvailability(selectedDate);
                bookedRanges = availability.booked || [];
                timedClosureRanges = availability.closed_blocks || [];
                openPlayRanges = availability.open_play || [];
                openPlayEventsByDate[selectedDate] = openPlayRanges;
                renderTimeSlots();
                updateTimePickerFee();
                openTimePickerModal();

                return;
            }

            closePaymentModal();

            if (data.status === 'paid') {
                // Credits covered the full total — go straight to the
                // receipt page. The PaymentReference is already confirmed
                // server-side, so the observer has fired the receipt email.
                const receiptPath = safeRedirectPath(
                    receiptUrlTemplate.replace('__ID__', data.booking_id)
                );
                paymentRedirecting = true;
                window.location.href = receiptPath || safeRedirectPath(
                    waitingUrlTemplate.replace('__ID__', data.booking_id)
                );
            } else if (data.booking && data.booking.status === 'paid') {
                showToast('Payment already matched — you\'re all set!', 'success');
                finishBookingReset();
            } else {
                // Full-page redirect instead of opening a modal — see
                // User_UserController::waitingForPayment()'s docblock.
                const redirectPath = safeRedirectPath(
                    waitingUrlTemplate.replace('__ID__', data.booking_id)
                );
                paymentRedirecting = true;
                window.location.href = redirectPath;
            }
        } catch (err) {
            console.error(err);
            if (requestTimedOut) {
                showToast("We couldn't confirm your booking. Check My Bookings before trying again.", 'error');
            } else {
                showToast('Something went wrong. Please try again.', 'error');
            }
        } finally {
            if (!paymentRedirecting) setPaymentSubmissionLocked(false);
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
            <button type="button" class="toast-close btn-ghost-danger" aria-label="Dismiss">&times;</button>
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

    function validDeepLinkDate(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false;
        const [year, month, day] = value.split('-').map(Number);
        const parsed = new Date(year, month - 1, day);
        const normalized = `${parsed.getFullYear()}-${pad(parsed.getMonth() + 1)}-${pad(parsed.getDate())}`;
        return normalized === value && value >= todayStr();
    }

    function validDeepLinkTime(value) {
        if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value || '')) return false;
        return Number(value.slice(3, 5)) % Math.max(1, STEP_MINUTES) === 0;
    }

    function highlightDeepLinkedTime(date, time) {
        const rows = Array.from(timeSlotGrid.querySelectorAll('.time-slot-row[data-slot-date][data-slot-time]'))
            .filter((row) => row.dataset.slotDate === date);
        const exact = rows.find((row) => row.dataset.slotTime === time);
        const unavailableBecauseTaken = exact?.dataset.unavailableReason === 'taken';
        let target = exact && !exact.classList.contains('disabled') ? exact : null;

        if (!target && unavailableBecauseTaken) {
            const requestedMinutes = timeToMinutes(time);
            target = rows
                .filter((row) => !row.classList.contains('disabled'))
                .sort((left, right) => Math.abs(timeToMinutes(left.dataset.slotTime) - requestedMinutes)
                    - Math.abs(timeToMinutes(right.dataset.slotTime) - requestedMinutes))[0] || null;
            if (deepLinkNotice) {
                deepLinkNotice.textContent = 'That time was just taken. Here are the closest open times.';
                deepLinkNotice.hidden = false;
            }
        }

        if (!target) return;
        target.classList.add('lumi-time-slot-highlight');
        target.scrollIntoView?.({
            behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center',
        });
        window.setTimeout(() => target.classList.remove('lumi-time-slot-highlight'), 5000);
    }

    const returnParams = new URLSearchParams(window.location.search);
    const legacyOpenBooking = returnParams.get('openBooking') === '1';
    const requestedCourtId = grid.dataset.deepLinkCourt || returnParams.get('court_id') || '';
    const deepLinkDate = validDeepLinkDate(grid.dataset.deepLinkDate) ? grid.dataset.deepLinkDate : '';
    const deepLinkTime = deepLinkDate && validDeepLinkTime(grid.dataset.deepLinkTime)
        ? grid.dataset.deepLinkTime
        : '';
    if (legacyOpenBooking || deepLinkDate) {
        const courtCard = Array.from(grid.querySelectorAll('.court-card-clickable'))
            .find((card) => !requestedCourtId || card.dataset.id === requestedCourtId);
        if (courtCard) {
            selectedCourt = {
                id: courtCard.dataset.id,
                name: courtCard.dataset.name,
                type: courtCard.dataset.type,
                length: courtCard.dataset.length,
                width: courtCard.dataset.width,
                price: courtCard.dataset.price,
            };
            openBookingModal();
            if (deepLinkDate && deepLinkTime) {
                selectDate(deepLinkDate).then(() => highlightDeepLinkedTime(deepLinkDate, deepLinkTime));
            } else if (deepLinkDate) {
                const [year, month, day] = deepLinkDate.split('-').map(Number);
                selectedDate = deepLinkDate;
                calendarCursor = new Date(year, month - 1, day);
                renderCalendar();
            } else if (legacyOpenBooking) {
                window.history.replaceState({}, '', window.location.pathname);
            }
        }
    }
});
