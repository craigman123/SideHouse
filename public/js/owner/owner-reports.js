document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('reportsPage');
    if (!page) return;

    const url = page.dataset.reportsUrl;
    const periodSelect = document.getElementById('periodSelect');
    const error = document.getElementById('reportsError');
    const summary = {
        total: document.getElementById('summaryTotalIncome'),
        month: document.getElementById('summaryMonthIncome'),
        today: document.getElementById('summaryTodayIncome'),
        bookings: document.getElementById('summaryPaidBookings'),
    };
    const canvases = {
        trend: document.getElementById('trendChart'),
        payment: document.getElementById('paymentMethodChart'),
        court: document.getElementById('courtChart'),
        split: document.getElementById('splitChart'),
    };
    const empty = {
        payment: document.getElementById('paymentMethodEmpty'),
        court: document.getElementById('courtEmpty'),
        split: document.getElementById('splitEmpty'),
    };
    const palette = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];
    const charts = { trend: null, payment: null, court: null, split: null };

    const currency = (value) => `₱${(Number(value) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
    const destroy = (chart) => { chart?.destroy(); return null; };
    const setLoading = (value) => page.classList.toggle('is-loading', value);

    function pie(canvas, labels, values, emptyNote) {
        const hasData = values.some((value) => value > 0);
        canvas.closest('.report-chart-wrap').hidden = !hasData;
        if (emptyNote) emptyNote.hidden = hasData;
        if (!hasData) return null;
        return new Chart(canvas, {
            type: 'pie',
            data: { labels, datasets: [{ data: values, backgroundColor: labels.map((_, index) => palette[index % palette.length]), borderWidth: 2, borderColor: '#fff' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => {
                        const total = ctx.dataset.data.reduce((sum, value) => sum + value, 0);
                        const percent = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                        return `${ctx.label}: ${currency(ctx.parsed)} (${percent}%)`;
                    } } },
                },
            },
        });
    }

    function render(data) {
        summary.total.textContent = currency(data.summary.total_income);
        summary.month.textContent = currency(data.summary.month_income);
        summary.today.textContent = currency(data.summary.today_income);
        summary.bookings.textContent = Number(data.summary.paid_bookings || 0).toLocaleString();

        charts.trend = destroy(charts.trend);
        charts.trend = new Chart(canvases.trend, {
            type: 'bar',
            data: { labels: data.trend.map((row) => row.label), datasets: [{ label: 'Income', data: data.trend.map((row) => row.total), backgroundColor: '#2563eb', borderRadius: 4, maxBarThickness: 36 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => currency(ctx.parsed.y) } } },
                scales: {
                    x: { ticks: { autoSkip: true, maxRotation: 0 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: (value) => currency(value) } },
                },
            },
        });

        charts.payment = destroy(charts.payment);
        charts.payment = pie(canvases.payment, data.by_payment_method.map((row) => row.label), data.by_payment_method.map((row) => row.total), empty.payment);
        charts.court = destroy(charts.court);
        charts.court = pie(canvases.court, data.by_court.map((row) => row.name), data.by_court.map((row) => row.total), empty.court);
        charts.split = destroy(charts.split);
        charts.split = pie(canvases.split, ['Court Rental', 'Equipment'], [data.income_split.court, data.income_split.equipment], empty.split);
    }

    async function load(period) {
        error.hidden = true;
        setLoading(true);
        try {
            const response = await fetch(`${url}?period=${encodeURIComponent(period)}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`Report request failed (${response.status})`);
            render(await response.json());
        } catch (loadError) {
            console.error(loadError);
            error.hidden = false;
        } finally {
            setLoading(false);
        }
    }

    periodSelect?.addEventListener('change', () => load(periodSelect.value));
    load(periodSelect?.value || 'month');
});
