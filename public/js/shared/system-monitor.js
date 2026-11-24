(function () {
    'use strict';

    const POLL_INTERVAL_MS = 5000;
    const MAX_POLL_INTERVAL_MS = 40000;
    const THRESHOLDS = {
        active_connections: { yellow: 40, red: 80 },
        queries_per_minute: { yellow: 300, red: 1000 },
        average_query_time_ms: { yellow: 100, red: 500 },
        php_memory_bytes: { yellow: 128 * 1024 * 1024, red: 256 * 1024 * 1024 },
        server_load: { yellow: 2, red: 5 },
        average_response_time_ms: { yellow: 500, red: 1500 },
        requests_per_minute: { yellow: 120, red: 300 },
        error_count_5m: { yellow: 5, red: 20 }
    };

    const panel = document.getElementById('systemMonitorPanel');
    if (! panel) return;

    const header = document.getElementById('systemMonitorHeader');
    const body = document.getElementById('systemMonitorBody');
    const collapseButton = document.getElementById('systemMonitorCollapse');
    const closeButton = document.getElementById('systemMonitorClose');
    const updated = document.getElementById('systemMonitorUpdated');
    const enabledControl = document.getElementById('systemMonitorEnabled');
    const resetControl = document.getElementById('systemMonitorResetPosition');
    const previewUpdated = document.getElementById('systemMonitorPreviewUpdated');
    const userId = panel.dataset.adminUserId;
    const storageKey = 'side-house:system-monitor:' + userId;

    let state = loadState();
    let x = Number.isFinite(state.x) ? state.x : 0;
    let y = Number.isFinite(state.y) ? state.y : 0;
    let dragging = false;
    let dragPointerId = null;
    let dragOffsetX = 0;
    let dragOffsetY = 0;
    let pollTimer = null;
    let pollInterval = POLL_INTERVAL_MS;
    let inFlight = false;

    function loadState() {
        try {
            const value = JSON.parse(localStorage.getItem(storageKey));
            return value && typeof value === 'object' ? value : {};
        } catch (_) {
            return {};
        }
    }

    function saveState() {
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                enabled: !!state.enabled,
                x: x,
                y: y,
                collapsed: !!state.collapsed
            }));
        } catch (_) {}
    }

    function clampPosition(nextX, nextY) {
        const rect = panel.getBoundingClientRect();
        const maxX = Math.max(16, window.innerWidth - rect.width - 16);
        const maxY = Math.max(16, window.innerHeight - rect.height - 16);
        x = Math.min(Math.max(16, nextX), maxX);
        y = Math.min(Math.max(16, nextY), maxY);
        panel.style.transform = 'translate(' + (x - 16) + 'px, ' + (y - 16) + 'px)';
        saveState();
    }

    function reClamp() {
        clampPosition(x, y);
    }

    function setCollapsed(collapsed) {
        state.collapsed = collapsed;
        panel.classList.toggle('is-collapsed', collapsed);
        collapseButton.setAttribute('aria-label', collapsed ? 'Expand System Monitor' : 'Collapse System Monitor');
        collapseButton.title = collapsed ? 'Expand' : 'Collapse';
        collapseButton.firstElementChild.textContent = collapsed ? '+' : '−';
        saveState();
        reClamp();
    }

    function setEnabled(enabled) {
        state.enabled = enabled;
        panel.hidden = !enabled;
        if (enabledControl) enabledControl.checked = enabled;
        saveState();
        if (enabled) {
            reClamp();
            pollNow();
            startPolling();
        } else {
            stopPolling();
        }
    }

    function resetPosition() {
        const rect = panel.getBoundingClientRect();
        x = Math.max(16, window.innerWidth - rect.width - 16);
        y = 16;
        panel.style.transform = 'translate(' + (x - 16) + 'px, ' + (y - 16) + 'px)';
        saveState();
    }

    function formatBytes(bytes) {
        if (!Number.isFinite(bytes)) return 'N/A';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function formatValue(key, value) {
        if (value === null || typeof value === 'undefined') return 'N/A';
        if (key === 'php_memory_bytes') return formatBytes(Number(value));
        if (key.endsWith('_ms')) return Number(value).toFixed(1) + ' ms';
        return String(value);
    }

    function statusFor(key, value) {
        if (value === null || typeof value === 'undefined') return 'green';
        const thresholds = THRESHOLDS[key];
        if (! thresholds) return 'green';
        const number = Number(value);
        if (! Number.isFinite(number)) return 'green';
        if (number >= thresholds.red) return 'red';
        if (number >= thresholds.yellow) return 'yellow';
        return 'green';
    }

    function updateStats(data) {
        const database = data.database || {};
        const app = data.app || {};
        const traffic = data.traffic || {};
        const values = Object.assign({}, database, app, traffic);

        document.querySelectorAll('[data-system-stat]').forEach(function (row) {
            const key = row.dataset.systemStat;
            const value = values[key];
            row.querySelector('[data-stat-value]').textContent = formatValue(key, value);
            row.dataset.status = statusFor(key, value);
        });

        document.querySelectorAll('[data-preview-stat]').forEach(function (item) {
            const key = item.dataset.previewStat;
            item.textContent = formatValue(key, values[key]);
        });

        const now = new Date();
        const timeText = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        updated.textContent = timeText;
        updated.dateTime = now.toISOString();
        if (previewUpdated) previewUpdated.textContent = 'Updated ' + timeText;
        panel.dispatchEvent(new CustomEvent('system-monitor:updated', { detail: values }));
    }

    function stopPolling() {
        if (pollTimer) window.clearTimeout(pollTimer);
        pollTimer = null;
    }

    function startPolling() {
        stopPolling();
        if (document.hidden || !state.enabled) return;
        pollTimer = window.setTimeout(pollNow, pollInterval);
    }

    async function pollNow() {
        if (document.hidden || inFlight || !state.enabled) return;
        inFlight = true;

        try {
            const response = await fetch('/admin/widgets/metrics', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (! response.ok) throw new Error('Metrics request failed');
            updateStats(await response.json());
            pollInterval = POLL_INTERVAL_MS;
        } catch (_) {
            pollInterval = Math.min(MAX_POLL_INTERVAL_MS, Math.max(POLL_INTERVAL_MS, pollInterval * 2));
        } finally {
            inFlight = false;
            startPolling();
        }
    }

    header.addEventListener('pointerdown', function (event) {
        if (event.target.closest('button')) return;
        dragging = true;
        dragPointerId = event.pointerId;
        const rect = panel.getBoundingClientRect();
        dragOffsetX = event.clientX - rect.left;
        dragOffsetY = event.clientY - rect.top;
        header.setPointerCapture(dragPointerId);
    });

    header.addEventListener('pointermove', function (event) {
        if (!dragging || event.pointerId !== dragPointerId) return;
        clampPosition(event.clientX - dragOffsetX, event.clientY - dragOffsetY);
    });

    function endDrag(event) {
        if (!dragging || event.pointerId !== dragPointerId) return;
        dragging = false;
        if (header.hasPointerCapture(dragPointerId)) header.releasePointerCapture(dragPointerId);
        dragPointerId = null;
    }

    header.addEventListener('pointerup', endDrag);
    header.addEventListener('pointercancel', endDrag);

    header.addEventListener('keydown', function (event) {
        const step = event.shiftKey ? 20 : 5;
        let nextX = x;
        let nextY = y;
        if (event.key === 'ArrowLeft') nextX -= step;
        if (event.key === 'ArrowRight') nextX += step;
        if (event.key === 'ArrowUp') nextY -= step;
        if (event.key === 'ArrowDown') nextY += step;
        if (event.key === 'Escape') {
            setEnabled(false);
            event.preventDefault();
            return;
        }
        if (nextX !== x || nextY !== y) {
            clampPosition(nextX, nextY);
            event.preventDefault();
        }
    });

    collapseButton.addEventListener('click', function () {
        setCollapsed(!state.collapsed);
    });

    closeButton.addEventListener('click', function () {
        setEnabled(false);
    });

    if (enabledControl) {
        enabledControl.addEventListener('change', function () {
            setEnabled(enabledControl.checked);
        });
    }

    if (resetControl) {
        resetControl.addEventListener('click', resetPosition);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else {
            pollNow();
            startPolling();
        }
    });

    window.addEventListener('resize', reClamp);

    if (! state.enabled) state.enabled = false;
    setCollapsed(!!state.collapsed);

    if (state.x === undefined || state.y === undefined) resetPosition();
    reClamp();
    setEnabled(!!state.enabled);
})();
