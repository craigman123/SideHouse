
document.addEventListener('DOMContentLoaded', () => {
    const statsSection = document.getElementById('courtStats');
    const bookNow = document.getElementById('bookNow');
    const chartEl = document.getElementById('vacantChart');
    if (!statsSection || !bookNow || !chartEl) return;

    const statsUrl = statsSection.dataset.statsUrl;
    const monthTag = document.getElementById('vacantChartMonthLabel');
    const yAxisEl = document.getElementById('vacantYAxisNumbers');
    const chartScroll = chartEl.closest('.stats-chart-scroll');

    const MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    // ---- Daily operating capacity, read straight off #bookNow's data-* ----
    const OPEN_HOUR = parseInt(bookNow.dataset.openHour, 10);
    const CLOSE_HOUR = parseInt(bookNow.dataset.closeHour, 10);
    const CLOSED_WEEKDAYS = (bookNow.dataset.closedWeekdays || '')
        .split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => !isNaN(n));
    const CLOSURE_DATES = (bookNow.dataset.closureDates || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);

    function dailyCapacityHours(dateStr) {
        const weekday = new Date(`${dateStr}T00:00:00`).getDay();
        if (CLOSED_WEEKDAYS.includes(weekday) || CLOSURE_DATES.includes(dateStr)) return 0;
        // Overnight courts (close hour <= open hour) wrap past midnight.
        return CLOSE_HOUR <= OPEN_HOUR ? (24 - OPEN_HOUR) + CLOSE_HOUR : CLOSE_HOUR - OPEN_HOUR;
    }

    function formatHours(n) {
        const rounded = Math.round(n * 10) / 10;
        return rounded % 1 === 0 ? `${rounded}` : rounded.toFixed(1);
    }

    function formatDateLabel(dateStr) {
        return new Date(`${dateStr}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function localTodayStr() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    // How many of today's operating hours have already gone by, right
    // now — clamped to [0, capacity]. Only meaningful for today; used to
    // shrink today's vacant count as the day passes, separate from hours
    // that are actually booked.
    function hoursPassedToday(capacity) {
        if (capacity <= 0) return 0;
        const now = new Date();
        const nowMin = now.getHours() * 60 + now.getMinutes();
        const openMin = OPEN_HOUR * 60;
        const closeMin = CLOSE_HOUR * 60;

        let elapsedMin;
        if (CLOSE_HOUR <= OPEN_HOUR) {
            // Overnight window (e.g. 6pm–2am): before opening today, or
            // already inside the wrapped post-midnight tail.
            if (nowMin >= openMin) elapsedMin = nowMin - openMin;
            else if (nowMin < closeMin) elapsedMin = (1440 - openMin) + nowMin;
            else elapsedMin = 0;
        } else {
            if (nowMin <= openMin) elapsedMin = 0;
            else if (nowMin >= closeMin) elapsedMin = closeMin - openMin;
            else elapsedMin = nowMin - openMin;
        }

        return Math.min(Math.max(elapsedMin / 60, 0), capacity);
    }

    // Same "nice" axis rounding as the usage chart, kept local to this
    // file so it doesn't depend on guest-book.js internals.
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

    function renderGridlines(axisMax, ticks) {
        ticks.forEach((tick) => {
            const line = document.createElement('div');
            line.className = 'stats-gridline';
            line.style.bottom = `${(tick / axisMax) * 100}%`;
            chartEl.appendChild(line);
        });
    }

    function updateScrollFade() {
        if (!chartScroll) return;
        const atEnd = chartScroll.scrollWidth - chartScroll.scrollLeft - chartScroll.clientWidth < 4;
        chartScroll.classList.toggle('at-end', atEnd);
    }

    if (chartScroll) {
        chartScroll.addEventListener('scroll', updateScrollFade, { passive: true });
        window.addEventListener('resize', updateScrollFade);
    }

    // ---- Opens the existing date & time picker for a specific date ----
    function openBookingModalForDate(dateStr) {
        bookNow.scrollIntoView({ behavior: 'smooth', block: 'start' });

        const calMonthLabel = document.getElementById('calMonthLabel');
        const calPrev = document.getElementById('calPrev');
        const calNext = document.getElementById('calNext');
        const calendarGrid = document.getElementById('calendarGrid');
        if (!calMonthLabel || !calPrev || !calNext || !calendarGrid) return;

        const target = new Date(`${dateStr}T00:00:00`);
        const targetKey = target.getFullYear() * 12 + target.getMonth();

        function shownMonthKey() {
            const [name, year] = calMonthLabel.textContent.trim().split(' ');
            const monthIndex = MONTH_NAMES.indexOf(name);
            if (monthIndex === -1 || !year) return null;
            return parseInt(year, 10) * 12 + monthIndex;
        }

        let guard = 0;
        function step() {
            const shownKey = shownMonthKey();
            if (shownKey === null) return;

            if (shownKey === targetKey) {
                const dayNum = String(target.getDate());
                const dayBtn = Array.from(calendarGrid.querySelectorAll('.calendar-day'))
                    .find((el) => !el.classList.contains('calendar-day-empty') && el.textContent === dayNum);
                if (dayBtn && !dayBtn.disabled) dayBtn.click();
                return;
            }

            // Safety cap so a bad/unexpected month label can't loop forever.
            if (guard++ > 60) return;
            (shownKey < targetKey ? calNext : calPrev).click();
            requestAnimationFrame(step);
        }

        // Let the smooth scroll get moving before hopping months so the
        // calendar isn't re-laying-out mid-scroll.
        setTimeout(step, 250);
    }

    function renderVacantChart(data) {
        const days = data.days || [];
        if (monthTag) monthTag.textContent = data.month_label || '';
        chartEl.innerHTML = '';

        if (days.length === 0) {
            chartEl.innerHTML = '<p class="loading-text">No data yet this month.</p>';
            return;
        }

        const todayStr = localTodayStr();

        const vacancy = days.map((d) => {
            const capacity = dailyCapacityHours(d.date);
            const booked = d.hours || 0;
            const isToday = d.date === todayStr;
            const elapsed = isToday ? hoursPassedToday(capacity) : 0;
            const vacant = Math.max(capacity - booked - elapsed, 0);
            return { date: d.date, day: d.day, capacity, vacant };
        });

        // Scale the axis off upcoming days only — past days render as a
        // flat grey placeholder, not a data-driven bar, so they shouldn't
        // stretch/shrink the scale for the days that actually matter.
        const upcomingVacant = vacancy.filter((v) => v.date >= todayStr).map((v) => v.vacant);
        const maxVacant = Math.max(...(upcomingVacant.length ? upcomingVacant : [0]), 1);
        const { axisMax, ticks } = computeNiceScale(maxVacant);
        renderYAxis(axisMax, ticks);
        renderGridlines(axisMax, ticks);

        vacancy.forEach((v) => {
            const isToday = v.date === todayStr;
            const isPast = v.date < todayStr;
            const isClosedDay = v.capacity === 0;
            const clickable = !isClosedDay && !isPast;

            const col = document.createElement('div');
            col.className = 'stats-bar-col' + (isToday ? ' stats-bar-today' : '') + (isPast ? ' stats-bar-past' : '');
            col.style.cursor = 'pointer';

            const track = document.createElement('div');
            track.className = 'stats-bar-track';

            const bar = document.createElement('div');
            bar.className = 'stats-bar stats-bar-vacant';

            function renderPlaceholderBar(bgColor, text) {
                bar.style.background = bgColor;
                bar.style.height = '100%';
                bar.style.display = 'flex';
                bar.style.alignItems = 'center';
                bar.style.justifyContent = 'center';

                const placeholderLabel = document.createElement('span');
                placeholderLabel.className = 'stats-bar-placeholder-label';
                placeholderLabel.textContent = text;
                placeholderLabel.style.writingMode = 'vertical-rl';
                placeholderLabel.style.transform = 'rotate(180deg)';
                placeholderLabel.style.fontSize = '10px';
                placeholderLabel.style.fontWeight = '700';
                placeholderLabel.style.letterSpacing = '1px';
                placeholderLabel.style.color = '#e5e7eb';
                bar.appendChild(placeholderLabel);
            }

            if (isPast) {
                // Past dates aren't bookable anymore — show a flat grey
                // placeholder instead of a data-driven bar, with a
                // vertical "PAST" label instead of a tooltip-only cue.
                bar.title = `${formatDateLabel(v.date)}: Past date`;
                renderPlaceholderBar('#6b7280', 'PAST');
            } else if (isClosedDay) {
                // Closed (but not yet past) — flat red placeholder so a
                // closed day reads clearly at a glance, not just a
                // near-invisible zero-height bar.
                bar.title = `${formatDateLabel(v.date)}: Closed`;
                renderPlaceholderBar('#dc2626', 'CLOSED');
            } else {
                // Distinct color from the usage chart's bars so the two
                // graphs read as different metrics at a glance.
                bar.style.background = 'linear-gradient(180deg, #34d399, #059669)';
                bar.style.height = `${v.vacant > 0 ? Math.max((v.vacant / axisMax) * 100, 3) : 0}%`;
                bar.title = `${formatDateLabel(v.date)}: ${formatHours(v.vacant)} vacant hr${v.vacant === 1 ? '' : 's'} of ${formatHours(v.capacity)}${isToday ? ' remaining today' : ''}`;
            }

            track.appendChild(bar);
            col.appendChild(track);

            const label = document.createElement('span');
            label.className = 'stats-bar-label';
            label.textContent = v.day;
            col.appendChild(label);

            if (clickable) {
                col.setAttribute('role', 'button');
                col.setAttribute('tabindex', '0');
                col.setAttribute('aria-label', `Book ${formatDateLabel(v.date)}`);
                col.addEventListener('click', () => openBookingModalForDate(v.date));
                col.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openBookingModalForDate(v.date);
                    }
                });
            }

            chartEl.appendChild(col);
        });

        requestAnimationFrame(updateScrollFade);
    }

    async function loadVacantStats() {
        if (!statsUrl) {
            chartEl.innerHTML = '<p class="loading-text">Stats endpoint not configured.</p>';
            return;
        }
        try {
            const res = await fetch(statsUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Failed to load stats');
            const data = await res.json();
            renderVacantChart(data);
        } catch (err) {
            console.error(err);
            chartEl.innerHTML = '<p class="loading-text">Couldn\'t load vacancy stats right now.</p>';
            if (monthTag) monthTag.textContent = '';
        }
    }

    loadVacantStats();
});