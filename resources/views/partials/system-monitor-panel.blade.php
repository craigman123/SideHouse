<aside class="system-monitor-panel" id="systemMonitorPanel" data-admin-user-id="{{ auth()->user()->user_id }}" aria-label="System Monitor" hidden>
    <header class="system-monitor-header" id="systemMonitorHeader" tabindex="0" aria-label="Drag System Monitor; use arrow keys to move">
        <div class="system-monitor-heading">
            <span class="system-monitor-status" aria-hidden="true"></span>
            <strong>System Monitor</strong>
        </div>
        <div class="system-monitor-actions">
            <button type="button" class="system-monitor-action" id="systemMonitorCollapse" aria-label="Collapse System Monitor" title="Collapse">
                <span aria-hidden="true">−</span>
            </button>
            <button type="button" class="system-monitor-action btn-ghost-danger" id="systemMonitorClose" aria-label="Close System Monitor" title="Close">
                <span aria-hidden="true">×</span>
            </button>
        </div>
    </header>
    <div class="system-monitor-body" id="systemMonitorBody">
        <div class="system-monitor-stat has-card-icon" data-system-stat="active_connections">
            <span class="card-icon-label card-icon-label--compact">
                DB connections<x-card-icon name="database" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="queries_per_minute">
            <span class="card-icon-label card-icon-label--compact">
                Queries / min<x-card-icon name="bar-chart" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="average_query_time_ms">
            <span class="card-icon-label card-icon-label--compact">
                Avg query<x-card-icon name="clock" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="php_memory_bytes">
            <span class="card-icon-label card-icon-label--compact">
                PHP memory<x-card-icon name="gauge" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="server_load">
            <span class="card-icon-label card-icon-label--compact">
                Server load<x-card-icon name="gauge" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="average_response_time_ms">
            <span class="card-icon-label card-icon-label--compact">
                Avg response<x-card-icon name="clock" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="requests_per_minute">
            <span class="card-icon-label card-icon-label--compact">
                Requests / min<x-card-icon name="trending-up" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat has-card-icon" data-system-stat="error_count_5m">
            <span class="card-icon-label card-icon-label--compact">
                Errors / 5 min<x-card-icon name="alert" class="icon-chip--compact" />
            </span>
            <strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-updated">Last updated <time id="systemMonitorUpdated">—</time></div>
    </div>
</aside>
