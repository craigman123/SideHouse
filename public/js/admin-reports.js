document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('reportsPage');
    if (!page) return;

    const reportsUrl = page.dataset.reportsUrl;
    const periodSelect = document.getElementById('periodSelect');
    const errorEl = document.getElementById('reportsError');

    const summaryTotalIncome = document.getElementById('summaryTotalIncome');
    const summaryMonthIncome = document.getElementById('summaryMonthIncome');
    const summaryTodayIncome = document.getElementById('summaryTodayIncome');
    const summaryPaidBookings = document.getElementById('summaryPaidBookings');

    const trendCanvas = document.getElementById('trendChart');
    const paymentMethodCanvas = document.getElementById('paymentMethodChart');
    const courtCanvas = document.getElementById('courtChart');
    const splitCanvas = document.getElementById('splitChart');

    const paymentMethodEmpty = document.getElementById('paymentMethodEmpty');
    const courtEmpty = document.getElementById('courtEmpty');
    const splitEmpty = document.getElementById('splitEmpty');

    // Palette kept small and reused across all charts so colors stay
    // consistent no matter how many slices/bars a given dataset has.
    const PALETTE = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];

    let currentPeriod = 'month';
    let trendChart = null;
    let paymentMethodChart = null;
    let courtChart = null;
    let splitChart = null;

    function formatCurrency(n) {
        const value = Number(n) || 0;
        return `\u20b1${value.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
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

    loadReports(currentPeriod);
});