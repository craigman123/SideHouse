@extends('staff.layouts.app')

@section('title', 'Staff Overview | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/overview.css') . '?v=' . filemtime(public_path('css/shared/overview.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/staff/staff-overview.css') . '?v=' . filemtime(public_path('css/staff/staff-overview.css')) }}">
@endpush

@section('content')
    <section class="admin-overview-page staff-overview" id="staffOverview" @if ($canViewBookings) data-overview-url="{{ route('staff.overview.data') }}" data-bookings-url="{{ route('staff.bookings') }}" @endif>
        <div class="overview-header">
            <div class="staff-overview-heading-copy">
                <p class="overview-intro">Today at <strong>Side House</strong></p>
                <p class="staff-sync-status" id="staffSyncStatus" role="status" aria-live="polite">Updated just now</p>
            </div>
            <div class="staff-overview-actions">
                @if ($canViewBookings)<a class="btn-secondary" href="{{ route('staff.bookings') }}">Bookings</a>@endif
                @if ($canCreateOpenPlay)<a class="btn-secondary" href="{{ route('staff.bookings', ['view' => 'board', 'new' => 'event']) }}">Add event</a>@endif
            </div>
        </div>
        <p class="staff-overview-error" id="staffOverviewError" role="alert" hidden>Overview could not refresh. <button type="button" id="staffOverviewRetry" class="btn-secondary">Retry</button></p>
        @if ($canViewBookings)
        <div class="card-grid staff-stat-grid">
            <div class="card"><p class="label">Today's bookings</p><p class="value" id="staffTodayBookingCount">{{ $overviewData['booking_count'] }}</p></div>
            <div class="card"><p class="label">Courts in use</p><p class="value" id="staffCourtCount">{{ $overviewData['court_count'] }}</p></div>
        </div>
        <div class="staff-overview-grid">
            <section class="panel staff-overview-card"><header class="dashboard-panel-header"><h2>Court status</h2></header><div id="staffCourtStatus" class="dashboard-panel-body staff-overview-list staff-court-status-list"><span class="dashboard-skeleton" aria-hidden="true"></span><span class="dashboard-skeleton" aria-hidden="true"></span></div></section>
            <section class="panel staff-overview-card"><header class="dashboard-panel-header"><h2>Next up</h2></header><div id="staffUpcomingBookings" class="dashboard-panel-body staff-overview-list"><span class="dashboard-skeleton" aria-hidden="true"></span></div></section>
            <div id="staffOpenPlay" class="staff-open-play-slot">
                <section class="panel staff-overview-card staff-open-play-card">
                    <header class="dashboard-panel-header"><h2>Open play</h2></header>
                    <div class="dashboard-panel-body staff-open-play-content"><span class="dashboard-skeleton" aria-hidden="true"></span></div>
                </section>
            </div>
            <section class="panel staff-overview-card"><header class="dashboard-panel-header"><h2>Equipment prep</h2></header><div id="staffEquipmentPrep" class="dashboard-panel-body staff-overview-list"><span class="dashboard-skeleton" aria-hidden="true"></span></div></section>
        </div>
        <script id="staffOverviewData" type="application/json">{!! json_encode($overviewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
        @endif
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-overview.js') . '?v=' . filemtime(public_path('js/staff/staff-overview.js')) }}" defer></script>
@endpush
