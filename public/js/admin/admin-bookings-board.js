(() => {
    const workspace = document.getElementById('bookingsWorkspace');
    const dataElement = document.getElementById('courtBoardData');
    const board = document.getElementById('courtBoard');

    if (!workspace || !dataElement || !board) return;

    const data = JSON.parse(dataElement.textContent || '{}');
    let bookings  = data.bookings  || [];
    let closures  = data.closures  || [];
    let blocks      = Array.isArray(data.blocks) ? data.blocks : [];
    let closingCutoffs = data.closingCutoffs || [];
    const courts    = data.courts    || [];
    const bizSettings = data.businessSettings || {};
    // Open/close hours from BusinessSetting — fallback to sane defaults
    // so the grid is usable even on a fresh install with no row yet.
    const BIZ_OPEN_HOUR  = typeof bizSettings.open_hour  === 'number' ? bizSettings.open_hour  : 6;
    const BIZ_CLOSE_HOUR = typeof bizSettings.close_hour === 'number' ? bizSettings.close_hour : 22;
    // Weekday numbers (0=Sun … 6=Sat) that are always closed.
    const BIZ_CLOSED_WEEKDAYS = Array.isArray(bizSettings.closed_weekdays) ? bizSettings.closed_weekdays : [];
    const dateInput = document.getElementById('courtBoardDate');
    let venueToday = String(bizSettings.today || dateInput.value || '');
    let venueTimeBase = Date.parse(bizSettings.server_time || '');
    let venuePerfBase = performance.now();
    const updateVenueClock = (today, isoTime) => {
        const previousDate = venueToday;
        if (today) {
            venueToday = String(today);
            bizSettings.today = venueToday;
        }
        if (isoTime && Number.isFinite(Date.parse(isoTime))) {
            venueTimeBase = Date.parse(isoTime);
            venuePerfBase = performance.now();
            bizSettings.server_time = isoTime;
        }
        if (venueToday !== previousDate) {
            const minimumBoardDate = new Date(venueToday + 'T00:00:00');
            minimumBoardDate.setDate(minimumBoardDate.getDate() - 1);
            dateInput.min = formatDateInputValue(minimumBoardDate);
            const maximumBoardDate = new Date(venueToday + 'T00:00:00');
            maximumBoardDate.setMonth(maximumBoardDate.getMonth() + 6);
            dateInput.max = formatDateInputValue(maximumBoardDate);
            workspace.querySelectorAll('.court-block-modal input[type="date"]').forEach((input) => { input.min = venueToday; });
            if (typeof validateCalendarForm === 'function') calendarDateTimeForms?.forEach((form) => validateCalendarForm(form));
        }
    };
    const venueClockMinutes = () => {
        const instant = venueTimeBase + (performance.now() - venuePerfBase);
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).formatToParts(instant);
        return Number(parts.find((part) => part.type === 'hour')?.value || 0) * 60
            + Number(parts.find((part) => part.type === 'minute')?.value || 0);
    };
    const venueNowMinutes = () => Number.isFinite(venueTimeBase) ? venueClockMinutes() : 0;
    const isPastDate = (date) => String(date || '') < venueToday;
    const itemIsPast = (kind, item) => {
        const date = kind === 'block' ? item.block_date : item.date;
        if (isPastDate(date)) return true;
        if (date !== venueToday) return false;
        const start = toMinutes(normalizeTime(kind === 'block' ? item.start_time : item.start));
        let end = toMinutes(normalizeTime(kind === 'block' ? item.end_time : item.end));
        if (end <= start) end += 1440;
        return end <= venueNowMinutes();
    };
    const summary = document.getElementById('courtBoardSummary');
    const weekInput = document.getElementById('courtWeekInput');
    const weekSummary = document.getElementById('courtWeekSummary');
    const weekGrid = document.getElementById('courtWeekGrid');
    const weekHeading = document.getElementById('courtWeekRangeHeading');
    const tabs = workspace.querySelectorAll('[data-bookings-view]');
    const panels = {
        list: document.getElementById('bookingListView'),
        board: document.getElementById('courtBoardView'),
        month: document.getElementById('courtMonthView'),
        week: document.getElementById('courtWeekView'),
    };

    // ---------- Month view ----------
    const monthInput = document.getElementById('courtMonthInput');
    const monthSummary = document.getElementById('courtMonthSummary');
    const monthGrid = document.getElementById('courtMonthGrid');
    const calendarGrids = [monthGrid, weekGrid].filter(Boolean);
    let activeCalendarGrid = monthGrid;
    const formatDateInputValue = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const escapeHtml = (value) => { const el = document.createElement('span'); el.textContent = String(value ?? ''); return el.innerHTML; };
    const formatCurrency = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const startOfWeek = (dateValue) => {
        const date = new Date(`${dateValue}T00:00:00`);
        date.setDate(date.getDate() - date.getDay());
        return formatDateInputValue(date);
    };
    const isoDateIsValid = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false;
        const parsed = new Date(`${value}T00:00:00`);
        return Number.isFinite(parsed.getTime()) && formatDateInputValue(parsed) === value;
    };
    const requestedDate = new URLSearchParams(window.location.search).get('date');
    const initialWeekDate = isoDateIsValid(requestedDate) ? requestedDate
        : (isoDateIsValid(dateInput.value) ? dateInput.value : venueToday);
    let weekStart = startOfWeek(initialWeekDate);
    weekInput.value = initialWeekDate;
    const stepMinutes = [15, 30, 60].includes(Number(bizSettings.step_minutes))
        ? Number(bizSettings.step_minutes)
        : 60;
    const blockModal = document.getElementById('courtBlockModal');
    const blockForm = document.getElementById('courtBlockForm');
    const blockFormPanel = document.getElementById('courtBlockFormPanel');
    const blockDetailsPanel = document.getElementById('courtBlockDetailsPanel');
    const blockEditPanel = document.getElementById('courtBlockEditPanel');
    const bookingDetailsPanel = document.getElementById('courtBookingDetailsPanel');
    const bookingEditPanel = document.getElementById('courtBookingEditPanel');
    const blockCourtSelect = document.getElementById('courtBlockCourt');
    const blockFormMessage = document.getElementById('courtBlockFormMessage');
    const blockDeleteMessage = document.getElementById('courtBlockDeleteMessage');
    let selectedBlock = null;
    let selectedBooking = null;
    let savingBlock = false;
    const walkInFields = [...blockForm.querySelectorAll('.walk-in-field')];
    const blockTitleLabel = document.getElementById('courtBlockTitle').closest('label');
    const blockNotesLabel = blockForm.querySelector('[data-block-field]');
    const walkInType = document.getElementById('courtBlockType');
    const updateTypeFields = () => {
        const walkIn = walkInType.value === 'walk_in';
        walkInFields.forEach((field) => { field.hidden = !walkIn; });
        blockTitleLabel.hidden = walkIn;
        blockNotesLabel.hidden = walkIn;
        document.getElementById('courtWalkInNotes').closest('label').hidden = !walkIn;
        document.getElementById('courtBlockTitle').required = !walkIn;
        document.getElementById('courtWalkInCustomer').required = walkIn;
        document.getElementById('courtWalkInContact').required = walkIn;
        blockCourtSelect.querySelector('option[value="all"]')?.toggleAttribute('hidden', walkIn);
        if (walkIn && blockCourtSelect.value === 'all') blockCourtSelect.value = courts.length ? String(courts[0].id) : '';
        if (walkIn) updateWalkInAmount();
    };
    const updateWalkInAmount = () => {
        const court = courts.find((item) => String(item.id) === blockCourtSelect.value);
        const amountInput = document.getElementById('courtWalkInAmount');
        const startValue = document.getElementById('courtBlockStart').value;
        const endValue = document.getElementById('courtBlockEnd').value;
        if (!court || court.hourly_rate == null || !startValue || !endValue) {
            amountInput.value = '';
            amountInput.required = true;
            return;
        }
        const toMinutes = (value) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
        const start = toMinutes(startValue);
        let end = toMinutes(endValue);
        if (end <= start) end += 1440;
        const step = stepMinutes;
        if ((start % step) || (end % step) || end <= start) { amountInput.value = ''; amountInput.required = true; return; }
        let total = 0;
        for (let minute = start; minute < end; minute += step) {
            const hour = Math.floor((minute % 1440) / 60);
            const peakStart = Number(bizSettings.peak_start_hour);
            const peakEnd = Number(bizSettings.peak_end_hour);
            const configured = Number.isFinite(peakStart) && Number.isFinite(peakEnd)
                && peakStart !== peakEnd && Number(bizSettings.peak_adjustment_value) > 0;
            const peak = configured && (peakEnd > peakStart ? hour >= peakStart && hour < peakEnd : hour >= peakStart || hour < peakEnd);
            let rate = Number(court.hourly_rate);
            if (peak) rate = bizSettings.peak_adjustment_type === 'percent'
                ? Math.round(rate * (1 + Number(bizSettings.peak_adjustment_value) / 100) * 100) / 100
                : rate + Number(bizSettings.peak_adjustment_value);
            total += rate * step / 60;
        }
        amountInput.value = total.toFixed(2);
        amountInput.required = false;
    };

    const normalizeTime = (time) => String(time || '').slice(0, 5);
    const normalizeBlock = (block) => ({
        ...block,
        block_date: String(block.block_date || '').slice(0, 10),
        start_time: normalizeTime(block.start_time),
        end_time: normalizeTime(block.end_time),
    });
    const rangeCache = [];
    const protectedItems = new Set();
    const moveQueues = new Map();
    const moveSequences = new Map();
    const etags = new Map();
    let pollTimer = null;
    let pollDelay = 45000;
    let authReloadPrompted = false;
    let activeDragKey = null;
    let syncIndicator = null;
    let calendarDataDirty = false;
    let affectedCalendarDates = null;
    const dateInRange = (date, from, to) => date >= from && date <= to;
    const rangeIsCached = (from, to) => rangeCache.some((range) => range.from <= from && range.to >= to);
    const currentVisibleRange = () => {
        if (panels.week && !panels.week.hidden) return { from: weekStart, to: addDays(weekStart, 6) };
        if (panels.month && !panels.month.hidden) {
            const [year, month] = monthInput.value.split('-').map(Number);
            return { from: `${year}-${String(month).padStart(2, '0')}-01`, to: `${year}-${String(month).padStart(2, '0')}-${String(new Date(year, month, 0).getDate()).padStart(2, '0')}` };
        }
        return { from: dateInput.value, to: dateInput.value };
    };
    const setSyncState = (message = '') => {
        if (!syncIndicator) {
            syncIndicator = document.createElement('span');
            syncIndicator.className = 'court-board-sync-status';
            syncIndicator.setAttribute('role', 'status');
            workspace.append(syncIndicator);
        }
        if (syncIndicator) {
            syncIndicator.textContent = message;
            syncIndicator.hidden = !message;
        }
    };
    const debugCalendarDuplicates = false;
    const renderCalendar = () => {
        data.bookings = bookings;
        data.blocks = blocks;
        data.closures = closures;
        data.closingCutoffs = closingCutoffs;
        const affectedDates = affectedCalendarDates;
        affectedCalendarDates = null;
        if (panels.month && !panels.month.hidden) renderMonth(affectedDates);
        if (panels.week && !panels.week.hidden) renderWeek(affectedDates);
        if (panels.board && !panels.board.hidden) renderBoard();
        calendarDataDirty = false;
        if (debugCalendarDuplicates) {
            const visibleGrid = activeCalendarGrid;
            visibleGrid?.querySelectorAll('.court-month-date-col').forEach((column) => {
                const seen = new Set();
                column.querySelectorAll('[data-calendar-kind][data-calendar-id]').forEach((bar) => {
                    const key = `${bar.dataset.calendarKind}:${String(bar.dataset.calendarId)}`;
                    if (seen.has(key)) console.warn('[board] duplicate bar', key);
                    seen.add(key);
                });
            });
        }
    };
    const mergeCalendarRange = (incoming, from, to) => {
        const before = [
            ['booking', bookings, (item) => item.id, (item) => item.date],
            ['block', blocks, (item) => item.id, (item) => item.block_date],
            ['closure', closures, (item) => `${item.court_id ?? 'all'}:${item.date}`, (item) => item.date],
            ['cutoff', closingCutoffs, (item) => item.date, (item) => item.date],
        ].map(([kind, items, keyOf, dateOf]) => [kind, new Map(items.map((item) => [String(keyOf(item)), { json: JSON.stringify(item), date: dateOf(item) }]))]);
        const isProtected = (id) => protectedItems.has(String(id))
            || protectedItems.has(`booking:${id}`) || protectedItems.has(`block:${id}`)
            || activeDragKey === String(id)
            || moveQueues.has(`booking:${id}`) || moveQueues.has(`block:${id}`);
        const mergeItems = (kind, current, fresh, keyOf, dateOf, rangeFrom = from) => {
            const freshById = new Map(fresh.map((item) => [String(keyOf(item)), item]));
            current.forEach((item) => {
                const key = String(keyOf(item));
                const queue = moveQueues.get(`${kind}:${key}`);
                if (queue && isProtected(key)) {
                    queue.deferredServerItem = freshById.get(key) || null;
                    queue.deferredRange = { from: rangeFrom, to };
                }
            });
            const merged = current.filter((item) => {
                const key = String(keyOf(item));
                if (isProtected(key)) return true;
                return !dateInRange(dateOf(item), rangeFrom, to) || freshById.has(key);
            }).map((item) => {
                const key = String(keyOf(item));
                if (isProtected(key)) return item;
                return freshById.get(key) || item;
            });
            const existing = new Set(merged.map((item) => String(keyOf(item))));
            fresh.forEach((item) => { if (!existing.has(String(keyOf(item)))) merged.push(item); });
            return merged;
        };
        const overlapFrom = addDays(from, -1);
        bookings = mergeItems('booking', bookings, incoming.bookings || [], (item) => item.id, (item) => item.date, overlapFrom);
        blocks = mergeItems('block', blocks, (incoming.blocks || []).map(normalizeBlock), (item) => item.id, (item) => item.block_date, overlapFrom);
        closures = mergeItems('closure', closures, incoming.closures || [], (item) => `${item.court_id ?? 'all'}:${item.date}`, (item) => item.date);
        closingCutoffs = mergeItems('cutoff', closingCutoffs, incoming.closingCutoffs || [], (item) => item.date, (item) => item.date);
        data.bookings = bookings;
        data.blocks = blocks;
        data.closures = closures;
        data.closingCutoffs = closingCutoffs;
        const changedDates = new Set();
        const after = [
            ['booking', bookings, (item) => item.id, (item) => item.date],
            ['block', blocks, (item) => item.id, (item) => item.block_date],
            ['closure', closures, (item) => `${item.court_id ?? 'all'}:${item.date}`, (item) => item.date],
            ['cutoff', closingCutoffs, (item) => item.date, (item) => item.date],
        ];
        after.forEach(([kind, items, keyOf, dateOf]) => {
            const old = before.find(([oldKind]) => oldKind === kind)[1];
            const fresh = new Map(items.map((item) => [String(keyOf(item)), { json: JSON.stringify(item), date: dateOf(item) }]));
            for (const [id, prior] of old) if (!fresh.has(id) || fresh.get(id).json !== prior.json) changedDates.add(prior.date);
            for (const [id, current] of fresh) if (!old.has(id) || old.get(id).json !== current.json) changedDates.add(current.date);
        });
        return [...changedDates].filter(Boolean);
    };
    const loadCalendarRange = async (from, to, { force = false } = {}) => {
        if (!force && rangeIsCached(from, to)) return true;
        const key = `${from}:${to}`;
        const headers = { Accept: 'application/json' };
        if (etags.has(key)) headers['If-None-Match'] = etags.get(key);
        try {
            const response = await fetch(`/admin/bookings/calendar?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, {
                credentials: 'same-origin', headers,
            });
            if (response.status === 304) {
                updateVenueClock(response.headers.get('X-Venue-Date'), response.headers.get('X-Venue-Time'));
                pollDelay = 45000;
                setSyncState('');
                return true;
            }
            if ((response.status === 419 || response.status === 401
                || (response.redirected && /mfa\.(challenge|setup)/.test(response.url))) && !authReloadPrompted) {
                authReloadPrompted = true;
                const reload = window.confirm('Your admin session needs attention. Reload the page now?');
                if (reload) window.location.reload();
                setSyncState('Session expired · reload to sync');
                return false;
            }
            if (!response.ok) {
                throw new Error(`Calendar sync failed (${response.status})`);
            }
            const incoming = await response.json();
            updateVenueClock(incoming.businessSettings?.today || response.headers.get('X-Venue-Date'),
                incoming.businessSettings?.server_time || response.headers.get('X-Venue-Time') || incoming.server_time);
            const changedDates = mergeCalendarRange(incoming, from, to);
            affectedCalendarDates = changedDates.length ? changedDates : null;
            const etag = response.headers.get('ETag');
            if (etag) etags.set(key, etag);
            rangeCache.push({ from, to });
            pollDelay = 45000;
            setSyncState('');
            if (calendarDrag || !blockModal.hidden) calendarDataDirty = true;
            return true;
        } catch (error) {
            if (authReloadPrompted) {
                setSyncState('Session expired · reload to sync');
                return false;
            }
            pollDelay = Math.min(Math.max(pollDelay * 2, 45000), 300000);
            setSyncState('Sync paused');
            return false;
        }
    };
    const notify = (message, type = 'success') => {
        if (typeof window.showToast === 'function') window.showToast(message, type);
    };
    const confirmDragPrice = (quote, booking) => new Promise((resolve) => {
        const wrap = document.createElement('div');
        wrap.className = 'court-price-confirm-backdrop';
        wrap.innerHTML = `<section class="court-price-confirm" role="dialog" aria-modal="true" aria-labelledby="courtPriceConfirmTitle">
            <h2 id="courtPriceConfirmTitle">Confirm booking price</h2>
            <p>${escapeHtml(booking.customer || 'Booking')} · ${escapeHtml(quote.date)} · ${escapeHtml(quote.start)}–${escapeHtml(quote.end)}</p>
            <p>Current amount: <strong>${escapeHtml(formatCurrency(quote.old_amount))}</strong></p>
            <p>New amount: <strong>${escapeHtml(formatCurrency(quote.new_amount))}</strong></p>
            <label class="court-price-confirm-choice"><input type="radio" name="drag-price-choice" value="original" checked> Keep original amount</label>
            <label class="court-price-confirm-choice"><input type="radio" name="drag-price-choice" value="new"> Use new amount</label>
            <label class="court-price-confirm-ack"><input type="checkbox"> I acknowledge this price change</label>
            <div class="court-price-confirm-actions"><button type="button" data-price-cancel>Cancel</button><button type="button" data-price-confirm disabled>Confirm move</button></div>
        </section>`;
        document.body.append(wrap);
        const ack = wrap.querySelector('.court-price-confirm-ack input');
        const confirm = wrap.querySelector('[data-price-confirm]');
        const finish = (value) => { wrap.remove(); document.removeEventListener('keydown', onKey); resolve(value); };
        const onKey = (event) => { if (event.key === 'Escape') { event.preventDefault(); finish(null); } };
        confirm.addEventListener('click', () => finish({
            amount: wrap.querySelector('input[name="drag-price-choice"]:checked').value === 'original'
                ? Number(quote.old_amount) : Number(quote.new_amount),
        }));
        ack.addEventListener('change', () => { confirm.disabled = !ack.checked; });
        wrap.querySelector('[data-price-cancel]').addEventListener('click', () => finish(null));
        wrap.addEventListener('click', (event) => { if (event.target === wrap) finish(null); });
        document.addEventListener('keydown', onKey);
        confirm.focus();
    });
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const itemPosition = (kind, item) => ({
        date: kind === 'block' ? item.block_date : item.date,
        start: normalizeTime(kind === 'block' ? item.start_time : item.start),
        end: normalizeTime(kind === 'block' ? item.end_time : item.end),
        court_id: item.court_id ?? null,
    });
    const samePosition = (a, b) => a && b && a.date === b.date && a.start === b.start && a.end === b.end
        && String(a.court_id ?? '') === String(b.court_id ?? '');
    const moveList = (kind) => kind === 'block' ? blocks : bookings;
    const movedBookingSlots = (date, start, end) => {
        const startMinutes = toMinutes(start);
        let endMinutes = toMinutes(end);
        if (endMinutes <= startMinutes) endMinutes += 1440;
        const count = Math.max(1, Math.ceil((endMinutes - startMinutes) / stepMinutes));
        return Array.from({ length: count }, (_, index) => {
            const slotStart = startMinutes + index * stepMinutes;
            const slotEnd = Math.min(endMinutes, slotStart + stepMinutes);
            return {
                date: addDays(date, Math.floor(slotStart / 1440)),
                start: formatTimeInput(slotStart),
                end: slotEnd >= 1440 ? '00:00' : formatTimeInput(slotEnd),
            };
        });
    };
    const moveItemExists = (queue) => moveList(queue.kind).some((item) =>
        String(item.id) === String(queue.id) && !(queue.kind === 'booking' && ['cancelled', 'expired'].includes(item.status))
    );
    const finishMoveQueue = (queue, applyDeferred = true, skipRender = false) => {
        if (moveQueues.get(queue.key) !== queue) return;
        moveQueues.delete(queue.key);
        protectedItems.delete(queue.key);
        const list = moveList(queue.kind);
        const index = list.findIndex((item) => String(item.id) === String(queue.id));
        let deferredChanged = false;
        if (applyDeferred && index >= 0 && queue.deferredServerItem !== undefined) {
            // A poll may have started before the save and finish afterward. Only
            // accept a deferred position when its version is newer than our response.
            if (queue.deferredServerItem?.updated_at
                && Date.parse(queue.deferredServerItem.updated_at) > Date.parse(queue.confirmed?.updated_at || '')) {
                list[index] = queue.kind === 'block' ? normalizeBlock(queue.deferredServerItem) : queue.deferredServerItem;
                deferredChanged = true;
            }
        }
        const remainingIndex = list.findIndex((item) => String(item.id) === String(queue.id));
        if (remainingIndex >= 0) delete list[remainingIndex]._saving;
        if (!skipRender || deferredChanged || calendarDataDirty) {
            affectedCalendarDates = queue.changedDates || null;
            renderCalendar();
        }
    };
    const pumpMoveQueue = async (queue) => {
        if (moveQueues.get(queue.key) !== queue || queue.inFlight || !queue.wanted) return;
        if (!moveItemExists(queue)) { finishMoveQueue(queue, false); return; }
        const token = csrfToken();
        if (!token) {
            const list = moveList(queue.kind), index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) list[index] = { ...queue.confirmed };
            finishMoveQueue(queue);
            notify('Your security token is missing. Reload the page and try again.', 'error');
            return;
        }
        const sent = { ...queue.wanted, position: { ...queue.wanted.position }, payload: { ...queue.wanted.payload } };
        queue.inFlight = sent;
        try {
            const url = `/admin/${queue.kind === 'block' ? 'blocks' : 'bookings'}/${encodeURIComponent(queue.id)}`;
            const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token };
            if (queue.kind === 'booking' && ['paid', 'confirmed'].includes(moveList(queue.kind).find((item) => String(item.id) === String(queue.id))?.status)) {
                const quoteResponse = await fetch(url, { method: 'PATCH', credentials: 'same-origin', headers,
                    body: JSON.stringify({ ...sent.payload, expected_updated_at: queue.version, dry_run: true }) });
                const quoteResult = await quoteResponse.json().catch(() => ({}));
                if (!quoteResponse.ok) throw new Error(quoteResult.message || 'Could not check the new booking price.');
                const quote = quoteResult.quote;
                if (quote?.amount_changed) {
                    const current = moveList(queue.kind).find((item) => String(item.id) === String(queue.id));
                    const choice = await confirmDragPrice(quote, current || {});
                    if (!choice) {
                        queue.inFlight = null;
                        if (queue.wanted.sequence !== sent.sequence) { void pumpMoveQueue(queue); return; }
                        const index = moveList(queue.kind).findIndex((item) => String(item.id) === String(queue.id));
                        if (index >= 0) moveList(queue.kind)[index] = { ...queue.confirmed };
                        finishMoveQueue(queue);
                        return;
                    }
                    if (moveQueues.get(queue.key) !== queue || !moveItemExists(queue)) { finishMoveQueue(queue, false); return; }
                    if (queue.wanted.sequence !== sent.sequence) { queue.inFlight = null; void pumpMoveQueue(queue); return; }
                    sent.payload.amount = choice.amount;
                    sent.payload.acknowledge_price_change = true;
                } else if (quote) sent.payload.amount = Number(quote.new_amount);
            }
            const response = await fetch(url, {
                method: 'PATCH', credentials: 'same-origin',
                headers,
                body: JSON.stringify({
                    ...sent.payload, expected_updated_at: queue.version,
                    client_token: { itemKey: queue.key, sequence: sent.sequence, targetPosition: sent.position },
                }),
            });
            const result = await response.json().catch(() => ({}));
            if (moveQueues.get(queue.key) !== queue || !moveItemExists(queue)) { finishMoveQueue(queue, false); return; }
            const serverItem = queue.kind === 'block' ? (result.block && normalizeBlock(result.block)) : result.booking;
            const latest = queue.wanted.sequence === sent.sequence && samePosition(queue.wanted.position, sent.position);
            if (response.ok) {
                if (!serverItem || !serverItem.updated_at) throw new Error('Updated item version missing from response.');
                queue.confirmed = serverItem;
                queue.version = serverItem.updated_at;
                queue.inFlight = null;
                if (latest) {
                    const list = moveList(queue.kind), index = list.findIndex((item) => String(item.id) === String(queue.id));
                    if (index >= 0) {
                        const shown = list[index];
                        list[index] = serverItem;
                        delete list[index]._saving;
                        if (samePosition(itemPosition(queue.kind, shown), itemPosition(queue.kind, serverItem))) {
                            const bar = activeCalendarGrid?.querySelector(`[data-calendar-kind="${queue.kind}"][data-calendar-id="${CSS.escape(String(queue.id))}"]`);
                            bar?.classList.remove('is-saving');
                            queue.changedDates = [];
                            finishMoveQueue(queue, true, true);
                        } else finishMoveQueue(queue);
                    } else finishMoveQueue(queue, false);
                    notify(result.message || 'Calendar item moved.', 'success');
                } else void pumpMoveQueue(queue);
                return;
            }
            if (response.status === 409 && serverItem) {
                queue.confirmed = serverItem;
                queue.version = serverItem.updated_at || result.updated_at || queue.version;
                queue.inFlight = null;
                if (queue.staleRetrySequence !== sent.sequence) {
                    queue.staleRetrySequence = sent.sequence;
                    void pumpMoveQueue(queue);
                    return;
                }
            } else if (!latest) {
                queue.inFlight = null;
                void pumpMoveQueue(queue);
                return;
            } else queue.inFlight = null;
            const failedWanted = queue.wanted;
            const list = moveList(queue.kind), index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) list[index] = { ...queue.confirmed };
            finishMoveQueue(queue);
            notify(result.message || 'Could not move this calendar item.', 'error');
        } catch (error) {
            if (moveQueues.get(queue.key) !== queue || !moveItemExists(queue)) { finishMoveQueue(queue, false); return; }
            const latest = queue.wanted.sequence === queue.inFlight?.sequence && samePosition(queue.wanted.position, queue.inFlight?.position);
            queue.inFlight = null;
            if (!latest) { void pumpMoveQueue(queue); return; }
            const list = moveList(queue.kind), index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) list[index] = { ...queue.confirmed };
            finishMoveQueue(queue);
            notify('Could not move this calendar item. Please try again.', 'error');
        }
    };
    const formatTimeInput = (minutes) => {
        const wrapped = ((minutes % 1440) + 1440) % 1440;
        return `${String(Math.floor(wrapped / 60)).padStart(2, '0')}:${String(wrapped % 60).padStart(2, '0')}`;
    };
    const addDays = (dateValue, count) => {
        const date = new Date(`${dateValue}T00:00:00`);
        date.setDate(date.getDate() + count);
        return toDateValue(date);
    };
    const overlaps = (aStart, aEnd, bStart, bEnd) => aStart < bEnd && aEnd > bStart;

    const populateCourtSelect = () => {
        blockCourtSelect.replaceChildren();
        courts.forEach((court) => {
            const option = document.createElement('option');
            option.value = String(court.id);
            option.textContent = court.name;
            blockCourtSelect.append(option);
        });
        const allCourts = document.createElement('option');
        allCourts.value = 'all';
        allCourts.textContent = 'All courts';
        blockCourtSelect.append(allCourts);
        updateTypeFields();
    };

    const closeBlockModal = (force = false) => {
        if (savingBlock && !force) return;
        blockModal.hidden = true;
        if (selectedBlock) protectedItems.delete(`block:${selectedBlock.id}`);
        if (selectedBooking) protectedItems.delete(`booking:${selectedBooking.id}`);
        selectedBlock = null;
        selectedBooking = null;
        document.getElementById('courtWalkInCustomer').value = '';
        document.getElementById('courtWalkInContact').value = '';
        document.getElementById('courtWalkInEmail').value = '';
        document.getElementById('courtWalkInNotes').value = '';
        if (calendarDataDirty && !calendarDrag && moveQueues.size === 0) renderCalendar();
    };

    const showModalPanel = (panel, titleId) => {
        [blockFormPanel, blockDetailsPanel, blockEditPanel, bookingDetailsPanel, bookingEditPanel]
            .forEach((item) => { item.hidden = item !== panel; });
        blockModal.classList.toggle('is-details', panel !== blockFormPanel);
        blockModal.querySelector('[role="dialog"]').setAttribute('aria-labelledby', titleId);
    };

    const fillCourtOptions = (select, includeAll = false) => {
        select.replaceChildren();
        courts.forEach((court) => {
            const option = document.createElement('option');
            option.value = String(court.id);
            option.textContent = court.name;
            select.append(option);
        });
        if (includeAll) {
            const option = document.createElement('option');
            option.value = 'all';
            option.textContent = 'All courts';
            select.append(option);
        }
    };

    const openCreateModal = (selection) => {
        if (isPastDate(selection.date)) return;
        selectedBlock = null;
        selectedBooking = null;
        populateCourtSelect();
        blockModal.classList.remove('is-details');
        blockModal.querySelector('[role="dialog"]').setAttribute('aria-labelledby', 'courtBlockModalTitle');
        blockForm.reset();
        updateTypeFields();
        blockFormPanel.hidden = false;
        blockDetailsPanel.hidden = true;
        blockEditPanel.hidden = true;
        bookingDetailsPanel.hidden = true;
        bookingEditPanel.hidden = true;
        blockFormMessage.textContent = '';
        blockFormMessage.classList.remove('is-error');
        document.getElementById('courtBlockDate').value = selection.date;
        document.getElementById('courtBlockStart').value = formatTimeInput(selection.startMinutes);
        document.getElementById('courtBlockEnd').value = formatTimeInput(selection.endMinutes);
        document.getElementById('courtBlockStart').step = stepMinutes * 60;
        document.getElementById('courtBlockEnd').step = stepMinutes * 60;
        blockCourtSelect.value = courts.length ? String(courts[0].id) : 'all';
        validateCalendarForm(blockForm);
        blockModal.hidden = false;
        document.getElementById('courtBlockType').focus();
    };

    const openBlockDetails = (block) => {
        block = blocks.find((item) => String(item.id) === String(block.id)) || block;
        if (selectedBooking) protectedItems.delete(`booking:${selectedBooking.id}`);
        protectedItems.add(`block:${block.id}`);
        selectedBlock = block;
        selectedBooking = null;
        showModalPanel(blockDetailsPanel, 'courtBlockDetailsTitle');
        blockDeleteMessage.textContent = '';
        blockDeleteMessage.classList.remove('is-error');
        document.getElementById('courtBlockDetailsTitle').textContent = block.title || 'Calendar event';
        const blockPast = itemIsPast('block', block);
        document.getElementById('courtBlockPastNote').hidden = !blockPast;
        document.getElementById('courtBlockEditOpen').disabled = false;
        const court = courts.find((item) => Number(item.id) === Number(block.court_id));
        const courtLabel = block.court_id === null ? 'All courts' : (court?.name || 'Court');
        document.getElementById('courtBlockDetailsMeta').textContent =
            `${block.type.replace('_', ' ')} · ${courtLabel} · ${formatDate(block.block_date)} · ${block.start_time}–${block.end_time}`;
        const notes = document.getElementById('courtBlockDetailsNotes');
        notes.textContent = block.notes || '';
        notes.hidden = !block.notes;
        blockModal.hidden = false;
        document.getElementById('courtBlockDetailsClose').focus();
    };

    const openBlockEdit = () => {
        if (!selectedBlock) return;
        selectedBlock = blocks.find((item) => String(item.id) === String(selectedBlock.id)) || selectedBlock;
        const form = document.getElementById('courtBlockEditForm');
        fillCourtOptions(form.elements.court_id, true);
        form.elements.type.value = selectedBlock.type;
        form.elements.court_id.value = selectedBlock.court_id === null ? 'all' : String(selectedBlock.court_id);
        form.elements.title.value = selectedBlock.title || '';
        form.elements.notes.value = selectedBlock.notes || '';
        form.elements.block_date.value = selectedBlock.block_date;
        form.elements.start_time.value = selectedBlock.start_time;
        form.elements.end_time.value = selectedBlock.end_time;
        const past = itemIsPast('block', selectedBlock);
        form.elements.block_date.disabled = past;
        form.elements.start_time.disabled = past;
        form.elements.end_time.disabled = past;
        form.elements.court_id.disabled = past;
        form.elements.type.disabled = past;
        form.elements.title.disabled = past;
        form.elements.notes.disabled = false;
        form.querySelector('[type="submit"]').disabled = past;
        form.querySelector('[data-past-edit-note]').hidden = !past;
        validateCalendarForm(form);
        document.getElementById('courtBlockEditMessage').textContent = '';
        showModalPanel(blockEditPanel, 'courtBlockEditTitle');
        form.elements.type.focus();
    };

    const openBookingDetails = (booking) => {
        booking = bookings.find((item) => String(item.id) === String(booking.id)) || booking;
        if (selectedBlock) protectedItems.delete(`block:${selectedBlock.id}`);
        protectedItems.add(`booking:${booking.id}`);
        selectedBooking = booking;
        selectedBlock = null;
        const panel = bookingDetailsPanel;
        document.getElementById('courtBookingDetailsTitle').textContent = booking.customer || 'Booking details';
        const bookingPast = itemIsPast('booking', booking);
        document.getElementById('courtBookingPastNote').hidden = !bookingPast;
        const courtLabel = courts.find((court) => Number(court.id) === Number(booking.court_id))?.name || booking.court || 'Court';
        document.getElementById('courtBookingDetailsMeta').textContent =
            `${booking.source === 'walk_in' ? 'Walk-in · ' : ''}${courtLabel} · ${formatDate(booking.date)} · ${booking.start}–${booking.end} · ${booking.status}`;
        const notes = document.getElementById('courtBookingDetailsNotes');
        notes.textContent = booking.notes || '';
        notes.hidden = !booking.notes;
        document.getElementById('courtBookingDetailsMessage').textContent = '';
        showModalPanel(panel, 'courtBookingDetailsTitle');
        blockModal.hidden = false;
        document.getElementById('courtBookingDetailsClose').focus();
    };

    const openBookingEdit = () => {
        if (!selectedBooking) return;
        const booking = bookings.find((item) => String(item.id) === String(selectedBooking.id)) || selectedBooking;
        selectedBooking = booking;
        const form = document.getElementById('courtBookingEditForm');
        fillCourtOptions(form.elements.court_id);
        form.elements.customer_name.value = booking.customer || '';
        form.elements.contact_number.value = booking.contact_number || '';
        form.elements.email.value = booking.email || '';
        form.elements.court_id.value = String(booking.court_id);
        form.elements.date.value = booking.date;
        form.elements.start_time.value = booking.start;
        form.elements.end_time.value = booking.end;
        durationControl.dataset.adjustMinutes = '0';
        bookingEditForm.elements.amount.dataset.manuallyChanged = '';
        durationControl.hidden = false;
        updateDurationFields(0);
        form.elements.amount.value = Number(booking.amount || 0).toFixed(2);
        form.elements.notes.value = booking.notes || '';
        const past = itemIsPast('booking', booking);
        ['court_id', 'date', 'amount'].forEach((name) => { form.elements[name].disabled = past; });
        bookingStartInput.disabled = past;
        bookingEndInput.disabled = past;
        durationControl.hidden = past;
        form.querySelector('[data-past-edit-note]').hidden = !past;
        form.querySelector('[type="submit"]').disabled = false;
        validateCalendarForm(form);
        form.elements.acknowledge_price_change.checked = false;
        form.querySelector('.court-booking-price-ack-field').hidden = !['paid', 'confirmed'].includes(booking.status);
        document.getElementById('courtBookingEditMessage').textContent = '';
        showModalPanel(bookingEditPanel, 'courtBookingEditTitle');
        form.elements.customer_name.focus();
    };

    document.getElementById('courtBlockModalClose').addEventListener('click', closeBlockModal);
    walkInType.addEventListener('change', updateTypeFields);
    [blockCourtSelect, document.getElementById('courtBlockStart'), document.getElementById('courtBlockEnd')]
        .forEach((input) => input.addEventListener('change', updateWalkInAmount));
    document.getElementById('courtBlockCancel').addEventListener('click', closeBlockModal);
    document.getElementById('courtBlockDetailsClose').addEventListener('click', closeBlockModal);
    document.getElementById('courtBlockEditOpen').addEventListener('click', openBlockEdit);
    document.getElementById('courtBlockEditCancel').addEventListener('click', () => selectedBlock && openBlockDetails(selectedBlock));
    document.getElementById('courtBookingDetailsClose').addEventListener('click', closeBlockModal);
    document.getElementById('courtBookingEditOpen').addEventListener('click', openBookingEdit);
    document.getElementById('courtBookingEditCancel').addEventListener('click', () => selectedBooking && openBookingDetails(selectedBooking));
    blockModal.addEventListener('click', (event) => {
        if (event.target === blockModal) closeBlockModal();
    });
    blockModal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeBlockModal();
    });

    blockForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!validateCalendarForm(blockForm)) return;
        if (savingBlock) return;

        const formData = new FormData(blockForm);
        const payload = Object.fromEntries(formData.entries());
        const isWalkIn = payload.type === 'walk_in';
        if (isWalkIn) {
            payload.date = payload.block_date;
            delete payload.block_date;
            payload.court_id = Number(payload.court_id);
            payload.notes = payload.walk_in_notes.trim() || null;
            delete payload.walk_in_notes;
            payload.amount = payload.amount === '' ? null : Number(payload.amount);
        } else {
            payload.court_id = payload.court_id === 'all' ? null : Number(payload.court_id);
            payload.notes = payload.notes.trim() || null;
        }
        const token = csrfToken();
        if (!token) {
            blockFormMessage.textContent = 'Your security token is missing. Reload the page and try again.';
            blockFormMessage.classList.add('is-error');
            return;
        }

        savingBlock = true;
        const saveButton = document.getElementById('courtBlockSave');
        saveButton.disabled = true;
        blockFormMessage.textContent = '';
        try {
            const response = await fetch(isWalkIn ? '/admin/bookings' : '/admin/blocks', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 419) {
                    blockFormMessage.textContent = 'Your session or security token expired. Refresh the page and try again.';
                    blockFormMessage.classList.add('is-error');
                    return;
                }
                const fieldErrors = Object.values(result.errors || {}).flat();
                const conflict = result.conflict;
                const conflictDetail = conflict
                    ? ` ${conflict.title || conflict.type}${conflict.start_time ? ` (${conflict.start_time}–${conflict.end_time})` : ''}.`
                    : '';
                blockFormMessage.textContent = `${result.message || 'Could not save this event.'}${conflictDetail}${fieldErrors.length ? ` ${fieldErrors.join(' ')}` : ''}`;
                blockFormMessage.classList.add('is-error');
                return;
            }

            if (result.block) {
                blocks.push(normalizeBlock(result.block));
                affectedCalendarDates = [result.block.block_date];
            }
            if (result.booking) {
                bookings.push(result.booking);
                affectedCalendarDates = [result.booking.date];
            }
            closeBlockModal(true);
            notify(result.message || (isWalkIn ? 'Walk-in booking created.' : 'Calendar event created.'), 'success');
            renderCalendar();
        } catch (error) {
            blockFormMessage.textContent = 'Something went wrong. Please try again.';
            blockFormMessage.classList.add('is-error');
        } finally {
            savingBlock = false;
            saveButton.disabled = false;
        }
    });

    const bookingEditForm = document.getElementById('courtBookingEditForm');
    const bookingStartInput = bookingEditForm.elements.start_time;
    const bookingEndInput = bookingEditForm.elements.end_time;
    bookingStartInput.type = 'text';
    bookingEndInput.type = 'text';
    [bookingStartInput, bookingEndInput].forEach((input) => { input.readOnly = true; input.setAttribute('aria-readonly', 'true'); });
    const durationControl = document.createElement('div');
    durationControl.className = 'court-booking-duration-control';
    durationControl.innerHTML = '<span>Adjust duration</span><div><button type="button" data-duration-adjust="-1" aria-label="Shorten by one booking step">−</button><strong data-duration-value></strong><button type="button" data-duration-adjust="1" aria-label="Extend by one booking step">+</button></div><small data-duration-preview></small><small data-duration-warning hidden></small>';
    bookingEndInput.closest('label').after(durationControl);
    const durationValue = durationControl.querySelector('[data-duration-value]');
    const durationPreview = durationControl.querySelector('[data-duration-preview]');
    const durationWarning = durationControl.querySelector('[data-duration-warning]');
    const updateDurationFields = (minutes) => {
        if (!selectedBooking) return;
        let oldDuration = toMinutes(selectedBooking.end) - toMinutes(selectedBooking.start);
        if (oldDuration <= 0) oldDuration += 1440;
        const duration = Math.max(stepMinutes, oldDuration + minutes);
        bookingStartInput.value = selectedBooking.start;
        bookingEndInput.value = formatTimeInput(toMinutes(selectedBooking.start) + duration);
        durationValue.textContent = `${duration / 60} hr`;
        durationControl.dataset.adjustMinutes = String(minutes);
        durationPreview.textContent = `New end ${bookingEndInput.value} · ${formatCurrency(bookingEditForm.elements.amount.value)}`;
        durationWarning.hidden = true;
        if (minutes > 0) {
            const proposedEnd = toMinutes(selectedBooking.start) + duration;
            const dateOffset = (slotDate) => Math.round((new Date(`${slotDate}T00:00:00`) - new Date(`${bookingEditForm.elements.date.value}T00:00:00`)) / 86400000) * 1440;
            const hasOverlap = bookings.some((other) => String(other.id) !== String(selectedBooking.id)
                && Number(other.court_id) === Number(bookingEditForm.elements.court_id.value)
                && (other.slots || []).some((slot) => {
                    const offset = dateOffset(slot.date || other.date);
                    const slotStart = offset + toMinutes(slot.start);
                    const slotEnd = offset + (toMinutes(slot.end) || 1440);
                    return slotStart < proposedEnd && slotEnd > toMinutes(selectedBooking.end);
                }));
            if (hasOverlap) {
                durationWarning.textContent = 'This extension overlaps another booking. The overlap will be logged for review.';
                durationWarning.hidden = false;
            }
        }
    };
    durationControl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-duration-adjust]');
        if (!button || !selectedBooking || itemIsPast('booking', selectedBooking)) return;
        updateDurationFields(Number(durationControl.dataset.adjustMinutes || 0) + Number(button.dataset.durationAdjust) * stepMinutes);
        refreshEditQuote();
    });
    let editQuoteTimer;
    const refreshEditQuote = () => {
        clearTimeout(editQuoteTimer);
        if (!selectedBooking || itemIsPast('booking', selectedBooking)) return;
        const token = csrfToken();
        if (!token) return;
        editQuoteTimer = setTimeout(async () => {
            const selectedId = String(selectedBooking.id);
            try {
                const response = await fetch(`/admin/bookings/${encodeURIComponent(selectedId)}`, {
                    method: 'PATCH', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ date: bookingEditForm.elements.date.value, court_id: Number(bookingEditForm.elements.court_id.value),
                        adjust_minutes: Number(durationControl.dataset.adjustMinutes || 0),
                        dry_run: true, expected_updated_at: selectedBooking.updated_at }),
                });
                const result = await response.json().catch(() => ({}));
                if (String(selectedBooking?.id) !== selectedId) return;
                if (response.ok && result.quote) {
                    durationPreview.textContent = `New end ${result.quote.end} · ${formatCurrency(result.quote.new_amount)}`;
                    if (!bookingEditForm.elements.amount.dataset.manuallyChanged && result.quote.amount_changed) {
                        bookingEditForm.elements.amount.value = Number(result.quote.new_amount).toFixed(2);
                        updatePriceAcknowledgementVisibility();
                    }
                } else if (!response.ok) {
                    durationWarning.textContent = result.message || 'This duration is outside the available hours.';
                    durationWarning.hidden = false;
                }
            } catch (_) { /* the save still receives server validation */ }
        }, 250);
    };
    bookingEditForm.elements.amount.addEventListener('input', () => {
        bookingEditForm.elements.amount.dataset.manuallyChanged = '1';
        durationPreview.textContent = `New end ${bookingEndInput.value} · ${formatCurrency(bookingEditForm.elements.amount.value)}`;
    });
    [bookingEditForm.elements.date, bookingEditForm.elements.court_id].forEach((field) => field.addEventListener('change', refreshEditQuote));
    const bookingEditMessage = document.getElementById('courtBookingEditMessage');
    const calendarDateTimeForms = [blockForm, bookingEditForm, document.getElementById('courtBlockEditForm')];
    const calendarFormDateErrors = new WeakMap();
    const calendarFormTimeErrors = new WeakMap();
    const setInlineFieldError = (input, errors, message) => {
        let error = errors.get(input);
        if (!error) {
            error = document.createElement('small');
            error.className = 'calendar-field-error';
            error.setAttribute('role', 'alert');
            input.insertAdjacentElement('afterend', error);
            errors.set(input, error);
        }
        error.textContent = message;
        error.hidden = !message;
    };
    const validateCalendarForm = (form) => {
        if (!form) return true;
        const dateField = form.elements.date || form.elements.block_date;
        const startField = form.elements.start_time;
        let valid = true;
        if (dateField) {
            dateField.min = venueToday;
            if (!dateField.dataset.lastValid) dateField.dataset.lastValid = dateField.value || venueToday;
            if (!dateField.disabled && dateField.value && dateField.value < venueToday) {
                setInlineFieldError(dateField, calendarFormDateErrors, 'Pick today or a later date');
                dateField.value = dateField.dataset.lastValid >= venueToday ? dateField.dataset.lastValid : venueToday;
                valid = false;
            } else {
                if (dateField.value && dateField.value >= venueToday) dateField.dataset.lastValid = dateField.value;
                setInlineFieldError(dateField, calendarFormDateErrors, '');
            }
        }
        if (startField && dateField && !startField.disabled) {
            const walkIn = form === blockForm && walkInType.value === 'walk_in';
            const startMinutes = startField.value ? toMinutes(startField.value) : null;
            let scheduleChanged = true;
            if (form === bookingEditForm && selectedBooking) {
                scheduleChanged = dateField.value !== selectedBooking.date
                    || startField.value !== selectedBooking.start
                    || form.elements.end_time.value !== selectedBooking.end
                    || Number(form.elements.court_id.value) !== Number(selectedBooking.court_id);
            } else if (form === document.getElementById('courtBlockEditForm') && selectedBlock) {
                scheduleChanged = dateField.value !== selectedBlock.block_date
                    || startField.value !== selectedBlock.start_time
                    || form.elements.end_time.value !== selectedBlock.end_time
                    || String(form.elements.court_id.value) !== String(selectedBlock.court_id ?? 'all');
            }
            if (!walkIn && scheduleChanged && dateField.value === venueToday
                && startMinutes !== null && startMinutes < venueNowMinutes()) {
                setInlineFieldError(startField, calendarFormTimeErrors, 'Choose a start time that has not passed in Asia/Manila.');
                valid = false;
            } else setInlineFieldError(startField, calendarFormTimeErrors, '');
        } else if (startField) setInlineFieldError(startField, calendarFormTimeErrors, '');
        const pastBlock = form === document.getElementById('courtBlockEditForm') && selectedBlock && itemIsPast('block', selectedBlock);
        const pastBooking = form === bookingEditForm && selectedBooking && itemIsPast('booking', selectedBooking);
        const pastBookingHasAllowedChange = pastBooking && (
            form.elements.customer_name.value !== (selectedBooking.customer || '')
            || form.elements.contact_number.value !== (selectedBooking.contact_number || '')
            || form.elements.email.value !== (selectedBooking.email || '')
            || form.elements.notes.value !== (selectedBooking.notes || '')
        );
        const save = form.querySelector('[type="submit"]');
        if (save) save.disabled = !valid
            || Boolean(pastBlock && form.elements.notes.value === (selectedBlock.notes || ''))
            || Boolean(pastBooking && !pastBookingHasAllowedChange);
        return valid;
    };
    calendarDateTimeForms.forEach((form) => {
        const dateField = form.elements.date || form.elements.block_date;
        const startField = form.elements.start_time;
        if (dateField) {
            dateField.min = venueToday;
            dateField.dataset.lastValid = dateField.value || venueToday;
            dateField.addEventListener('change', () => validateCalendarForm(form));
            dateField.addEventListener('input', () => validateCalendarForm(form));
        }
        if (startField) {
            startField.addEventListener('change', () => validateCalendarForm(form));
            startField.addEventListener('input', () => validateCalendarForm(form));
        }
        const notes = form.elements.notes;
        if (notes) notes.addEventListener('input', () => validateCalendarForm(form));
        if (form === bookingEditForm) ['customer_name', 'contact_number', 'email'].forEach((name) =>
            form.elements[name].addEventListener('input', () => validateCalendarForm(form))
        );
    });
    walkInType.addEventListener('change', () => validateCalendarForm(blockForm));
    const updatePriceAcknowledgementVisibility = () => {
        const amountChanged = Number(bookingEditForm.elements.amount.value) !== Number(selectedBooking?.amount || 0);
        const acknowledgement = bookingEditForm.elements.acknowledge_price_change;
        const eligible = ['paid', 'confirmed'].includes(selectedBooking?.status);
        bookingEditForm.querySelector('.court-booking-price-ack-field').hidden = !eligible || !amountChanged;
        acknowledgement.required = false;
        if (!amountChanged) acknowledgement.checked = false;
    };
    bookingEditForm.elements.amount.addEventListener('input', updatePriceAcknowledgementVisibility);
    bookingEditForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!validateCalendarForm(bookingEditForm)) return;
        if (!selectedBooking || savingBlock) return;
        const token = csrfToken();
        if (!token) {
            bookingEditMessage.textContent = 'Your security token is missing. Reload the page and try again.';
            bookingEditMessage.classList.add('is-error');
            return;
        }
        const payload = Object.fromEntries(new FormData(bookingEditForm).entries());
        const targetStart = bookingStartInput.value;
        const targetEnd = bookingEndInput.value;
        const previousDate = selectedBooking.date;
        const originalBooking = { ...selectedBooking };
        const originalIndex = bookings.findIndex((booking) => String(booking.id) === String(selectedBooking.id));
        if (payload.court_id !== undefined) payload.court_id = Number(payload.court_id);
        payload.adjust_minutes = Number(durationControl.dataset.adjustMinutes || 0);
        if (payload.amount !== undefined) payload.amount = Number(payload.amount);
        const priceNeedsAcknowledgement = ['paid', 'confirmed'].includes(selectedBooking.status)
            && payload.amount !== undefined
            && Math.abs(payload.amount - Number(selectedBooking.amount || 0)) >= 0.005
            && !payload.acknowledge_price_change;
        if (payload.amount !== undefined && Math.abs(payload.amount - Number(selectedBooking.amount || 0)) < 0.005) delete payload.amount;
        payload.email = payload.email.trim() || null;
        payload.notes = payload.notes.trim() || null;
        payload.acknowledge_price_change = bookingEditForm.elements.acknowledge_price_change.checked;
        if (!priceNeedsAcknowledgement && originalIndex >= 0) {
            const optimistic = {
                ...originalBooking,
                customer: payload.customer_name,
                contact_number: payload.contact_number,
                email: payload.email,
                notes: payload.notes,
                court_id: payload.court_id ?? originalBooking.court_id,
                date: payload.date ?? originalBooking.date,
                start: targetStart,
                end: targetEnd,
                ...(payload.amount === undefined ? {} : { amount: payload.amount }),
                _saving: true,
            };
            if (optimistic.date !== originalBooking.date || optimistic.start !== originalBooking.start || optimistic.end !== originalBooking.end) {
                optimistic.slots = movedBookingSlots(optimistic.date, optimistic.start, optimistic.end);
            }
            bookings[originalIndex] = optimistic;
            selectedBooking = optimistic;
            affectedCalendarDates = [previousDate, optimistic.date];
            renderCalendar();
        }
        delete payload.start_time;
        delete payload.end_time;
        savingBlock = true;
        bookingEditForm.querySelector('[type="submit"]').disabled = true;
        bookingEditMessage.textContent = '';
        bookingEditMessage.classList.remove('is-error');
        try {
            const response = await fetch(`/admin/bookings/${encodeURIComponent(selectedBooking.id)}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (!priceNeedsAcknowledgement && originalIndex >= 0) {
                    bookings[originalIndex] = originalBooking;
                    selectedBooking = originalBooking;
                    affectedCalendarDates = [previousDate, payload.date];
                    renderCalendar();
                }
                const fieldErrors = Object.values(result.errors || {}).flat();
                const conflict = result.conflict;
                if (result.new_amount !== undefined && result.old_amount !== undefined) {
                    bookingEditForm.elements.amount.value = Number(result.new_amount).toFixed(2);
                    updatePriceAcknowledgementVisibility();
                }
                const conflictMessage = conflict ? ` ${conflict.title || conflict.type}${conflict.start_time ? ` (${conflict.start_time}–${conflict.end_time})` : ''}.` : '';
                bookingEditMessage.textContent = `${result.message || (response.status === 419 ? 'Your session or security token expired. Refresh the page and try again.' : 'Could not update this booking.')}${conflictMessage}${fieldErrors.length ? ` ${fieldErrors.join(' ')}` : ''}`;
                bookingEditMessage.classList.add('is-error');
                return;
            }
            const index = bookings.findIndex((booking) => String(booking.id) === String(result.booking.id));
            let serverPositionMatchesShown = false;
            if (index >= 0) {
                const shown = bookings[index];
                serverPositionMatchesShown = samePosition(itemPosition('booking', shown), itemPosition('booking', result.booking));
                bookings[index] = result.booking;
                delete bookings[index]._saving;
                if (serverPositionMatchesShown) {
                    activeCalendarGrid?.querySelector(`[data-calendar-kind="booking"][data-calendar-id="${CSS.escape(String(result.booking.id))}"]`)?.classList.remove('is-saving');
                    affectedCalendarDates = [];
                }
            }
            affectedCalendarDates = [previousDate, result.booking.date];
            selectedBooking = result.booking;
            closeBlockModal(true);
            if (!serverPositionMatchesShown) renderCalendar();
            notify(result.message || 'Booking updated.', 'success');
        } catch (error) {
            if (!priceNeedsAcknowledgement && originalIndex >= 0) {
                bookings[originalIndex] = originalBooking;
                selectedBooking = originalBooking;
                affectedCalendarDates = [previousDate, payload.date];
                renderCalendar();
            }
            bookingEditMessage.textContent = 'Something went wrong. Please try again.';
            bookingEditMessage.classList.add('is-error');
        } finally {
            savingBlock = false;
            bookingEditForm.querySelector('[type="submit"]').disabled = false;
        }
    });

    const blockEditForm = document.getElementById('courtBlockEditForm');
    const blockEditMessage = document.getElementById('courtBlockEditMessage');
    blockEditForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!validateCalendarForm(blockEditForm)) return;
        if (!selectedBlock || savingBlock) return;
        const token = csrfToken();
        if (!token) {
            blockEditMessage.textContent = 'Your security token is missing. Reload the page and try again.';
            blockEditMessage.classList.add('is-error');
            return;
        }
        const payload = Object.fromEntries(new FormData(blockEditForm).entries());
        const previousDate = selectedBlock.block_date;
        const originalBlock = { ...selectedBlock };
        const originalIndex = blocks.findIndex((item) => String(item.id) === String(selectedBlock.id));
        payload.court_id = payload.court_id === 'all' ? null : Number(payload.court_id);
        payload.notes = payload.notes.trim() || null;
        if (originalIndex >= 0) {
            const optimistic = {
                ...originalBlock, ...payload,
                block_date: payload.block_date,
                start_time: payload.start_time,
                end_time: payload.end_time,
                _saving: true,
            };
            blocks[originalIndex] = optimistic;
            selectedBlock = optimistic;
            affectedCalendarDates = [previousDate, optimistic.block_date];
            renderCalendar();
        }
        savingBlock = true;
        blockEditForm.querySelector('[type="submit"]').disabled = true;
        blockEditMessage.textContent = '';
        blockEditMessage.classList.remove('is-error');
        try {
            const response = await fetch(`/admin/blocks/${encodeURIComponent(selectedBlock.id)}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (originalIndex >= 0) {
                    blocks[originalIndex] = originalBlock;
                    selectedBlock = originalBlock;
                    affectedCalendarDates = [previousDate, payload.block_date];
                    renderCalendar();
                }
                const fieldErrors = Object.values(result.errors || {}).flat();
                const conflict = result.conflict;
                const conflictMessage = conflict ? ` ${conflict.title || conflict.type}${conflict.start_time ? ` (${conflict.start_time}–${conflict.end_time})` : ''}.` : '';
                blockEditMessage.textContent = `${result.message || (response.status === 419 ? 'Your session or security token expired. Refresh the page and try again.' : 'Could not update this event.')}${conflictMessage}${fieldErrors.length ? ` ${fieldErrors.join(' ')}` : ''}`;
                blockEditMessage.classList.add('is-error');
                return;
            }
            const index = blocks.findIndex((item) => String(item.id) === String(result.block.id));
            if (index >= 0) blocks[index] = normalizeBlock(result.block);
            affectedCalendarDates = [previousDate, result.block.block_date];
            closeBlockModal(true);
            renderCalendar();
            notify(result.message || 'Calendar event updated.', 'success');
        } catch (error) {
            if (originalIndex >= 0) {
                blocks[originalIndex] = originalBlock;
                selectedBlock = originalBlock;
                affectedCalendarDates = [previousDate, payload.block_date];
                renderCalendar();
            }
            blockEditMessage.textContent = 'Something went wrong. Please try again.';
            blockEditMessage.classList.add('is-error');
        } finally {
            savingBlock = false;
            blockEditForm.querySelector('[type="submit"]').disabled = false;
        }
    });

    document.getElementById('courtBlockDelete').addEventListener('click', async () => {
        if (!selectedBlock || savingBlock) return;
        if (!window.confirm(`Delete “${selectedBlock.title || 'this event'}”?`)) return;

        const token = csrfToken();
        if (!token) {
            blockDeleteMessage.textContent = 'Your security token is missing. Reload the page and try again.';
            blockDeleteMessage.classList.add('is-error');
            return;
        }

        savingBlock = true;
        const deleteButton = document.getElementById('courtBlockDelete');
        deleteButton.disabled = true;
        try {
            const response = await fetch(`/admin/blocks/${encodeURIComponent(selectedBlock.id)}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 419) {
                    blockDeleteMessage.textContent = 'Your session or security token expired. Refresh the page and try again.';
                    blockDeleteMessage.classList.add('is-error');
                    return;
                }
                blockDeleteMessage.textContent = result.message || 'Could not delete this event.';
                blockDeleteMessage.classList.add('is-error');
                return;
            }

            const deletedDate = selectedBlock.block_date;
            blocks = blocks.filter((block) => String(block.id) !== String(selectedBlock.id));
            affectedCalendarDates = [deletedDate];
            closeBlockModal(true);
            notify(result.message || 'Calendar event deleted.', 'success');
            renderCalendar();
        } catch (error) {
            blockDeleteMessage.textContent = 'Something went wrong. Please try again.';
            blockDeleteMessage.classList.add('is-error');
        } finally {
            savingBlock = false;
            deleteButton.disabled = false;
        }
    });

    const formatDate = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('en-US', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
    });

    const toDateValue = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const eventWindows = (booking, date) => {
        const slotsForDay = (booking.slots || []).filter((slot) => slot.date === date);
        if (slotsForDay.length) return slotsForDay;
        if (booking.date === date) {
            const endMinutes = toMinutes(booking.end);
            const startMinutes = toMinutes(booking.start);
            return endMinutes <= startMinutes
                ? [{ start: booking.start, end: '24:00' }]
                : [{ start: booking.start, end: booking.end }];
        }
        const bookingEnd = toMinutes(booking.end);
        if (bookingEnd > 0 && bookingEnd <= toMinutes(booking.start) && addDays(booking.date, 1) === date) {
            return [{ start: '00:00', end: booking.end }];
        }
        return [];
    };

    const queryParams = new URLSearchParams(window.location.search);
    const listStateParams = ['search', 'date', 'status', 'page'];
    const hasListState = listStateParams.some((key) => queryParams.has(key));
    const requestedView = queryParams.get('view');
    const initialView = ['list', 'board', 'month', 'week'].includes(requestedView)
        ? requestedView
        : (hasListState ? 'list' : 'month');

    const changeView = (view, updateUrl = true) => {
        if (!['list', 'board', 'month', 'week'].includes(view)) return;
        const previousView = Object.keys(panels).find((key) => !panels[key].hidden);
        if (view === 'week' && previousView === 'month') {
            const [year, month] = monthInput.value.split('-').map(Number);
            const todayYear = Number(venueToday.slice(0, 4));
            const todayMonth = Number(venueToday.slice(5, 7));
            const choice = todayYear === year && todayMonth === month
                ? venueToday
                : `${year}-${String(month).padStart(2, '0')}-01`;
            weekInput.value = choice;
            weekStart = startOfWeek(choice);
        } else if (view === 'month' && previousView === 'week') {
            const wednesday = new Date(`${weekStart}T00:00:00`);
            wednesday.setDate(wednesday.getDate() + 3);
            monthInput.value = toMonthValue(wednesday);
        }
        tabs.forEach((tab) => {
            const active = tab.dataset.bookingsView === view;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        Object.entries(panels).forEach(([key, panel]) => {
            if (panel) panel.hidden = key !== view;
        });
        if (view === 'board' || view === 'month' || view === 'week') renderCalendar();
        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', view);
            if (view === 'week') url.searchParams.set('date', weekStart);
            else if (view === 'month') url.searchParams.set('date', `${monthInput.value}-01`);
            else if (view === 'board') url.searchParams.set('date', dateInput.value);
            window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
        }
    };

    const makeEvent = (booking, window) => {
        const event = document.createElement('article');
        event.className = `court-board-event status-${booking.status}`;

        const time = document.createElement('span');
        time.className = 'court-board-event-time';
        time.textContent = `${window.start} – ${window.end}`;

        const customer = document.createElement('strong');
        customer.className = 'court-board-event-customer';
        customer.textContent = booking.customer || 'Walk-in booking';

        const meta = document.createElement('div');
        meta.className = 'court-board-event-meta';
        const status = document.createElement('span');
        status.className = 'court-board-event-status';
        status.textContent = booking.status;
        meta.append(status);

        const equipment = (booking.equipment || [])
            .map((line) => `${line.quantity}× ${line.name}`)
            .join(', ');
        if (equipment) {
            const equipmentLine = document.createElement('span');
            equipmentLine.className = 'court-board-event-equipment';
            equipmentLine.textContent = equipment;
            meta.append(equipmentLine);
        }

        event.append(time, customer, meta);
        return event;
    };

    const renderBoard = () => {
        const date = dateInput.value;
        if (!date) return;

        const windows = bookings.flatMap((booking) => eventWindows(booking, date).map((window) => ({ booking, window })));
        const active = windows.filter(({ booking }) => booking.status !== 'cancelled');
        summary.textContent = `${formatDate(date)} · ${active.length} active reservation${active.length === 1 ? '' : 's'} across ${courts.length} court${courts.length === 1 ? '' : 's'}`;
        board.replaceChildren();

        courts.forEach((court) => {
            const card = document.createElement('section');
            card.className = 'court-board-court';

            const heading = document.createElement('div');
            heading.className = 'court-board-court-heading';
            const title = document.createElement('h3');
            title.textContent = court.name;
            const count = document.createElement('span');
            const courtEvents = windows.filter(({ booking }) => Number(booking.court_id) === Number(court.id));
            count.textContent = `${courtEvents.filter(({ booking }) => booking.status !== 'cancelled').length} active`;
            heading.append(title, count);
            card.append(heading);

            const courtClosures = closures.filter((closure) => closure.date === date && (closure.court_id === null || Number(closure.court_id) === Number(court.id)));
            courtClosures.forEach((closure) => {
                const notice = document.createElement('div');
                notice.className = 'court-board-closure';
                notice.textContent = closure.reason ? `Closed: ${closure.reason}` : 'Closed';
                card.append(notice);
            });

            const events = document.createElement('div');
            events.className = 'court-board-events';
            const { startHour: dayStartHour, endHour: dayEndHour } = getHourRange();
            const wrappedStartMinutes = (window) => {
                let minutes = toMinutes(window.start);
                if (dayEndHour > 24 && minutes < dayStartHour * 60) minutes += 24 * 60;
                return minutes;
            };
            courtEvents
                .sort((a, b) => wrappedStartMinutes(a.window) - wrappedStartMinutes(b.window))
                .forEach(({ booking, window }) => events.append(makeEvent(booking, window)));

            if (!courtEvents.length && !courtClosures.length) {
                const empty = document.createElement('p');
                empty.className = 'court-board-empty';
                empty.textContent = 'No reservations for this court.';
                events.append(empty);
            }

            card.append(events);
            board.append(card);
        });

        if (!courts.length) {
            board.textContent = 'No courts are configured yet.';
        }
    };

    const toMinutes = (hhmm) => {
        const [h, m] = hhmm.split(':').map(Number);
        return h * 60 + m;
    };

    const blockWindowsForDate = (block, date) => {
        const normalized = normalizeBlock(block);
        const startMinutes = toMinutes(normalized.start_time);
        const rawEndMinutes = toMinutes(normalized.end_time);
        const overnight = rawEndMinutes <= startMinutes;
        if (normalized.block_date === date) {
            return [{
                startMinutes,
                endMinutes: overnight ? 1440 : rawEndMinutes,
                block,
            }];
        }
        if (overnight && rawEndMinutes > 0 && addDays(normalized.block_date, 1) === date) {
            return [{ startMinutes: 0, endMinutes: rawEndMinutes, block }];
        }
        return [];
    };

    const rangeHasConflict = (date, startMinutes, endMinutes, ignoreBookingId = null, courtId = null, ignoreBlockId = null) => {
        const weekday = new Date(`${date}T00:00:00`).getDay();
        if (BIZ_CLOSED_WEEKDAYS.includes(weekday)
            || closures.some((closure) => closure.date === date)) return true;

        const bookingConflict = bookings.some((booking) => {
            if (ignoreBookingId !== null && String(booking.id) === String(ignoreBookingId)) return false;
            if (courtId !== null && Number(booking.court_id) !== Number(courtId)) return false;
            if (booking.status === 'cancelled') return false;
            return eventWindows(booking, date).some((window) => {
                const start = toMinutes(window.start);
                let end = toMinutes(window.end);
                if (end <= start) end += 1440;
                return overlaps(startMinutes, endMinutes, start, end);
            });
        });
        if (bookingConflict) return true;

        return blocks.some((block) => (ignoreBlockId === null || String(block.id) !== String(ignoreBlockId))
            && (courtId === null || block.court_id === null || Number(block.court_id) === Number(courtId))
            && blockWindowsForDate(block, date).some((window) =>
            overlaps(startMinutes, endMinutes, window.startMinutes, window.endMinutes)
        ));
    };

    const timeCellAtPoint = (event, column) => {
        const hits = document.elementsFromPoint(event.clientX, event.clientY);
        for (const hit of hits) {
            const cell = hit.closest?.('.court-month-hour-cell');
            if (cell && cell.closest('.court-month-date-col') === column) return cell;
        }
        // Booking bars and blocks sit above the cells; resolve against
        // viewport rectangles so an overlay cannot interrupt a drag.
        return Array.from(column.querySelectorAll('.court-month-hour-cell')).find((cell) => {
            const rect = cell.getBoundingClientRect();
            return event.clientX >= rect.left && event.clientX < rect.right
                && event.clientY >= rect.top && event.clientY < rect.bottom;
        }) || null;
    };

    const slotFromPoint = (event, column) => {
        const cell = timeCellAtPoint(event, column);
        if (!cell) return null;
        const rect = cell.getBoundingClientRect();
        const minuteWithinHour = Math.max(0, Math.min(59, (event.clientY - rect.top) / rect.height * 60));
        const minute = Math.floor(minuteWithinHour / stepMinutes) * stepMinutes;
        return {
            date: cell.dataset.date,
            minutes: Number(cell.dataset.hour) * 60 + minute,
        };
    };

    let calendarDrag = null;
    let activeTouchCreatePending = null;
    let dragEndedAt = 0;
    let suppressNextCalendarClick = false;
    let calendarPointerExceededThreshold = false;
    const touchMoveFallbacks = new WeakMap();
    const releaseDragVisuals = (drag) => {
        if (drag?.touchHoldTimer) window.clearTimeout(drag.touchHoldTimer);
        if (drag?.ghost) drag.ghost.remove();
        if (drag?.clone) drag.clone.remove();
        if (drag?.source?.isConnected) drag.source.classList.remove('is-drag-placeholder');
        drag?.touchMoveFallbackCleanup?.();
        if (drag?.key) {
            if (activeDragKey === drag.key) activeDragKey = null;
            if (!moveQueues.has(drag.key)) protectedItems.delete(drag.key);
        }
        if (drag?.animationFrame) cancelAnimationFrame(drag.animationFrame);
        if (drag) drag.animationFrame = 0;
        drag?.grid?.classList.remove('is-booking-drag');
        endMonthDrag(drag?.pointerId);
        if (calendarDataDirty && blockModal.hidden && moveQueues.size === 0) renderCalendar();
    };
    const updateDragClone = (drag, clientX, clientY) => {
        if (!drag.clone) return;
        drag.pointerX = clientX;
        drag.pointerY = clientY;
        drag.lastPointerAt = performance.now();
        if (drag.animationFrame) return;
        const animateClone = () => {
            if (!drag.clone || calendarDrag !== drag) { drag.animationFrame = 0; return; }
            const now = performance.now();
            const deltaX = drag.pointerX - drag.lastFrameX;
            drag.lastFrameX = drag.pointerX;
            const currentDx = now - drag.lastPointerAt < 80 ? deltaX : 0;
            drag.smoothedDx = (drag.smoothedDx * 0.7) + (currentDx * 0.3);
            const targetAngle = drag.reducedMotion ? 0 : Math.max(-10, Math.min(10, drag.smoothedDx * 1.15));
            drag.angle += (targetAngle - drag.angle) * 0.18;
            const rotation = drag.reducedMotion ? '' : ` rotate(${drag.angle.toFixed(2)}deg)`;
            const scale = drag.reducedMotion ? 1 : 1.02;
            drag.clone.style.transform = `translate3d(${drag.pointerX - drag.grabX}px, ${drag.pointerY - drag.grabY - drag.liftOffset}px, 0)${rotation} scale(${scale})`;
            if (autoScrollDrag(drag)) {
                const point = dragHitPoint(drag);
                updateCalendarDragTarget(drag, point.x, point.y);
            }
            drag.animationFrame = requestAnimationFrame(animateClone);
        };
        drag.animationFrame = requestAnimationFrame(animateClone);
    };
    const dragHitPoint = (drag) => ({
        x: drag.pointerX - drag.grabX + (drag.clone?.offsetWidth || 0) / 2,
        y: drag.pointerY - drag.grabY - drag.liftOffset,
    });
    const updateCalendarDragTarget = (drag, clientX, clientY) => {
        const targetColumn = document.elementsFromPoint(clientX, clientY)
            .map((element) => element.closest?.('.court-month-date-col'))
            .find((column) => column && drag.grid.contains(column));
        const hit = { clientX, clientY };
        const slot = targetColumn ? slotFromPoint(hit, targetColumn) : null;
        if (!targetColumn || !slot) {
            drag.target = null;
            drag.ghost?.remove();
            drag.ghost = null;
            return;
        }
        const startMinutes = slot.minutes;
        const endMinutes = startMinutes + drag.duration;
        if (!drag.ghost) {
            drag.ghost = document.createElement('div');
            drag.ghost.className = 'court-month-selection-ghost is-booking-drag-ghost';
            drag.ghost.setAttribute('aria-hidden', 'true');
        }
        if (drag.ghost.parentElement !== targetColumn) targetColumn.append(drag.ghost);
        const yForTarget = targetColumn._monthYForMinute || (() => 0);
        drag.ghost.style.top = `${yForTarget(startMinutes)}px`;
        drag.ghost.style.height = `${Math.max(8, yForTarget(endMinutes) - yForTarget(startMinutes))}px`;
        const past = isPastDate(slot.date) || (slot.date === venueToday && startMinutes < venueNowMinutes());
        const conflict = past || (drag.kind === 'block'
            ? rangeHasConflict(slot.date, startMinutes, endMinutes, null, drag.item.court_id, drag.item.id)
            : rangeHasConflict(slot.date, startMinutes, endMinutes, drag.item.id, drag.item.court_id));
        drag.ghost.classList.toggle('is-conflict', conflict);
        drag.target = { date: slot.date, startMinutes, endMinutes, conflict, past, targetColumn };
    };
    const autoScrollDrag = (drag) => {
        const scrollArea = drag.grid.closest('.court-month-scroll');
        if (!scrollArea) return false;
        const rect = scrollArea.getBoundingClientRect();
        const point = dragHitPoint(drag);
        const edge = 52;
        const maxStep = 12;
        const edgeStep = (coordinate, start, end) => {
            if (coordinate < start + edge) return -Math.ceil(maxStep * Math.min(1, (start + edge - coordinate) / edge));
            if (coordinate > end - edge) return Math.ceil(maxStep * Math.min(1, (coordinate - (end - edge)) / edge));
            return 0;
        };
        const vertical = edgeStep(point.y, rect.top, rect.bottom);
        const horizontal = drag.grid.classList.contains('court-week-grid') ? 0 : edgeStep(point.x, rect.left, rect.right);
        const priorTop = scrollArea.scrollTop;
        const priorLeft = scrollArea.scrollLeft;
        if (vertical) scrollArea.scrollTop += vertical;
        if (horizontal) scrollArea.scrollLeft += horizontal;
        const changed = priorTop !== scrollArea.scrollTop || priorLeft !== scrollArea.scrollLeft;
        if (changed && activeMonthDrag?.scrollArea === scrollArea) {
            activeMonthDrag.scrollTop = scrollArea.scrollTop;
            activeMonthDrag.scrollLeft = scrollArea.scrollLeft;
        }
        return changed;
    };
    const beginCalendarDrag = (event) => {
        const grid = event.currentTarget;
        const source = event.target.closest?.('[data-calendar-kind][data-calendar-id]');
        if (!source || !grid.contains(source) || calendarDrag
            || (event.pointerType === 'mouse' && event.button !== 0)) return;
        const kind = source.dataset.calendarKind;
        const id = source.dataset.calendarId;
        const item = kind === 'booking'
            ? bookings.find((entry) => String(entry.id) === id)
            : blocks.find((entry) => String(entry.id) === id);
        if (!item || itemIsPast(kind, item)) return;
        const rect = source.getBoundingClientRect();
        let start = kind === 'booking' ? toMinutes(item.start) : toMinutes(item.start_time);
        let end = kind === 'booking' ? toMinutes(item.end) : toMinutes(item.end_time);
        if (end <= start) end += 1440;
        const key = `${kind}:${id}`;
        activeCalendarGrid = grid;
        activeDragKey = key;
        protectedItems.add(key);
        const dragState = {
            pointerId: event.pointerId, kind, id, key, item, original: { ...item }, source, grid,
            pointerType: event.pointerType, startedAt: performance.now(),
            startX: event.clientX, startY: event.clientY, moved: false,
            duration: Math.max(kind === 'booking' ? stepMinutes : 1, end - start),
            grabX: event.clientX - rect.left, grabY: event.clientY - rect.top,
            pointerX: event.clientX, pointerY: event.clientY, lastFrameX: event.clientX,
            lastPointerAt: performance.now(), smoothedDx: 0, angle: 0,
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            liftOffset: event.pointerType === 'touch' ? 24 : 0,
            ghost: null, clone: null, target: null,
        };
        calendarDrag = dragState;
        if (event.pointerType === 'touch') {
            dragState.pointerCaptureTarget = source;
            dragState.touchMoveFallbackCleanup = touchMoveFallbacks.get(source) || null;
            try { source.setPointerCapture(event.pointerId); } catch (error) { /* Pointer capture may be unavailable on older Safari. */ }
        }
    };
    const moveCalendarDrag = (event) => {
        const drag = calendarDrag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        const dragThreshold = drag.pointerType === 'touch' ? 8 : 5;
        if (!drag.moved && Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) > dragThreshold) {
            drag.moved = true;
            calendarPointerExceededThreshold = true;
            if (!drag.pointerCaptureTarget) {
                drag.pointerCaptureTarget = drag.grid;
                drag.grid.setPointerCapture?.(event.pointerId);
            }
            beginMonthDrag(event.pointerId, drag.grid, drag.pointerType === 'touch');
            drag.grid.classList.add('is-booking-drag');
            const rect = drag.source.getBoundingClientRect();
            drag.clone = drag.source.cloneNode(true);
            drag.clone.classList.add('court-month-drag-clone');
            drag.clone.setAttribute('aria-hidden', 'true');
            Object.assign(drag.clone.style, {
                left: '0', top: '0', width: `${rect.width}px`, height: `${rect.height}px`,
                opacity: '.96', pointerEvents: 'none',
                transformOrigin: `${drag.grabX}px ${drag.grabY}px`,
                transform: `translate3d(${event.clientX - drag.grabX}px, ${event.clientY - drag.grabY - drag.liftOffset}px, 0)${drag.reducedMotion ? '' : ' rotate(0deg)'} scale(${drag.reducedMotion ? 1 : 1.02})`,
            });
            document.body.append(drag.clone);
            drag.source.classList.add('is-drag-placeholder');
        }
        if (!drag.moved) return;
        event.preventDefault();
        lockMonthScroll();
        updateDragClone(drag, event.clientX, event.clientY);
        const targetPoint = dragHitPoint(drag);
        updateCalendarDragTarget(drag, targetPoint.x, targetPoint.y);
    };
    const stopCalendarDrag = async (event, cancelled = false) => {
        const drag = calendarDrag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        if (drag.moved) {
            drag.pointerX = event.clientX;
            drag.pointerY = event.clientY;
            const point = dragHitPoint(drag);
            updateCalendarDragTarget(drag, point.x, point.y);
        }
        calendarDrag = null;
        const captureTarget = drag.pointerCaptureTarget || drag.grid;
        if (captureTarget.hasPointerCapture?.(event.pointerId)) captureTarget.releasePointerCapture(event.pointerId);
        if (!drag.moved) {
            const touchPointerUp = !cancelled && drag.pointerType === 'touch';
            const touchTap = touchPointerUp && performance.now() - drag.startedAt < 300;
            if (touchPointerUp) {
                dragEndedAt = performance.now();
                suppressNextCalendarClick = true;
            }
            releaseDragVisuals(drag);
            if (touchTap) {
                const currentItem = drag.kind === 'booking'
                    ? bookings.find((item) => String(item.id) === drag.id)
                    : blocks.find((item) => String(item.id) === drag.id);
                if (currentItem) (drag.kind === 'booking' ? openBookingDetails(currentItem) : openBlockDetails(currentItem));
            }
            return;
        }
        event.preventDefault();
        dragEndedAt = performance.now();
        suppressNextCalendarClick = true;
        const target = drag.target;
        drag.ghost?.remove();
        drag.ghost = null;
        if (cancelled || !target) { releaseDragVisuals(drag); return; }
        endMonthDrag(event.pointerId);
        if (isPastDate(target.date)) {
            releaseDragVisuals(drag);
            notify("You can't move this to a past date", 'error');
            return;
        }
        if (target.date === venueToday && target.startMinutes < venueNowMinutes()) {
            releaseDragVisuals(drag);
            notify("You can't move this to a past time", 'error');
            return;
        }
        if (target.conflict) {
            releaseDragVisuals(drag);
            notify('That time overlaps a booking, closure, or another calendar event.', 'error');
            return;
        }
        const payload = drag.kind === 'block'
            ? { block_date: target.date, start_time: formatTimeInput(target.startMinutes), end_time: formatTimeInput(target.endMinutes) }
            : { date: target.date, start_time: formatTimeInput(target.startMinutes), end_time: formatTimeInput(target.endMinutes) };
        const list = moveList(drag.kind);
        const index = list.findIndex((entry) => String(entry.id) === drag.id);
        if (index < 0) { releaseDragVisuals(drag); return; }
        const queue = moveQueues.get(drag.key) || {
            key: drag.key, kind: drag.kind, id: drag.id, confirmed: { ...list[index] },
            version: list[index].updated_at, wanted: null, inFlight: null, changedDates: [],
        };
        const currentDisplayed = { ...list[index] };
        const sequence = (moveSequences.get(drag.key) || 0) + 1;
        moveSequences.set(drag.key, sequence);
        const position = {
            date: target.date, start: formatTimeInput(target.startMinutes), end: formatTimeInput(target.endMinutes),
            court_id: currentDisplayed.court_id ?? null,
        };
        queue.wanted = { sequence, position, payload };
        queue.changedDates = [...new Set([...(queue.changedDates || []),
            drag.kind === 'block' ? currentDisplayed.block_date : currentDisplayed.date, target.date])];
        moveQueues.set(drag.key, queue);
        protectedItems.add(drag.key);
        const optimistic = drag.kind === 'block'
            ? { ...currentDisplayed, ...payload, _saving: true }
            : { ...currentDisplayed, date: payload.date, start: payload.start_time, end: payload.end_time,
                slots: movedBookingSlots(payload.date, payload.start_time, payload.end_time), _saving: true };
        list[index] = optimistic;
        affectedCalendarDates = queue.changedDates;
        renderCalendar();
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const settle = drag.grid.querySelector(`[data-calendar-kind="${drag.kind}"][data-calendar-id="${CSS.escape(drag.id)}"]`);
        if (drag.animationFrame) cancelAnimationFrame(drag.animationFrame);
        drag.animationFrame = 0;
        const settlePromise = drag.clone && settle && !reducedMotion
            ? (() => {
                const rect = settle.getBoundingClientRect();
                drag.clone.classList.add('is-settling');
                drag.clone.style.transform = `translate3d(${rect.left}px, ${rect.top}px, 0) rotate(0deg) scale(1)`;
                return new Promise((resolve) => window.setTimeout(resolve, 150));
            })()
            : Promise.resolve();
        void pumpMoveQueue(queue);
        await settlePromise;
        drag.clone?.remove();
        drag.source?.classList.remove('is-drag-placeholder');
        releaseDragVisuals(drag);
    };
    const preventGridNativeDrag = (event) => event.preventDefault();
    const attachCalendarGridEvents = (grid) => {
        grid.addEventListener('pointermove', (event) => {
            const pending = activeTouchCreatePending;
            if (pending && !pending.longPressed && pending.pointerId === event.pointerId
                && Math.hypot(event.clientX - pending.startX, event.clientY - pending.startY) > 8) {
                window.clearTimeout(pending.holdTimer);
                activeTouchCreatePending = null;
            }
        }, true);
        grid.addEventListener('pointerup', (event) => {
            const pending = activeTouchCreatePending;
            if (pending && pending.pointerId === event.pointerId && !pending.longPressed) {
                window.clearTimeout(pending.holdTimer);
                activeTouchCreatePending = null;
            }
        }, true);
        grid.addEventListener('pointercancel', (event) => {
            const pending = activeTouchCreatePending;
            if (pending?.pointerId === event.pointerId) {
                window.clearTimeout(pending.holdTimer);
                activeTouchCreatePending = null;
            }
        }, true);
        grid.addEventListener('touchstart', (event) => {
            const bar = event.target.closest?.('.court-month-bar, .court-month-block');
            if (!bar || !grid.contains(bar)) return;
            touchMoveFallbacks.get(bar)?.();
            const preventTouchScrollDuringDrag = (touchEvent) => {
                if (calendarDrag?.source === bar && calendarDrag.moved && touchEvent.cancelable) touchEvent.preventDefault();
            };
            const cleanup = () => {
                bar.removeEventListener('touchmove', preventTouchScrollDuringDrag);
                bar.removeEventListener('touchend', cleanup);
                bar.removeEventListener('touchcancel', cleanup);
                touchMoveFallbacks.delete(bar);
            };
            bar.addEventListener('touchmove', preventTouchScrollDuringDrag, { passive: false });
            bar.addEventListener('touchend', cleanup, { once: true });
            bar.addEventListener('touchcancel', cleanup, { once: true });
            touchMoveFallbacks.set(bar, cleanup);
        }, { capture: true, passive: true });
        grid.addEventListener('pointerdown', () => {
            dragEndedAt = 0;
            suppressNextCalendarClick = false;
            calendarPointerExceededThreshold = false;
        }, true);
        grid.addEventListener('pointerdown', beginCalendarDrag);
        grid.addEventListener('pointermove', moveCalendarDrag);
        grid.addEventListener('pointerup', (event) => { void stopCalendarDrag(event); });
        grid.addEventListener('pointercancel', (event) => { void stopCalendarDrag(event, true); });
        grid.addEventListener('lostpointercapture', (event) => { void stopCalendarDrag(event, true); });
        grid.addEventListener('click', (event) => {
            const elapsedSinceDrag = performance.now() - dragEndedAt;
            if (suppressNextCalendarClick || calendarPointerExceededThreshold || (dragEndedAt > 0 && elapsedSinceDrag < 350)) {
                event.preventDefault();
                event.stopPropagation();
                suppressNextCalendarClick = false;
                calendarPointerExceededThreshold = false;
                dragEndedAt = 0;
                return;
            }
            const target = event.target.closest?.('[data-calendar-kind][data-calendar-id]');
            if (!target) return;
            event.stopPropagation();
            const item = target.dataset.calendarKind === 'booking'
                ? bookings.find((entry) => String(entry.id) === target.dataset.calendarId)
                : blocks.find((entry) => String(entry.id) === target.dataset.calendarId);
            if (item) (target.dataset.calendarKind === 'booking' ? openBookingDetails(item) : openBlockDetails(item));
        }, true);
        grid.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && calendarDrag?.grid === grid) {
                const drag = calendarDrag;
                calendarDrag = null;
                const captureTarget = drag.pointerCaptureTarget || grid;
                if (captureTarget.hasPointerCapture?.(drag.pointerId)) captureTarget.releasePointerCapture(drag.pointerId);
                releaseDragVisuals(drag);
            }
        });
        grid.addEventListener('dragstart', preventGridNativeDrag);
        grid.addEventListener('selectstart', preventGridNativeDrag);
        grid.addEventListener('contextmenu', (event) => {
            if (event.target.closest?.('.court-month-bar, .court-month-block') || calendarDrag?.grid === grid) event.preventDefault();
        });
    };
    calendarGrids.forEach(attachCalendarGridEvents);

    const formatHourLabel = (hour) => {
        const h = ((hour % 24) + 24) % 24;
        const period = h >= 12 ? 'PM' : 'AM';
        const hour12 = h % 12 === 0 ? 12 : h % 12;
        return `${hour12} ${period}`;
    };

    // Derive initials from a customer name — max 2 characters.
    const toInitials = (name) => {
        if (!name || !name.trim()) return '?';
        const parts = name.trim().split(/\s+/);
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    };

    // Fixed px-per-hour so the timeline never squeezes to fit the screen.
    const PX_PER_HOUR = 64;

    // Label column width (time axis, left side).
    const LABEL_W = 64;

    // Hour range is pinned to BusinessSetting open_hour / close_hour so the
    // grid always shows the full operating window regardless of whether any
    // bookings exist yet — no more grid that stops at the last booking's
    // end time. close_hour > 12 means midnight-crossing hours (e.g. 7 means
    // next-day 7 AM = hour 31); store as-is and let formatHourLabel wrap.
    let cachedHourRange = null;
    const getHourRange = () => {
        if (cachedHourRange) return cachedHourRange;

        // Resolve the close hour: if close_hour <= open_hour it crosses
        // midnight, so add 24 to get the correct ceiling.
        const rawClose = BIZ_CLOSE_HOUR <= BIZ_OPEN_HOUR
            ? BIZ_CLOSE_HOUR + 24
            : BIZ_CLOSE_HOUR;

        cachedHourRange = {
            startHour: BIZ_OPEN_HOUR,
            endHour:   rawClose,
        };
        return cachedHourRange;
    };

    // Greedy interval-scheduling lane assignment so overlapping bookings
    // stack instead of overlapping visually.
    const assignLanes = (items) => {
        const lanes = [];
        return items
            .slice()
            .sort((a, b) => a.startMinutes - b.startMinutes)
            .map((item) => {
                let lane = lanes.findIndex((endMinutes) => endMinutes <= item.startMinutes);
                if (lane === -1) { lane = lanes.length; lanes.push(0); }
                lanes[lane] = item.endMinutes;
                return { ...item, lane };
            })
            .map((item) => ({ ...item, laneCount: lanes.length }));
    };

    const toMonthValue = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return `${year}-${month}`;
    };

    const jumpToDay = (date) => {
        dateInput.value = date;
        changeView('board');
    };

    let monthInitialScrollDone = false;
    let weekInitialScrollDone = false;
    let activeMonthDrag = null;

    const lockMonthScroll = () => {
        if (!activeMonthDrag) return;
        const { scrollArea, scrollLeft, scrollTop } = activeMonthDrag;
        if (scrollArea.scrollLeft !== scrollLeft) scrollArea.scrollLeft = scrollLeft;
        if (scrollArea.scrollTop !== scrollTop) scrollArea.scrollTop = scrollTop;
    };
    const preventMonthScroll = (event) => {
        if (activeMonthDrag) event.preventDefault();
    };

    const beginMonthDrag = (pointerId, grid = activeCalendarGrid, lockPage = false) => {
        const scrollArea = grid.closest('.court-month-scroll');
        if (!scrollArea) return;
        const pageStyles = lockPage ? {
            rootOverflow: document.documentElement.style.overflow,
            bodyOverflow: document.body.style.overflow,
            scrollTouchAction: scrollArea.style.touchAction,
        } : null;
        if (lockPage) {
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
            scrollArea.style.touchAction = 'none';
        }
        activeMonthDrag = { pointerId, scrollArea, grid, scrollLeft: scrollArea.scrollLeft, scrollTop: scrollArea.scrollTop, pageStyles };
        grid.classList.add('is-selecting-range');
        scrollArea.classList.add('is-selecting-range');
        scrollArea.addEventListener('scroll', lockMonthScroll, { passive: true });
        scrollArea.addEventListener('wheel', preventMonthScroll, { passive: false });
        scrollArea.addEventListener('touchmove', preventMonthScroll, { passive: false });
    };

    const endMonthDrag = (pointerId) => {
        if (!activeMonthDrag || activeMonthDrag.pointerId !== pointerId) return;
        const { scrollArea, grid, pageStyles } = activeMonthDrag;
        activeMonthDrag = null;
        scrollArea.removeEventListener('scroll', lockMonthScroll);
        scrollArea.removeEventListener('wheel', preventMonthScroll);
        scrollArea.removeEventListener('touchmove', preventMonthScroll);
        grid.classList.remove('is-selecting-range');
        scrollArea.classList.remove('is-selecting-range');
        if (pageStyles) {
            document.documentElement.style.overflow = pageStyles.rootOverflow;
            document.body.style.overflow = pageStyles.bodyOverflow;
            scrollArea.style.touchAction = pageStyles.scrollTouchAction;
        }
    };


    // ── Professional muted palette ──────────────────────────────────────────
    // Replaces the neon rainbow. Colours are readable on dark backgrounds
    // without screaming for attention.
    const STATUS_COLORS = {
        paid:      { bg: 'var(--surface)', border: 'var(--brand)', text: 'var(--text)' },
        pending:   { bg: 'var(--surface)', border: 'var(--warning)', text: 'var(--warning)' },
        cancelled: { bg: 'var(--surface)', border: 'var(--danger)', text: 'var(--danger)' },
    };

    // Per-booking accent border — muted, distinct, cycles by booking index.
    const LANE_ACCENTS = [
        'var(--brand)', 'var(--info)', 'var(--warning)', 'var(--danger)',
        'var(--text-muted)', 'var(--brand)', 'var(--info)', 'var(--warning)',
        'var(--danger)', 'var(--text-muted)', 'var(--brand)', 'var(--info)',
    ];

    // ── Month view day layout ────────────────────────────────────────────
    // Unlike the continuous "open hour → close hour (+24 if wrapped)" block
    // used elsewhere, the month grid displays a FIXED daily layout matching
    // the guest booking widget: the overnight tail (12 AM up to close_hour)
    // first, then a closed divider, then the day's own session (open_hour
    // up to midnight). This means every date column has the same row
    // structure, and a booking's clock time maps directly onto it with no
    // +24 wraparound needed.
    const DIVIDER_H = PX_PER_HOUR * 2;
    const buildDayRowPlan = () => {
        const isOvernight = BIZ_CLOSE_HOUR <= BIZ_OPEN_HOUR;
        const tailHours = isOvernight
            ? Array.from({ length: BIZ_CLOSE_HOUR }, (_, h) => h)
            : [];
        const mainHours = isOvernight
            ? Array.from({ length: 24 - BIZ_OPEN_HOUR }, (_, i) => BIZ_OPEN_HOUR + i)
            : Array.from({ length: Math.max(0, BIZ_CLOSE_HOUR - BIZ_OPEN_HOUR) }, (_, i) => BIZ_OPEN_HOUR + i);
        const hasDivider = isOvernight && tailHours.length > 0;

        const rowPlan = [
            ...tailHours.map((hour) => ({ type: 'hour', hour })),
            ...(hasDivider ? [{ type: 'divider' }] : []),
            ...mainHours.map((hour) => ({ type: 'hour', hour })),
        ];

        // Cumulative pixel offset for each hour row — used to position
        // both the row itself and any booking bar that starts in it.
        const hourTop = new Map();
        let cursor = 0;
        let dividerTop = null;
        rowPlan.forEach((row) => {
            if (row.type === 'hour') {
                hourTop.set(row.hour, cursor);
                cursor += PX_PER_HOUR;
            } else {
                dividerTop = cursor;
                cursor += DIVIDER_H;
            }
        });

        return { rowPlan, hourTop, dividerTop, gridH: cursor };
    };

    // ── Month view: dates across the top, time down the left ────────────────
    const fullWeekRangeLabel = (startValue, endValue) => {
        const start = new Date(`${startValue}T00:00:00`);
        const end = new Date(`${endValue}T00:00:00`);
        const monthDay = (date) => date.toLocaleDateString('en-US', { month: 'long', day: 'numeric' });
        if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
            return `${monthDay(start)} - ${end.getDate()}, ${end.getFullYear()}`;
        }
        if (start.getFullYear() === end.getFullYear()) return `${monthDay(start)} - ${monthDay(end)}, ${end.getFullYear()}`;
        return `${monthDay(start)}, ${start.getFullYear()} - ${monthDay(end)}, ${end.getFullYear()}`;
    };
    const shortWeekRangeLabel = (startValue, endValue) => {
        const start = new Date(`${startValue}T00:00:00`);
        const end = new Date(`${endValue}T00:00:00`);
        const startText = start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        const endText = end.getMonth() === start.getMonth()
            ? String(end.getDate())
            : end.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        return `${startText} - ${endText}`;
    };

    const renderCalendarGrid = (startDate, dayCount, mode, targetGrid, changedDates = null) => {
        if (!startDate || !dayCount || !targetGrid) return;
        const baseDate = new Date(`${startDate}T00:00:00`);
        const { rowPlan, hourTop, dividerTop, gridH } = buildDayRowPlan();
        const todayValue = venueToday;

        const dates = Array.from({ length: dayCount }, (_, i) => addDays(startDate, i));

        // Column width per date.
        const COL_W = mode === 'week' ? 1 : 90;
        // Row height per hour.
        const ROW_H = PX_PER_HOUR;
        // Time label column width (left side).
        const TIME_LABEL_W = mode === 'week' ? 42 : LABEL_W;

        const gridW = TIME_LABEL_W + dates.length * COL_W;

        const nextGrid = document.createElement('div');
        nextGrid.className = `court-month-grid${mode === 'week' ? ' court-week-grid' : ''}`;
        nextGrid.style.width = mode === 'week' ? '100%' : `${gridW}px`;

        // ── Header row: blank corner + one date column per day ──────────────
        const header = document.createElement('div');
        header.className = 'court-month-header';
        header.style.display = mode === 'week' ? 'grid' : 'flex';
        if (mode === 'week') header.style.gridTemplateColumns = `${TIME_LABEL_W}px repeat(7, minmax(0, 1fr))`;
        header.style.position = 'sticky';
        header.style.top = '0';
        header.style.zIndex = '3';
        header.style.background = 'var(--surface-2)';

        // Corner cell
        const corner = document.createElement('div');
        corner.className = 'court-month-corner';
        corner.style.cssText = `flex:0 0 ${TIME_LABEL_W}px;width:${TIME_LABEL_W}px;border-right:1px solid var(--text-muted);`;
        header.append(corner);

        dates.forEach((dateStr, colIndex) => {
            const d = new Date(`${dateStr}T00:00:00`);
            const dayNum = d.getDate();
            const weekday = d.toLocaleDateString('en-US', { weekday: 'short' });
            const isToday = dateStr === todayValue;

            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'court-month-date-header' + (isToday ? ' is-today' : '');
            cell.dataset.date = dateStr;
            cell.style.cssText = mode === 'week' ? 'min-width:0;' : `flex:0 0 ${COL_W}px;width:${COL_W}px;`;
            const monthTag = colIndex === 0 || dayNum === 1
                ? ` <small>${d.toLocaleDateString('en-US', { month: 'short' })}</small>`
                : '';
            cell.innerHTML = `<strong>${dayNum}${mode === 'week' ? monthTag : ''}</strong><span>${weekday}</span>`;
            cell.title = `Open ${formatDate(dateStr)} on the daily board`;
            cell.addEventListener('click', () => jumpToDay(dateStr));
            header.append(cell);
        });
        nextGrid.append(header);

        // ── Body: one row per hour, one column per date ─────────────────────
        // Pre-build booking and block windows per date.
        const windowsByDate = new Map();
        dates.forEach((dateStr) => {
            const raw = bookings
                .filter((booking) => booking.status !== 'cancelled')
                .flatMap((booking) => eventWindows(booking, dateStr).map((window) => ({ booking, window })))
                .map(({ booking, window }) => ({
                    kind: 'booking',
                    booking,
                    startMinutes: toMinutes(window.start),
                    endMinutes: toMinutes(window.end) === 0 ? 24 * 60 : toMinutes(window.end),
                }));

            // Merge overlapping/adjacent windows for the SAME booking.
            const mergedByBooking = new Map();
            raw.slice()
                .sort((a, b) => a.startMinutes - b.startMinutes)
                .forEach((item) => {
                    const runs = mergedByBooking.get(item.booking) || [];
                    const last = runs[runs.length - 1];
                    if (last && item.startMinutes <= last.endMinutes) {
                        last.endMinutes = Math.max(last.endMinutes, item.endMinutes);
                    } else {
                        runs.push({ ...item });
                    }
                    mergedByBooking.set(item.booking, runs);
                });

            const blockEvents = blocks.flatMap((block) => blockWindowsForDate(block, dateStr)
                .map((window) => ({ ...window, kind: 'block' })));
            windowsByDate.set(dateStr, [
                ...[...mergedByBooking.values()].flat(),
                ...blockEvents,
            ]);
        });

        // One wrapper div that holds all rows — positions bars absolutely.
        const body = document.createElement('div');
        body.className = `court-month-body${mode === 'week' ? ' court-week-body' : ''}`;
        body.style.cssText = mode === 'week'
            ? `position:relative;display:grid;width:100%;grid-template-columns:${TIME_LABEL_W}px repeat(7,minmax(0,1fr));`
            : 'position:relative;display:flex;';

        // Time label column (sticky left).
        const timeCol = document.createElement('div');
        timeCol.className = 'court-month-time-col';
        timeCol.style.cssText = `flex:0 0 ${TIME_LABEL_W}px;width:${TIME_LABEL_W}px;position:sticky;left:0;z-index:2;background:var(--surface);border-right:1px solid var(--text-muted);`;

        rowPlan.forEach((row) => {
            const cell = document.createElement('div');
            if (row.type === 'hour') {
                cell.className = 'court-month-time-cell';
                cell.style.cssText = `height:${ROW_H}px;display:flex;align-items:flex-start;justify-content:flex-end;padding:var(--s1) 6px 0 0;border-bottom:1px solid var(--text-muted);color:var(--text-muted);font-size:${mode === 'week' ? '.6875rem' : '.75rem'};font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap;`;
                cell.textContent = formatHourLabel(row.hour);
            } else {
                cell.className = 'court-month-time-divider';
                cell.style.cssText = `height:${DIVIDER_H}px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:var(--s1);border-bottom:1px solid var(--text-muted);background:var(--surface-2);font-size:var(--font-size-small);font-weight:800;line-height:1.35;text-align:center;padding:var(--s1);`;
                cell.innerHTML = `<span style="color:var(--danger);font-style:italic;">Closes<br><span style="color:var(--text-muted);font-style:normal;font-weight:600;">${formatHourLabel(BIZ_CLOSE_HOUR)}</span></span><span style="color:var(--brand);font-style:italic;">Opens<br><span style="color:var(--text-muted);font-style:normal;font-weight:600;">${formatHourLabel(BIZ_OPEN_HOUR)}</span></span>`;
            }
            timeCol.append(cell);
        });
        body.append(timeCol);

        // Date columns.
        dates.forEach((dateStr, colIndex) => {
            const colClosures = closures.filter((c) => c.date === dateStr);
            // Also treat recurring closed weekdays (from BusinessSetting) as closures.
            const weekday = new Date(`${dateStr}T00:00:00`).getDay(); // 0=Sun…6=Sat
            const isWeekdayClosed = BIZ_CLOSED_WEEKDAYS.includes(weekday);
            const colWindows = windowsByDate.get(dateStr) || [];
            const isToday = dateStr === todayValue;

            // Closure strip overlay — manual closures + recurring closed weekdays.
            const hasAnyClosure = colClosures.length || isWeekdayClosed;

            const col = document.createElement('div');
            const pastColumn = isPastDate(dateStr);
            col.className = 'court-month-date-col' + (isToday ? ' is-today' : '') + (pastColumn ? ' is-past' : '') + (isWeekdayClosed ? ' is-closed-weekday' : '') + (hasAnyClosure ? ' is-closed' : '');
            col.dataset.date = dateStr;
            col.style.cssText = (mode === 'week' ? 'min-width:0;width:auto;' : `flex:0 0 ${COL_W}px;width:${COL_W}px;`) +
                'position:relative;box-sizing:border-box;' +
                `border-right:1px solid var(--text-muted);` + (hasAnyClosure ? `background:var(--surface-2);` : '');
            let touchCreatePending = null;

            // Hour grid lines — skipped for closed columns so the red fill
            // reads as one solid block instead of a striped grid.
            rowPlan.forEach((row) => {
                const cell = document.createElement('div');
                if (row.type === 'hour') {
                    cell.className = 'court-month-hour-cell';
                    if (pastColumn) cell.classList.add('is-past');
                    cell.dataset.date = dateStr;
                    cell.dataset.hour = String(row.hour);
                    cell.setAttribute('role', 'button');
                    cell.tabIndex = pastColumn ? -1 : 0;
                    if (pastColumn) cell.setAttribute('aria-disabled', 'true');
                    cell.setAttribute('aria-label', `${formatDate(dateStr)}, ${formatHourLabel(row.hour)}. Press Enter to add an event for one step.`);
                    cell.style.cssText = `height:${ROW_H}px;box-sizing:border-box;border-bottom:1px solid var(--text-muted);`;
                } else {
                    cell.style.cssText = `height:${DIVIDER_H}px;box-sizing:border-box;background:var(--surface-2);border-bottom:1px solid var(--text-muted);`;
                }
                col.append(cell);
            });

            if (hasAnyClosure) {
                const strip = document.createElement('div');
                strip.className = 'court-month-closure-col';
                strip.style.cssText = `position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;`;
                const closureLabels = colClosures.map((c) => c.reason || 'Closed');
                if (isWeekdayClosed) closureLabels.push('Closed (business hours)');
                strip.title = closureLabels.join(', ');

                const label = document.createElement('span');
                label.className = 'court-month-closed-label';
                label.textContent = 'CLOSED';
                label.style.cssText = `writing-mode:vertical-rl;transform:rotate(180deg);color:var(--danger);font-size:var(--font-size-h1);font-weight:800;letter-spacing:var(--s5);`;
                strip.append(label);

                col.append(strip);
            }

            // Booking bars — absolute positioned by time within the column.
            const laned = assignLanes(colWindows);
            const laneCount = laned.length ? Math.max(...laned.map((w) => w.laneCount)) : 1;
            const barW = Math.floor((COL_W - 4) / Math.max(1, laneCount));
            const laneLeft = (lane) => mode === 'week' ? `${lane * 100 / laneCount}%` : `${lane * barW + 2}px`;
            const laneWidth = mode === 'week' ? `${100 / laneCount}%` : `${barW - 2}px`;

            laned.forEach(({ kind, booking, block, startMinutes, endMinutes, lane }, index) => {
                const startHourOfWindow = Math.floor(startMinutes / 60);
                const rowTop = hourTop.has(startHourOfWindow) ? hourTop.get(startHourOfWindow) : null;

                // A window should always start inside an open hour row (the
                // business is closed between close_hour and open_hour), but
                // guard against odd data by skipping anything that doesn't
                // land in a real row rather than mis-drawing it.
                if (rowTop === null) return;

                const topPx = rowTop + ((startMinutes % 60) / 60) * ROW_H;
                const heightPx = Math.max(18, ((endMinutes - startMinutes) / 60) * ROW_H);

                if (kind === 'block') {
                    const event = document.createElement('button');
                    event.type = 'button';
                    event.className = `court-month-block type-${block.type}${block._saving ? ' is-saving' : ''}`;
                    event.dataset.calendarKind = 'block';
                    event.dataset.calendarId = String(block.id);
                    event.style.cssText = `top:${topPx}px;left:${mode === 'week' ? `calc(${laneLeft(lane)} + 2px)` : laneLeft(lane)};width:${mode === 'week' ? `calc(${laneWidth} - 4px)` : laneWidth};height:${heightPx}px;`;
                    const sessionCount = block.participant_count ?? block.attendee_count ?? block.players_count;
                    const sessionCapacity = block.capacity ?? block.max_participants ?? block.max_capacity;
                    let blockLabel = block.title || block.type.replace('_', ' ');
                    if (block.type === 'open_play' && sessionCount !== null && sessionCount !== undefined
                        && sessionCapacity !== null && sessionCapacity !== undefined
                        && Number.isFinite(Number(sessionCount)) && Number.isFinite(Number(sessionCapacity))) {
                        blockLabel = `Open play ${Number(sessionCount)}/${Number(sessionCapacity)}`;
                        if (block.is_full || block.status === 'full' || Number(sessionCount) >= Number(sessionCapacity)) blockLabel += ' · Full';
                    } else if (block.type === 'open_play' && (block.is_full || block.status === 'full')) {
                        blockLabel = `${blockLabel} · Full`;
                    }
                    event.textContent = blockLabel;
                    event.title = `${block.title || block.type} · ${block.start_time}–${block.end_time}`;
                    event.setAttribute('aria-label', `${block.title || block.type}, ${block.type.replace('_', ' ')}, ${formatDate(block.block_date)}. Open event details.`);
                    col.append(event);
                    return;
                }

                const colors = STATUS_COLORS[booking.status] || STATUS_COLORS.paid;
                const accentColor = LANE_ACCENTS[index % LANE_ACCENTS.length];
                const initials = toInitials(booking.customer || '');

                const bar = document.createElement('button');
                bar.type = 'button';
                bar.className = `court-month-bar status-${booking.status}${booking._saving ? ' is-saving' : ''}`;
                bar.dataset.calendarKind = 'booking';
                bar.dataset.calendarId = String(booking.id);
                bar.style.cssText = `
                    position: absolute;
                    top: ${topPx}px;
                    left: ${mode === 'week' ? `calc(${laneLeft(lane)} + 2px)` : laneLeft(lane)};
                    width: ${mode === 'week' ? `calc(${laneWidth} - 4px)` : laneWidth};
                    height: ${heightPx}px;
                    background: ${colors.bg};
                    border: 1px solid ${colors.border};
                    border-left: 3px solid ${accentColor};
                    border-radius: 4px;
                    color: ${colors.text};
                    font-size: var(--font-size-small);
                    font-weight: 800;
                    display: flex;
                    align-items: ${mode === 'week' ? 'stretch' : 'center'};
                    justify-content: center;
                    text-align: ${mode === 'week' ? 'left' : 'center'};
                    overflow: hidden;
                    cursor: ${itemIsPast('booking', booking) ? 'not-allowed' : 'pointer'};
                    padding: ${mode === 'week' ? 'var(--s1) var(--s2)' : '0'};
                    overflow-wrap: ${mode === 'week' ? 'anywhere' : 'normal'};
                    letter-spacing: .03em;
                `;
                if (mode === 'week') {
                    const timeText = `${formatTimeInput(startMinutes)}–${endMinutes === 1440 ? '24:00' : formatTimeInput(endMinutes)}`;
                    const courtText = booking.court || `Court ${booking.court_id}`;
                    const customerName = document.createElement('span');
                    customerName.className = 'court-week-bar-customer';
                    customerName.textContent = booking.customer || 'Booking';
                    const details = document.createElement('span');
                    details.className = 'court-week-bar-details';
                    details.textContent = `${timeText} · ${courtText} · ${booking.status}`;
                    bar.append(customerName, details);
                } else {
                    bar.textContent = booking.source === 'walk_in' ? 'Walk-in' : initials;
                }
                if (booking.source === 'walk_in') bar.classList.add('source-walk-in');
                bar.title = `${booking.source === 'walk_in' ? 'Walk-in · ' : ''}${booking.customer || 'Booking'} · ${booking.court || ''} · ${booking.status}`;
                col.append(bar);
            });

            const yForMinute = (minutes) => {
                const hour = Math.floor(minutes / 60);
                const minute = minutes % 60;
                if (hourTop.has(hour)) return hourTop.get(hour) + (minute / 60) * ROW_H;
                for (let previousHour = hour - 1; previousHour >= 0; previousHour--) {
                    if (hourTop.has(previousHour)) return hourTop.get(previousHour) + ROW_H;
                }
                return 0;
            };
            col._monthYForMinute = yForMinute;
            const showGhost = (selection) => {
                if (!selection.ghost) {
                    selection.ghost = document.createElement('div');
                    selection.ghost.className = 'court-month-selection-ghost';
                    selection.ghost.setAttribute('aria-hidden', 'true');
                    col.append(selection.ghost);
                }

                const top = yForMinute(selection.startMinutes);
                const bottom = yForMinute(selection.endMinutes);
                selection.ghost.style.top = `${top}px`;
                selection.ghost.style.height = `${Math.max(8, bottom - top)}px`;
                const overnight = BIZ_CLOSE_HOUR <= BIZ_OPEN_HOUR;
                const crossesClosedBand = overnight
                    && selection.startMinutes < BIZ_CLOSE_HOUR * 60
                    && selection.endMinutes > BIZ_OPEN_HOUR * 60;
                const outsideHours = overnight
                    ? crossesClosedBand
                    : selection.startMinutes < BIZ_OPEN_HOUR * 60 || selection.endMinutes > BIZ_CLOSE_HOUR * 60;
                const conflict = outsideHours || rangeHasConflict(
                    selection.date,
                    selection.startMinutes,
                    selection.endMinutes,
                );
                const pastTime = selection.date === venueToday && selection.startMinutes < venueNowMinutes();
                selection.ghost.classList.toggle('is-conflict', conflict || pastColumn || pastTime);
            };
            const finishPointerSelection = (selection, cancelled = false) => {
                if (selection.ghost) selection.ghost.remove();
                if (!cancelled) {
                    openCreateModal({
                        date: selection.date,
                        startMinutes: selection.startMinutes,
                        endMinutes: selection.endMinutes,
                    });
                }
            };

            col.addEventListener('pointerdown', (pointerEvent) => {
                if (pastColumn) return;
                if ((pointerEvent.pointerType === 'mouse' && pointerEvent.button !== 0)
                    || pointerEvent.target.closest('.court-month-bar, .court-month-block')) return;
                const slot = slotFromPoint(pointerEvent, col);
                if (!slot) return;

                if (pointerEvent.pointerType === 'touch') {
                    const pending = {
                        pointerId: pointerEvent.pointerId, date: dateStr, origin: slot.minutes,
                        startMinutes: slot.minutes, endMinutes: slot.minutes + stepMinutes,
                        startX: pointerEvent.clientX, startY: pointerEvent.clientY, ghost: null,
                        longPressed: false,
                    };
                    touchCreatePending = pending;
                    activeTouchCreatePending = pending;
                    pending.holdTimer = window.setTimeout(() => {
                        if (activeTouchCreatePending !== pending || touchCreatePending !== pending) return;
                        pending.longPressed = true;
                        try { col.setPointerCapture(pointerEvent.pointerId); } catch (error) { /* Capture is optional when the pointer was cancelled by a scroll gesture. */ }
                        col._courtMonthSelection = pending;
                        beginMonthDrag(pointerEvent.pointerId, targetGrid, true);
                        showGhost(pending);
                        pending.ghost?.classList.add('is-hold-armed');
                        if (typeof navigator.vibrate === 'function') navigator.vibrate(12);
                    }, 400);
                    return;
                }

                pointerEvent.preventDefault();
                col.setPointerCapture(pointerEvent.pointerId);
                beginMonthDrag(pointerEvent.pointerId);
                const selection = {
                    pointerId: pointerEvent.pointerId,
                    date: dateStr,
                    origin: slot.minutes,
                    startMinutes: slot.minutes,
                    endMinutes: slot.minutes + stepMinutes,
                    ghost: null,
                };
                col._courtMonthSelection = selection;
                showGhost(selection);
            });

            col.addEventListener('pointermove', (pointerEvent) => {
                if (touchCreatePending && touchCreatePending.pointerId === pointerEvent.pointerId
                    && !touchCreatePending.longPressed) {
                    if (Math.hypot(pointerEvent.clientX - touchCreatePending.startX, pointerEvent.clientY - touchCreatePending.startY) > 8) {
                        window.clearTimeout(touchCreatePending.holdTimer);
                        touchCreatePending = null;
                    }
                    return;
                }
                const selection = col._courtMonthSelection;
                if (!selection || selection.pointerId !== pointerEvent.pointerId) return;
                pointerEvent.preventDefault();
                lockMonthScroll();
                const slot = slotFromPoint(pointerEvent, col);
                if (!slot || slot.date !== selection.date) return;
                const endSlot = slot.minutes;
                selection.startMinutes = Math.min(selection.origin, endSlot);
                selection.endMinutes = Math.max(selection.origin, endSlot) + stepMinutes;
                showGhost(selection);
            });

            col.addEventListener('pointerup', (pointerEvent) => {
                if (touchCreatePending?.pointerId === pointerEvent.pointerId) {
                    const pending = touchCreatePending;
                    window.clearTimeout(pending.holdTimer);
                    touchCreatePending = null;
                    if (activeTouchCreatePending === pending) activeTouchCreatePending = null;
                    if (!pending.longPressed) return;
                }
                const selection = col._courtMonthSelection;
                if (!selection || selection.pointerId !== pointerEvent.pointerId) return;
                pointerEvent.preventDefault();
                const slot = slotFromPoint(pointerEvent, col);
                if (slot && slot.date === selection.date) {
                    selection.startMinutes = Math.min(selection.origin, slot.minutes);
                    selection.endMinutes = Math.max(selection.origin, slot.minutes) + stepMinutes;
                }
                col._courtMonthSelection = null;
                if (col.hasPointerCapture(pointerEvent.pointerId)) col.releasePointerCapture(pointerEvent.pointerId);
                endMonthDrag(pointerEvent.pointerId);
                finishPointerSelection(selection);
            });

            col.addEventListener('pointercancel', (pointerEvent) => {
                if (touchCreatePending?.pointerId === pointerEvent.pointerId) {
                    window.clearTimeout(touchCreatePending.holdTimer);
                    if (activeTouchCreatePending === touchCreatePending) activeTouchCreatePending = null;
                    touchCreatePending = null;
                }
                const selection = col._courtMonthSelection;
                if (!selection || selection.pointerId !== pointerEvent.pointerId) return;
                col._courtMonthSelection = null;
                if (col.hasPointerCapture(pointerEvent.pointerId)) col.releasePointerCapture(pointerEvent.pointerId);
                endMonthDrag(pointerEvent.pointerId);
                finishPointerSelection(selection, true);
            });

            col.addEventListener('lostpointercapture', (pointerEvent) => {
                if (touchCreatePending?.pointerId === pointerEvent.pointerId) {
                    window.clearTimeout(touchCreatePending.holdTimer);
                    if (activeTouchCreatePending === touchCreatePending) activeTouchCreatePending = null;
                    touchCreatePending = null;
                }
                const selection = col._courtMonthSelection;
                if (!selection || selection.pointerId !== pointerEvent.pointerId) return;
                col._courtMonthSelection = null;
                endMonthDrag(pointerEvent.pointerId);
                finishPointerSelection(selection, true);
            });

            col.querySelectorAll('.court-month-hour-cell').forEach((cell) => {
                cell.addEventListener('keydown', (keyEvent) => {
                    if (keyEvent.key !== 'Enter' && keyEvent.key !== ' ') return;
                    keyEvent.preventDefault();
                    if (pastColumn) return;
                    const startMinutes = Number(cell.dataset.hour) * 60;
                    openCreateModal({
                        date: dateStr,
                        startMinutes,
                        endMinutes: startMinutes + stepMinutes,
                    });
                });
            });

            body.append(col);
        });

        // Prominent full-width banner across the closed band — the narrow
        // per-column text is enough to align the grid, but this is what
        // actually makes the closed window jump out at a glance instead of
        // requiring the admin to notice the small sticky time-column label.
        if (dividerTop !== null) {
            const banner = document.createElement('div');
            banner.className = 'court-month-divider-banner';
            banner.style.cssText = `
                position: absolute;
                top: ${dividerTop}px;
                left: ${TIME_LABEL_W}px;
                width: ${mode === 'week' ? `calc(100% - ${TIME_LABEL_W}px)` : `${dates.length * COL_W}px`};
                height: ${DIVIDER_H}px;
                display: flex;
                align-items: center;
                justify-content: center;
                pointer-events: none;
                z-index: 1;
            `;
            banner.innerHTML = `
                <span style="
                    background: var(--surface-2);
                    border: 1px solid var(--text-muted);
                    border-radius: var(--r-md);
                    padding: var(--s2) var(--s5);
                    font-size: var(--font-size-body);
                    font-weight: 700;
                    letter-spacing: .01em;
                    white-space: nowrap;
                    color: var(--text-muted);
                "><span style="color:var(--danger);font-style:italic;font-weight:800;">Closes</span> at ${formatHourLabel(BIZ_CLOSE_HOUR)} &nbsp;·&nbsp; <span style="color:var(--brand);font-style:italic;font-weight:800;">Opens</span> at ${formatHourLabel(BIZ_OPEN_HOUR)}</span>
            `;
            body.append(banner);
        }

        nextGrid.append(body);

        let updatedOnly = Boolean(changedDates?.length && targetGrid.children.length);
        if (updatedOnly) {
            const currentHeader = targetGrid.querySelector('.court-month-header');
            const nextHeader = nextGrid.querySelector('.court-month-header');
            const currentBody = targetGrid.querySelector('.court-month-body');
            const nextBody = nextGrid.querySelector('.court-month-body');
            for (const date of new Set(changedDates)) {
                const oldHeader = Array.from(currentHeader?.querySelectorAll('[data-date]') || []).find((item) => item.dataset.date === date);
                const newHeader = Array.from(nextHeader?.querySelectorAll('[data-date]') || []).find((item) => item.dataset.date === date);
                const oldColumn = Array.from(currentBody?.querySelectorAll('.court-month-date-col') || []).find((item) => item.dataset.date === date);
                const newColumn = Array.from(nextBody?.querySelectorAll('.court-month-date-col') || []).find((item) => item.dataset.date === date);
                if (!oldHeader || !newHeader || !oldColumn || !newColumn) { updatedOnly = false; break; }
                oldHeader.replaceWith(newHeader);
                oldColumn.replaceWith(newColumn);
            }
        }
        if (!updatedOnly) targetGrid.replaceChildren(...nextGrid.childNodes);
        targetGrid.style.width = mode === 'week' ? '100%' : `${gridW}px`;

        const activeCount = new Set([...windowsByDate.values()].flat()
            .filter((event) => event.kind === 'booking').map((event) => String(event.booking.id))).size;
        if (mode === 'week') {
            const rangeLabel = fullWeekRangeLabel(dates[0], dates[dates.length - 1]);
            weekSummary.textContent = `${shortWeekRangeLabel(dates[0], dates[dates.length - 1])} · ${activeCount} active reservation${activeCount === 1 ? '' : 's'}`;
            weekSummary.setAttribute('aria-label', `${rangeLabel} · ${activeCount} active reservations`);
        } else {
            const monthLabel = baseDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            monthSummary.textContent = `${monthLabel} · ${activeCount} active reservation${activeCount === 1 ? '' : 's'}`;
        }

        const initialScrollDone = mode === 'week' ? weekInitialScrollDone : monthInitialScrollDone;
        if (!initialScrollDone) {
            if (mode === 'week') weekInitialScrollDone = true;
            else monthInitialScrollDone = true;
            const scrollArea = targetGrid.closest('.court-month-scroll');
            const todayColumn = Array.from(targetGrid.querySelectorAll('.court-month-date-col'))
                .find((column) => column.dataset.date === todayValue)
                || targetGrid.querySelector('.court-month-date-col');
            if (scrollArea) {
                requestAnimationFrame(() => {
                    const openingTop = hourTop.get(BIZ_OPEN_HOUR) || 0;
                    scrollArea.scrollTop = Math.max(0, openingTop - ROW_H);
                    if (todayColumn && mode === 'month') {
                        const availableWidth = scrollArea.clientWidth - TIME_LABEL_W;
                        scrollArea.scrollLeft = Math.max(
                            0,
                            todayColumn.offsetLeft - TIME_LABEL_W - Math.max(0, (availableWidth - COL_W) / 2),
                        );
                    }
                });
            }
        }
    };

    const renderMonth = (changedDates = null) => {
        if (!monthInput.value) return;
        const [year, month] = monthInput.value.split('-').map(Number);
        const start = `${year}-${String(month).padStart(2, '0')}-01`;
        activeCalendarGrid = monthGrid;
        renderCalendarGrid(start, new Date(year, month, 0).getDate(), 'month', monthGrid, changedDates);
    };
    const renderWeek = (changedDates = null) => {
        const chosen = isoDateIsValid(weekInput.value) ? weekInput.value : venueToday;
        weekInput.value = chosen;
        weekStart = startOfWeek(chosen);
        activeCalendarGrid = weekGrid;
        renderCalendarGrid(weekStart, 7, 'week', weekGrid, changedDates);
        weekHeading.textContent = fullWeekRangeLabel(weekStart, addDays(weekStart, 6));
        updateWeekNavigation();
    };

    function updateWeekNavigation() {
        const previous = workspace.querySelector('[data-week-nav="previous"]');
        const next = workspace.querySelector('[data-week-nav="next"]');
        const minimumStart = startOfWeek(dateInput.min || weekStart);
        const maximumStart = startOfWeek(dateInput.max || weekStart);
        if (previous) {
            previous.disabled = weekStart <= minimumStart;
            previous.title = previous.disabled ? `Available calendar begins ${formatDate(dateInput.min)}.` : 'Previous week';
        }
        if (next) {
            next.disabled = weekStart >= maximumStart;
            next.title = next.disabled ? `Available calendar ends ${formatDate(dateInput.max)}.` : 'Next week';
        }
    }

    const loadVisibleRangeThenRender = async (range, summaryElement, render) => {
        if (!rangeIsCached(range.from, range.to)) {
            summaryElement.textContent = 'Loading calendar…';
            const loaded = await loadCalendarRange(range.from, range.to);
            if (!loaded) {
                render();
                return;
            }
        }
        render();
    };
    const writeCalendarUrl = (view) => {
        const url = new URL(window.location.href);
        url.searchParams.set('view', view);
        if (view === 'week') url.searchParams.set('date', weekInput.value);
        else if (view === 'month') url.searchParams.set('date', `${monthInput.value}-01`);
        else if (view === 'board') url.searchParams.set('date', dateInput.value);
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    };
    const weekDateForStart = (startValue) => {
        const preferred = addDays(startValue, 3);
        return preferred < dateInput.min ? dateInput.min : (preferred > dateInput.max ? dateInput.max : preferred);
    };
    const selectWeekDate = (dateValue, updateUrl = true) => {
        if (!isoDateIsValid(dateValue)) return;
        weekInput.value = dateValue;
        weekStart = startOfWeek(dateValue);
        updateWeekNavigation();
        if (updateUrl) writeCalendarUrl('week');
        void loadVisibleRangeThenRender({ from: weekStart, to: addDays(weekStart, 6) }, weekSummary, renderWeek).then(schedulePoll);
    };
    const stopPolling = () => {
        if (pollTimer) window.clearTimeout(pollTimer);
        pollTimer = null;
    };
    const schedulePoll = () => {
        stopPolling();
        if (authReloadPrompted) return;
        if (document.visibilityState !== 'visible') return;
        if ((!panels.month || panels.month.hidden) && (!panels.week || panels.week.hidden)
            && (!panels.board || panels.board.hidden)) return;
        pollTimer = window.setTimeout(async () => {
            if (document.visibilityState !== 'visible') return schedulePoll();
            const range = currentVisibleRange();
            const loaded = await loadCalendarRange(range.from, range.to, { force: true });
            if (loaded) {
                if (!calendarDrag && blockModal.hidden && moveQueues.size === 0) renderCalendar();
                else calendarDataDirty = true;
            }
            schedulePoll();
        }, pollDelay);
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => {
        const view = tab.dataset.bookingsView;
        changeView(view);
        if (view === 'week') void loadVisibleRangeThenRender(currentVisibleRange(), weekSummary, renderWeek);
        if (view === 'month') void loadVisibleRangeThenRender(currentVisibleRange(), monthSummary, renderMonth);
        schedulePoll();
    }));
    dateInput.addEventListener('change', () => {
        writeCalendarUrl('board');
        void loadVisibleRangeThenRender({ from: dateInput.value, to: dateInput.value }, summary, renderBoard);
    });
    monthInput.addEventListener('change', () => {
        const range = currentVisibleRange();
        writeCalendarUrl('month');
        void loadVisibleRangeThenRender(range, monthSummary, renderMonth);
    });
    weekInput.addEventListener('change', () => selectWeekDate(weekInput.value));

    if (isoDateIsValid(requestedDate)) monthInput.value = toMonthValue(new Date(`${requestedDate}T00:00:00`));
    changeView(initialView, false);

    workspace.querySelectorAll('[data-month-nav]').forEach((button) => {
        button.addEventListener('click', () => {
            const [year, month] = monthInput.value.split('-').map(Number);
            const cursor = new Date(year, month - 1, 1);
            if (button.dataset.monthNav === 'today') {
                const today = new Date(venueToday + 'T00:00:00');
                today.setDate(1);
                cursor.setTime(today.getTime());
            }
            if (button.dataset.monthNav === 'previous') cursor.setMonth(cursor.getMonth() - 1);
            if (button.dataset.monthNav === 'next') cursor.setMonth(cursor.getMonth() + 1);
            monthInput.value = toMonthValue(cursor);
            writeCalendarUrl('month');
            const range = currentVisibleRange();
            void loadVisibleRangeThenRender(range, monthSummary, renderMonth).then(schedulePoll);
        });
    });

    workspace.querySelectorAll('[data-week-nav]').forEach((button) => button.addEventListener('click', () => {
        if (button.disabled) return;
        if (button.dataset.weekNav === 'today') {
            selectWeekDate(venueToday);
            return;
        }
        const nextStart = new Date(`${weekStart}T00:00:00`);
        nextStart.setDate(nextStart.getDate() + (button.dataset.weekNav === 'next' ? 7 : -7));
        selectWeekDate(weekDateForStart(toDateValue(nextStart)));
    }));
    workspace.querySelectorAll('[data-board-date]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = new Date(`${dateInput.value}T00:00:00`);
            if (button.dataset.boardDate === 'today') value.setTime(new Date(venueToday + 'T00:00:00').getTime());
            if (button.dataset.boardDate === 'previous') value.setDate(value.getDate() - 1);
            if (button.dataset.boardDate === 'next') value.setDate(value.getDate() + 1);
            dateInput.value = toDateValue(value);
            void loadVisibleRangeThenRender({ from: dateInput.value, to: dateInput.value }, summary, renderBoard).then(schedulePoll);
        });
    });
    document.addEventListener('visibilitychange', schedulePoll);
    blockModal.addEventListener('transitionend', schedulePoll);

    const initialToday = venueToday;
    const initialEnd = new Date(`${initialToday}T00:00:00`);
    initialEnd.setMonth(initialEnd.getMonth() + 6);
    rangeCache.push({ from: addDays(initialToday, -1), to: toDateValue(initialEnd) });
    if (initialView === 'week') void loadVisibleRangeThenRender(currentVisibleRange(), weekSummary, renderWeek);
    if (initialView === 'month') void loadVisibleRangeThenRender(currentVisibleRange(), monthSummary, renderMonth);
    if (initialView === 'board') void loadVisibleRangeThenRender(currentVisibleRange(), summary, renderBoard);
    schedulePoll();
})();
