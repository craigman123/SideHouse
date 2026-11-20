(() => {
    const workspace = document.getElementById('bookingsWorkspace');
    const dataElement = document.getElementById('courtBoardData');
    const board = document.getElementById('courtBoard');

    if (!workspace || !dataElement || !board) return;

    const data = JSON.parse(dataElement.textContent || '{}');
    const bookings  = data.bookings  || [];
    const closures  = data.closures  || [];
    let blocks      = Array.isArray(data.blocks) ? data.blocks : [];
    const courts    = data.courts    || [];
    const bizSettings = data.businessSettings || {};
    // Open/close hours from BusinessSetting — fallback to sane defaults
    // so the grid is usable even on a fresh install with no row yet.
    const BIZ_OPEN_HOUR  = typeof bizSettings.open_hour  === 'number' ? bizSettings.open_hour  : 6;
    const BIZ_CLOSE_HOUR = typeof bizSettings.close_hour === 'number' ? bizSettings.close_hour : 22;
    // Weekday numbers (0=Sun … 6=Sat) that are always closed.
    const BIZ_CLOSED_WEEKDAYS = Array.isArray(bizSettings.closed_weekdays) ? bizSettings.closed_weekdays : [];
    const dateInput = document.getElementById('courtBoardDate');
    const summary = document.getElementById('courtBoardSummary');
    const tabs = workspace.querySelectorAll('[data-bookings-view]');
    const panels = {
        list: document.getElementById('bookingListView'),
        board: document.getElementById('courtBoardView'),
        month: document.getElementById('courtMonthView'),
    };

    // ---------- Month view ----------
    const monthInput = document.getElementById('courtMonthInput');
    const monthSummary = document.getElementById('courtMonthSummary');
    const monthGrid = document.getElementById('courtMonthGrid');
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
    const notify = (message, type = 'success') => {
        if (typeof window.showToast === 'function') window.showToast(message, type);
    };
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
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
        selectedBlock = null;
        selectedBooking = null;
        document.getElementById('courtWalkInCustomer').value = '';
        document.getElementById('courtWalkInContact').value = '';
        document.getElementById('courtWalkInEmail').value = '';
        document.getElementById('courtWalkInNotes').value = '';
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
        blockModal.hidden = false;
        document.getElementById('courtBlockType').focus();
    };

    const openBlockDetails = (block) => {
        selectedBlock = block;
        selectedBooking = null;
        showModalPanel(blockDetailsPanel, 'courtBlockDetailsTitle');
        blockDeleteMessage.textContent = '';
        blockDeleteMessage.classList.remove('is-error');
        document.getElementById('courtBlockDetailsTitle').textContent = block.title || 'Calendar event';
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
        const form = document.getElementById('courtBlockEditForm');
        fillCourtOptions(form.elements.court_id, true);
        form.elements.type.value = selectedBlock.type;
        form.elements.court_id.value = selectedBlock.court_id === null ? 'all' : String(selectedBlock.court_id);
        form.elements.title.value = selectedBlock.title || '';
        form.elements.notes.value = selectedBlock.notes || '';
        form.elements.block_date.value = selectedBlock.block_date;
        form.elements.start_time.value = selectedBlock.start_time;
        form.elements.end_time.value = selectedBlock.end_time;
        document.getElementById('courtBlockEditMessage').textContent = '';
        showModalPanel(blockEditPanel, 'courtBlockEditTitle');
        form.elements.type.focus();
    };

    const openBookingDetails = (booking) => {
        selectedBooking = booking;
        selectedBlock = null;
        const panel = bookingDetailsPanel;
        document.getElementById('courtBookingDetailsTitle').textContent = booking.customer || 'Booking details';
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
        const booking = selectedBooking;
        const form = document.getElementById('courtBookingEditForm');
        fillCourtOptions(form.elements.court_id);
        form.elements.customer_name.value = booking.customer || '';
        form.elements.contact_number.value = booking.contact_number || '';
        form.elements.email.value = booking.email || '';
        form.elements.court_id.value = String(booking.court_id);
        form.elements.date.value = booking.date;
        form.elements.start_time.value = booking.start;
        form.elements.end_time.value = booking.end;
        form.elements.amount.value = Number(booking.amount || 0).toFixed(2);
        form.elements.notes.value = booking.notes || '';
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

            if (result.block) blocks.push(normalizeBlock(result.block));
            if (result.booking) bookings.push(result.booking);
            closeBlockModal(true);
            notify(result.message || (isWalkIn ? 'Walk-in booking created.' : 'Calendar event created.'), 'success');
            renderMonth();
        } catch (error) {
            blockFormMessage.textContent = 'Something went wrong. Please try again.';
            blockFormMessage.classList.add('is-error');
        } finally {
            savingBlock = false;
            saveButton.disabled = false;
        }
    });

    const bookingEditForm = document.getElementById('courtBookingEditForm');
    const bookingEditMessage = document.getElementById('courtBookingEditMessage');
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
        if (!selectedBooking || savingBlock) return;
        const token = csrfToken();
        if (!token) {
            bookingEditMessage.textContent = 'Your security token is missing. Reload the page and try again.';
            bookingEditMessage.classList.add('is-error');
            return;
        }
        const payload = Object.fromEntries(new FormData(bookingEditForm).entries());
        payload.court_id = Number(payload.court_id);
        payload.amount = Number(payload.amount);
        if (Math.abs(payload.amount - Number(selectedBooking.amount || 0)) < 0.005) delete payload.amount;
        payload.email = payload.email.trim() || null;
        payload.notes = payload.notes.trim() || null;
        payload.acknowledge_price_change = bookingEditForm.elements.acknowledge_price_change.checked;
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
            if (index >= 0) bookings[index] = result.booking;
            selectedBooking = result.booking;
            closeBlockModal(true);
            renderMonth();
            renderBoard();
            notify(result.message || 'Booking updated.', 'success');
        } catch (error) {
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
        if (!selectedBlock || savingBlock) return;
        const token = csrfToken();
        if (!token) {
            blockEditMessage.textContent = 'Your security token is missing. Reload the page and try again.';
            blockEditMessage.classList.add('is-error');
            return;
        }
        const payload = Object.fromEntries(new FormData(blockEditForm).entries());
        payload.court_id = payload.court_id === 'all' ? null : Number(payload.court_id);
        payload.notes = payload.notes.trim() || null;
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
                const fieldErrors = Object.values(result.errors || {}).flat();
                const conflict = result.conflict;
                const conflictMessage = conflict ? ` ${conflict.title || conflict.type}${conflict.start_time ? ` (${conflict.start_time}–${conflict.end_time})` : ''}.` : '';
                blockEditMessage.textContent = `${result.message || (response.status === 419 ? 'Your session or security token expired. Refresh the page and try again.' : 'Could not update this event.')}${conflictMessage}${fieldErrors.length ? ` ${fieldErrors.join(' ')}` : ''}`;
                blockEditMessage.classList.add('is-error');
                return;
            }
            const index = blocks.findIndex((item) => String(item.id) === String(result.block.id));
            if (index >= 0) blocks[index] = normalizeBlock(result.block);
            closeBlockModal(true);
            renderMonth();
            renderBoard();
            notify(result.message || 'Calendar event updated.', 'success');
        } catch (error) {
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

            blocks = blocks.filter((block) => String(block.id) !== String(selectedBlock.id));
            closeBlockModal(true);
            notify(result.message || 'Calendar event deleted.', 'success');
            renderMonth();
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
        return booking.date === date ? [{ start: booking.start, end: booking.end }] : [];
    };

    const queryParams = new URLSearchParams(window.location.search);
    const listStateParams = ['search', 'date', 'status', 'page'];
    const hasListState = listStateParams.some((key) => queryParams.has(key));
    const requestedView = queryParams.get('view');
    const initialView = ['list', 'board', 'month'].includes(requestedView)
        ? requestedView
        : (hasListState ? 'list' : 'month');

    const changeView = (view, updateUrl = true) => {
        if (!['list', 'board', 'month'].includes(view)) return;
        tabs.forEach((tab) => {
            const active = tab.dataset.bookingsView === view;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        Object.entries(panels).forEach(([key, panel]) => {
            if (panel) panel.hidden = key !== view;
        });
        if (view === 'board') renderBoard();
        if (view === 'month') renderMonth();
        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', view);
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
    const PX_PER_HOUR = 55;

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

    const beginMonthDrag = (pointerId) => {
        const scrollArea = monthGrid.closest('.court-month-scroll');
        if (!scrollArea) return;
        activeMonthDrag = { pointerId, scrollArea, scrollLeft: scrollArea.scrollLeft, scrollTop: scrollArea.scrollTop };
        monthGrid.classList.add('is-selecting-range');
        scrollArea.classList.add('is-selecting-range');
        scrollArea.addEventListener('scroll', lockMonthScroll, { passive: true });
        scrollArea.addEventListener('wheel', preventMonthScroll, { passive: false });
        scrollArea.addEventListener('touchmove', preventMonthScroll, { passive: false });
    };

    const endMonthDrag = (pointerId) => {
        if (!activeMonthDrag || activeMonthDrag.pointerId !== pointerId) return;
        const { scrollArea } = activeMonthDrag;
        activeMonthDrag = null;
        scrollArea.removeEventListener('scroll', lockMonthScroll);
        scrollArea.removeEventListener('wheel', preventMonthScroll);
        scrollArea.removeEventListener('touchmove', preventMonthScroll);
        monthGrid.classList.remove('is-selecting-range');
        scrollArea.classList.remove('is-selecting-range');
    };

    const preventGridNativeDrag = (event) => event.preventDefault();
    monthGrid.addEventListener('dragstart', preventGridNativeDrag);
    monthGrid.addEventListener('selectstart', preventGridNativeDrag);

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
    const renderMonth = () => {
        if (!monthInput.value) return;
        const [year, month] = monthInput.value.split('-').map(Number);
        const daysInMonth = new Date(year, month, 0).getDate();
        const { rowPlan, hourTop, dividerTop, gridH } = buildDayRowPlan();
        const todayValue = toDateValue(new Date());

        // Build the list of date strings for the month.
        const dates = Array.from({ length: daysInMonth }, (_, i) => {
            const d = i + 1;
            return `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        });

        // Column width per date.
        const COL_W = 90;
        // Row height per hour.
        const ROW_H = PX_PER_HOUR;
        // Time label column width (left side).
        const TIME_LABEL_W = LABEL_W;

        const gridW = TIME_LABEL_W + dates.length * COL_W;

        monthGrid.replaceChildren();
        monthGrid.style.width = `${gridW}px`;

        // ── Header row: blank corner + one date column per day ──────────────
        const header = document.createElement('div');
        header.className = 'court-month-header';
        header.style.display = 'flex';
        header.style.position = 'sticky';
        header.style.top = '0';
        header.style.zIndex = '3';
        header.style.background = 'var(--surface-2)';

        // Corner cell
        const corner = document.createElement('div');
        corner.className = 'court-month-corner';
        corner.style.cssText = `flex:0 0 ${TIME_LABEL_W}px;width:${TIME_LABEL_W}px;border-right:1px solid var(--text-muted);border-bottom:1px solid var(--text-muted);`;
        header.append(corner);

        dates.forEach((dateStr) => {
            const d = new Date(`${dateStr}T00:00:00`);
            const dayNum = d.getDate();
            const weekday = d.toLocaleDateString('en-US', { weekday: 'short' });
            const isToday = dateStr === todayValue;

            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'court-month-date-header' + (isToday ? ' is-today' : '');
            cell.style.cssText = `flex:0 0 ${COL_W}px;width:${COL_W}px;`;
            cell.innerHTML = `<strong>${dayNum}</strong><span>${weekday}</span>`;
            cell.title = `Open ${formatDate(dateStr)} on the daily board`;
            cell.addEventListener('click', () => jumpToDay(dateStr));
            header.append(cell);
        });
        monthGrid.append(header);

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
        body.className = 'court-month-body';
        body.style.cssText = `position:relative;display:flex;`;

        // Time label column (sticky left).
        const timeCol = document.createElement('div');
        timeCol.className = 'court-month-time-col';
        timeCol.style.cssText = `flex:0 0 ${TIME_LABEL_W}px;width:${TIME_LABEL_W}px;position:sticky;left:0;z-index:2;background:var(--surface);border-right:1px solid var(--text-muted);`;

        rowPlan.forEach((row) => {
            const cell = document.createElement('div');
            if (row.type === 'hour') {
                cell.className = 'court-month-time-cell';
                cell.style.cssText = `height:${ROW_H}px;display:flex;align-items:flex-start;justify-content:center;padding-top:var(--s2);border-bottom:1px solid var(--text-muted);color:var(--text-muted);font-size:var(--font-size-small);font-weight:700;`;
                cell.textContent = formatHourLabel(row.hour);
            } else {
                cell.className = 'court-month-time-divider';
                cell.style.cssText = `height:${DIVIDER_H}px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:var(--s1);border-top:1px solid var(--text-muted);border-bottom:1px solid var(--text-muted);background:var(--surface-2);font-size:var(--font-size-small);font-weight:800;line-height:1.35;text-align:center;padding:var(--s1);`;
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
            col.className = 'court-month-date-col' + (isToday ? ' is-today' : '') + (isWeekdayClosed ? ' is-closed-weekday' : '') + (hasAnyClosure ? ' is-closed' : '');
            col.dataset.date = dateStr;
            col.style.cssText = `flex:0 0 ${COL_W}px;width:${COL_W}px;position:relative;box-sizing:border-box;` +
                (hasAnyClosure
                    ? `border-right:2px solid var(--danger);background:var(--surface-2);`
                    : `border-right:1px solid var(--text-muted);`);

            // Hour grid lines — skipped for closed columns so the red fill
            // reads as one solid block instead of a striped grid.
            rowPlan.forEach((row) => {
                const cell = document.createElement('div');
                if (row.type === 'hour') {
                    cell.className = 'court-month-hour-cell';
                    cell.dataset.date = dateStr;
                    cell.dataset.hour = String(row.hour);
                    cell.setAttribute('role', 'button');
                    cell.tabIndex = 0;
                    cell.setAttribute('aria-label', `${formatDate(dateStr)}, ${formatHourLabel(row.hour)}. Press Enter to add an event for one step.`);
                    cell.style.cssText = `height:${ROW_H}px;box-sizing:border-box;` +
                        (hasAnyClosure ? '' : `border-bottom:1px solid var(--text-muted);`);
                } else {
                    cell.style.cssText = `height:${DIVIDER_H}px;box-sizing:border-box;background:var(--surface-2);` +
                        (hasAnyClosure ? '' : `border-top:1px solid var(--text-muted);border-bottom:1px solid var(--text-muted);`);
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
                label.style.cssText = `writing-mode:vertical-rl;transform:rotate(180deg);color:var(--danger);font-size:var(--font-size-h1);font-weight:800;letter-spacing:var(--s5);text-shadow:0 1px 2px var(--surface);`;
                strip.append(label);

                col.append(strip);
            }

            // Booking bars — absolute positioned by time within the column.
            const laned = assignLanes(colWindows);
            const laneCount = laned.length ? Math.max(...laned.map((w) => w.laneCount)) : 1;
            const barW = Math.floor((COL_W - 4) / Math.max(1, laneCount));

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
                    event.className = `court-month-block type-${block.type}`;
                    event.style.cssText = `top:${topPx}px;left:${lane * barW + 2}px;width:${barW - 2}px;height:${heightPx}px;`;
                    event.textContent = block.title || block.type.replace('_', ' ');
                    event.title = `${block.title || block.type} · ${block.start_time}–${block.end_time}`;
                    event.setAttribute('aria-label', `${block.title || block.type}, ${block.type.replace('_', ' ')}, ${formatDate(block.block_date)}. Open event details.`);
                    event.addEventListener('click', (clickEvent) => {
                        if (event._suppressCalendarClick) {
                            event._suppressCalendarClick = false;
                            clickEvent.preventDefault();
                            clickEvent.stopPropagation();
                            return;
                        }
                        clickEvent.stopPropagation();
                        openBlockDetails(block);
                    });
                    event.addEventListener('pointerdown', (pointerEvent) => {
                        if ((pointerEvent.pointerType === 'mouse' && pointerEvent.button !== 0) || event._calendarDrag) return;
                        console.debug('[board] bar pointerdown', {
                            kind: 'block', id: block.id, pointerType: pointerEvent.pointerType,
                            target: document.elementFromPoint(pointerEvent.clientX, pointerEvent.clientY)?.className,
                        });
                        event.setPointerCapture(pointerEvent.pointerId);
                        event._calendarDrag = {
                            pointerId: pointerEvent.pointerId,
                            startX: pointerEvent.clientX,
                            startY: pointerEvent.clientY,
                            moved: false,
                            duration: (() => {
                                const start = toMinutes(block.start_time);
                                let end = toMinutes(block.end_time);
                                if (end <= start) end += 1440;
                                return end - start;
                            })(),
                            ghost: null,
                            target: null,
                        };
                    });
                    event.addEventListener('pointermove', (pointerEvent) => {
                        const drag = event._calendarDrag;
                        if (!drag || drag.pointerId !== pointerEvent.pointerId) return;
                        if (!drag.moved && Math.hypot(pointerEvent.clientX - drag.startX, pointerEvent.clientY - drag.startY) > 5) {
                            drag.moved = true;
                            beginMonthDrag(pointerEvent.pointerId);
                            monthGrid.classList.add('is-booking-drag');
                            console.debug('[board] threshold reached', { kind: 'block', id: block.id });
                        }
                        if (!drag.moved) return;
                        pointerEvent.preventDefault();
                        lockMonthScroll();
                        const targetColumn = document.elementsFromPoint(pointerEvent.clientX, pointerEvent.clientY)
                            .map((elementAtPoint) => elementAtPoint.closest?.('.court-month-date-col'))
                            .find((candidate) => candidate && monthGrid.contains(candidate));
                        const slot = targetColumn ? slotFromPoint(pointerEvent, targetColumn) : null;
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
                        const conflict = rangeHasConflict(slot.date, startMinutes, endMinutes, null, block.court_id, block.id);
                        drag.ghost.classList.toggle('is-conflict', conflict);
                        drag.target = { date: slot.date, startMinutes, endMinutes, conflict };
                    });
                    const stopBlockDrag = async (pointerEvent, cancelled = false) => {
                        const drag = event._calendarDrag;
                        if (!drag || drag.pointerId !== pointerEvent.pointerId) return;
                        event._calendarDrag = null;
                        if (event.hasPointerCapture(pointerEvent.pointerId)) event.releasePointerCapture(pointerEvent.pointerId);
                        drag.ghost?.remove();
                        if (!drag.moved) return;
                        pointerEvent.preventDefault();
                        event._suppressCalendarClick = true;
                        window.setTimeout(() => { event._suppressCalendarClick = false; }, 0);
                        endMonthDrag(pointerEvent.pointerId);
                        monthGrid.classList.remove('is-booking-drag');
                        if (cancelled || !drag.target) return;
                        console.debug('[board] drop target', { kind: 'block', id: block.id, ...drag.target });
                        if (drag.target.conflict) {
                            notify('That time overlaps a booking, closure, or another calendar event.', 'error');
                            return;
                        }
                        const token = csrfToken();
                        if (!token) {
                            notify('Your security token is missing. Reload the page and try again.', 'error');
                            return;
                        }
                        const payload = {
                            block_date: drag.target.date,
                            start_time: formatTimeInput(drag.target.startMinutes),
                            end_time: formatTimeInput(drag.target.endMinutes),
                        };
                        try {
                            const response = await fetch(`/admin/blocks/${encodeURIComponent(block.id)}`, {
                                method: 'PATCH',
                                credentials: 'same-origin',
                                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                                body: JSON.stringify(payload),
                            });
                            const result = await response.json().catch(() => ({}));
                            console.debug('[board] PATCH response', { kind: 'block', id: block.id, status: response.status, ok: response.ok, message: result.message, conflict: result.conflict?.type });
                            if (!response.ok) {
                                notify(result.message || 'Could not move this calendar event.', 'error');
                                return;
                            }
                            const index = blocks.findIndex((item) => String(item.id) === String(result.block.id));
                            if (index >= 0) blocks[index] = normalizeBlock(result.block);
                            renderMonth();
                            renderBoard();
                            notify(result.message || 'Calendar event moved.', 'success');
                        } catch (error) {
                            console.debug('[board] PATCH response', { kind: 'block', id: block.id, error: error?.message });
                            notify('Could not move this calendar event. Please try again.', 'error');
                        }
                    };
                    event.addEventListener('pointerup', (pointerEvent) => { void stopBlockDrag(pointerEvent); });
                    event.addEventListener('pointercancel', (pointerEvent) => { void stopBlockDrag(pointerEvent, true); });
                    event.addEventListener('lostpointercapture', (pointerEvent) => { void stopBlockDrag(pointerEvent, true); });
                    col.append(event);
                    return;
                }

                const colors = STATUS_COLORS[booking.status] || STATUS_COLORS.paid;
                const accentColor = LANE_ACCENTS[index % LANE_ACCENTS.length];
                const initials = toInitials(booking.customer || '');

                const bar = document.createElement('button');
                bar.type = 'button';
                bar.className = `court-month-bar status-${booking.status}`;
                bar.style.cssText = `
                    position: absolute;
                    top: ${topPx}px;
                    left: ${lane * barW + 2}px;
                    width: ${barW - 2}px;
                    height: ${heightPx}px;
                    background: ${colors.bg};
                    border: 1px solid ${colors.border};
                    border-left: 3px solid ${accentColor};
                    border-radius: var(--r-sm);
                    color: ${colors.text};
                    font-size: var(--font-size-small);
                    font-weight: 800;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    overflow: hidden;
                    cursor: pointer;
                    padding: 0;
                    letter-spacing: .03em;
                `;
                bar.textContent = booking.source === 'walk_in' ? 'Walk-in' : initials;
                if (booking.source === 'walk_in') bar.classList.add('source-walk-in');
                bar.title = `${booking.source === 'walk_in' ? 'Walk-in · ' : ''}${booking.customer || 'Booking'} · ${booking.court || ''} · ${booking.status}`;
                bar.addEventListener('click', (clickEvent) => {
                    if (bar._suppressCalendarClick) {
                        bar._suppressCalendarClick = false;
                        clickEvent.preventDefault();
                        return;
                    }
                    openBookingDetails(booking);
                });
                bar.addEventListener('pointerdown', (pointerEvent) => {
                    if ((pointerEvent.pointerType === 'mouse' && pointerEvent.button !== 0) || bar._calendarDrag) return;
                    console.debug('[board] bar pointerdown', {
                        kind: 'booking', id: booking.id, status: booking.status,
                        pointerType: pointerEvent.pointerType,
                        target: document.elementFromPoint(pointerEvent.clientX, pointerEvent.clientY)?.className,
                    });
                    bar.setPointerCapture(pointerEvent.pointerId);
                    bar._calendarDrag = {
                        pointerId: pointerEvent.pointerId,
                        startX: pointerEvent.clientX,
                        startY: pointerEvent.clientY,
                        moved: false,
                        booking,
                        duration: Math.max(stepMinutes, (() => {
                            const startMinutes = toMinutes(booking.start);
                            let endMinutes = toMinutes(booking.end);
                            if (endMinutes <= startMinutes) endMinutes += 1440;
                            return endMinutes - startMinutes;
                        })()),
                        ghost: null,
                        target: null,
                    };
                });
                bar.addEventListener('pointermove', (pointerEvent) => {
                    const drag = bar._calendarDrag;
                    if (!drag || drag.pointerId !== pointerEvent.pointerId) return;
                    if (!drag.moved && Math.hypot(pointerEvent.clientX - drag.startX, pointerEvent.clientY - drag.startY) > 5) {
                        drag.moved = true;
                        beginMonthDrag(pointerEvent.pointerId);
                        monthGrid.classList.add('is-booking-drag');
                        console.debug('[board] threshold reached', { kind: 'booking', id: booking.id });
                    }
                    if (!drag.moved) return;
                    pointerEvent.preventDefault();
                    lockMonthScroll();
                    const targetColumn = document.elementsFromPoint(pointerEvent.clientX, pointerEvent.clientY)
                        .map((element) => element.closest?.('.court-month-date-col'))
                        .find((column) => column && monthGrid.contains(column));
                    const slot = targetColumn ? slotFromPoint(pointerEvent, targetColumn) : null;
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
                    const yForTarget = targetColumn._monthYForMinute || ((minutes) => {
                        const matching = targetColumn.querySelector(`.court-month-hour-cell[data-hour="${Math.floor(minutes / 60)}"]`);
                        return matching ? matching.offsetTop + ((minutes % 60) / 60) * matching.offsetHeight : 0;
                    });
                    const top = yForTarget(startMinutes);
                    const bottom = yForTarget(endMinutes);
                    drag.ghost.style.top = `${top}px`;
                    drag.ghost.style.height = `${Math.max(8, bottom - top)}px`;
                    const conflict = rangeHasConflict(slot.date, startMinutes, endMinutes, booking.id, booking.court_id);
                    drag.ghost.classList.toggle('is-conflict', conflict);
                    drag.target = { date: slot.date, startMinutes, endMinutes, conflict };
                });
                const stopBookingDrag = async (pointerEvent, cancelled = false) => {
                    const drag = bar._calendarDrag;
                    if (!drag || drag.pointerId !== pointerEvent.pointerId) return;
                    bar._calendarDrag = null;
                    if (bar.hasPointerCapture(pointerEvent.pointerId)) bar.releasePointerCapture(pointerEvent.pointerId);
                    drag.ghost?.remove();
                    if (drag.moved) {
                        pointerEvent.preventDefault();
                        bar._suppressCalendarClick = true;
                        window.setTimeout(() => { bar._suppressCalendarClick = false; }, 0);
                        endMonthDrag(pointerEvent.pointerId);
                        monthGrid.classList.remove('is-booking-drag');
                        if (cancelled || !drag.target) return;
                        console.debug('[board] drop target', { kind: 'booking', id: booking.id, ...drag.target });
                        if (drag.target.conflict) {
                            notify('That time overlaps another booking or calendar event.', 'error');
                            return;
                        }
                        const token = csrfToken();
                        if (!token) {
                            notify('Your security token is missing. Reload the page and try again.', 'error');
                            return;
                        }
                        const payload = {
                            date: drag.target.date,
                            start_time: formatTimeInput(drag.target.startMinutes),
                            end_time: formatTimeInput(drag.target.endMinutes),
                        };
                        try {
                            const response = await fetch(`/admin/bookings/${encodeURIComponent(booking.id)}`, {
                                method: 'PATCH',
                                credentials: 'same-origin',
                                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                                body: JSON.stringify(payload),
                            });
                            const result = await response.json().catch(() => ({}));
                            console.debug('[board] PATCH response', { kind: 'booking', id: booking.id, status: response.status, ok: response.ok, message: result.message, conflict: result.conflict?.type });
                            if (!response.ok) {
                                if (result.new_amount !== undefined) {
                                    openBookingDetails(booking);
                                    openBookingEdit();
                                    const form = document.getElementById('courtBookingEditForm');
                                    form.elements.date.value = payload.date;
                                    form.elements.start_time.value = payload.start_time;
                                    form.elements.end_time.value = payload.end_time;
                                    form.elements.amount.value = Number(result.new_amount).toFixed(2);
                                    updatePriceAcknowledgementVisibility();
                                    bookingEditMessage.textContent = result.message || 'Review and acknowledge the price change to finish rescheduling.';
                                    bookingEditMessage.classList.add('is-error');
                                } else {
                                    notify(result.message || 'Could not reschedule this booking.', 'error');
                                }
                                return;
                            }
                            const index = bookings.findIndex((item) => String(item.id) === String(result.booking.id));
                            if (index >= 0) bookings[index] = result.booking;
                            renderMonth();
                            renderBoard();
                            notify(result.message || 'Booking rescheduled.', 'success');
                        } catch (error) {
                            console.debug('[board] PATCH response', { kind: 'booking', id: booking.id, error: error?.message });
                            notify('Could not reschedule this booking. Please try again.', 'error');
                        }
                        return;
                    }
                    if (cancelled) return;
                };
                bar.addEventListener('pointerup', (pointerEvent) => { void stopBookingDrag(pointerEvent); });
                bar.addEventListener('pointercancel', (pointerEvent) => { void stopBookingDrag(pointerEvent, true); });
                bar.addEventListener('lostpointercapture', (pointerEvent) => { void stopBookingDrag(pointerEvent, true); });
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
                selection.ghost.classList.toggle('is-conflict', conflict);
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
                if ((pointerEvent.pointerType === 'mouse' && pointerEvent.button !== 0)
                    || pointerEvent.target.closest('.court-month-bar, .court-month-block')) return;
                const slot = slotFromPoint(pointerEvent, col);
                if (!slot) return;

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
                const selection = col._courtMonthSelection;
                if (!selection || selection.pointerId !== pointerEvent.pointerId) return;
                col._courtMonthSelection = null;
                if (col.hasPointerCapture(pointerEvent.pointerId)) col.releasePointerCapture(pointerEvent.pointerId);
                endMonthDrag(pointerEvent.pointerId);
                finishPointerSelection(selection, true);
            });

            col.addEventListener('lostpointercapture', (pointerEvent) => {
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
                width: ${dates.length * COL_W}px;
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

        monthGrid.append(body);

        const monthLabel = new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const activeCount = [...windowsByDate.values()].flat().filter((event) => event.kind === 'booking').length;
        monthSummary.textContent = `${monthLabel} · ${activeCount} active reservation${activeCount === 1 ? '' : 's'}`;

        if (!monthInitialScrollDone) {
            monthInitialScrollDone = true;
            const scrollArea = monthGrid.closest('.court-month-scroll');
            const todayColumn = Array.from(monthGrid.querySelectorAll('.court-month-date-col'))
                .find((column) => column.dataset.date === todayValue);
            if (scrollArea) {
                requestAnimationFrame(() => {
                    const openingTop = hourTop.get(BIZ_OPEN_HOUR) || 0;
                    scrollArea.scrollTop = Math.max(0, openingTop - ROW_H);
                    if (todayColumn) {
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

    tabs.forEach((tab) => tab.addEventListener('click', () => changeView(tab.dataset.bookingsView)));
    dateInput.addEventListener('change', renderBoard);
    monthInput.addEventListener('change', renderMonth);

    changeView(initialView, false);

    workspace.querySelectorAll('[data-month-nav]').forEach((button) => {
        button.addEventListener('click', () => {
            const [year, month] = monthInput.value.split('-').map(Number);
            const cursor = new Date(year, month - 1, 1);
            if (button.dataset.monthNav === 'today') cursor.setTime(new Date().setDate(1));
            if (button.dataset.monthNav === 'previous') cursor.setMonth(cursor.getMonth() - 1);
            if (button.dataset.monthNav === 'next') cursor.setMonth(cursor.getMonth() + 1);
            monthInput.value = toMonthValue(cursor);
            renderMonth();
        });
    });

    workspace.querySelectorAll('[data-board-date]').forEach((button) => {
        button.addEventListener('click', () => {
            const today = new Date();
            const value = new Date(`${dateInput.value}T00:00:00`);
            if (button.dataset.boardDate === 'today') value.setTime(today.setHours(0, 0, 0, 0));
            if (button.dataset.boardDate === 'previous') value.setDate(value.getDate() - 1);
            if (button.dataset.boardDate === 'next') value.setDate(value.getDate() + 1);
            dateInput.value = toDateValue(value);
            renderBoard();
        });
    });
})();
