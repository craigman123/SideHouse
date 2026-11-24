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
            <button type="button" class="system-monitor-action" id="systemMonitorClose" aria-label="Close System Monitor" title="Close">
                <span aria-hidden="true">×</span>
            </button>
        </div>
    </header>
    <div class="system-monitor-body" id="systemMonitorBody">
        <div class="system-monitor-stat" data-system-stat="active_connections">
            <span>DB connections</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="queries_per_minute">
            <span>Queries / min</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="average_query_time_ms">
            <span>Avg query</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="php_memory_bytes">
            <span>PHP memory</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="server_load">
            <span>Server load</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="average_response_time_ms">
            <span>Avg response</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="requests_per_minute">
            <span>Requests / min</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-stat" data-system-stat="error_count_5m">
            <span>Errors / 5 min</span><strong data-stat-value>N/A</strong><i data-stat-dot></i>
        </div>
        <div class="system-monitor-updated">Last updated <time id="systemMonitorUpdated">—</time></div>
    </div>
</aside>
