(() => {
    const workspace = document.getElementById('bookingsWorkspace');
    const dataElement = document.getElementById('courtBoardData');
    const board = document.getElementById('courtBoard');

    if (!workspace || !dataElement || !board) return;

    const data = JSON.parse(dataElement.textContent || '{}');
    const bookings = data.bookings || [];
    const closures = data.closures || [];
    const courts = data.courts || [];
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

    const changeView = (view) => {
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
            courtEvents
                .sort((a, b) => a.window.start.localeCompare(b.window.start))
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

    // The board data spans a fixed 6-month window (see BookingController),
    // so the operating-hours range is derived once from every booking in
    // it rather than per-month — keeps the time axis stable as you flip
    // between months instead of jumping around based on what happened to
    // be booked that particular month.
    let cachedHourRange = null;
    const getHourRange = () => {
        if (cachedHourRange) return cachedHourRange;

        let minMinutes = 24 * 60;
        let maxMinutes = 0;
        bookings.forEach((booking) => {
            (booking.slots && booking.slots.length ? booking.slots : [{ start: booking.start, end: booking.end }])
                .forEach(({ start, end }) => {
                    if (!start || !end) return;
                    minMinutes = Math.min(minMinutes, toMinutes(start));
                    const endMinutes = toMinutes(end) === 0 ? 24 * 60 : toMinutes(end);
                    maxMinutes = Math.max(maxMinutes, endMinutes);
                });
        });

        if (minMinutes >= maxMinutes) {
            cachedHourRange = { startHour: 6, endHour: 22 };
        } else {
            cachedHourRange = {
                startHour: Math.max(0, Math.floor(minMinutes / 60)),
                endHour: Math.min(24, Math.ceil(maxMinutes / 60)),
            };
        }
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

    // ── Professional muted palette ──────────────────────────────────────────
    // Replaces the neon rainbow. Colours are readable on dark backgrounds
    // without screaming for attention.
    const STATUS_COLORS = {
        paid:      { bg: '#1e3a5f', border: '#2d6a9f', text: '#a8c8e8' },
        pending:   { bg: '#3d2e0a', border: '#7a5c1e', text: '#c9a84c' },
        cancelled: { bg: '#2a1515', border: '#5c2626', text: '#a05050' },
    };

    // Per-booking accent border — muted, distinct, cycles by booking index.
    const LANE_ACCENTS = [
        '#2d6a9f', '#3d7a5c', '#6b4d8a', '#7a6a2d',
        '#2d6a6a', '#7a3d5c', '#5c6a2d', '#4d5c8a',
        '#8a4d3d', '#3d5c7a', '#6a5c3d', '#4d8a6b',
    ];

    // ── Month view: dates across the top, time down the left ────────────────
    const renderMonth = () => {
        if (!monthInput.value) return;
        const [year, month] = monthInput.value.split('-').map(Number);
        const daysInMonth = new Date(year, month, 0).getDate();
        const { startHour, endHour } = getHourRange();
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

        const totalHours = endHour - startHour;
        const gridW = TIME_LABEL_W + dates.length * COL_W;
        const gridH = totalHours * ROW_H;

        monthGrid.replaceChildren();
        monthGrid.style.width = `${gridW}px`;

        // ── Header row: blank corner + one date column per day ──────────────
        const header = document.createElement('div');
        header.className = 'court-month-header';
        header.style.display = 'flex';
        header.style.position = 'sticky';
        header.style.top = '0';
        header.style.zIndex = '3';
        header.style.background = '#161b22';

        // Corner cell
        const corner = document.createElement('div');
        corner.className = 'court-month-corner';
        corner.style.cssText = `flex:0 0 ${TIME_LABEL_W}px;width:${TIME_LABEL_W}px;border-right:1px solid #21262d;border-bottom:1px solid #21262d;`;
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
        // Pre-build merged booking windows per date.
        const windowsByDate = new Map();
        dates.forEach((dateStr) => {
            const raw = bookings
                .flatMap((booking) => eventWindows(booking, dateStr).map((window) => ({ booking, window })))
                .map(({ booking, window }) => ({
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

            windowsByDate.set(dateStr, [...mergedByBooking.values()].flat());
        });

        // One wrapper div that holds all rows — positions bars absolutely.
        const body = document.createElement('div');
        body.className = 'court-month-body';
        body.style.cssText = `position:relative;display:flex;`;

        // Time label column (sticky left).
        const timeCol = document.createElement('div');
        timeCol.className = 'court-month-time-col';
        timeCol.style.cssText = `flex:0 0 ${TIME_LABEL_W}px;width:${TIME_LABEL_W}px;position:sticky;left:0;z-index:2;background:#0d1117;border-right:1px solid #21262d;`;

        for (let hour = startHour; hour < endHour; hour++) {
            const cell = document.createElement('div');
            cell.className = 'court-month-time-cell';
            cell.style.cssText = `height:${ROW_H}px;display:flex;align-items:flex-start;justify-content:center;padding-top:6px;border-bottom:1px solid #21262d;color:#6e7681;font-size:10px;font-weight:700;`;
            cell.textContent = formatHourLabel(hour);
            timeCol.append(cell);
        }
        body.append(timeCol);

        // Date columns.
        dates.forEach((dateStr, colIndex) => {
            const colClosures = closures.filter((c) => c.date === dateStr);
            const colWindows = windowsByDate.get(dateStr) || [];
            const isToday = dateStr === todayValue;

            const col = document.createElement('div');
            col.className = 'court-month-date-col' + (isToday ? ' is-today' : '');
            col.style.cssText = `flex:0 0 ${COL_W}px;width:${COL_W}px;position:relative;border-right:1px solid #21262d;`;

            // Hour grid lines.
            for (let hour = startHour; hour < endHour; hour++) {
                const cell = document.createElement('div');
                cell.style.cssText = `height:${ROW_H}px;border-bottom:1px solid #21262d;box-sizing:border-box;`;
                if (colClosures.length) cell.style.background = 'rgba(163,113,247,.07)';
                col.append(cell);
            }

            // Closure strip overlay.
            if (colClosures.length) {
                const strip = document.createElement('div');
                strip.className = 'court-month-closure-col';
                strip.style.cssText = `position:absolute;inset:0;background:rgba(163,113,247,.06);border-left:2px solid rgba(163,113,247,.35);pointer-events:none;`;
                strip.title = colClosures.map((c) => c.reason || 'Closed').join(', ');
                col.append(strip);
            }

            // Booking bars — absolute positioned by time within the column.
            const laned = assignLanes(colWindows);
            const laneCount = laned.length ? Math.max(...laned.map((w) => w.laneCount)) : 1;
            const barW = Math.floor((COL_W - 4) / Math.max(1, laneCount));

            laned.forEach(({ booking, startMinutes, endMinutes, lane }, index) => {
                const clampedStart = Math.max(startMinutes, startHour * 60);
                const clampedEnd = Math.min(endMinutes, endHour * 60);
                const topPx = ((clampedStart - startHour * 60) / 60) * ROW_H;
                const heightPx = Math.max(18, ((clampedEnd - clampedStart) / 60) * ROW_H);

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
                    border-radius: 4px;
                    color: ${colors.text};
                    font-size: 11px;
                    font-weight: 800;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    overflow: hidden;
                    cursor: pointer;
                    padding: 0;
                    letter-spacing: .03em;
                `;
                bar.textContent = initials;
                bar.title = `${booking.customer || 'Walk-in'} · ${booking.court || ''} · ${booking.status}`;
                bar.addEventListener('click', () => jumpToDay(dateStr));
                col.append(bar);
            });

            body.append(col);
        });

        monthGrid.append(body);

        const monthLabel = new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const activeCount = [...windowsByDate.values()].flat().filter(({ booking }) => booking.status !== 'cancelled').length;
        monthSummary.textContent = `${monthLabel} · ${activeCount} active reservation${activeCount === 1 ? '' : 's'}`;
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => changeView(tab.dataset.bookingsView)));
    dateInput.addEventListener('change', renderBoard);
    monthInput.addEventListener('change', renderMonth);

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