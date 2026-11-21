(() => {
    const page = document.getElementById('staffOverview');
    const payload = document.getElementById('staffOverviewData');
    if (!page || !payload) return;

    const bookingCount = document.getElementById('staffTodayBookingCount');
    const date = document.getElementById('staffOverviewDate');
    const upcoming = document.getElementById('staffUpcomingBookings');
    const events = document.getElementById('staffTodayEvents');
    const sync = document.getElementById('staffSyncStatus');
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
        if (!rows?.length) {
            target.append(element('p', 'staff-overview-empty', emptyText));
            return;
        }
        rows.forEach((row) => target.append(build(row)));
    };

    const render = (data) => {
        current = data;
        bookingCount.textContent = Number(data.booking_count || 0).toLocaleString();
        date.textContent = data.date || '';
        renderRows(upcoming, data.upcoming, 'No upcoming bookings today.', (booking) => {
            const row = element('div', 'staff-overview-row');
            row.append(element('strong', '', booking.customer || 'Customer'));
            row.append(element('span', '', booking.time || ''));
            row.append(element('span', 'staff-overview-row-meta', [booking.court, booking.status].filter(Boolean).join(' · ')));
            return row;
        });
        renderRows(events, data.events, 'No sessions, closures, or maintenance today.', (event) => {
            const row = element('div', 'staff-overview-row');
            row.append(element('strong', '', event.title || event.type || 'Court event'));
            row.append(element('span', '', event.time || ''));
            const joined = event.type === 'open_play' && event.joined != null && event.capacity != null
                ? `${event.joined}/${event.capacity} joined`
                : '';
            row.append(element('span', 'staff-overview-row-meta', [event.court, joined].filter(Boolean).join(' · ')));
            return row;
        });
        sync.textContent = `Updated ${data.synced_at || 'just now'}`;
        sync.classList.remove('is-paused');
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
        } catch (error) {
            sync.textContent = 'Sync paused · refresh to retry';
            sync.classList.add('is-paused');
        }
        schedule();
    };

    const schedule = () => {
        if (timer) window.clearTimeout(timer);
        timer = null;
        if (document.visibilityState === 'visible') timer = window.setTimeout(poll, 45_000);
    };

    render(current);
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
