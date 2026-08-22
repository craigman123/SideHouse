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
                    // An end of "00:00" etc. on an overnight slot reads as
                    // the top of the range, not the bottom.
                    const endMinutes = toMinutes(end) === 0 ? 24 * 60 : toMinutes(end);
                    maxMinutes = Math.max(maxMinutes, endMinutes);
                });
        });

        if (minMinutes >= maxMinutes) {
            // No bookings anywhere yet — fall back to a sane default
            // rather than an empty/negative-width grid.
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
    // (different courts, same time) stack instead of overlapping visually.
    const assignLanes = (items) => {
        const lanes = []; // lanes[i] = end-minutes of the last item placed in that lane
        return items
            .slice()
            .sort((a, b) => a.startMinutes - b.startMinutes)
            .map((item) => {
                let lane = lanes.findIndex((endMinutes) => endMinutes <= item.startMinutes);
                if (lane === -1) {
                    lane = lanes.length;
                    lanes.push(0);
                }
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

    // Fixed px-per-hour so the timeline never squeezes to fit the screen —
    // the .court-month-scroll wrapper (width: 100%, overflow-x: auto)
    // handles narrow screens by scrolling, not by shrinking hour labels
    // into unreadable overlapping text.
    const PX_PER_HOUR = 55;

    // Border-left accents cycle by the booking's order within the day, not
    // status — status is already shown via the bar's fill color, so a
    // separate per-booking color makes every booking on a given date easy
    // to tell apart at a glance, even ones that don't overlap in time.
    const LANE_ACCENTS = [
        '#56d364', '#58a6ff', '#d2a8ff', '#f2cc60',
        '#ff9492', '#39c5cf', '#ffa657', '#7ee2b8',
        '#f778ba', '#79c0ff', '#e3b341', '#a5d6ff',
    ];

    const renderMonth = () => {
        if (!monthInput.value) return;
        const [year, month] = monthInput.value.split('-').map(Number);
        const daysInMonth = new Date(year, month, 0).getDate();
        const { startHour, endHour } = getHourRange();
        const trackWidth = (endHour - startHour) * PX_PER_HOUR;
        const todayValue = toDateValue(new Date());

        monthGrid.replaceChildren();
        monthGrid.style.width = `${64 + trackWidth}px`; // label column + track

        // Header row: date column + one tick per hour in range.
        const header = document.createElement('div');
        header.className = 'court-month-header';
        const headerLabel = document.createElement('span');
        headerLabel.className = 'court-month-row-label';
        headerLabel.textContent = 'Date';
        header.append(headerLabel);

        const headerTrack = document.createElement('div');
        headerTrack.className = 'court-month-track court-month-track-header';
        headerTrack.style.width = `${trackWidth}px`;
        for (let hour = startHour; hour <= endHour; hour += 1) {
            const tick = document.createElement('span');
            tick.className = 'court-month-tick';
            tick.style.left = `${(hour - startHour) * PX_PER_HOUR}px`;
            tick.textContent = formatHourLabel(hour);
            headerTrack.append(tick);
        }
        header.append(headerTrack);
        monthGrid.append(header);

        let activeCount = 0;

        for (let day = 1; day <= daysInMonth; day += 1) {
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

            const windows = bookings
                .flatMap((booking) => eventWindows(booking, dateStr).map((window) => ({ booking, window })))
                .filter(({ booking }) => booking.status !== 'cancelled')
                .map(({ booking, window }) => ({
                    booking,
                    startMinutes: toMinutes(window.start),
                    endMinutes: toMinutes(window.end) === 0 ? 24 * 60 : toMinutes(window.end),
                }));

            // A single booking can produce several slot windows for the same
            // day (e.g. hourly recurring slots). Drawn separately those come
            // out as touching-but-distinct pills with their own rounded
            // corners and border — merge each booking's overlapping/adjacent
            // windows into one continuous range so it renders as a single
            // connected bar, the way one booking should look.
            const mergedByBooking = new Map();
            windows
                .slice()
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
            const mergedWindows = [...mergedByBooking.values()].flat();

            const dayClosures = closures.filter((closure) => closure.date === dateStr);
            const lanedWindows = assignLanes(mergedWindows);
            activeCount += windows.length;

            const row = document.createElement('div');
            row.className = 'court-month-row';
            if (dateStr === todayValue) row.classList.add('is-today');
            if (dayClosures.length) row.classList.add('has-closure');

            const label = document.createElement('button');
            label.type = 'button';
            label.className = 'court-month-row-label court-month-row-label-btn';
            const weekday = new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', { weekday: 'short' });
            label.innerHTML = `<strong>${day}</strong><span>${weekday}</span>`;
            label.title = `Open ${formatDate(dateStr)} on the daily board`;
            label.addEventListener('click', () => jumpToDay(dateStr));
            row.append(label);

            const track = document.createElement('div');
            track.className = 'court-month-track';
            track.style.width = `${trackWidth}px`;
            track.style.minHeight = `${Math.max(1, Math.max(...lanedWindows.map((w) => w.laneCount), 1)) * 20}px`;

            if (dayClosures.length) {
                const closureStrip = document.createElement('div');
                closureStrip.className = 'court-month-closure';
                closureStrip.title = dayClosures.map((c) => c.reason || 'Closed').join(', ');
                track.append(closureStrip);
            }

            lanedWindows.forEach(({ booking, startMinutes, endMinutes, lane }, index) => {
                const bar = document.createElement('button');
                bar.type = 'button';
                bar.className = `court-month-bar status-${booking.status}`;
                const clampedStart = Math.max(startMinutes, startHour * 60);
                const clampedEnd = Math.min(endMinutes, endHour * 60);
                bar.style.left = `${((clampedStart - startHour * 60) / 60) * PX_PER_HOUR}px`;
                bar.style.width = `${Math.max(10, ((clampedEnd - clampedStart) / 60) * PX_PER_HOUR)}px`;
                bar.style.top = `${lane * 20}px`;
                // Cycle by this booking's position within the day (not its
                // lane) so every booking on the same date gets a distinct
                // border color — two non-overlapping bookings that happen
                // to land in the same lane would otherwise look identical.
                bar.style.borderLeftColor = LANE_ACCENTS[index % LANE_ACCENTS.length];
                bar.title = `${booking.customer || 'Walk-in booking'} · ${booking.court} · ${booking.status}`;

                const label = document.createElement('span');
                label.textContent = booking.customer || 'Walk-in booking';
                bar.append(label);

                bar.addEventListener('click', () => jumpToDay(dateStr));
                track.append(bar);
            });

            row.append(track);
            monthGrid.append(row);
        }


        const monthLabel = new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
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