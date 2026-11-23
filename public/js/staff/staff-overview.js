(() => {
    const page = document.getElementById('staffOverview');
    const payload = document.getElementById('staffOverviewData');
    if (!page || !payload || !page.dataset.overviewUrl) return;

    const bookingCount = document.getElementById('staffTodayBookingCount');
    const courtCount = document.getElementById('staffCourtCount');
    const courts = document.getElementById('staffCourtStatus');
    const upcoming = document.getElementById('staffUpcomingBookings');
    const needsAttention = document.getElementById('staffNeedsAttention');
    const openPlay = document.getElementById('staffOpenPlay');
    const equipmentPrep = document.getElementById('staffEquipmentPrep');
    const sync = document.getElementById('staffSyncStatus');
    const error = document.getElementById('staffOverviewError');
    const retry = document.getElementById('staffOverviewRetry');
    let current = JSON.parse(payload.textContent || '{}');
    let timer = null;

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    };

    const renderRows = (target, rows, emptyText, build) => {
        target.replaceChildren();
        if (!Array.isArray(rows) || rows.length === 0) {
            target.append(element('p', 'staff-overview-empty', emptyText));
            return;
        }
        rows.forEach((row) => target.append(build(row)));
    };

    const buildRow = (title, time, meta) => {
        const row = element('div', 'staff-overview-row');
        row.append(element('strong', '', title || '—'));
        row.append(element('span', '', time || ''));
        if (meta) row.append(element('span', 'staff-overview-row-meta', meta));
        return row;
    };

    const render = (data) => {
        current = data;
        bookingCount.textContent = Number(data.booking_count || 0).toLocaleString();
        courtCount.textContent = Number(data.court_count || 0).toLocaleString();
        renderRows(courts, data.courts, 'No courts are configured yet.', (court) => buildRow(court.name, court.status, ''));
        renderRows(upcoming, data.upcoming, 'No upcoming bookings today.', (booking) =>
            buildRow(booking.customer, booking.time, [booking.court, booking.status].filter(Boolean).join(' · ')));
        renderRows(needsAttention, data.needs_attention, 'Nothing needs attention.', (booking) =>
            buildRow(booking.customer, booking.time, [booking.court, booking.status].filter(Boolean).join(' · ')));
        renderRows(openPlay, data.open_play, 'No open play sessions today.', (session) =>
            buildRow(session.title, session.time, session.court));
        renderRows(equipmentPrep, data.equipment_prep, 'No equipment is reserved for today.', (item) =>
            buildRow(item.name, `${Number(item.quantity || 0)} reserved`, 'Prepare for today’s bookings'));
        sync.textContent = 'Updated just now';
        sync.classList.remove('is-paused');
        error.hidden = true;
    };

    const schedule = () => {
        if (timer) window.clearTimeout(timer);
        timer = null;
        if (document.visibilityState === 'visible') timer = window.setTimeout(poll, 45_000);
    };

    const poll = async () => {
        if (document.visibilityState !== 'visible') {
            sync.textContent = 'Sync paused';
            sync.classList.add('is-paused');
            return schedule();
        }

        try {
            const response = await fetch(page.dataset.overviewUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`Overview request failed (${response.status})`);
            render(await response.json());
        } catch (_) {
            error.hidden = false;
            sync.textContent = 'Update failed';
            sync.classList.add('is-paused');
        }
        schedule();
    };

    render(current);
    retry.addEventListener('click', () => void poll());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void poll();
        else {
            if (timer) window.clearTimeout(timer);
            timer = null;
            sync.textContent = 'Sync paused';
            sync.classList.add('is-paused');
        }
    });
    schedule();
})();
