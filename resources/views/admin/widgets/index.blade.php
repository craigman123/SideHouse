@extends('layouts.app')

@section('title', 'Widgets | Side House')
@section('page-title', 'Widgets')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-schedule.css') }}">
@endpush

@section('content')
    <div class="schedule-page admin-widgets-page">
        <div class="schedule-header">
            <div>
                <h1 class="schedule-title">Widgets</h1>
                <p class="schedule-subtitle">Manage and review widgets for the Side House dashboard.</p>
            </div>
        </div>

        <div class="schedule-panel">
            <div class="schedule-panel-header">
                <h2>Floating panel</h2>
                <p class="schedule-panel-note">Show live system metrics in a movable panel across dashboard pages.</p>
            </div>
            <div class="system-monitor-widgets-controls">
                <label class="system-monitor-toggle">
                    <input type="checkbox" id="systemMonitorEnabled">
                    <span>Floating panel</span>
                </label>
                <button type="button" class="btn-secondary btn-ghost-danger" id="systemMonitorResetPosition">Reset position</button>
            </div>
            <div class="system-monitor-preview" id="systemMonitorPreview" aria-live="polite">
                <div class="system-monitor-preview-heading">
                    <strong>Live preview</strong>
                    <span id="systemMonitorPreviewUpdated">Waiting for updates</span>
                </div>
                <div class="system-monitor-preview-grid">
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            DB connections<x-card-icon name="database" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="active_connections">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            Queries / min<x-card-icon name="bar-chart" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="queries_per_minute">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            Avg query<x-card-icon name="clock" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="average_query_time_ms">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            PHP memory<x-card-icon name="gauge" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="php_memory_bytes">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            Server load<x-card-icon name="gauge" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="server_load">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            Avg response<x-card-icon name="clock" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="average_response_time_ms">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            Requests / min<x-card-icon name="trending-up" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="requests_per_minute">N/A</strong>
                    </div>
                    <div class="has-card-icon">
                        <span class="card-icon-label card-icon-label--compact">
                            Errors / 5 min<x-card-icon name="alert" class="icon-chip--compact" />
                        </span>
                        <strong data-preview-stat="error_count_5m">N/A</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
