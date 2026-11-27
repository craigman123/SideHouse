{{-- is-loading starts present so the skeleton shows immediately on
         first paint, before admin-reports.js even runs — avoids a flash
         of "₱0" before the real numbers load. --}}
    <div class="reports-page is-loading" id="reportsPage" data-reports-url="{{ role_route('admin.reports.data') }}" @unless ($ownerView ?? false) data-system-url="{{ route('admin.reports.system') }}" @endunless>

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
            <div class="summary-card has-card-icon">
                <span class="summary-card-label card-icon-label">Total Income<x-card-icon name="trending-up" /></span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryTotalIncome">&#8369;0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card has-card-icon">
                <span class="summary-card-label card-icon-label">This Month<x-card-icon name="peso-coin" /></span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryMonthIncome">&#8369;0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card has-card-icon">
                <span class="summary-card-label card-icon-label">Today<x-card-icon name="peso-coin" /></span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryTodayIncome">&#8369;0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
            <div class="summary-card has-card-icon">
                <span class="summary-card-label card-icon-label">Paid Bookings<x-card-icon name="calendar-check" /></span>
                <div class="summary-card-value-wrap">
                    <span class="summary-card-value" id="summaryPaidBookings">0</span>
                    <span class="skeleton" aria-hidden="true"></span>
                </div>
            </div>
        </div>

        {{-- Trend bar chart --}}
        <div class="report-panel has-card-icon">
            <div class="report-panel-header">
                <h2 class="card-icon-label">Income Trend<x-card-icon name="bar-chart" /></h2>
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
            <div class="report-panel has-card-icon">
                <div class="report-panel-header">
                    <h2 class="card-icon-label">Income by Payment Method<x-card-icon name="wallet" /></h2>
                </div>
                <div class="report-chart-wrap">
                    <canvas id="paymentMethodChart" role="img" aria-label="Income by payment method"></canvas>
                    <div class="chart-skeleton" aria-hidden="true">
                        <div class="chart-skeleton-pie skeleton"></div>
                    </div>
                </div>
                <p class="report-empty-note" id="paymentMethodEmpty" hidden>No paid bookings yet.</p>
            </div>

            <div class="report-panel has-card-icon">
                <div class="report-panel-header">
                    <h2 class="card-icon-label">Income by Court<x-card-icon name="court" /></h2>
                </div>
                <div class="report-chart-wrap">
                    <canvas id="courtChart" role="img" aria-label="Income by court"></canvas>
                    <div class="chart-skeleton" aria-hidden="true">
                        <div class="chart-skeleton-pie skeleton"></div>
                    </div>
                </div>
                <p class="report-empty-note" id="courtEmpty" hidden>No paid bookings yet.</p>
            </div>

            <div class="report-panel has-card-icon">
                <div class="report-panel-header">
                    <h2 class="card-icon-label">Court Rental vs Equipment<x-card-icon name="court" /></h2>
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

        @unless ($ownerView ?? false)
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

        <div class="report-panel">
            <div class="report-panel-header report-panel-header-with-select">
                <h2 id="trafficPanelTitle">Traffic (Last 24 Hours)</h2>
                <select id="trafficRangeSelect" class="reports-period-select" aria-label="Traffic range">
                    <option value="30m">30 Min</option>
                    <option value="1h">1 Hour</option>
                    <option value="2h">2 Hours</option>
                    <option value="10h">10 Hours</option>
                    <option value="24h" selected>24 Hours</option>
                </select>
            </div>
            <div class="report-chart-wrap report-chart-wrap-wide">
                <canvas id="trafficTrendChart" role="img" aria-label="Requests over the selected time range"></canvas>
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
                <div class="disk-segment-bar" id="dbSegmentBar">
                    <div class="disk-segment disk-segment-database" id="segDatabase"></div>
                    <div class="disk-segment disk-segment-wal" id="segWal"></div>
                    <div class="disk-segment disk-segment-system" id="segSystem"></div>
                </div>
                <ul class="disk-segment-legend">
                    <li><span class="legend-dot legend-dot-database"></span>Database</li>
                    <li><span class="legend-dot legend-dot-wal"></span>WAL</li>
                    <li><span class="legend-dot legend-dot-system"></span>System <span class="legend-note">(not visible from app)</span></li>
                    <li><span class="legend-dot legend-dot-available"></span>Available space</li>
                </ul>
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
        @endunless

        <p class="reports-error" id="reportsError" hidden>Couldn't load report data. Please refresh the page.</p>
    </div>
