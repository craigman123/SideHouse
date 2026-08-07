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

    // ---------- FAQ accordion ----------
    // Runs independently of the booking widget too — allow only one
    // answer open at a time within its own .faq-list.
    document.querySelectorAll('.faq-question').forEach((btn) => {
        btn.addEventListener('click', () => {
            const isOpen = btn.getAttribute('aria-expanded') === 'true';
            const list = btn.closest('.faq-list');

            if (list) {
                list.querySelectorAll('.faq-question[aria-expanded="true"]').forEach((other) => {
                    if (other !== btn) other.setAttribute('aria-expanded', 'false');
                });
            }

            btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });

    // ---------- Court usage statistics (bar chart) ----------
    // Independent of the booking widget — runs as long as #courtStats
    // is on the page, fetching from its own data-stats-url.
    (function initCourtStats() {
        const statsSection = document.getElementById('courtStats');
        if (!statsSection) return;

        const statsUrl = statsSection.dataset.statsUrl;
        const monthLabel = document.getElementById('statsMonthLabel');
        const totalHoursEl = document.getElementById('statsTotalHours');
        const busiestDayEl = document.getElementById('statsBusiestDay');
        const avgHoursEl = document.getElementById('statsAvgHours');
        const chartEl = document.getElementById('statsChart');
        const monthTag = document.getElementById('statsChartMonthLabel');
        const yAxisEl = document.getElementById('statsYAxisNumbers');
        const chartScroll = chartEl ? chartEl.closest('.stats-chart-scroll') : null;

        function updateScrollFade() {
            if (!chartScroll) return;
            const atEnd = chartScroll.scrollWidth - chartScroll.scrollLeft - chartScroll.clientWidth < 4;
            chartScroll.classList.toggle('at-end', atEnd);
        }

        if (chartScroll) {
            chartScroll.addEventListener('scroll', updateScrollFade, { passive: true });
            window.addEventListener('resize', updateScrollFade);
        }

        function formatHours(n) {
            const rounded = Math.round(n * 10) / 10;
            return rounded % 1 === 0 ? `${rounded}` : rounded.toFixed(1);
        }

        // Picks a "nice" axis top and step (1, 2, 5, 10, 20, 50...) for the
        // y-axis, the same way most charting libraries round the top of the
        // scale instead of using the raw max value as the last tick.
        function computeNiceScale(maxValue) {
            const target = Math.max(maxValue, 1);
            const rawStep = target / 5;
            const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
            const residual = rawStep / magnitude;

            let step;
            if (residual > 5) step = 10 * magnitude;
            else if (residual > 2) step = 5 * magnitude;
            else if (residual > 1) step = 2 * magnitude;
            else step = magnitude;
            step = Math.max(1, Math.round(step));

            const axisMax = Math.ceil(target / step) * step;
            const ticks = [];
            for (let v = 0; v <= axisMax; v += step) ticks.push(v);

            return { axisMax, ticks };
        }

        // Numbers on the left ("Number of Bookings" axis) — positioned by
        // % so each one lines up with its matching gridline in the chart.
        function renderYAxis(axisMax, ticks) {
            if (!yAxisEl) return;
            yAxisEl.innerHTML = '';
            ticks.forEach((tick) => {
                const el = document.createElement('span');
                el.className = 'stats-yaxis-tick';
                el.style.bottom = `${(tick / axisMax) * 100}%`;
                el.textContent = tick;
                yAxisEl.appendChild(el);
            });
        }

        // Dashed horizontal guide lines behind the bars, one per tick.
        function renderGridlines(axisMax, ticks) {
            ticks.forEach((tick) => {
                const line = document.createElement('div');
                line.className = 'stats-gridline';
                line.style.bottom = `${(tick / axisMax) * 100}%`;
                chartEl.appendChild(line);
            });
        }

        async function loadStats() {
            if (!statsUrl) {
                chartEl.innerHTML = '<p class="loading-text">Stats endpoint not configured.</p>';
                return;
            }

            try {
                const res = await fetch(statsUrl, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('Failed to load stats');
                const data = await res.json();
                renderStats(data);
            } catch (err) {
                console.error(err);
                chartEl.innerHTML = '<p class="loading-text">Couldn\'t load usage stats right now.</p>';
                monthLabel.textContent = '';
            }
        }

        function renderStats(data) {
            const days = data.days || []; // [{ day, date, hours }]
            monthLabel.textContent = data.month_label || '';

            if (days.length === 0) {
                chartEl.innerHTML = '<p class="loading-text">No bookings yet this month.</p>';
                if (monthTag) monthTag.textContent = data.month_label || '';
                totalHoursEl.textContent = '0';
                busiestDayEl.textContent = '—';
                avgHoursEl.textContent = '0';
                return;
            }

            const totalHours = days.reduce((sum, d) => sum + (d.hours || 0), 0);
            const maxBookings = Math.max(...days.map((d) => d.bookings || 0), 1);
            const busiest = days.reduce((a, b) => ((b.hours || 0) > (a.hours || 0) ? b : a), days[0]);
            const todayStr = new Date().toISOString().slice(0, 10);

            totalHoursEl.textContent = formatHours(totalHours);
            avgHoursEl.textContent = formatHours(totalHours / days.length);
            busiestDayEl.textContent = (busiest.hours || 0) > 0
                ? new Date(`${busiest.date}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                : '—';

            chartEl.innerHTML = '';

            // Title above the chart.
            if (monthTag) monthTag.textContent = data.month_label || '';

            // "Number of Bookings" axis on the left, sharing its scale
            // with the dashed gridlines drawn behind the bars.
            const { axisMax, ticks } = computeNiceScale(maxBookings);
            renderYAxis(axisMax, ticks);
            renderGridlines(axisMax, ticks);

            days.forEach((d) => {
                const hours = d.hours || 0;
                const bookingsCount = d.bookings || 0;
                const isToday = d.date === todayStr;

                const col = document.createElement('div');
                col.className = 'stats-bar-col' + (isToday ? ' stats-bar-today' : '');

                const track = document.createElement('div');
                track.className = 'stats-bar-track';

                const bar = document.createElement('div');
                bar.className = 'stats-bar';
                bar.style.height = `${bookingsCount > 0 ? Math.max((bookingsCount / axisMax) * 100, 3) : 0}%`;
                bar.title = `${new Date(`${d.date}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}: ${bookingsCount} booking${bookingsCount === 1 ? '' : 's'} · ${formatHours(hours)} hr${hours === 1 ? '' : 's'} booked`;

                track.appendChild(bar);
                col.appendChild(track);

                // Date of the month — the x-axis label below each bar.
                const label = document.createElement('span');
                label.className = 'stats-bar-label';
                label.textContent = d.day;
                col.appendChild(label);

                chartEl.appendChild(col);
            });

            // Run after render so scrollWidth reflects the real bar count —
            // hides the fade immediately if everything already fits.
            requestAnimationFrame(updateScrollFade);
        }

        loadStats();
    })();

    const grid = document.getElementById('bookNow');
    if (!grid) return;

    const availabilityUrl = grid.dataset.availabilityUrl;
    const equipmentUrl = grid.dataset.equipmentUrl;
    const storeUrl = grid.dataset.storeUrl;
    const statusUrlTemplate = grid.dataset.statusUrlTemplate;

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
    const googleSignInBtnEl = document.getElementById('googleSignInBtn');
    const guestEmailConfirmed = document.getElementById('guestEmailConfirmed');
    const guestEmailConfirmedAddress = document.getElementById('guestEmailConfirmedAddress');
    const guestEmailChangeBtn = document.getElementById('guestEmailChange');
    const guestEmailLabel = document.getElementById('guestEmailLabel');
    const paymentGrid = document.getElementById('paymentGrid');
    const gcashQrPanel = document.getElementById('gcashQrPanel');
    const gcashRefInput = document.getElementById('gcashRefNumber');
    const gcashWaitModal = document.getElementById('gcashWaitModal');
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

    const PAYMENT_LABELS = { gcash: 'GCash' };

    let selectedCourt = null;
    let calendarCursor = new Date();
    let selectedDate = null;
    let selectedStart = null;
    let selectedDuration = null;
    let selectedPayment = null;
    let bookedRanges = [];
    let equipmentCatalog = []; // [{id, name, category, price, available}]
    let equipmentSelection = {}; // { [equipmentId]: quantity }
    let googleIdToken = null; // raw JWT from Google — sent as-is, verified server-side
    let googleEmail = ''; // decoded from the token, for display only (never trusted on its own)

    const pad = (n) => n.toString().padStart(2, '0');
    const todayStr = () => new Date().toISOString().slice(0, 10);

    // ---------- GCash payment confirmation polling ----------

    const GCASH_POLL_INTERVAL_MS = 4000;
    let gcashPollTimer = null;
    let gcashCountdownTimer = null;
    let gcashActiveCancelUrl = null;

    function stopGcashPolling() {
        if (gcashPollTimer) clearTimeout(gcashPollTimer);
        if (gcashCountdownTimer) clearInterval(gcashCountdownTimer);
        gcashPollTimer = null;
        gcashCountdownTimer = null;
    }

    function closeGcashWaitModal() {
        stopGcashPolling();
        gcashWaitModal.classList.remove('open');
    }

    function formatCountdown(msRemaining) {
        const totalSeconds = Math.max(0, Math.floor(msRemaining / 1000));
        const mins = Math.floor(totalSeconds / 60);
        const secs = totalSeconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    // Polls GET .../guest/bookings/{id}/status?token=... every few seconds.
    // Ends in one of three ways: GcashWebhookController confirms it
    // ('confirmed'), ExpireUnconfirmedGcashBookings times it out
    // ('cancelled'), or the guest gives up and cancels manually.
    function watchGcashPayment(bookingId, pollToken, expiresAtIso, amountLabel) {
        const statusUrl = statusUrlTemplate.replace('__ID__', bookingId) + `?token=${encodeURIComponent(pollToken)}`;
        const cancelUrl = statusUrlTemplate.replace('__ID__', bookingId).replace('/status', '/cancel') + `?token=${encodeURIComponent(pollToken)}`;
        gcashActiveCancelUrl = cancelUrl;
        const expiresAt = new Date(expiresAtIso).getTime();

        gcashWaitAmount.textContent = amountLabel;
        gcashWaitStatus.textContent = "We'll confirm automatically the moment GCash notifies us — usually within a minute or two.";
        gcashWaitModal.classList.add('open');

        gcashCountdownTimer = setInterval(() => {
            const remaining = expiresAt - Date.now();
            gcashWaitCountdown.textContent = formatCountdown(remaining);
            if (remaining <= 0) clearInterval(gcashCountdownTimer);
        }, 1000);
        gcashWaitCountdown.textContent = formatCountdown(expiresAt - Date.now());

        async function poll() {
            try {
                const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();

                    if (data.status === 'confirmed') {
                        stopGcashPolling();
                        gcashWaitStatus.textContent = 'Payment confirmed!';
                        setTimeout(() => {
                            closeGcashWaitModal();
                            showToast('Payment confirmed — see you on the court!', 'success');
                            finishBookingReset();
                        }, 900);
                        return;
                    }

                    if (data.status === 'cancelled') {
                        stopGcashPolling();
                        closeGcashWaitModal();
                        showToast("We didn't receive that payment in time, so the slot was released. Please rebook when you're ready to pay.", 'error');
                        finishBookingReset();
                        return;
                    }
                }
            } catch (err) {
                // Network hiccup — just try again on the next tick rather
                // than surfacing an error for a transient failure.
                console.error(err);
            }

            gcashPollTimer = setTimeout(poll, GCASH_POLL_INTERVAL_MS);
        }

        poll();
    }

    if (gcashWaitCancel) {
        gcashWaitCancel.addEventListener('click', async () => {
            const cancelUrl = gcashActiveCancelUrl;
            closeGcashWaitModal();
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
    }

    function finishBookingReset() {
        guestNameInput.value = '';
        guestContactInput.value = '';
        clearGoogleSignIn();
        equipmentSelection = {};
        if (gcashRefInput) gcashRefInput.value = '';
        resetBookingState();
        renderCalendar();
    }

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
        if (gcashQrPanel) gcashQrPanel.classList.remove('open');
        updateSummary();
        paymentModal.classList.add('open');
    }

    function closePaymentModal() {
        paymentModal.classList.remove('open');
    }

    // ---------- Google Sign-In (Google confirms the address, not a
    // typed-twice field or a self-serve yes/no) ----------

    function decodeJwtPayload(jwt) {
        try {
            const base64 = jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
            const json = decodeURIComponent(
                atob(base64)
                    .split('')
                    .map((c) => '%' + c.charCodeAt(0).toString(16).padStart(2, '0'))
                    .join('')
            );
            return JSON.parse(json);
        } catch (err) {
            return null;
        }
    }

    function showSignedInState(email) {
        guestEmailConfirmedAddress.textContent = email;
        guestEmailConfirmed.hidden = false;
        googleSignInBtnEl.hidden = true;
        if (guestEmailLabel) guestEmailLabel.hidden = true;
    }

    function showSignedOutState() {
        guestEmailConfirmed.hidden = true;
        googleSignInBtnEl.hidden = false;
        if (guestEmailLabel) guestEmailLabel.hidden = false;
    }

    function clearGoogleSignIn() {
        googleIdToken = null;
        googleEmail = '';
        showSignedOutState();
        if (window.google && window.google.accounts && window.google.accounts.id) {
            window.google.accounts.id.disableAutoSelect();
        }
    }

    function handleGoogleCredential(response) {
        const payload = decodeJwtPayload(response.credential);
        if (!payload || !payload.email) {
            showToast("Couldn't verify that Google account. Please try again.", 'error');
            return;
        }
        googleIdToken = response.credential;
        googleEmail = payload.email;
        showSignedInState(payload.email);
        const emailBlock = document.querySelector('.guest-email-block');
        if (emailBlock) emailBlock.classList.remove('guest-email-block-invalid');
    }

    (function initGoogleSignIn() {
        const clientId = grid.dataset.googleClientId;
        if (!clientId || !googleSignInBtnEl) return;

        function render() {
            window.google.accounts.id.initialize({
                client_id: clientId,
                callback: handleGoogleCredential,
                auto_select: false,
            });
            window.google.accounts.id.renderButton(googleSignInBtnEl, {
                type: 'standard',
                theme: 'filled_black',
                size: 'large',
                text: 'continue_with',
                shape: 'pill',
            });
        }

        if (window.google && window.google.accounts && window.google.accounts.id) {
            render();
        } else {
            // The GIS script tag is `defer`, so it may not have finished
            // loading yet — poll briefly instead of assuming order.
            let attempts = 0;
            const wait = setInterval(() => {
                attempts += 1;
                if (window.google && window.google.accounts && window.google.accounts.id) {
                    clearInterval(wait);
                    render();
                } else if (attempts > 40) {
                    clearInterval(wait);
                }
            }, 250);
        }
    })();

    guestEmailChangeBtn.addEventListener('click', clearGoogleSignIn);

    function resetBookingState() {
        selectedDate = null;
        selectedStart = null;
        selectedDuration = null;
        selectedPayment = null;
        bookedRanges = [];
        equipmentSelection = {};
        calendarCursor = new Date();
        durationSection.hidden = true;
        paymentGrid.querySelectorAll('.payment-btn').forEach((el) => el.classList.remove('selected'));
        if (gcashQrPanel) gcashQrPanel.classList.remove('open');
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

        if (gcashQrPanel) {
            gcashQrPanel.classList.toggle('open', selectedPayment === 'gcash');
        }

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
        if (!googleIdToken) missing.push('an email address (sign in with Google)');
        if (!selectedPayment) missing.push('a payment method');
        if (selectedPayment === 'gcash' && !(gcashRefInput && gcashRefInput.value.trim())) {
            missing.push('your GCash reference number');
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
        const missing = getMissingGuestRequirements();
        if (missing.length > 0) {
            showToast(`Please provide ${formatList(missing)} to continue.`, 'error');
            guestNameInput.classList.toggle('field-invalid', !guestNameInput.value.trim());
            guestContactInput.classList.toggle('field-invalid', !guestContactInput.value.trim());
            document.querySelector('.guest-email-block').classList.toggle('guest-email-block-invalid', !googleIdToken);
            document.querySelector('.gcash-proof-block')?.classList.toggle(
                'gcash-proof-invalid',
                selectedPayment === 'gcash' && !(gcashRefInput && gcashRefInput.value.trim())
            );
            return;
        }
        document.querySelector('.guest-email-block').classList.remove('guest-email-block-invalid');
        document.querySelector('.gcash-proof-block')?.classList.remove('gcash-proof-invalid');

        if (!selectedCourt || !selectedDate || !selectedStart || !selectedDuration) return;

        confirmBtn.disabled = true;
        const originalLabel = confirmBtn.textContent;
        confirmBtn.textContent = 'Booking…';

        const equipmentPayload = Object.entries(equipmentSelection).map(([id, quantity]) => ({
            id: Number(id),
            quantity,
        }));

        const formData = new FormData();
        formData.append('court_id', selectedCourt.id);
        formData.append('date', selectedDate);
        formData.append('start_time', selectedStart);
        formData.append('duration', selectedDuration);
        formData.append('payment_method', selectedPayment);
        formData.append('guest_name', guestNameInput.value.trim());
        formData.append('guest_contact', guestContactInput.value.trim());
        formData.append('google_id_token', googleIdToken);
        if (gcashRefInput && gcashRefInput.value.trim()) {
            formData.append('gcash_reference', gcashRefInput.value.trim());
        }
        equipmentPayload.forEach((item, i) => {
            formData.append(`equipment[${i}][id]`, item.id);
            formData.append(`equipment[${i}][quantity]`, item.quantity);
        });
        try {
            // No 'Content-Type' header here on purpose — the browser sets
            // multipart/form-data with the correct boundary itself. Setting
            // it manually breaks the upload.
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                body: formData,
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

            closePaymentModal();
            watchGcashPayment(data.booking_id, data.poll_token, data.expires_at, `₱${Number(data.amount).toFixed(2)}`);
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