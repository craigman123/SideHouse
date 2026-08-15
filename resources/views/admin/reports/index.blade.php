{{--
    NOTE: I don't have your admin layout file, so this assumes the same
    @extends/@section convention your other admin views likely use
    (matching Admin_DashboardController's view('admin.admin_dashboard')).
    If your layout uses different names, just swap the two lines below —
    everything else on this page is self-contained.
--}}
@extends('layouts.app')

@section('title', 'Income Reports')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin-reports.css') }}">
@endpush

@section('content')
    {{-- is-loading starts present so the skeleton shows immediately on
         first paint, before admin-reports.js even runs — avoids a flash
         of "₱0" before the real numbers load. --}}
    <div class="reports-page is-loading" id="reportsPage" data-reports-url="{{ route('admin.reports.data') }}" data-system-url="{{ route('admin.reports.system') }}">

        <div class="reports-header">
            <div>
                <h1 class="reports-title">Income Reports</h1>
                <p class="reports-subtitle">Full breakdown of income across the business.</p>
            </div>

            <div class="reports-period-select-wrap">
                <label for="periodSelect" class="reports-period-label">Range</label>
                <select id="periodSelect" class="reports-period-select" aria-label="Trend range">
                    <option value="today">Today</option>
                    <option value="week">7 Days</option>
                    <option value="month" selected>30 Days</option>
                    <option value="year">12 Months</option>
                </select>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="reports-summary-grid" id="summaryGrid">
            <div class="summary-card">
                <span class="summary-card-label">Total Income</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryTotalIncome">&#8369;0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card">
                <span class="summary-card-label">This Month</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryMonthIncome">&#8369;0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card">
                <span class="summary-card-label">Today</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryTodayIncome">&#8369;0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card">
                <span class="summary-card-label">Paid Bookings</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryPaidBookings">0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
        </div>

        {{-- Trend bar chart --}}
        <div class="report-panel">
            <div class="report-panel-header">
                <h2>Income Trend</h2>
            </div>
            <div class="report-chart-wrap report-chart-wrap-wide">
                <canvas id="trendChart" role="img" aria-label="Income trend over time"></canvas>
                <div class="chart-skeleton" aria-hidden="true">
                    <div class="chart-skeleton-bars">
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pie charts --}}
        <div class="report-panel-grid">
            <div class="report-panel">
                <div class="report-panel-header">
                    <h2>Income by Payment Method</h2>
                </div>
                <div class="report-chart-wrap">
                    <canvas id="paymentMethodChart" role="img" aria-label="Income by payment method"></canvas>
                    <div class="chart-skeleton" aria-hidden="true">
                        <div class="chart-skeleton-pie skeleton"></div>
                    </div>
                </div>
                <p class="report-empty-note" id="paymentMethodEmpty" hidden>No paid bookings yet.</p>
            </div>

            <div class="report-panel">
                <div class="report-panel-header">
                    <h2>Income by Court</h2>
                </div>
                <div class="report-chart-wrap">
                    <canvas id="courtChart" role="img" aria-label="Income by court"></canvas>
                    <div class="chart-skeleton" aria-hidden="true">
                        <div class="chart-skeleton-pie skeleton"></div>
                    </div>
                </div>
                <p class="report-empty-note" id="courtEmpty" hidden>No paid bookings yet.</p>
            </div>

            <div class="report-panel">
                <div class="report-panel-header">
                    <h2>Court Rental vs Equipment</h2>
                </div>
                <div class="report-chart-wrap">
                    <canvas id="splitChart" role="img" aria-label="Court rental income vs equipment rental income"></canvas>
                    <div class="chart-skeleton" aria-hidden="true">
                        <div class="chart-skeleton-pie skeleton"></div>
                    </div>
                </div>
                <p class="report-empty-note" id="splitEmpty" hidden>No paid bookings yet.</p>
            </div>
        </div>

        {{-- System health --}}
        <div class="reports-header">
            <div>
                <h2 class="reports-title reports-title-sm">System Health</h2>
                <p class="reports-subtitle">Live server, database, and traffic status.</p>
            </div>
        </div>

        <div class="reports-summary-grid" id="systemSummaryGrid">
            <div class="summary-card">
                <span class="summary-card-label">Users Online</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryUsersOnline">0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card">
                <span class="summary-card-label">Requests Today</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryRequestsToday">0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card">
                <span class="summary-card-label">Requests / Hour</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryRequestsHour">0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card">
                <span class="summary-card-label">Unique Visitors Today</span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryUniqueVisitors">0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
        </div>

        <div class="report-panel-grid report-panel-grid-health">
            <div class="report-panel">
                <div class="report-panel-header">
                    <h2>Database</h2>
                </div>
                <div class="health-status-row">
                    <span class="health-status-label">Connection</span>
                    <span class="health-status-pill" id="dbStatusPill">—</span>
                </div>
                <div class="health-stat-row">
                    <span class="health-stat-label">Database Size</span>
                    <span class="health-stat-value" id="dbSize">—</span>
                </div>
                <ul class="health-table-list" id="dbTableList"></ul>
            </div>

            <div class="report-panel">
                <div class="report-panel-header">
                    <h2>Server</h2>
                </div>
                <div class="health-stat-row">
                    <span class="health-stat-label">PHP Version</span>
                    <span class="health-stat-value" id="phpVersion">—</span>
                </div>
                <div class="health-stat-row">
                    <span class="health-stat-label">Laravel Version</span>
                    <span class="health-stat-value" id="laravelVersion">—</span>
                </div>

                <div class="health-stat-row">
                    <span class="health-stat-label">Disk Usage</span>
                    <span class="health-stat-value" id="diskUsage">—</span>
                </div>
                <div class="health-progress"><div class="health-progress-bar" id="diskUsageBar"></div></div>

                <div class="health-stat-row">
                    <span class="health-stat-label">PHP Memory Usage</span>
                    <span class="health-stat-value" id="memoryUsage">—</span>
                </div>
                <div class="health-progress"><div class="health-progress-bar" id="memoryUsageBar"></div></div>
            </div>
        </div>

        <p class="reports-error" id="reportsError" hidden>Couldn't load report data. Please refresh the page.</p>
    </div>
@endsection

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
    <script src="{{ asset('js/admin-reports.js') }}" defer></script>
@endpush