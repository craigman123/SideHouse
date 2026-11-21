@extends('staff.layouts.app')

@section('title', 'Staff Overview | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/staff/staff-layout.css') . '?v=' . filemtime(public_path('css/staff/staff-layout.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/staff/staff-overview.css') . '?v=' . filemtime(public_path('css/staff/staff-overview.css')) }}">
@endpush

@section('content')
    @php($canCreateWalkIn = app(\App\Services\UserAccessService::class)->allows($user, 'bookings.create'))
    <section class="staff-overview" id="staffOverview" data-overview-url="{{ route('staff.overview.data') }}" data-permissions="{{ json_encode(['bookings.create' => $canCreateWalkIn]) }}">
        <header class="staff-page-heading">
            <div><p class="staff-eyebrow">Your shift</p><h2>Today at Side House</h2><p id="staffOverviewDate">{{ $overviewData['date'] }}</p></div>
            <div class="staff-overview-actions">
                @if (app(\App\Services\UserAccessService::class)->allows($user, 'bookings.view'))
                    <a class="staff-action-button" href="{{ route('staff.bookings') }}">Bookings</a>
                    @if ($canCreateWalkIn)<a class="staff-action-button is-primary" href="{{ route('staff.bookings', ['view' => 'board', 'new' => 'walk-in']) }}">Add walk-in</a>@endif
                @endif
            </div>
        </header>

        <div class="staff-stat-card"><span>Today's bookings</span><strong id="staffTodayBookingCount">{{ $overviewData['booking_count'] }}</strong></div>
        <p class="staff-sync-status" id="staffSyncStatus" role="status" aria-live="polite">Updated {{ $overviewData['synced_at'] }}</p>
        <div class="staff-overview-grid">
            <section class="staff-overview-card"><h3>Next bookings</h3><div id="staffUpcomingBookings" class="staff-overview-list"></div></section>
            <section class="staff-overview-card"><h3>Today's sessions and court events</h3><div id="staffTodayEvents" class="staff-overview-list"></div></section>
        </div>
        <script id="staffOverviewData" type="application/json">{!! json_encode($overviewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-overview.js') . '?v=' . filemtime(public_path('js/staff/staff-overview.js')) }}" defer></script>
@endpush
