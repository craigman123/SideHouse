document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('reportsPage');
    if (!page) return;

    const reportsUrl = page.dataset.reportsUrl;
    const systemUrl = page.dataset.systemUrl;
    const periodSelect = document.getElementById('periodSelect');
    const trafficRangeSelect = document.getElementById('trafficRangeSelect');
    const trafficPanelTitle = document.getElementById('trafficPanelTitle');
    const errorEl = document.getElementById('reportsError');

    const summaryTotalIncome = document.getElementById('summaryTotalIncome');
    const summaryMonthIncome = document.getElementById('summaryMonthIncome');
    const summaryTodayIncome = document.getElementById('summaryTodayIncome');
    const summaryPaidBookings = document.getElementById('summaryPaidBookings');

    const summaryUsersOnline = document.getElementById('summaryUsersOnline');
    const summaryRequestsToday = document.getElementById('summaryRequestsToday');
    const summaryRequestsHour = document.getElementById('summaryRequestsHour');
    const summaryUniqueVisitors = document.getElementById('summaryUniqueVisitors');

    const dbStatusPill = document.getElementById('dbStatusPill');
    const dbSize = document.getElementById('dbSize');
    const dbTableList = document.getElementById('dbTableList');
    const phpVersion = document.getElementById('phpVersion');
    const laravelVersion = document.getElementById('laravelVersion');
    const diskUsage = document.getElementById('diskUsage');
    const diskUsageBar = document.getElementById('diskUsageBar');
    const memoryUsage = document.getElementById('memoryUsage');
    const memoryUsageBar = document.getElementById('memoryUsageBar');

    const trendCanvas = document.getElementById('trendChart');
    const paymentMethodCanvas = document.getElementById('paymentMethodChart');
    const courtCanvas = document.getElementById('courtChart');
    const splitCanvas = document.getElementById('splitChart');
    const trafficTrendCanvas = document.getElementById('trafficTrendChart');

    const paymentMethodEmpty = document.getElementById('paymentMethodEmpty');
    const courtEmpty = document.getElementById('courtEmpty');
    const splitEmpty = document.getElementById('splitEmpty');

    // Palette kept small and reused across all charts so colors stay
    // consistent no matter how many slices/bars a given dataset has.
    const PALETTE = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];

    let currentPeriod = 'month';
    let currentTrafficRange = '24h';
    const TRAFFIC_RANGE_LABELS = {
        '30m': 'Last 30 Minutes',
        '1h': 'Last 1 Hour',
        '2h': 'Last 2 Hours',
        '10h': 'Last 10 Hours',
        '24h': 'Last 24 Hours',
    };
    let trendChart = null;
    let paymentMethodChart = null;
    let courtChart = null;
    let splitChart = null;
    let trafficTrendChart = null;

    function formatCurrency(n) {
        const value = Number(n) || 0;
        return `\u20b1${value.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
    }

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined || bytes < 0) return 'Unlimited';
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
    }

    function setProgressBar(barEl, percent) {
        if (!barEl) return;
        const value = percent === null || percent === undefined ? 0 : Math.max(0, Math.min(100, percent));
        barEl.style.width = `${value}%`;
        barEl.classList.toggle('is-warning', value >= 70 && value < 90);
        barEl.classList.toggle('is-critical', value >= 90);
    }

    // Paints the segmented Database/WAL/System/Available bar under
    // "Database Size", mirroring Supabase's own Infrastructure > Disk
    // page. Widths are percentages of total capacity. "System" segment
    // is intentionally left at 0 width — that figure isn't obtainable
    // from a normal Postgres connection, so we don't fake it; the
    // legend note makes that explicit instead.
    function renderDiskSegments(db) {
        const segDatabase = document.getElementById('segDatabase');
        const segWal = document.getElementById('segWal');
        const segSystem = document.getElementById('segSystem');
        if (!segDatabase || !segWal || !segSystem) return;

        const capacity = db.capacity_bytes;
        if (!capacity) {
            segDatabase.style.width = '0%';
            segWal.style.width = '0%';
            segSystem.style.width = '0%';
            return;
        }

        const pct = (bytes) => `${Math.max(0, Math.min(100, ((bytes || 0) / capacity) * 100))}%`;

        segDatabase.style.width = pct(db.size_bytes);
        segWal.style.width = db.wal_bytes != null ? pct(db.wal_bytes) : '0%';
        // No real "system" figure available — kept at 0 on purpose.
        segSystem.style.width = '0%';
    }

    function setError(show) {
        if (errorEl) errorEl.hidden = !show;
    }

    // The .is-loading class on #reportsPage is what actually shows the
    // skeleton — see admin-reports.css. It's already present in the
    // markup on first paint (before this script runs) so there's no
    // flash of "₱0" while the very first fetch is in flight.
    function setLoading(isLoading) {
        page.classList.toggle('is-loading', isLoading);
    }

    function destroyChart(chart) {
        if (chart) chart.destroy();
        return null;
    }

    // ---------- Trend bar chart ----------

    function renderTrendChart(trend) {
        const labels = trend.map((row) => row.label);
        const data = trend.map((row) => row.total);

        trendChart = destroyChart(trendChart);
        trendChart = new Chart(trendCanvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Income',
                    data,
                    backgroundColor: '#2563eb',
                    borderRadius: 4,
                    maxBarThickness: 36,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => formatCurrency(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: { autoSkip: true, maxRotation: 0 },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => formatCurrency(value),
                        },
                    },
                },
            },
        });
    }

    // ---------- Pie charts ----------

    function renderPieChart(canvas, labels, data, emptyEl) {
        const hasData = data.some((v) => v > 0);
        canvas.closest('.report-chart-wrap').hidden = !hasData;
        if (emptyEl) emptyEl.hidden = hasData;

        if (!hasData) return null;

        return new Chart(canvas, {
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                    borderWidth: 2,
                    borderColor: '#fff',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return `${ctx.label}: ${formatCurrency(ctx.parsed)} (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    }

    function renderPaymentMethodChart(rows) {
        paymentMethodChart = destroyChart(paymentMethodChart);
        paymentMethodChart = renderPieChart(
            paymentMethodCanvas,
            rows.map((r) => r.label),
            rows.map((r) => r.total),
            paymentMethodEmpty
        );
    }

    function renderCourtChart(rows) {
        courtChart = destroyChart(courtChart);
        courtChart = renderPieChart(
            courtCanvas,
            rows.map((r) => r.name),
            rows.map((r) => r.total),
            courtEmpty
        );
    }

    function renderSplitChart(split) {
        splitChart = destroyChart(splitChart);
        splitChart = renderPieChart(
            splitCanvas,
            ['Court Rental', 'Equipment'],
            [split.court, split.equipment],
            splitEmpty
        );
    }

    // ---------- Summary cards ----------

    function renderSummary(summary) {
        summaryTotalIncome.textContent = formatCurrency(summary.total_income);
        summaryMonthIncome.textContent = formatCurrency(summary.month_income);
        summaryTodayIncome.textContent = formatCurrency(summary.today_income);
        summaryPaidBookings.textContent = summary.paid_bookings.toLocaleString();
    }

    // ---------- Traffic line chart ----------

    function renderTrafficTrendChart(trend) {
        const labels = trend.map((row) => row.label);
        const data = trend.map((row) => row.count);

        trafficTrendChart = destroyChart(trafficTrendChart);
        trafficTrendChart = new Chart(trafficTrendCanvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Requests',
                    data,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.parsed.y.toLocaleString()} requests`,
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: { autoSkip: true, maxRotation: 0, maxTicksLimit: 8 },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                },
            },
        });
    }

    // ---------- System health ----------

    function renderSystem(sys) {
        // Traffic + users online
        summaryUsersOnline.textContent = sys.users_online.toLocaleString();
        summaryRequestsToday.textContent = sys.traffic.requests_today.toLocaleString();
        summaryRequestsHour.textContent = sys.traffic.requests_last_hour.toLocaleString();
        summaryUniqueVisitors.textContent = sys.traffic.unique_visitors_today.toLocaleString();
        renderTrafficTrendChart(sys.traffic.trend);
        if (trafficPanelTitle) {
            trafficPanelTitle.textContent = `Traffic (${TRAFFIC_RANGE_LABELS[sys.traffic.range] || 'Last 24 Hours'})`;
        }

        // Database
        const dbUp = sys.database.status === 'up';
        dbStatusPill.textContent = dbUp ? 'Up' : 'Down';
        dbStatusPill.classList.toggle('status-up', dbUp);
        dbStatusPill.classList.toggle('status-down', !dbUp);
        // "X GB used of Y GB" (matches Supabase's own Infrastructure >
        // Disk phrasing) instead of the raw pg_database_size pretty
        // string. This "used" figure is just the database's own size —
        // it won't exactly match Supabase's dashboard number, since
        // that also includes WAL + system overhead that isn't
        // queryable through a normal Postgres connection.
        const db = sys.database;
        if (db.size_bytes != null && db.capacity_bytes) {
            const usedGb = (db.size_bytes / (1024 ** 3)).toFixed(2).replace(/\.00$/, '');
            const capGb = (db.capacity_bytes / (1024 ** 3)).toFixed(0);
            dbSize.textContent = `${usedGb} GB used of ${capGb} GB`;
        } else {
            dbSize.textContent = db.size || '—';
        }
        renderDiskSegments(db);
        setProgressBar(document.getElementById('dbSizeBar'), sys.database.used_percent);

        dbTableList.innerHTML = '';
            (sys.database.tables || []).forEach((t) => {
                const li = document.createElement('li');
                const nameSpan = document.createElement('span');
                nameSpan.className = 'table-name';
                nameSpan.textContent = t.name;
                const rowsSpan = document.createElement('span');
                rowsSpan.className = 'table-rows';
                rowsSpan.textContent = t.rows.toLocaleString();
                li.appendChild(nameSpan);
                li.appendChild(rowsSpan);
                dbTableList.appendChild(li);
            });

        // Server
        phpVersion.textContent = sys.server.php_version;
        laravelVersion.textContent = sys.server.laravel_version;

        const disk = sys.server.disk;
        diskUsage.textContent = disk.total_bytes
            ? `${formatBytes(disk.used_bytes)} / ${formatBytes(disk.total_bytes)} (${disk.used_percent}%)`
            : 'Unavailable';
        setProgressBar(diskUsageBar, disk.used_percent);

        const mem = sys.server.memory;
        memoryUsage.textContent = mem.limit_bytes > 0
            ? `${formatBytes(mem.used_bytes)} / ${formatBytes(mem.limit_bytes)} (${mem.used_percent}%)`
            : `${formatBytes(mem.used_bytes)} / Unlimited`;
        setProgressBar(memoryUsageBar, mem.used_percent);
    }

    async function loadSystem() {
        if (!systemUrl) return;
        try {
            const url = `${systemUrl}?traffic_range=${encodeURIComponent(currentTrafficRange)}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            renderSystem(data);
        } catch (err) {
            // Non-fatal — the health panel just keeps its last known
            // values until the next successful poll rather than
            // surfacing the shared reportsError banner for this.
            console.error(err);
        }
    }

    // ---------- Load + wire up ----------

    async function loadReports(period) {
        setError(false);
        setLoading(true);
        try {
            const url = `${reportsUrl}?period=${encodeURIComponent(period)}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                const body = await res.text().catch(() => '');
                throw new Error(`Failed to load report data (${res.status}): ${body.slice(0, 500)}`);
            }
            const data = await res.json();

            renderSummary(data.summary);
            renderTrendChart(data.trend);
            renderPaymentMethodChart(data.by_payment_method);
            renderCourtChart(data.by_court);
            renderSplitChart(data.income_split);
        } catch (err) {
            console.error(err);
            setError(true);
        } finally {
            setLoading(false);
        }
    }

    if (periodSelect) {
        currentPeriod = periodSelect.value;
        periodSelect.addEventListener('change', () => {
            currentPeriod = periodSelect.value;
            loadReports(currentPeriod);
        });
    }

    if (trafficRangeSelect) {
        currentTrafficRange = trafficRangeSelect.value;
        trafficRangeSelect.addEventListener('change', () => {
            currentTrafficRange = trafficRangeSelect.value;
            loadSystem();
        });
    }

    loadReports(currentPeriod);

    // System health has its own refresh cadence — independent of the
    // period selector, since "users online" and "requests/hour" are
    // meant to feel live rather than only updating when the income
    // filter changes.
    loadSystem();
    setInterval(loadSystem, 10000);
});