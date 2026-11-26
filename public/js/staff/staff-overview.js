(() => {
    const page = document.getElementById('staffOverview');
    const payload = document.getElementById('staffOverviewData');
    if (!page || !payload || !page.dataset.overviewUrl) return;

    const bookingCount = document.getElementById('staffTodayBookingCount');
    const courtCount = document.getElementById('staffCourtCount');
    const courts = document.getElementById('staffCourtStatus');
    const upcoming = document.getElementById('staffUpcomingBookings');
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

    const renderOpenPlay = (session) => {
        const hasSession = Boolean(session);
        const canOpenBookings = hasSession && Boolean(page.dataset.bookingsUrl);
        const pane = element(canOpenBookings ? 'a' : 'section', 'panel staff-overview-card staff-open-play-card');

        if (canOpenBookings) {
            const url = new URL(page.dataset.bookingsUrl, window.location.origin);
            url.searchParams.set('view', 'week');
            url.searchParams.set('date', session.date);
            url.searchParams.set('highlight_open_play', String(session.id));
            pane.href = url.toString();
            pane.setAttribute('aria-label', `View ${session.title} on ${session.date_label} in Bookings`);
        }

        if (session?.is_today) pane.classList.add('is-today');

        const header = element('header', 'dashboard-panel-header staff-open-play-header');
        header.append(element('h2', '', 'Open play'));

        if (hasSession) {
            const statusLabels = { active: 'Active', upcoming: 'Upcoming', soon: 'Soon' };
            const status = statusLabels[session.status] ? session.status : 'soon';
            const indicator = element('span', `staff-open-play-status staff-open-play-status--${status}`);
            indicator.setAttribute('aria-label', `Open play status: ${statusLabels[status]}`);
            indicator.setAttribute('role', 'status');
            const dot = element('span', 'staff-open-play-status-dot');
            dot.setAttribute('aria-hidden', 'true');
            indicator.append(dot, element('span', '', statusLabels[status]));
            header.append(indicator);
        }

        pane.append(header);
        const body = element('div', 'dashboard-panel-body staff-open-play-content');

        if (!hasSession) {
            const empty = element('div', 'staff-open-play-empty');
            const iconNamespace = 'http://www.w3.org/2000/svg';
            const icon = document.createElementNS(iconNamespace, 'svg');
            icon.setAttribute('viewBox', '0 0 24 24');
            icon.setAttribute('aria-hidden', 'true');
            icon.setAttribute('focusable', 'false');
            const rect = document.createElementNS(iconNamespace, 'rect');
            rect.setAttribute('x', '3');
            rect.setAttribute('y', '5');
            rect.setAttribute('width', '18');
            rect.setAttribute('height', '16');
            rect.setAttribute('rx', '2');
            const path = document.createElementNS(iconNamespace, 'path');
            path.setAttribute('d', 'M16 3v4M8 3v4M3 11h18M8 15h3');
            icon.append(rect, path);
            empty.append(icon, element('h3', '', 'No upcoming open play'));
            empty.append(element('p', '', 'Check back later for the next session.'));
            body.append(empty);
        } else {
            const details = element('div', 'staff-open-play-details');
            details.append(element('h3', 'staff-open-play-title', session.title || 'Open play'));
            details.append(element('p', 'staff-open-play-court', session.court || 'All courts'));

            const schedule = element('div', 'staff-open-play-schedule');
            schedule.append(element('span', '', session.date_label || session.date || ''));
            schedule.append(element('span', '', session.time || ''));
            details.append(schedule);

            const players = element('div', 'staff-open-play-players');
            const participantNames = Array.isArray(session.participants) ? session.participants.slice(0, 3) : [];
            const participantCount = Math.max(0, Number(session.participant_count || 0));
            players.append(element('p', 'staff-open-play-player-count', `${participantCount} players joined`));
            if (participantNames.length > 0) {
                const names = element('div', 'staff-open-play-player-names');
                participantNames.forEach((name) => names.append(element('span', 'staff-open-play-player-name', name || 'Player')));
                const moreCount = Math.max(0, participantCount - participantNames.length);
                if (moreCount > 0) names.append(element('span', 'staff-open-play-player-more', `+${moreCount} more`));
                players.append(names);
            }

            body.append(details, players);
        }

        pane.append(body);
        openPlay.replaceChildren(pane);
    };

    const render = (data) => {
        current = data;
        bookingCount.textContent = Number(data.booking_count || 0).toLocaleString();
        courtCount.textContent = Number(data.court_count || 0).toLocaleString();
        renderRows(courts, data.courts, 'No courts are configured yet.', (court) => buildRow(court.name, court.status, ''));
        renderRows(upcoming, data.upcoming, 'No upcoming bookings today.', (booking) =>
            buildRow(booking.customer, booking.time, [booking.court, booking.status].filter(Boolean).join(' · ')));
        renderOpenPlay(data.open_play);
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
