(() => {
    const workspace = document.getElementById('bookingsWorkspace');
    const dataElement = document.getElementById('courtBoardData');
    const board = document.getElementById('courtBoard');

    if (!workspace || !dataElement || !board) return;

    const calendarUrl = workspace.dataset.calendarUrl;
    const walkInStoreUrl = workspace.dataset.walkInUrl;
    const blockStoreUrl = workspace.dataset.blockStoreUrl;
    const bookingUpdateUrlTemplate = workspace.dataset.bookingUpdateUrlTemplate;
    const blockUpdateUrlTemplate = workspace.dataset.blockUpdateUrlTemplate;
    const blockDeleteUrlTemplate = workspace.dataset.blockDeleteUrlTemplate;
    const routeWithId = (template, id) => template.replace('__ID__', encodeURIComponent(String(id)));
    const data = JSON.parse(dataElement.textContent || '{}');
    const priceRules = data.pricingRules || {};
    const priceCalculator = window.SideHouseBookingPriceCalculator;
    const permissions = data.permissions || {};
    const hasPermission = (permission) => permissions[permission] === true;
    const canManageEventType = (type) => hasPermission('calendar_events.manage')
        || (type === 'open_play' && hasPermission('calendar_events.open_play'));
    const canCreateWalkIn = () => hasPermission('bookings.create') && Boolean(walkInStoreUrl);
    const canManageBlock = (block) => Boolean(block && block.source !== 'weekly_closure' && canManageEventType(block.type));
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
    const dateKey = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const parseLocalDate = (value) => {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!match) return new Date(Number.NaN);
        const [, year, month, day] = match;
        return new Date(year, month - 1, day);
    };
    const normalizeDateKey = (value) => {
        const date = parseLocalDate(value);
        return Number.isFinite(date.getTime()) ? dateKey(date) : '';
    };
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
            const minimumBoardDate = parseLocalDate(venueToday);
            minimumBoardDate.setDate(minimumBoardDate.getDate() - 1);
            dateInput.min = dateKey(minimumBoardDate);
            const maximumBoardDate = parseLocalDate(venueToday);
            maximumBoardDate.setMonth(maximumBoardDate.getMonth() + 6);
            dateInput.max = dateKey(maximumBoardDate);
            const createDate = document.getElementById('courtBlockDate');
            if (createDate) createDate.min = venueToday;
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
    const boardRegion = document.getElementById('courtBoardRegion');
    const boardSkeleton = document.getElementById('courtBoardSkeleton');
    const boardLoadingStatus = document.getElementById('courtBoardLoadingStatus');
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
    document.addEventListener('specific-closures:updated', () => {
        const range = currentVisibleRange();
        const view = Object.keys(panels).find((key) => !panels[key].hidden);
        if (view === 'month') void loadVisibleRangeThenRender('month', range, renderMonth, { force: true });
        else if (view === 'week') void loadVisibleRangeThenRender('week', range, renderWeek, { force: true });
    });

    // ---------- Month view ----------
    const monthInput = document.getElementById('courtMonthInput');
    const monthSummary = document.getElementById('courtMonthSummary');
    const monthGrid = document.getElementById('courtMonthGrid');
    const calendarEmptyStates = new Map(['board', 'month', 'week'].map((view) => [
        view,
        workspace.querySelector(`[data-calendar-empty-state="${view}"]`),
    ]));
    const calendarErrorStates = new Map(['board', 'month', 'week'].map((view) => {
        const element = workspace.querySelector(`[data-calendar-error-state="${view}"]`);
        return [view, { element, message: element?.querySelector('[data-calendar-error-message]') }];
    }));
    const setCalendarEmpty = (view, isEmpty) => {
        const element = calendarEmptyStates.get(view);
        if (element) element.hidden = !isEmpty;
    };
    const clearCalendarError = (view) => {
        const state = calendarErrorStates.get(view);
        if (!state?.element) return;
        state.element.hidden = true;
        if (state.message) state.message.textContent = '';
    };
    const showCalendarError = (view, message) => {
        const state = calendarErrorStates.get(view);
        if (!state?.element) return;
        if (state.message) state.message.textContent = message;
        state.element.hidden = false;
    };
    const renderCalendarSafely = (view, render) => {
        setCalendarEmpty(view, false);
        try {
            render();
            clearCalendarError(view);
            return true;
        } catch (error) {
            console.error(`[bookings] ${view} calendar render failed`, error);
            showCalendarError(view, 'Calendar could not be displayed. Try again.');
            return false;
        }
    };
    const calendarLoadingStates = new Map();
    const addCalendarLoadingState = (view, container, skeleton, status) => {
        if (!container || !skeleton || !status) return;
        calendarLoadingStates.set(view, { view, container, skeleton, status, pending: 0, shownAt: 0 });
    };
    addCalendarLoadingState('board', boardRegion, boardSkeleton, boardLoadingStatus);
    [monthGrid, weekGrid].forEach((grid) => {
        const scroll = grid?.closest('.court-month-scroll');
        const skeleton = scroll?.querySelector('.court-calendar-skeleton');
        const status = scroll?.querySelector('.court-calendar-loading-status');
        addCalendarLoadingState(grid === monthGrid ? 'month' : 'week', scroll, skeleton, status);
    });
    let activeCalendarGrid = monthGrid;
    const formatDateInputValue = dateKey;
    const escapeHtml = (value) => { const el = document.createElement('span'); el.textContent = String(value ?? ''); return el.innerHTML; };
    const formatCurrency = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const startOfWeek = (dateValue) => {
        const date = parseLocalDate(dateValue);
        date.setDate(date.getDate() - date.getDay());
        return formatDateInputValue(date);
    };
    const isoDateIsValid = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false;
        const parsed = parseLocalDate(value);
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
    const openPlayFields = [...blockForm.querySelectorAll('.open-play-field')];
    const blockTitleLabel = document.getElementById('courtBlockTitle').closest('label');
    const blockNotesLabel = blockForm.querySelector('[data-block-field]');
    const walkInType = document.getElementById('courtBlockType');
    const setRequiredField = (field, required) => {
        field.required = required;
        field.setAttribute('aria-required', required ? 'true' : 'false');
        const marker = field.closest('label')?.querySelector('.required-mark');
        if (marker) marker.hidden = !required;
    };
    const updateTypeFields = () => {
        const walkIn = walkInType.value === 'walk_in';
        const openPlay = walkInType.value === 'open_play';
        walkInFields.forEach((field) => { field.hidden = !walkIn; });
        openPlayFields.forEach((field) => { field.hidden = !openPlay; });
        setRequiredField(blockForm.elements.type, true);
        setRequiredField(blockCourtSelect, walkIn);
        setRequiredField(document.getElementById('courtBlockTitle'), !walkIn);
        setRequiredField(document.getElementById('courtWalkInCustomer'), walkIn);
        setRequiredField(document.getElementById('courtWalkInContact'), false);
        setRequiredField(document.getElementById('courtBlockDate'), true);
        setRequiredField(document.getElementById('courtBlockStart'), true);
        setRequiredField(document.getElementById('courtBlockEnd'), true);
        setRequiredField(blockForm.elements.max_players, openPlay);
        setRequiredField(blockForm.elements.price_per_player, openPlay);
        setRequiredField(document.getElementById('courtWalkInAmount'), walkIn);
        setRequiredField(document.getElementById('courtWalkInPayment'), walkIn);
        blockTitleLabel.hidden = walkIn;
        blockNotesLabel.hidden = walkIn;
        document.getElementById('courtWalkInNotes').closest('label').hidden = !walkIn;
        blockCourtSelect.querySelector('option[value="all"]')?.toggleAttribute('hidden', walkIn);
        if (walkIn && blockCourtSelect.value === 'all') blockCourtSelect.value = courts.length ? String(courts[0].id) : '';
        if (walkIn) updateWalkInAmount();
    };
    const updateWalkInAmount = () => {
        if (walkInType.value !== 'walk_in') return;
        const court = courts.find((item) => String(item.id) === blockCourtSelect.value);
        const amountInput = document.getElementById('courtWalkInAmount');
        const startValue = document.getElementById('courtBlockStart').value;
        const endValue = document.getElementById('courtBlockEnd').value;
        if (!court || court.hourly_rate == null || !startValue || !endValue) {
            amountInput.value = '';
            setRequiredField(amountInput, true);
            return;
        }
        const toMinutes = (value) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
        const start = toMinutes(startValue);
        let end = toMinutes(endValue);
        if (end <= start) end += 1440;
        const step = stepMinutes;
        if ((start % step) || (end % step) || end <= start) {
            amountInput.value = '';
            setRequiredField(amountInput, true);
            return;
        }
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
        setRequiredField(amountInput, true);
    };

    const normalizeTime = (time) => String(time || '').slice(0, 5);
    const normalizeBlock = (block) => ({
        ...block,
        block_date: normalizeDateKey(block.block_date),
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
        if (panels.month && !panels.month.hidden) renderCalendarSafely('month', () => renderMonth(affectedDates));
        if (panels.week && !panels.week.hidden) renderCalendarSafely('week', () => renderWeek(affectedDates));
        if (panels.board && !panels.board.hidden) renderCalendarSafely('board', renderBoard);
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
            const requestUrl = new URL(calendarUrl, window.location.href);
            requestUrl.searchParams.set('from', from);
            requestUrl.searchParams.set('to', to);
            const response = await fetch(requestUrl.toString(), {
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
                const error = new Error(responseFailureMessage({}, `Calendar sync failed (${response.status})`, response));
                error.status = response.status;
                throw error;
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
            if (error.status === 429) notify(error.message, 'error');
            return false;
        }
    };
    const notify = (message, type = 'success') => {
        if (typeof window.showToast === 'function') window.showToast(message, type);
    };
    const confirmDragPrice = (quote, booking, { canChangeAmount = false, qrPaid = false } = {}) => new Promise((resolve) => {
        const wrap = document.createElement('div');
        wrap.className = 'court-price-confirm-backdrop';
        const showAmounts = Number.isFinite(Number(quote.old_amount)) && Number.isFinite(Number(quote.new_amount));
        const mayChooseNew = canChangeAmount && !qrPaid && showAmounts;
        wrap.innerHTML = `<section class="court-price-confirm" role="dialog" aria-modal="true" aria-labelledby="courtPriceConfirmTitle">
            <h2 id="courtPriceConfirmTitle">Confirm booking price</h2>
            <p>${escapeHtml(booking.customer || 'Booking')}</p>
            <p>Previous time: <strong>${escapeHtml(quote.old_date)} · ${escapeHtml(quote.old_start)}–${escapeHtml(quote.old_end)}</strong></p>
            <p>New time: <strong>${escapeHtml(quote.date)} · ${escapeHtml(quote.start)}–${escapeHtml(quote.end)}</strong></p>
            ${showAmounts ? `<p>Current amount: <strong>${escapeHtml(formatCurrency(quote.old_amount))}</strong></p><p>New amount: <strong>${escapeHtml(formatCurrency(quote.new_amount))}</strong></p>` : ''}
            <label class="court-price-confirm-choice"><input type="radio" name="drag-price-choice" value="keep" checked> Keep original amount</label>
            ${mayChooseNew ? '<label class="court-price-confirm-choice"><input type="radio" name="drag-price-choice" value="new"> Use new amount</label><label class="court-price-confirm-ack"><input type="checkbox"> I acknowledge this price change</label>' : ''}
            ${qrPaid ? '<p class="court-price-confirm-note">Paid by QR. The amount can’t change.</p>' : ''}
            <div class="court-price-confirm-actions"><button type="button" class="btn-ghost-danger" data-price-cancel>Cancel</button><button type="button" data-price-confirm disabled>Confirm move</button></div>
        </section>`;
        document.body.append(wrap);
        const ack = wrap.querySelector('.court-price-confirm-ack input');
        const confirm = wrap.querySelector('[data-price-confirm]');
        const choiceInputs = wrap.querySelectorAll('input[name="drag-price-choice"]');
        const updateConfirm = () => { confirm.disabled = Boolean(ack && wrap.querySelector('input[name="drag-price-choice"]:checked')?.value === 'new' && !ack.checked); };
        const finish = (value) => { wrap.remove(); document.removeEventListener('keydown', onKey); resolve(value); };
        const onKey = (event) => { if (event.key === 'Escape') { event.preventDefault(); finish(null); } };
        confirm.addEventListener('click', () => finish({ decision: wrap.querySelector('input[name="drag-price-choice"]:checked').value }));
        choiceInputs.forEach((input) => input.addEventListener('change', updateConfirm));
        ack?.addEventListener('change', updateConfirm);
        wrap.querySelector('[data-price-cancel]').addEventListener('click', () => finish(null));
        wrap.addEventListener('click', (event) => { if (event.target === wrap) finish(null); });
        document.addEventListener('keydown', onKey);
        updateConfirm();
        confirm.focus();
    });
    const openMoveConfirmModal = ({
        title, label, previous, target, amount, estimate, notes = [], hasPrice = true,
        quoteRequired = true, canChangeAmount = false, qrPaid = false,
    }) => {
        const wrap = document.createElement('div');
        wrap.className = 'court-price-confirm-backdrop';
        const showEstimate = estimate !== null && estimate !== undefined && Number.isFinite(Number(estimate));
        const groupName = `move-price-${Math.random().toString(36).slice(2)}`;
        wrap.innerHTML = `<section class="court-price-confirm" role="dialog" aria-modal="true" aria-labelledby="courtMoveConfirmTitle">
            <h2 id="courtMoveConfirmTitle">${escapeHtml(title)}</h2>
            <p>${escapeHtml(label)}</p>
            <p>Previous time: <strong>${escapeHtml(previous.date)} · ${escapeHtml(previous.start)}–${escapeHtml(previous.end)}</strong></p>
            <p>New time: <strong>${escapeHtml(target.date)} · ${escapeHtml(target.start)}–${escapeHtml(target.end)}</strong></p>
            ${hasPrice ? `<p>Current amount: <strong>${escapeHtml(formatCurrency(amount))}</strong></p>
                <p class="court-price-confirm-new-amount">New amount: <strong data-move-new-amount>${showEstimate ? escapeHtml(formatCurrency(estimate)) : '<span class="court-price-confirm-spinner" aria-hidden="true"></span> Checking…'}</strong>
                <small data-move-price-status>${showEstimate ? 'Estimated · checking with server' : 'Checking with server'}</small></p>
                <div class="court-price-confirm-choices" data-move-price-choices hidden>
                    <label class="court-price-confirm-choice"><input type="radio" name="${groupName}" value="keep" checked> Keep original amount</label>
                    <label class="court-price-confirm-choice" data-move-new-choice hidden><input type="radio" name="${groupName}" value="new"> Use new amount</label>
                    <label class="court-price-confirm-ack" data-move-price-ack hidden><input type="checkbox"> I acknowledge this price change</label>
                </div>
                ${qrPaid ? '<p class="court-price-confirm-note">Paid by QR. The amount can’t change.</p>' : ''}` : ''}
            ${notes.map((note) => `<p class="court-price-confirm-note">${escapeHtml(note)}</p>`).join('')}
            <p class="court-price-confirm-error" data-move-error role="alert" hidden></p>
            <div class="court-price-confirm-actions">
                <button type="button" class="btn-ghost-danger" data-move-cancel>Cancel</button>
                <button type="button" data-move-confirm disabled>Confirm move</button>
            </div>
            <button type="button" class="court-price-confirm-retry" data-move-retry hidden>Retry price check</button>
        </section>`;
        document.body.append(wrap);

        let resolveModal;
        let retryResolver = null;
        let retryHandler = null;
        let settled = false;
        let quoteReady = !quoteRequired;
        let activeQuote = null;
        const promise = new Promise((resolve) => { resolveModal = resolve; });
        const confirm = wrap.querySelector('[data-move-confirm]');
        const choices = wrap.querySelector('[data-move-price-choices]');
        const newChoice = wrap.querySelector('[data-move-new-choice]');
        const ackLabel = wrap.querySelector('[data-move-price-ack]');
        const ack = ackLabel?.querySelector('input');
        const errorMessage = wrap.querySelector('[data-move-error]');
        const retryButton = wrap.querySelector('[data-move-retry]');
        const updateConfirm = () => {
            const useNew = wrap.querySelector(`input[name="${groupName}"]:checked`)?.value === 'new';
            confirm.disabled = !quoteReady || Boolean(useNew && ack && !ack.checked);
        };
        const finish = (value) => {
            if (settled) return;
            settled = true;
            wrap.remove();
            document.removeEventListener('keydown', onKey);
            retryResolver?.(false);
            retryResolver = null;
            resolveModal(value);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                finish(null);
            }
        };
        confirm.addEventListener('click', () => {
            if (!quoteReady) return;
            finish({
                decision: wrap.querySelector(`input[name="${groupName}"]:checked`)?.value || 'keep',
                quote: activeQuote,
            });
        });
        wrap.querySelectorAll(`input[name="${groupName}"]`).forEach((input) => input.addEventListener('change', updateConfirm));
        ack?.addEventListener('change', updateConfirm);
        wrap.querySelector('[data-move-cancel]').addEventListener('click', () => finish(null));
        wrap.addEventListener('click', (event) => { if (event.target === wrap) finish(null); });
        retryButton.addEventListener('click', () => {
            if (!retryHandler || settled) return;
            retryHandler();
            retryResolver?.(true);
            retryResolver = null;
        });
        document.addEventListener('keydown', onKey);
        updateConfirm();
        wrap.querySelector('[data-move-cancel]').focus();

        const modal = {
            promise,
            setLoading() {
                quoteReady = false;
                errorMessage.hidden = true;
                retryButton.hidden = true;
                const status = wrap.querySelector('[data-move-price-status]');
                if (status) status.textContent = 'Checking with server';
                updateConfirm();
            },
            setQuote(quote) {
                activeQuote = quote;
                quoteReady = true;
                if (hasPrice) {
                    wrap.querySelector('[data-move-new-amount]').textContent = formatCurrency(quote.new_amount);
                    wrap.querySelector('[data-move-price-status]').textContent = 'Server quote';
                    const changed = Boolean(quote.amount_changed);
                    choices.hidden = !changed;
                    if (changed) {
                        const mayChooseNew = canChangeAmount && !qrPaid && quote.can_change_amount !== false;
                        newChoice.hidden = !mayChooseNew;
                        ackLabel.hidden = !mayChooseNew;
                        if (!mayChooseNew) ack.checked = false;
                    }
                }
                updateConfirm();
            },
            setError(message) {
                quoteReady = false;
                errorMessage.textContent = message;
                errorMessage.hidden = false;
                retryButton.hidden = false;
                const status = wrap.querySelector('[data-move-price-status]');
                if (status) status.textContent = 'Price check failed';
                updateConfirm();
            },
            setRetryHandler(handler) { retryHandler = handler; },
            waitForRetry() {
                return new Promise((resolve) => { retryResolver = resolve; });
            },
            autoConfirm() {
                finish({ decision: 'keep', quote: activeQuote, automatic: true });
            },
            dismiss() { finish(null); },
        };
        return modal;
    };
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const itemPosition = (kind, item) => ({
        date: kind === 'block' ? item.block_date : item.date,
        start: normalizeTime(kind === 'block' ? item.start_time : item.start),
        end: normalizeTime(kind === 'block' ? item.end_time : item.end),
        court_id: item.court_id ?? null,
    });
    const itemCalendarDates = (kind, item) => {
        const date = kind === 'block' ? item.block_date : item.date;
        const start = toMinutes(kind === 'block' ? item.start_time : item.start);
        const end = toMinutes(kind === 'block' ? item.end_time : item.end);
        const dates = kind === 'booking' && item.slots?.length
            ? item.slots.map((slot) => slot.date)
            : [date];
        if (end <= start) dates.push(addDays(date, 1));
        return [...new Set(dates.filter(Boolean))];
    };
    const samePosition = (a, b) => a && b && a.date === b.date && a.start === b.start && a.end === b.end
        && String(a.court_id ?? '') === String(b.court_id ?? '');
    const moveList = (kind) => kind === 'block' ? blocks : bookings;
    const isMovableCalendarItem = (kind, item) => kind === 'booking'
        ? !['cancelled', 'expired'].includes(item?.status)
        : Boolean(item && item.source !== 'weekly_closure' && item.type === 'open_play');
    const applyMovedBookings = (movedBookings = []) => {
        const changedDates = [];
        movedBookings.forEach((moved) => {
            const index = bookings.findIndex((booking) => String(booking.id) === String(moved.id));
            if (index < 0) return;
            const current = bookings[index];
            changedDates.push(current.date, moved.date);
            bookings[index] = {
                ...current, court_id: moved.court_id, date: moved.date,
                start: moved.start, end: moved.end,
                slots: moved.slots?.map((slot, slotIndex) => ({ ...current.slots?.[slotIndex], ...slot })) ?? current.slots,
                updated_at: moved.updated_at,
            };
            delete bookings[index]._saving;
        });
        return changedDates.filter(Boolean);
    };
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
    const shiftLocalDateTime = (date, minutes) => {
        const dayOffset = Math.floor(minutes / 1440);
        const shiftedDate = parseLocalDate(date);
        shiftedDate.setDate(shiftedDate.getDate() + dayOffset);
        return { date: dateKey(shiftedDate), time: formatTimeInput(minutes) };
    };
    const shiftScheduleByMinutes = (date, start, end, minutes) => {
        const startMinutes = toMinutes(start);
        let endMinutes = toMinutes(end);
        if (endMinutes <= startMinutes) endMinutes += 1440;
        const shiftedStart = shiftLocalDateTime(date, startMinutes + minutes);
        const shiftedEnd = shiftLocalDateTime(date, endMinutes + minutes);
        return { date: shiftedStart.date, start: shiftedStart.time, end: shiftedEnd.time };
    };
    const shiftBookingByMinutes = (booking, minutes) => ({
        ...booking,
        ...shiftScheduleByMinutes(booking.date, booking.start, booking.end, minutes),
        slots: (booking.slots || []).map((slot) => ({
            ...slot,
            ...shiftScheduleByMinutes(slot.date, slot.start, slot.end, minutes),
        })),
    });
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
        (queue.relatedBookingKeys || []).forEach((key) => {
            protectedItems.delete(`booking:${key}`);
            const relatedIndex = bookings.findIndex((item) => String(item.id) === String(key));
            if (relatedIndex >= 0) delete bookings[relatedIndex]._saving;
        });
        if (!skipRender || deferredChanged || calendarDataDirty) {
            affectedCalendarDates = queue.changedDates || null;
            renderCalendar();
        }
    };
    const requestFailureMessage = (error) => {
        if (error?.name === 'AbortError' || error instanceof TypeError || navigator.onLine === false) {
            return 'No connection or the request timed out. Check your connection and try again.';
        }
        return error?.message || 'Could not move this calendar item.';
    };
    const responseFailureMessage = (result, fallback, response = null) => {
        if (response?.status === 429) {
            const retryAfter = Number(response.headers.get('Retry-After'));
            const seconds = Number.isFinite(retryAfter) && retryAfter > 0 ? Math.ceil(retryAfter) : 60;
            return `Too many changes. Try again in ${seconds} seconds.`;
        }
        return result.message
            || Object.values(result.errors || {}).flat().find((message) => typeof message === 'string')
            || fallback;
    };
    const pumpMoveQueue = async (queue) => {
        if (moveQueues.get(queue.key) !== queue || queue.inFlight || !queue.wanted) return;
        if (!moveItemExists(queue)) { finishMoveQueue(queue, false); return; }
        const token = csrfToken();
        if (!token) {
            const list = moveList(queue.kind), index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) list[index] = { ...queue.confirmed };
            (queue.relatedBookings || []).forEach(({ id, original }) => {
                const relatedIndex = bookings.findIndex((item) => String(item.id) === String(id));
                if (relatedIndex >= 0) bookings[relatedIndex] = { ...original };
            });
            finishMoveQueue(queue);
            notify('Your security token is missing. Reload the page and try again.', 'error');
            return;
        }

        const sent = { ...queue.wanted, position: { ...queue.wanted.position }, payload: { ...queue.wanted.payload } };
        queue.inFlight = sent;
        const url = routeWithId(
            queue.kind === 'block' ? blockUpdateUrlTemplate : bookingUpdateUrlTemplate,
            queue.id,
        );
        const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token };
        const isCurrent = () => moveQueues.get(queue.key) === queue
            && queue.wanted?.sequence === sent.sequence && samePosition(queue.wanted.position, sent.position);
        const fetchWithTimeout = async (options, controllerKey) => {
            const controller = new AbortController();
            const timer = window.setTimeout(() => controller.abort(), 60000);
            queue[controllerKey] = controller;
            try {
                return await fetch(url, { ...options, signal: controller.signal });
            } finally {
                window.clearTimeout(timer);
                if (queue[controllerKey] === controller) queue[controllerKey] = null;
            }
        };
        const rollback = (message) => {
            if (moveQueues.get(queue.key) !== queue) return;
            queue.quoteController?.abort();
            const list = moveList(queue.kind);
            const index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) list[index] = { ...queue.confirmed };
            (queue.relatedBookings || []).forEach(({ id, original }) => {
                const relatedIndex = bookings.findIndex((item) => String(item.id) === String(id));
                if (relatedIndex >= 0) bookings[relatedIndex] = { ...original };
            });
            finishMoveQueue(queue, false);
            notify(message, 'error');
        };
        const setSavingState = (saving) => {
            const list = moveList(queue.kind);
            const index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) {
                list[index] = { ...list[index] };
                if (saving) list[index]._saving = true;
                else delete list[index]._saving;
            }
            (queue.relatedBookingKeys || []).forEach((key) => {
                const relatedIndex = bookings.findIndex((item) => String(item.id) === String(key));
                if (relatedIndex >= 0) {
                    bookings[relatedIndex] = { ...bookings[relatedIndex] };
                    if (saving) bookings[relatedIndex]._saving = true;
                    else delete bookings[relatedIndex]._saving;
                }
            });
            affectedCalendarDates = queue.changedDates;
            renderCalendar();
        };
        const markSaving = () => setSavingState(true);
        const saveBody = (payload) => ({
            ...payload,
            expected_updated_at: queue.version,
            client_token: { itemKey: queue.key, sequence: sent.sequence, targetPosition: sent.position },
        });
        const save = async (payload) => {
            const response = await fetchWithTimeout({
                method: 'PATCH', credentials: 'same-origin', headers,
                body: JSON.stringify(saveBody(payload)),
            }, 'saveController');
            return { response, result: await response.json().catch(() => ({})) };
        };

        let modal = null;
        let quote = null;
        let priceDecision = 'keep';
        let savePayload = { ...sent.payload };
        let skipConfirmation = false;
        let lastQuoteError = '';

        try {
            if (queue.kind === 'booking') {
                const current = queue.confirmed;
                const target = {
                    court_id: current.court_id, date: sent.payload.date,
                    start: sent.payload.start_time, end: sent.payload.end_time,
                };
                const canChangeAmount = hasPermission('bookings.create');
                const localAmount = canChangeAmount && priceCalculator
                    ? priceCalculator.calculateMove(priceRules, current, target) : null;
                const amountChanged = localAmount !== null
                    && Math.abs(localAmount - Number(current.amount)) >= 0.005;
                const notes = [];
                if (current.status === 'paid' && itemIsPast('booking', current)) {
                    notes.push("This paid booking is in the past. Moving it won't change its payment status.");
                }
                const needsConfirmation = notes.length > 0 || localAmount === null || amountChanged;
                skipConfirmation = !needsConfirmation;
                if (!needsConfirmation) {
                    savePayload.price_decision = 'keep';
                } else {
                    modal = openMoveConfirmModal({
                        title: 'Confirm booking move',
                        label: current.customer || 'Booking',
                        previous: itemPosition('booking', current),
                        target,
                        amount: current.amount,
                        estimate: localAmount,
                        notes,
                        quoteRequired: true,
                        canChangeAmount,
                        qrPaid: Boolean(current.qr_paid),
                    });
                    modal.setRetryHandler(() => {});
                    const checkQuote = async () => {
                        while (isCurrent()) {
                            modal.setLoading();
                            try {
                                const response = await fetchWithTimeout({
                                    method: 'PATCH', credentials: 'same-origin', headers,
                                    body: JSON.stringify({
                                        ...sent.payload, expected_updated_at: queue.version, dry_run: true,
                                    }),
                                }, 'quoteController');
                                const result = await response.json().catch(() => ({}));
                                if (moveQueues.get(queue.key) !== queue || !isCurrent()) return;
                                if (!response.ok || !result.quote) {
                                    const error = new Error(responseFailureMessage(result, 'Could not check the new booking price.', response));
                                    error.status = response.status;
                                    throw error;
                                }
                                quote = { ...result.quote, can_change_amount: Boolean(result.quote.can_change_amount) };
                                modal.setQuote(quote);
                                if (notes.length === 0 && !quote.amount_changed) modal.autoConfirm();
                                return;
                            } catch (error) {
                                if (moveQueues.get(queue.key) !== queue || !isCurrent()) return;
                                lastQuoteError = requestFailureMessage(error);
                                if (error.status === 429) {
                                    modal.dismiss();
                                    rollback(lastQuoteError);
                                    return;
                                }
                                modal.setError(lastQuoteError);
                                if (!await modal.waitForRetry()) return;
                            }
                        }
                    };
                    void checkQuote();
                    const choice = await modal.promise;
                    if (!choice) {
                        rollback(lastQuoteError || 'Move cancelled. The original schedule was restored.');
                        return;
                    }
                    quote = choice.quote;
                    priceDecision = choice.decision || 'keep';
                    if (!quote || !isCurrent()) {
                        rollback('The booking quote is no longer current. The original schedule was restored.');
                        return;
                    }
                    savePayload.price_decision = priceDecision;
                    savePayload.acknowledge_price_change = priceDecision === 'new';
                    if (priceDecision === 'new') {
                        savePayload.expected_amount = Number(quote.new_amount);
                        savePayload.price_rules_version = quote.rules_version || priceRules.version;
                    }
                }
            } else if (queue.confirmationNotes?.length) {
                modal = openMoveConfirmModal({
                    title: 'Confirm calendar move',
                    label: queue.confirmationLabel || 'Open Play',
                    previous: itemPosition('block', queue.confirmed),
                    target: itemPosition('block', moveList('block').find((item) => String(item.id) === String(queue.id))),
                    notes: queue.confirmationNotes,
                    hasPrice: false,
                    quoteRequired: false,
                });
                const choice = await modal.promise;
                if (!choice) {
                    rollback('Move cancelled. The event and joined bookings were restored.');
                    return;
                }
            }

            if (!isCurrent()) { finishMoveQueue(queue, false); return; }
            markSaving();
            let saved = await save(savePayload);

            if (saved.response.status === 409 && saved.result.quote && skipConfirmation && isCurrent()) {
                setSavingState(false);
                const freshQuote = {
                    ...saved.result.quote,
                    old_date: queue.confirmed.date,
                    old_start: queue.confirmed.start,
                    old_end: queue.confirmed.end,
                    date: sent.position.date,
                    start: sent.position.start,
                    end: sent.position.end,
                    can_change_amount: Boolean(saved.result.quote.can_change_amount),
                    qr_paid: Boolean(queue.confirmed.qr_paid),
                };
                modal = openMoveConfirmModal({
                    title: 'Confirm booking move',
                    label: queue.confirmed.customer || 'Booking',
                    previous: itemPosition('booking', queue.confirmed),
                    target: sent.position,
                    amount: queue.confirmed.amount,
                    estimate: null,
                    quoteRequired: false,
                    canChangeAmount: hasPermission('bookings.create'),
                    qrPaid: Boolean(queue.confirmed.qr_paid),
                });
                modal.setQuote(freshQuote);
                const choice = await modal.promise;
                if (!choice) {
                    rollback('Move cancelled. The original schedule was restored.');
                    return;
                }
                priceDecision = choice.decision || 'keep';
                savePayload = { ...sent.payload, price_decision: priceDecision,
                    acknowledge_price_change: priceDecision === 'new' };
                if (priceDecision === 'new') {
                    savePayload.expected_amount = Number(freshQuote.new_amount);
                    savePayload.price_rules_version = freshQuote.rules_version || priceRules.version;
                }
                markSaving();
                saved = await save(savePayload);
            }

            if (!isCurrent()) { finishMoveQueue(queue, false); return; }
            if (!saved.response.ok) {
                const staleMessage = saved.response.status === 409
                    ? 'This calendar item changed on the server. Reload the board and try again.'
                    : 'The server rejected this calendar move.';
                rollback(responseFailureMessage(saved.result, staleMessage, saved.response));
                return;
            }

            let serverItem = queue.kind === 'block'
                ? (saved.result.block && normalizeBlock(saved.result.block))
                : saved.result.booking;
            if (queue.kind === 'block' && serverItem) {
                serverItem = {
                    ...serverItem,
                    participants: queue.confirmed.participants,
                    participant_count: serverItem.participant_count ?? queue.confirmed.participant_count,
                };
            }
            if (!serverItem || !serverItem.updated_at) {
                throw new Error('The server response did not include the updated calendar item.');
            }
            if (queue.kind === 'block') {
                queue.changedDates.push(...applyMovedBookings(saved.result.moved_bookings));
            }
            queue.changedDates.push(itemPosition(queue.kind, serverItem).date);
            if (queue.kind === 'booking') {
                queue.changedDates.push(...(serverItem.slots || []).map((slot) => slot.date));
            }
            const list = moveList(queue.kind);
            const index = list.findIndex((item) => String(item.id) === String(queue.id));
            if (index >= 0) list[index] = serverItem;
            queue.confirmed = serverItem;
            queue.version = serverItem.updated_at;
            queue.inFlight = null;
            notify(saved.result.message || 'Calendar item moved.', 'success');
            finishMoveQueue(queue);
        } catch (error) {
            if (moveQueues.get(queue.key) !== queue || !moveItemExists(queue)) {
                finishMoveQueue(queue, false);
                return;
            }
            rollback(requestFailureMessage(error));
        }
    };
    const formatTimeInput = (minutes) => {
        const wrapped = ((minutes % 1440) + 1440) % 1440;
        return `${String(Math.floor(wrapped / 60)).padStart(2, '0')}:${String(wrapped % 60).padStart(2, '0')}`;
    };
    const addDays = (dateValue, count) => {
        const date = parseLocalDate(dateValue);
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
        if (!hasPermission('calendar_events.manage') && !hasPermission('calendar_events.open_play') && !canCreateWalkIn()) return;
        if (isPastDate(selection.date)) return;
        selectedBlock = null;
        selectedBooking = null;
        populateCourtSelect();
        blockModal.classList.remove('is-details');
        blockModal.querySelector('[role="dialog"]').setAttribute('aria-labelledby', 'courtBlockModalTitle');
        blockForm.reset();
        blockForm.querySelectorAll('[data-event-permission]').forEach((option) => {
            const allowed = canManageEventType(option.value);
            option.hidden = !allowed;
            option.disabled = !allowed;
        });
        const walkInOption = walkInType.querySelector('option[value="walk_in"]');
        if (walkInOption) {
            walkInOption.hidden = !canCreateWalkIn();
            walkInOption.disabled = !canCreateWalkIn();
        }
        walkInType.value = hasPermission('calendar_events.manage') ? 'closed'
            : (hasPermission('calendar_events.open_play') ? 'open_play' : 'walk_in');
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
        updateWalkInAmount();
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
        const editEventButton = document.getElementById('courtBlockEditOpen');
        editEventButton.hidden = !canManageBlock(block);
        editEventButton.disabled = !canManageBlock(block);
        const deleteEventButton = document.getElementById('courtBlockDelete');
        deleteEventButton.hidden = !canManageBlock(block);
        deleteEventButton.disabled = !canManageBlock(block);
        const court = courts.find((item) => Number(item.id) === Number(block.court_id));
        const courtLabel = block.court_id === null ? 'All courts' : (court?.name || 'Court');
        document.getElementById('courtBlockDetailsMeta').textContent =
            `${block.type.replace('_', ' ')} · ${courtLabel} · ${formatDate(block.block_date)} · ${block.start_time}–${block.end_time}`;
        if (block.type === 'open_play') {
            const joined = Math.max(0, Number(block.participant_count ?? 0));
            const maxPlayers = Math.max(0, Number(block.max_players ?? 0));
            const spotsLeft = Math.max(0, maxPlayers - joined);
            const capacityText = block.max_players == null
                ? 'Capacity not set'
                : joined >= maxPlayers ? 'Full' : `${spotsLeft} of ${maxPlayers} spots left`;
            const participants = Array.isArray(block.participants) ? block.participants : [];
            const listCount = participants.length;
            document.getElementById('courtBlockDetailsMeta').textContent +=
                ` · ${listCount} joined · ${capacityText}`;

            const playersSection = document.getElementById('courtBlockPlayersSection');
            const playersTitle = document.getElementById('courtBlockPlayersTitle');
            const playersList = document.getElementById('courtBlockPlayersList');
            const playersEmpty = document.getElementById('courtBlockPlayersEmpty');
            playersSection.hidden = false;
            playersTitle.replaceChildren();
            playersTitle.insertAdjacentHTML('beforeend', '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>');
            const count = listCount;
            playersTitle.append(document.createTextNode(`Players (${count})`));
            playersList.replaceChildren();
            playersEmpty.textContent = '';
            playersEmpty.hidden = true;
            if (count === 0) {
                playersEmpty.textContent = 'No players have joined yet.';
                playersEmpty.hidden = false;
            } else {
                participants.forEach((participant, index) => {
                    const row = document.createElement('li');
                    const number = document.createElement('span');
                    number.className = 'court-block-player-number';
                    number.textContent = String(index + 1);
                    const name = document.createElement('span');
                    name.className = 'court-block-player-name';
                    name.textContent = participant.name || 'Player';
                    const detail = document.createElement('span');
                    detail.className = 'court-block-player-detail';
                    detail.textContent = participant.joined_time || participant.status || '';
                    row.append(number, name, detail);
                    playersList.append(row);
                });
            }
        } else {
            document.getElementById('courtBlockPlayersSection').hidden = true;
        }
        const notes = document.getElementById('courtBlockDetailsNotes');
        notes.textContent = block.notes || '';
        notes.hidden = !block.notes;
        blockModal.hidden = false;
        document.getElementById('courtBlockDetailsClose').focus();
    };

    const openBlockEdit = () => {
        if (!selectedBlock || !canManageBlock(selectedBlock)) return;
        selectedBlock = blocks.find((item) => String(item.id) === String(selectedBlock.id)) || selectedBlock;
        const form = document.getElementById('courtBlockEditForm');
        form.querySelectorAll('[data-event-permission]').forEach((option) => {
            const allowed = canManageEventType(option.value);
            option.hidden = !allowed;
            option.disabled = !allowed;
        });
        fillCourtOptions(form.elements.court_id, true);
        form.elements.type.value = selectedBlock.type;
        form.elements.court_id.value = selectedBlock.court_id === null ? 'all' : String(selectedBlock.court_id);
        form.elements.title.value = selectedBlock.title || '';
        form.elements.notes.value = selectedBlock.notes || '';
        form.elements.block_date.value = selectedBlock.block_date;
        form.elements.start_time.value = selectedBlock.start_time;
        form.elements.end_time.value = selectedBlock.end_time;
        form.elements.max_players.value = selectedBlock.max_players ?? '';
        form.elements.price_per_player.value = selectedBlock.price_per_player ?? '';
        updateBlockEditRequirements();
        const past = itemIsPast('block', selectedBlock);
        const movable = isMovableCalendarItem('block', selectedBlock);
        [form.elements.block_date, form.elements.start_time, form.elements.end_time, form.elements.court_id]
            .forEach((field) => { field.disabled = !movable; });
        if (movable) form.elements.block_date.removeAttribute('min');
        else form.elements.block_date.min = venueToday;
        form.elements.type.disabled = past;
        form.elements.title.disabled = past;
        form.elements.notes.disabled = false;
        form.querySelector('[type="submit"]').disabled = past && !movable;
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
        const editBookingButton = document.getElementById('courtBookingEditOpen');
        editBookingButton.hidden = !hasPermission('bookings.edit');
        editBookingButton.disabled = !hasPermission('bookings.edit');
        showModalPanel(panel, 'courtBookingDetailsTitle');
        blockModal.hidden = false;
        document.getElementById('courtBookingDetailsClose').focus();
    };

    const openBookingEdit = () => {
        if (!selectedBooking || !hasPermission('bookings.edit')) return;
        const booking = bookings.find((item) => String(item.id) === String(selectedBooking.id)) || selectedBooking;
        selectedBooking = booking;
        const form = document.getElementById('courtBookingEditForm');
        fillCourtOptions(form.elements.court_id);
        form.elements.customer_name.value = booking.customer || '';
        form.elements.contact_number.value = booking.contact_number || '';
        setRequiredField(form.elements.contact_number, booking.source !== 'walk_in');
        form.querySelector('.contact-optional-note').hidden = booking.source !== 'walk_in';
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
        ['court_id', 'date'].forEach((name) => { form.elements[name].disabled = false; });
        form.elements.amount.disabled = past;
        form.elements.date.removeAttribute('min');
        bookingStartInput.disabled = false;
        bookingEndInput.disabled = false;
        durationControl.hidden = past;
        form.querySelector('[data-past-edit-note]').hidden = !past;
        form.querySelector('[type="submit"]').disabled = false;
        validateCalendarForm(form);
        form.elements.acknowledge_price_change.checked = false;
        form.querySelector('.court-booking-price-ack-field').hidden = !['paid', 'confirmed'].includes(booking.status);
        updatePriceAcknowledgementVisibility();
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
        const selectedType = walkInType.value;
        if ((selectedType === 'walk_in' && !canCreateWalkIn())
            || (selectedType !== 'walk_in' && !canManageEventType(selectedType))) return;
        if (!validateCalendarForm(blockForm)) return;
        if (savingBlock) return;

        const formData = new FormData(blockForm);
        const payload = Object.fromEntries(formData.entries());
        const isWalkIn = payload.type === 'walk_in';
        const storeUrl = isWalkIn ? walkInStoreUrl : blockStoreUrl;
        if (!storeUrl) {
            blockFormMessage.textContent = 'This event type is not available for your role.';
            blockFormMessage.classList.add('is-error');
            return;
        }
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
            payload.max_players = payload.type === 'open_play' ? Number(payload.max_players) : null;
            payload.price_per_player = payload.type === 'open_play' ? Number(payload.price_per_player) : null;
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
            const response = await fetch(storeUrl, {
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
        const oldEndAbsolute = toMinutes(selectedBooking.start) + oldDuration;
        bookingStartInput.value = selectedBooking.start;
        bookingEndInput.value = formatTimeInput(toMinutes(selectedBooking.start) + duration);
        durationValue.textContent = `${duration / 60} hr`;
        durationControl.dataset.adjustMinutes = String(minutes);
        durationPreview.textContent = `New end ${bookingEndInput.value} · ${formatCurrency(bookingEditForm.elements.amount.value)}`;
        durationWarning.hidden = true;
        if (minutes > 0) {
            const proposedEnd = toMinutes(selectedBooking.start) + duration;
            const dateOffset = (slotDate) => Math.round((parseLocalDate(slotDate) - parseLocalDate(bookingEditForm.elements.date.value)) / 86400000) * 1440;
            const hasOverlap = bookings.some((other) => String(other.id) !== String(selectedBooking.id)
                && Number(other.court_id) === Number(bookingEditForm.elements.court_id.value)
                && (other.slots || []).some((slot) => {
                    const offset = dateOffset(slot.date || other.date);
                    const slotStart = offset + toMinutes(slot.start);
                    const slotEnd = offset + (toMinutes(slot.end) || 1440);
                    return slotStart < proposedEnd && slotEnd > oldEndAbsolute;
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
    let currentEditQuote = null;
    const editTarget = () => ({ court_id: Number(bookingEditForm.elements.court_id.value), date: bookingEditForm.elements.date.value, start: bookingStartInput.value, end: bookingEndInput.value });
    const loadEditQuote = async () => {
        if (!selectedBooking) return null;
        const target = editTarget();
        const localAmount = hasPermission('bookings.create') && priceCalculator ? priceCalculator.calculateMove(priceRules, selectedBooking, target) : null;
        if (localAmount !== null) return { old_amount: Number(selectedBooking.amount), new_amount: localAmount, amount_changed: Math.abs(localAmount - Number(selectedBooking.amount)) >= 0.005, old_date: selectedBooking.date, old_start: selectedBooking.start, old_end: selectedBooking.end, date: target.date, start: target.start, end: target.end, rules_version: priceRules.version, can_change_amount: true, qr_paid: Boolean(selectedBooking.qr_paid) };
        const token = csrfToken();
        if (!token) return null;
        const selectedId = String(selectedBooking.id);
        const response = await fetch(routeWithId(bookingUpdateUrlTemplate, selectedId), { method: 'PATCH', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ date: target.date, court_id: target.court_id, start_time: target.start, end_time: target.end, ...(Number(durationControl.dataset.adjustMinutes || 0) ? { adjust_minutes: Number(durationControl.dataset.adjustMinutes) } : {}), dry_run: true, expected_updated_at: selectedBooking.updated_at }) });
        const result = await response.json().catch(() => ({}));
        if (String(selectedBooking?.id) !== selectedId) return null;
        if (!response.ok || !result.quote) {
            const error = new Error(responseFailureMessage(result, 'Could not check the new booking price.', response));
            error.status = response.status;
            throw error;
        }
        return result.quote;
    };
    const refreshEditQuote = () => {
        clearTimeout(editQuoteTimer);
        if (!selectedBooking) return;
        const target = editTarget();
        const localAmount = hasPermission('bookings.create') && priceCalculator ? priceCalculator.calculateMove(priceRules, selectedBooking, target) : null;
        if (localAmount !== null) {
            currentEditQuote = { old_amount: Number(selectedBooking.amount), new_amount: localAmount, amount_changed: Math.abs(localAmount - Number(selectedBooking.amount)) >= 0.005, old_date: selectedBooking.date, old_start: selectedBooking.start, old_end: selectedBooking.end, date: target.date, start: target.start, end: target.end, rules_version: priceRules.version, can_change_amount: true, qr_paid: Boolean(selectedBooking.qr_paid) };
            durationPreview.textContent = `New end ${target.end} · ${formatCurrency(localAmount)}`;
            return;
        }
        editQuoteTimer = setTimeout(async () => {
            try { currentEditQuote = await loadEditQuote(); if (currentEditQuote) durationPreview.textContent = `New end ${currentEditQuote.end} · ${formatCurrency(currentEditQuote.new_amount)}`; }
            catch (error) {
                durationWarning.textContent = error.message;
                durationWarning.hidden = false;
                if (error.status === 429) notify(error.message, 'error');
            }
        }, 250);
    };
    [bookingEditForm.elements.date, bookingEditForm.elements.court_id, bookingStartInput, bookingEndInput].forEach((field) => field.addEventListener('change', refreshEditQuote));    const bookingEditMessage = document.getElementById('courtBookingEditMessage');
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
        const blockEditForm = document.getElementById('courtBlockEditForm');
        const allowsPastSchedule = form === bookingEditForm
            || (form === blockEditForm && selectedBlock && isMovableCalendarItem('block', selectedBlock));
        let valid = true;
        if (dateField) {
            if (allowsPastSchedule) dateField.removeAttribute('min');
            else dateField.min = venueToday;
            if (!dateField.dataset.lastValid) dateField.dataset.lastValid = dateField.value || venueToday;
            if (!allowsPastSchedule && !dateField.disabled && dateField.value && dateField.value < venueToday) {
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
            if (!allowsPastSchedule && !walkIn && scheduleChanged && dateField.value === venueToday
                && startMinutes !== null && startMinutes < venueNowMinutes()) {
                setInlineFieldError(startField, calendarFormTimeErrors, 'Choose a start time that has not passed in Asia/Manila.');
                valid = false;
            } else setInlineFieldError(startField, calendarFormTimeErrors, '');
        } else if (startField) setInlineFieldError(startField, calendarFormTimeErrors, '');
        const pastBlock = form === blockEditForm && selectedBlock && itemIsPast('block', selectedBlock);
        const pastBlockHasAllowedChange = pastBlock && (
            form.elements.notes.value !== (selectedBlock.notes || '')
            || (isMovableCalendarItem('block', selectedBlock)
                && (form.elements.block_date.value !== selectedBlock.block_date
                    || form.elements.start_time.value !== selectedBlock.start_time
                    || form.elements.end_time.value !== selectedBlock.end_time
                    || String(form.elements.court_id.value) !== String(selectedBlock.court_id ?? 'all')))
        );
        const pastBooking = form === bookingEditForm && selectedBooking && itemIsPast('booking', selectedBooking);
        const pastBookingHasAllowedChange = pastBooking && (
            form.elements.customer_name.value !== (selectedBooking.customer || '')
            || form.elements.contact_number.value !== (selectedBooking.contact_number || '')
            || form.elements.email.value !== (selectedBooking.email || '')
            || form.elements.notes.value !== (selectedBooking.notes || '')
            || form.elements.date.value !== selectedBooking.date
            || form.elements.start_time.value !== selectedBooking.start
            || form.elements.end_time.value !== selectedBooking.end
            || Number(form.elements.court_id.value) !== Number(selectedBooking.court_id)
        );
        const save = form.querySelector('[type="submit"]');
        if (save) save.disabled = !valid
            || Boolean(pastBlock && !pastBlockHasAllowedChange)
            || Boolean(pastBooking && !pastBookingHasAllowedChange);
        return valid;
    };
    calendarDateTimeForms.forEach((form) => {
        const dateField = form.elements.date || form.elements.block_date;
        const startField = form.elements.start_time;
        if (dateField) {
            if (form === blockForm) dateField.min = venueToday;
            else dateField.removeAttribute('min');
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
        setRequiredField(acknowledgement, eligible && amountChanged);
        if (!amountChanged) acknowledgement.checked = false;
        const save = bookingEditForm.querySelector('[type="submit"]');
        if (save) save.disabled = !validateCalendarForm(bookingEditForm) || (eligible && amountChanged && !acknowledgement.checked);
    };
    bookingEditForm.elements.amount.addEventListener('input', updatePriceAcknowledgementVisibility);
    bookingEditForm.elements.acknowledge_price_change.addEventListener('change', updatePriceAcknowledgementVisibility);
    bookingEditForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!hasPermission('bookings.edit')) return;
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
        if (Number(durationControl.dataset.adjustMinutes || 0)) payload.adjust_minutes = Number(durationControl.dataset.adjustMinutes);
        const scheduleChanged = String(payload.date) !== String(originalBooking.date)
            || Number(payload.court_id) !== Number(originalBooking.court_id)
            || targetStart !== originalBooking.start || targetEnd !== originalBooking.end;
        if (scheduleChanged && originalBooking.status === 'paid' && itemIsPast('booking', originalBooking)
            && !window.confirm('This paid booking is in the past. Move it anyway?')) return;
        delete payload.amount;
        if (scheduleChanged) {
            let quote;
            try { quote = await loadEditQuote(); }
            catch (error) {
                bookingEditMessage.textContent = error.message;
                bookingEditMessage.classList.add('is-error');
                if (error.status === 429) notify(error.message, 'error');
                return;
            }
            if (!quote) {
                bookingEditMessage.textContent = 'The booking price could not be checked. Try again.';
                bookingEditMessage.classList.add('is-error');
                return;
            }
            if (quote.amount_changed) {
                const choice = await confirmDragPrice(quote, originalBooking, {
                    canChangeAmount: Boolean(quote.can_change_amount), qrPaid: Boolean(quote.qr_paid || originalBooking.qr_paid),
                });
                if (!choice) return;
                payload.price_decision = choice.decision;
                payload.acknowledge_price_change = choice.decision === 'new';
                if (choice.decision === 'new') {
                    payload.expected_amount = Number(quote.new_amount);
                    payload.price_rules_version = quote.rules_version || priceRules.version;
                }
            } else payload.price_decision = 'keep';
        }
        payload.email = payload.email.trim() || null;
        payload.notes = payload.notes.trim() || null;
        payload.acknowledge_price_change = bookingEditForm.elements.acknowledge_price_change.checked;
        if (originalIndex >= 0) {
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
            let response = await fetch(routeWithId(bookingUpdateUrlTemplate, selectedBooking.id), {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload),
            });
            let result = await response.json().catch(() => ({}));
            if (response.status === 409 && result.quote) {
                const corrected = { ...result.quote, old_date: originalBooking.date, old_start: originalBooking.start,
                    old_end: originalBooking.end, date: payload.date, start: targetStart, end: targetEnd };
                const choice = await confirmDragPrice(corrected, originalBooking, {
                    canChangeAmount: Boolean(corrected.can_change_amount), qrPaid: Boolean(corrected.qr_paid || originalBooking.qr_paid),
                });
                if (choice) {
                    payload.price_decision = choice.decision;
                    payload.acknowledge_price_change = choice.decision === 'new';
                    delete payload.expected_amount;
                    delete payload.price_rules_version;
                    if (choice.decision === 'new') {
                        payload.expected_amount = Number(corrected.new_amount);
                        payload.price_rules_version = corrected.rules_version;
                    }
                    response = await fetch(routeWithId(bookingUpdateUrlTemplate, selectedBooking.id), {
                        method: 'PATCH', credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify(payload),
                    });
                    result = await response.json().catch(() => ({}));
                }
            }
            if (!response.ok) {
                if (originalIndex >= 0) {
                    bookings[originalIndex] = originalBooking;
                    selectedBooking = originalBooking;
                    affectedCalendarDates = [previousDate, payload.date];
                    renderCalendar();
                }
                const fieldErrors = Object.values(result.errors || {}).flat();
                const conflict = result.conflict;
                const conflictMessage = conflict ? ` ${conflict.title || conflict.type}${conflict.start_time ? ` (${conflict.start_time}–${conflict.end_time})` : ''}.` : '';
                const failureMessage = responseFailureMessage(
                    result,
                    response.status === 419 ? 'Your session or security token expired. Refresh the page and try again.' : 'Could not update this booking.',
                    response
                );
                bookingEditMessage.textContent = `${failureMessage}${conflictMessage}${fieldErrors.length ? ` ${fieldErrors.join(' ')}` : ''}`;
                bookingEditMessage.classList.add('is-error');
                if (response.status === 429) notify(failureMessage, 'error');
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
            if (originalIndex >= 0) {
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
    const updateBlockEditRequirements = () => {
        const open = blockEditForm.elements.type.value === 'open_play';
        blockEditForm.querySelectorAll('.open-play-edit-field').forEach((field) => { field.hidden = !open; });
        setRequiredField(blockEditForm.elements.type, true);
        setRequiredField(blockEditForm.elements.court_id, false);
        setRequiredField(blockEditForm.elements.title, true);
        setRequiredField(blockEditForm.elements.block_date, true);
        setRequiredField(blockEditForm.elements.start_time, true);
        setRequiredField(blockEditForm.elements.end_time, true);
        setRequiredField(blockEditForm.elements.max_players, open);
        setRequiredField(blockEditForm.elements.price_per_player, open);
    };
    blockEditForm.elements.type.addEventListener('change', updateBlockEditRequirements);
    blockEditForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!selectedBlock || !canManageBlock(selectedBlock)) return;
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
        payload.type = payload.type ?? originalBlock.type;
        payload.court_id = payload.court_id === undefined
            ? originalBlock.court_id
            : (payload.court_id === 'all' ? null : Number(payload.court_id));
        payload.block_date = payload.block_date ?? originalBlock.block_date;
        payload.start_time = payload.start_time ?? originalBlock.start_time;
        payload.end_time = payload.end_time ?? originalBlock.end_time;
        payload.notes = payload.notes.trim() || null;
        payload.max_players = payload.type === 'open_play' ? Number(payload.max_players) : null;
        payload.price_per_player = payload.type === 'open_play' ? Number(payload.price_per_player) : null;
        const scheduleChanged = String(payload.block_date) !== String(originalBlock.block_date)
            || String(payload.start_time) !== String(originalBlock.start_time)
            || String(payload.end_time) !== String(originalBlock.end_time)
            || String(payload.court_id ?? '') !== String(originalBlock.court_id ?? '');
        const joined = Number(originalBlock.participant_count ?? originalBlock.participants?.length ?? 0);
        if (scheduleChanged && joined > 0
            && !window.confirm(`${joined} ${joined === 1 ? 'player has' : 'players have'} joined. Move anyway?`)) return;
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
            const response = await fetch(routeWithId(blockUpdateUrlTemplate, selectedBlock.id), {
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
                const failureMessage = responseFailureMessage(
                    result,
                    response.status === 419 ? 'Your session or security token expired. Refresh the page and try again.' : 'Could not update this event.',
                    response
                );
                blockEditMessage.textContent = `${failureMessage}${conflictMessage}${fieldErrors.length ? ` ${fieldErrors.join(' ')}` : ''}`;
                blockEditMessage.classList.add('is-error');
                if (response.status === 429) notify(failureMessage, 'error');
                return;
            }
            const index = blocks.findIndex((item) => String(item.id) === String(result.block.id));
            if (index >= 0) blocks[index] = {
                ...normalizeBlock(result.block),
                participants: originalBlock.participants,
                participant_count: result.block.participant_count ?? originalBlock.participant_count,
            };
            affectedCalendarDates = [previousDate, result.block.block_date, ...applyMovedBookings(result.moved_bookings)];
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
        if (!selectedBlock || !canManageBlock(selectedBlock)) return;
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
            const response = await fetch(routeWithId(blockDeleteUrlTemplate, selectedBlock.id), {
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
                blockDeleteMessage.textContent = responseFailureMessage(result, 'Could not delete this event.', response);
                blockDeleteMessage.classList.add('is-error');
                if (response.status === 429) notify(blockDeleteMessage.textContent, 'error');
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

    const formatDate = (date) => parseLocalDate(date).toLocaleDateString('en-US', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
    });

    const toDateValue = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const eventWindows = (booking, date) => {
        const slotsForDay = (booking.slots || []).filter((slot) => normalizeDateKey(slot.date) === date);
        if (slotsForDay.length) return slotsForDay;
        if (normalizeDateKey(booking.date) === date) {
            const endMinutes = toMinutes(booking.end);
            const startMinutes = toMinutes(booking.start);
            return endMinutes <= startMinutes
                ? [{ start: booking.start, end: '24:00' }]
                : [{ start: booking.start, end: booking.end }];
        }
        const bookingEnd = toMinutes(booking.end);
        if (bookingEnd > 0 && bookingEnd <= toMinutes(booking.start) && addDays(normalizeDateKey(booking.date), 1) === date) {
            return [{ start: '00:00', end: booking.end }];
        }
        return [];
    };

    const queryParams = new URLSearchParams(window.location.search);
    const listStateParams = ['search', 'date', 'status', 'page'];
    const hasListState = listStateParams.some((key) => queryParams.has(key));
    const requestedView = queryParams.get('view');
    const requestedOpenPlayId = document.documentElement.classList.contains('staff-layout-shell') && requestedView === 'week'
        ? queryParams.get('highlight_open_play')
        : null;
    let openPlayHighlightReady = false;
    let openPlayHighlightScrolled = false;
    const highlightRequestedOpenPlay = () => {
        if (!requestedOpenPlayId) return;
        const visiblePanel = workspace.querySelector('.bookings-view-panel:not([hidden])');
        const target = Array.from(visiblePanel?.querySelectorAll('[data-calendar-kind="block"][data-calendar-id]') || [])
            .find((item) => item.dataset.calendarId === requestedOpenPlayId && item.classList.contains('type-open_play'));
        if (!target) return;
        target.classList.add('is-open-play-highlight');
        if (openPlayHighlightScrolled) return;
        openPlayHighlightScrolled = true;
        requestAnimationFrame(() => {
            target.focus({ preventScroll: true });
            target.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'center',
                inline: 'center',
            });
        });
    };
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
            const wednesday = parseLocalDate(weekStart);
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
        setCalendarEmpty('board', courts.length > 0 && active.length === 0);
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

    const participantBookingIds = (block) => {
        const start = toMinutes(block.start_time);
        let end = toMinutes(block.end_time);
        if (end <= start) end += 1440;
        const nextDate = addDays(block.block_date, 1);
        return bookings.filter((booking) => !['cancelled', 'expired'].includes(booking.status)
            && (block.court_id === null || Number(booking.court_id) === Number(block.court_id))
            && [
                ...eventWindows(booking, block.block_date).map((window) => ({ window, offset: 0 })),
                ...eventWindows(booking, nextDate).map((window) => ({ window, offset: 1440 })),
            ].some(({ window, offset }) => {
                const bookingStart = toMinutes(window.start) + offset;
                let bookingEnd = toMinutes(window.end) + offset;
                if (bookingEnd <= bookingStart) bookingEnd += 1440;
                return overlaps(start, end, bookingStart, bookingEnd);
            })).map((booking) => String(booking.id));
    };

    const rangeHasConflict = (date, startMinutes, endMinutes, ignoreBookingId = null, courtId = null, ignoreBlockId = null, ignoreBookingIds = []) => {
        const weekday = parseLocalDate(date).getDay();
        if (BIZ_CLOSED_WEEKDAYS.includes(weekday)
            || closures.some((closure) => closure.date === date)) return true;

        const bookingConflict = bookings.some((booking) => {
            if (ignoreBookingId !== null && String(booking.id) === String(ignoreBookingId)) return false;
            if (ignoreBookingIds.includes(String(booking.id))) return false;
            if (courtId !== null && Number(booking.court_id) !== Number(courtId)) return false;
            if (['cancelled', 'expired'].includes(booking.status)) return false;
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
        const participantIds = drag.kind === 'block' ? participantBookingIds(drag.item) : [];
        const conflict = drag.kind === 'block'
            ? rangeHasConflict(slot.date, startMinutes, endMinutes, null, drag.item.court_id, drag.item.id, participantIds)
            : rangeHasConflict(slot.date, startMinutes, endMinutes, drag.item.id, drag.item.court_id);
        drag.ghost.classList.toggle('is-conflict', conflict);
        drag.target = { date: slot.date, startMinutes, endMinutes, conflict, targetColumn };
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
        if (!item || !isMovableCalendarItem(kind, item)
            || (kind === 'booking' && !hasPermission('bookings.edit'))
            || (kind === 'block' && !canManageBlock(item))) return;
        const rect = source.getBoundingClientRect();
        let start = kind === 'booking' ? toMinutes(item.start) : toMinutes(item.start_time);
        let end = kind === 'booking' ? toMinutes(item.end) : toMinutes(item.end_time);
        if (end <= start) end += 1440;
        const key = `${kind}:${id}`;
        if (protectedItems.has(key) || moveQueues.has(key) || item._saving) return;
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
            version: list[index].updated_at, wanted: null, inFlight: null, changedDates: [], relatedBookings: [],
        };
        const currentDisplayed = { ...list[index] };
        const joinedCount = drag.kind === 'block'
            ? Number(currentDisplayed.participant_count ?? currentDisplayed.participants?.length ?? 0)
            : 0;
        const joinedIds = drag.kind === 'block' && currentDisplayed.type === 'open_play' && joinedCount > 0
            ? participantBookingIds(currentDisplayed) : [];
        const joinedBookings = joinedIds
            .map((id) => bookings.find((booking) => String(booking.id) === id))
            .filter(Boolean);
        const movableJoinedBookings = joinedBookings.length === joinedCount ? joinedBookings : [];
        if (movableJoinedBookings.some((booking) =>
            protectedItems.has(`booking:${booking.id}`) || moveQueues.has(`booking:${booking.id}`) || booking._saving
        )) {
            releaseDragVisuals(drag);
            notify('A joined booking is already being moved. Try again when it finishes.', 'error');
            return;
        }
        const sequence = (moveSequences.get(drag.key) || 0) + 1;
        moveSequences.set(drag.key, sequence);
        const position = {
            date: target.date, start: formatTimeInput(target.startMinutes), end: formatTimeInput(target.endMinutes),
            court_id: currentDisplayed.court_id ?? null,
        };
        queue.wanted = { sequence, position, payload };
        queue.relatedBookings = movableJoinedBookings.map((booking) => ({
            id: String(booking.id), original: { ...booking },
        }));
        queue.relatedBookingKeys = queue.relatedBookings.map(({ id }) => id);
        queue.confirmationNotes = drag.kind === 'block' && joinedCount > 0
            ? [`${joinedCount} ${joinedCount === 1 ? 'player has' : 'players have'} joined. Their bookings will move with this event.`]
            : [];
        queue.confirmationLabel = currentDisplayed.title || 'Open Play';
        moveQueues.set(drag.key, queue);
        protectedItems.add(drag.key);
        const optimistic = drag.kind === 'block'
            ? {
                ...currentDisplayed, ...payload,
                ...(joinedCount > 0 && movableJoinedBookings.length === 0 ? { _saving: true } : {}),
            }
            : { ...currentDisplayed, date: payload.date, start: payload.start_time, end: payload.end_time,
                slots: movedBookingSlots(payload.date, payload.start_time, payload.end_time) };
        queue.changedDates = [...new Set([
            ...(queue.changedDates || []),
            ...itemCalendarDates(drag.kind, currentDisplayed),
            ...itemCalendarDates(drag.kind, optimistic),
            ...queue.relatedBookings.flatMap(({ original }) => itemCalendarDates('booking', original)),
        ])];
        if (drag.kind === 'block' && movableJoinedBookings.length) {
            const dateDelta = Math.round((parseLocalDate(target.date).getTime()
                - parseLocalDate(currentDisplayed.block_date).getTime()) / 86400000);
            const shiftMinutes = dateDelta * 1440 + toMinutes(payload.start_time) - toMinutes(currentDisplayed.start_time);
            queue.relatedBookings.forEach(({ id }) => {
                const bookingIndex = bookings.findIndex((booking) => String(booking.id) === id);
                if (bookingIndex < 0) return;
                const moved = shiftBookingByMinutes(bookings[bookingIndex], shiftMinutes);
                bookings[bookingIndex] = { ...moved, _saving: false };
                queue.changedDates.push(...itemCalendarDates('booking', moved));
                protectedItems.add(`booking:${id}`);
            });
        }
        queue.changedDates = [...new Set(queue.changedDates)];
        affectedCalendarDates = queue.changedDates;
        list[index] = optimistic;
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
        const start = parseLocalDate(startValue);
        const end = parseLocalDate(endValue);
        const monthDay = (date) => date.toLocaleDateString('en-US', { month: 'long', day: 'numeric' });
        if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth()) {
            return `${monthDay(start)} - ${end.getDate()}, ${end.getFullYear()}`;
        }
        if (start.getFullYear() === end.getFullYear()) return `${monthDay(start)} - ${monthDay(end)}, ${end.getFullYear()}`;
        return `${monthDay(start)}, ${start.getFullYear()} - ${monthDay(end)}, ${end.getFullYear()}`;
    };
    const shortWeekRangeLabel = (startValue, endValue) => {
        const start = parseLocalDate(startValue);
        const end = parseLocalDate(endValue);
        const startText = start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        const endText = end.getMonth() === start.getMonth()
            ? String(end.getDate())
            : end.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        return `${startText} - ${endText}`;
    };

    const icons = {
        lock: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>',
        padel: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 3c4-2 9 0 11 4s1 9-3 12-9 2-11-2S3 6 7 3Z"/><path d="m8 18-4 4"/><circle cx="8" cy="7" r=".7"/><circle cx="14" cy="6" r=".7"/><circle cx="16" cy="11" r=".7"/><circle cx="10" cy="13" r=".7"/><circle cx="7" cy="10" r=".7"/></svg>',
        wrench: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14.5 6.5a5 5 0 0 0-6.6 6.6L3 18l3 3 4.9-4.9a5 5 0 0 0 6.6-6.6L14 13l-3-3 3.5-3.5Z"/></svg>',
        people: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    };
    workspace.querySelectorAll('[data-calendar-legend-icon]').forEach((legend) => {
        const name = legend.dataset.calendarLegendIcon;
        if (icons[name]) legend.insertAdjacentHTML('afterbegin', icons[name]);
    });
    const displayTime = (value) => {
        const normalized = normalizeTime(value);
        const [rawHour, rawMinute] = normalized.split(':').map(Number);
        const minutes = rawHour * 60 + rawMinute;
        const hour = Math.floor((minutes % 1440) / 60);
        const minute = minutes % 60;
        return { clock: `${hour % 12 || 12}:${String(minute).padStart(2, '0')}`, period: hour < 12 ? 'AM' : 'PM' };
    };
    const formatTimeRange = (start, end) => {
        const from = displayTime(start);
        const to = displayTime(end);
        return `${from.clock} - ${to.clock}${from.period === to.period ? ` ${to.period}` : ` ${from.period} - ${to.period}`}`;
    };
    const courtNameFor = (item) => {
        if (item.court_id == null) return 'All courts';
        if (courts.length <= 1) return '';
        return courts.find((court) => Number(court.id) === Number(item.court_id))?.name || item.court || `Court ${item.court_id}`;
    };
    const appendCalendarContent = (button, { icon, title, time, lines, fullInfo }) => {
        const full = document.createElement('span');
        full.className = 'calendar-item-full';
        if (icon) full.insertAdjacentHTML('beforeend', icons[icon]);
        const fullText = document.createElement('span');
        fullText.className = 'calendar-item-copy';
        lines.forEach((line, index) => {
            const row = document.createElement('span');
            row.className = index === 0 ? 'calendar-item-title' : 'calendar-item-line';
            row.textContent = line;
            fullText.append(row);
        });
        full.append(fullText);
        button.append(full);

        const compact = document.createElement('span');
        compact.className = 'calendar-item-compact';
        const compactHeading = document.createElement('span');
        compactHeading.className = 'calendar-item-compact-heading';
        if (icon) compactHeading.insertAdjacentHTML('beforeend', icons[icon]);
        const compactTitle = document.createElement('span');
        compactTitle.className = 'calendar-item-title';
        compactTitle.textContent = title;
        compactHeading.append(compactTitle);
        compact.append(compactHeading);
        const compactTime = document.createElement('span');
        compactTime.className = 'calendar-item-time';
        compactTime.textContent = time;
        compact.append(compactTime);
        button.append(compact);

        const tiny = document.createElement('span');
        tiny.className = 'calendar-item-tiny';
        if (icon) tiny.insertAdjacentHTML('beforeend', icons[icon]);
        const tinyTime = document.createElement('span');
        const tinyStart = displayTime(time.split(' - ')[0]);
        tinyTime.textContent = `${tinyStart.clock} ${tinyStart.period}`;
        tiny.append(tinyTime);
        button.append(tiny);
        button.title = fullInfo;
        button.setAttribute('aria-label', fullInfo);
        return { fullText, compactHeading, compact, tiny };
    };

    const renderCalendarGrid = (startDate, dayCount, mode, targetGrid, changedDates = null) => {
        if (!startDate || !dayCount || !targetGrid) return;
        const baseDate = parseLocalDate(startDate);
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
            const d = parseLocalDate(dateStr);
            const dayNum = d.getDate();
            const weekday = d.toLocaleDateString('en-US', { weekday: 'short' });
            const weekdayNarrow = new Intl.DateTimeFormat('en-PH', { weekday: 'narrow' }).format(d);
            const isToday = dateStr === todayValue;

            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'court-month-date-header' + (isToday ? ' is-today' : '');
            cell.dataset.date = dateStr;
            cell.style.cssText = mode === 'week' ? 'min-width:0;' : `flex:0 0 ${COL_W}px;width:${COL_W}px;`;
            const monthTag = colIndex === 0 || dayNum === 1
                ? ` <small>${d.toLocaleDateString('en-US', { month: 'short' })}</small>`
                : '';
            const dayNumber = document.createElement('strong');
            dayNumber.className = 'court-month-day-number';
            dayNumber.textContent = String(dayNum);
            if (mode === 'week' && monthTag) dayNumber.insertAdjacentHTML('beforeend', monthTag);
            const weekdayShort = document.createElement('span');
            weekdayShort.className = 'court-month-weekday-short';
            weekdayShort.textContent = weekday;
            const weekdayLetter = document.createElement('span');
            weekdayLetter.className = 'court-month-weekday-narrow';
            weekdayLetter.textContent = weekdayNarrow;
            cell.append(dayNumber, weekdayShort, weekdayLetter);
            const accessibleDate = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            cell.title = accessibleDate;
            cell.setAttribute('aria-label', accessibleDate);
            cell.addEventListener('click', () => jumpToDay(dateStr));
            header.append(cell);
        });
        nextGrid.append(header);

        // ── Body: one row per hour, one column per date ─────────────────────
        // Pre-build booking and block windows per date.
        const windowsByDate = new Map();
        dates.forEach((dateStr) => {
        const raw = bookings
            .filter((booking) => {
                if (booking.status === 'cancelled') return false;
                return !blocks.some((block) => block.type === 'open_play'
                    && blockWindowsForDate(block, dateStr).some((window) =>
                        (block.court_id == null || booking.court_id == null
                            || Number(booking.court_id) === Number(block.court_id))
                        && toMinutes(booking.start) < window.endMinutes
                        && toMinutes(booking.end) > window.startMinutes));
            })
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
            const weekday = parseLocalDate(dateStr).getDay(); // 0=Sun…6=Sat
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
                    if (isMovableCalendarItem('block', block)) event.classList.add('is-calendar-movable');
                    event.dataset.calendarKind = 'block';
                    event.dataset.calendarId = String(block.id);
                    event.style.cssText = `top:${topPx}px;left:${mode === 'week' ? `calc(${laneLeft(lane)} + 2px)` : laneLeft(lane)};width:${mode === 'week' ? `calc(${laneWidth} - 4px)` : laneWidth};height:${heightPx}px;`;
                    const sessionCount = block.participants
                        ? block.participants.length
                        : (block.participant_count ?? block.attendee_count ?? block.players_count);
                    const sessionCapacity = block.max_players ?? block.capacity ?? block.max_participants ?? block.max_capacity;
                    const type = block.type === 'closed' ? 'Closed' : block.type === 'maintenance' ? 'Maintenance' : 'Open play';
                    const title = block.title || type;
                    const time = formatTimeRange(block.start_time, block.end_time);
                    const court = courtNameFor(block);
                    const cancelled = block.status === 'cancelled' || block.cancelled_at;
                    const fullSession = block.is_full || block.status === 'full' || (sessionCount != null && sessionCapacity != null && Number(sessionCount) >= Number(sessionCapacity));
                    let statusLine = '';
                    let price = '';
                    if (block.type === 'open_play') {
                        const joined = Math.max(0, Number(sessionCount || 0));
                        const maxPlayers = Math.max(0, Number(sessionCapacity || 0));
                        const spotsLeft = Math.max(0, maxPlayers - joined);
                        const joinedText = `${joined} joined`;
                        statusLine = cancelled ? joinedText
                            : fullSession ? `${joinedText} · Full`
                                : sessionCapacity == null
                                    ? `${joinedText} · Capacity not set`
                                    : maxPlayers > 0
                                        ? `${joinedText} · ${spotsLeft} of ${maxPlayers} spots left`
                                        : `${joinedText} · 0 of 0 spots left`;
                        const perPlayer = block.price_per_player ?? block.price ?? block.amount_per_player;
                        if (perPlayer != null && Number.isFinite(Number(perPlayer))) price = `₱${Number(perPlayer).toLocaleString('en-PH')}/player`;
                    }
                    if (cancelled) event.classList.add('is-cancelled');
                    const details = [type, title, formatDate(block.block_date), time, court, statusLine || block.status, price].filter(Boolean).join(' · ');
                    if (block.type === 'open_play') {
                        const joined = Math.max(0, Number(sessionCount || 0));
                        const maxPlayers = Math.max(0, Number(sessionCapacity || 0));
                        const fullText = sessionCapacity == null
                            ? 'Capacity not set'
                            : fullSession ? 'Full'
                                : `${Math.max(0, maxPlayers - joined)} of ${maxPlayers} spots left`;
                        const compactLabel = mode === 'week'
                            ? (sessionCapacity == null ? `${title} · ${joined} joined` : `${title} · ${joined}/${maxPlayers}`)
                            : `Open Play · ${joined} joined`;
                        const content = appendCalendarContent(event, {
                            icon: 'people',
                            title,
                            time,
                            lines: [title, time, `${joined} joined · ${fullText}`, price].filter(Boolean),
                            fullInfo: details,
                        });
                        if (heightPx < 60) {
                            content.compactHeading.querySelector('.calendar-item-title').textContent = compactLabel;
                            content.fullText.querySelector('.calendar-item-title').textContent = compactLabel;
                        }
                        if (mode === 'month') {
                            content.compactHeading.querySelector('.calendar-item-title').textContent =
                                maxPlayers > 0 ? `Open Play · ${joined}/${maxPlayers}` : `Open Play · ${joined} joined`;
                        }
                        event.setAttribute('aria-label', details);
                        event.title = details;
                    } else {
                        appendCalendarContent(event, { icon: block.type === 'closed' ? 'lock' : 'wrench', title, time, lines: [title, time, court, statusLine, price].filter(Boolean), fullInfo: details });
                    }
                    col.append(event);
                    return;
                }

                const colors = STATUS_COLORS[booking.status] || STATUS_COLORS.paid;
                const accentColor = LANE_ACCENTS[index % LANE_ACCENTS.length];

                const bar = document.createElement('button');
                bar.type = 'button';
                bar.className = `court-month-bar status-${booking.status}${booking._saving ? ' is-saving' : ''}`;
                if (isMovableCalendarItem('booking', booking)) bar.classList.add('is-calendar-movable');
                bar.dataset.calendarKind = 'booking';
                bar.dataset.calendarId = String(booking.id);
                bar.style.cssText = `
                    position: absolute;
                    top: ${topPx}px;
                    left: ${mode === 'week' ? `calc(${laneLeft(lane)} + 2px)` : laneLeft(lane)};
                    width: ${mode === 'week' ? `calc(${laneWidth} - 4px)` : laneWidth};
                    height: ${heightPx}px;
                    background: var(--calendar-booking-fill, ${colors.bg});
                    border: 1px solid var(--calendar-booking-border, ${colors.border});
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
                    cursor: ${isMovableCalendarItem('booking', booking) ? 'grab' : 'pointer'};
                    padding: ${mode === 'week' ? 'var(--s1) var(--s2)' : '0'};
                    overflow-wrap: ${mode === 'week' ? 'anywhere' : 'normal'};
                    letter-spacing: .03em;
                `;
                const customer = booking.customer || 'Booking';
                const time = formatTimeRange(booking.start, booking.end);
                const court = courtNameFor(booking);
                const walkIn = booking.source === 'walk_in';
                const fullInfo = [walkIn ? 'Walk-in' : 'Booking', customer, formatDate(booking.date), time, court, booking.status, booking.payment_method || ''].filter(Boolean).join(' · ');
                const content = appendCalendarContent(bar, { title: customer, time, lines: [customer, time, court, booking.status], fullInfo });
                const payment = document.createElement('span');
                payment.className = `calendar-payment-marker payment-${String(booking.status || '').replace(/[^a-z0-9_-]/gi, '')}`;
                payment.setAttribute('aria-hidden', 'true');
                payment.textContent = booking.status === 'paid' || booking.status === 'confirmed' ? '✓' : booking.status === 'cancelled' ? '×' : '•';
                content.fullText.append(payment.cloneNode(true));
                content.compactHeading.append(payment);
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
        setCalendarEmpty(mode, activeCount === 0);
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
    const updateWeekPastSlots = () => {
        if (!weekGrid) return;
        const today = venueToday;
        const currentMinutes = venueNowMinutes();
        const currentHour = Math.floor(currentMinutes / 60);
        const currentMinute = currentMinutes % 60;

        weekGrid.querySelectorAll('.court-month-date-col').forEach((column) => {
            const date = column.dataset.date || '';
            const isPastDay = date < today;
            const isToday = date === today;
            column.querySelectorAll('.court-month-hour-cell').forEach((cell) => {
                const hour = Number(cell.dataset.hour);
                const isCurrentHour = isToday && hour === currentHour && currentMinute > 0;
                cell.classList.toggle('past-slot', isPastDay || (isToday && hour < currentHour));
                cell.classList.toggle('past-slot-current', isCurrentHour);
                if (isCurrentHour) {
                    cell.style.setProperty('--past-progress', `${currentMinute / 60 * 100}%`);
                } else {
                    cell.style.removeProperty('--past-progress');
                }
            });
        });
    };
    const renderWeek = (changedDates = null) => {
        const chosen = isoDateIsValid(weekInput.value) ? weekInput.value : venueToday;
        weekInput.value = chosen;
        weekStart = startOfWeek(chosen);
        activeCalendarGrid = weekGrid;
        renderCalendarGrid(weekStart, 7, 'week', weekGrid, changedDates);
        weekHeading.textContent = fullWeekRangeLabel(weekStart, addDays(weekStart, 6));
        updateWeekNavigation();
        updateWeekPastSlots();
        if (openPlayHighlightReady) highlightRequestedOpenPlay();
    };
    window.setInterval(updateWeekPastSlots, 60000);

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

    const beginCalendarLoading = (state) => {
        const bodyHeight = state.view === 'month' || state.view === 'week'
            ? `${buildDayRowPlan().gridH}px`
            : null;
        const gridWidth = state.view === 'month'
            ? (() => {
                const [year, month] = monthInput.value.split('-').map(Number);
                return `${64 + new Date(year, month, 0).getDate() * 90}px`;
            })()
            : null;
        state.pending += 1;
        state.container.setAttribute('aria-busy', 'true');
        state.status.textContent = 'Loading calendar…';
        if (bodyHeight) state.container.style.setProperty('--calendar-body-height', bodyHeight);
        if (gridWidth) state.container.style.setProperty('--calendar-grid-width', gridWidth);

        if (state.pending === 1 && state.skeleton.hidden) {
            state.shownAt = performance.now();
            state.container.classList.add('is-calendar-loading');
            state.skeleton.hidden = false;
        }

        return async () => {
            state.pending = Math.max(0, state.pending - 1);
            if (state.pending) return;

            if (!state.skeleton.hidden) {
                const remaining = Math.max(0, 120 - (performance.now() - state.shownAt));
                if (remaining) await new Promise((resolve) => window.setTimeout(resolve, remaining));
            }

            if (state.pending) return;
            state.skeleton.hidden = true;
            state.container.classList.remove('is-calendar-loading');
            state.container.setAttribute('aria-busy', 'false');
            state.container.style.removeProperty('--calendar-body-height');
            state.container.style.removeProperty('--calendar-grid-width');
            state.status.textContent = '';
        };
    };
    const loadVisibleRangeThenRender = async (view, range, render, { force = false } = {}) => {
        clearCalendarError(view);
        setCalendarEmpty(view, false);
        const shouldFetch = force || !rangeIsCached(range.from, range.to);
        const loadingState = shouldFetch ? calendarLoadingStates.get(view) : null;
        let finishLoading = null;
        let loaded = true;

        try {
            if (loadingState) finishLoading = beginCalendarLoading(loadingState);
            if (shouldFetch) loaded = await loadCalendarRange(range.from, range.to, { force });
            const rendered = renderCalendarSafely(view, render);
            if (!loaded && rendered) {
                showCalendarError(view, 'Could not refresh the calendar. Showing the available page data.');
            }
            return loaded && rendered;
        } catch (error) {
            console.error(`[bookings] ${view} calendar load failed`, error);
            const rendered = renderCalendarSafely(view, render);
            if (rendered) showCalendarError(view, 'Calendar could not be loaded. Showing the available page data.');
            return false;
        } finally {
            if (finishLoading) await finishLoading();
        }
    };
    workspace.querySelectorAll('[data-calendar-retry]').forEach((button) => {
        button.addEventListener('click', () => {
            const view = button.dataset.calendarRetry;
            if (view === 'board') {
                void loadVisibleRangeThenRender('board', { from: dateInput.value, to: dateInput.value }, renderBoard, { force: true });
            } else if (view === 'month') {
                void loadVisibleRangeThenRender('month', currentVisibleRange(), renderMonth, { force: true });
            } else if (view === 'week') {
                void loadVisibleRangeThenRender('week', currentVisibleRange(), renderWeek, { force: true });
            }
        });
    });
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
        void loadVisibleRangeThenRender('week', { from: weekStart, to: addDays(weekStart, 6) }, renderWeek).then(schedulePoll);
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
            const view = ['month', 'week', 'board'].find((name) => panels[name] && !panels[name].hidden);
            const loaded = await loadCalendarRange(range.from, range.to, { force: true });
            if (loaded) {
                clearCalendarError(view);
                if (!calendarDrag && blockModal.hidden && moveQueues.size === 0) renderCalendar();
                else calendarDataDirty = true;
            } else if (view) {
                showCalendarError(view, 'Could not refresh the calendar. Showing the available page data.');
            }
            schedulePoll();
        }, pollDelay);
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => {
        const view = tab.dataset.bookingsView;
        changeView(view);
        if (view === 'week') void loadVisibleRangeThenRender('week', currentVisibleRange(), renderWeek);
        if (view === 'month') void loadVisibleRangeThenRender('month', currentVisibleRange(), renderMonth);
        if (view === 'board') void loadVisibleRangeThenRender('board', currentVisibleRange(), renderBoard);
        schedulePoll();
    }));
    dateInput.addEventListener('change', () => {
        writeCalendarUrl('board');
        void loadVisibleRangeThenRender('board', { from: dateInput.value, to: dateInput.value }, renderBoard);
    });
    monthInput.addEventListener('change', () => {
        const range = currentVisibleRange();
        writeCalendarUrl('month');
        void loadVisibleRangeThenRender('month', range, renderMonth);
    });
    weekInput.addEventListener('change', () => selectWeekDate(weekInput.value));

    if (isoDateIsValid(requestedDate)) monthInput.value = toMonthValue(parseLocalDate(requestedDate));
    changeView(initialView, false);

    const requestedNewItem = new URLSearchParams(window.location.search).get('new');
    if ((requestedNewItem === 'walk-in' && canCreateWalkIn())
        || (requestedNewItem === 'event' && (hasPermission('calendar_events.manage') || hasPermission('calendar_events.open_play')))) {
        const currentMinutes = venueNowMinutes();
        const firstOpen = BIZ_OPEN_HOUR * 60;
        const rawClose = BIZ_CLOSE_HOUR <= BIZ_OPEN_HOUR ? BIZ_CLOSE_HOUR + 24 : BIZ_CLOSE_HOUR;
        const roundedNow = Math.ceil(currentMinutes / stepMinutes) * stepMinutes;
        let startMinutes = Math.max(firstOpen, roundedNow);
        let date = venueToday;
        if (startMinutes + stepMinutes > rawClose * 60) {
            date = addDays(venueToday, 1);
            startMinutes = firstOpen;
        }
        openCreateModal({ date, startMinutes, endMinutes: startMinutes + stepMinutes });
        if (requestedNewItem === 'walk-in' && walkInType.querySelector('option[value="walk_in"]')) {
            walkInType.value = 'walk_in';
            updateTypeFields();
        } else if (requestedNewItem === 'event') {
            walkInType.value = 'open_play';
            updateTypeFields();
        }
        const url = new URL(window.location.href);
        url.searchParams.delete('new');
        history.replaceState(history.state, '', `${url.pathname}${url.search}${url.hash}`);
    }

    workspace.querySelectorAll('[data-month-nav]').forEach((button) => {
        button.addEventListener('click', () => {
            const [year, month] = monthInput.value.split('-').map(Number);
            const cursor = new Date(year, month - 1, 1);
            if (button.dataset.monthNav === 'today') {
                const today = parseLocalDate(venueToday);
                today.setDate(1);
                cursor.setTime(today.getTime());
            }
            if (button.dataset.monthNav === 'previous') cursor.setMonth(cursor.getMonth() - 1);
            if (button.dataset.monthNav === 'next') cursor.setMonth(cursor.getMonth() + 1);
            monthInput.value = toMonthValue(cursor);
            writeCalendarUrl('month');
            const range = currentVisibleRange();
            void loadVisibleRangeThenRender('month', range, renderMonth).then(schedulePoll);
        });
    });

    workspace.querySelectorAll('[data-week-nav]').forEach((button) => button.addEventListener('click', () => {
        if (button.disabled) return;
        if (button.dataset.weekNav === 'today') {
            selectWeekDate(venueToday);
            return;
        }
        const nextStart = parseLocalDate(weekStart);
        nextStart.setDate(nextStart.getDate() + (button.dataset.weekNav === 'next' ? 7 : -7));
        selectWeekDate(weekDateForStart(toDateValue(nextStart)));
    }));
    workspace.querySelectorAll('[data-board-date]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = parseLocalDate(dateInput.value);
            if (button.dataset.boardDate === 'today') value.setTime(parseLocalDate(venueToday).getTime());
            if (button.dataset.boardDate === 'previous') value.setDate(value.getDate() - 1);
            if (button.dataset.boardDate === 'next') value.setDate(value.getDate() + 1);
            dateInput.value = toDateValue(value);
            void loadVisibleRangeThenRender('board', { from: dateInput.value, to: dateInput.value }, renderBoard).then(schedulePoll);
        });
    });
    document.addEventListener('visibilitychange', schedulePoll);
    blockModal.addEventListener('transitionend', schedulePoll);

    const initialToday = venueToday;
    const initialEnd = parseLocalDate(initialToday);
    initialEnd.setMonth(initialEnd.getMonth() + 6);
    rangeCache.push({ from: addDays(initialToday, -1), to: toDateValue(initialEnd) });
    if (initialView === 'week') {
        void loadVisibleRangeThenRender('week', currentVisibleRange(), renderWeek).then(() => {
            openPlayHighlightReady = true;
            highlightRequestedOpenPlay();
            schedulePoll();
        });
    }
    if (initialView === 'month') void loadVisibleRangeThenRender('month', currentVisibleRange(), renderMonth);
    if (initialView === 'board') void loadVisibleRangeThenRender('board', currentVisibleRange(), renderBoard);
    schedulePoll();
})();
