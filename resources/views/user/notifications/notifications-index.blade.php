@extends('layouts.user')

@section('title', 'Notifications | Side House')
@section('page-title', 'Notifications')
@section('page-class', 'user-notifications-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/notifications.css') }}">
@endpush

@section('content')

    <div
        id="flash-data"
        data-success="{{ session('success') }}"
    ></div>

    <div class="notifications-page">
        <div class="notifications-header">
            <div class="notifications-heading">
                <h2 class="notifications-title">Stay <span>informed</span></h2>
                <p class="notifications-subtitle">Updates about your bookings and account.</p>
            </div>
            @if ($notifications->total() > 0)
                <form method="POST" action="{{ route('user.notifications.mark-all-read') }}" class="notifications-mark-all-form">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Mark all as read</button>
                </form>
            @endif
        </div>

        @if (session('error'))
            <div class="notifications-error" role="alert">
                <p>{{ session('error') }}</p>
                <a class="notifications-retry" href="{{ request()->fullUrl() }}">Retry</a>
            </div>
        @endif

        @if ($notifications->isEmpty())
            <div class="notifications-empty">
                <svg class="notifications-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                <h3>You're all caught up</h3>
                <p>There are no notifications to show right now.</p>
                <a href="{{ route('book.index') }}" class="notifications-book-link">Book a court</a>
            </div>
        @else
            <div class="notifications-filters" role="group" aria-label="Filter notifications">
                <button type="button" class="notifications-filter is-active" data-notification-filter="all" aria-pressed="true">All</button>
                <button type="button" class="notifications-filter" data-notification-filter="unread" aria-pressed="false">Unread</button>
            </div>

            <p class="notifications-filter-empty" hidden>No unread notifications on this page.</p>

            <div class="notifications-loading" aria-live="polite" hidden>
                <span class="visually-hidden">Loading notifications</span>
                <div class="notification-skeleton" aria-hidden="true"><span class="notification-skeleton-icon"></span><span class="notification-skeleton-copy"><i></i><i></i><i></i></span></div>
                <div class="notification-skeleton" aria-hidden="true"><span class="notification-skeleton-icon"></span><span class="notification-skeleton-copy"><i></i><i></i><i></i></span></div>
                <div class="notification-skeleton" aria-hidden="true"><span class="notification-skeleton-icon"></span><span class="notification-skeleton-copy"><i></i><i></i><i></i></span></div>
            </div>

            <div class="notifications-list" aria-busy="false">
                @foreach ($notifications as $notification)
                    <div class="notification-row notification-type-{{ $notification->type }} {{ $notification->read_at === null ? 'is-unread' : '' }}">
                        <div class="notification-row-icon" aria-hidden="true">
                            @if ($notification->type === 'booking_status')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 15l2 2 4-4"/></svg>
                            @elseif ($notification->type === 'booking_reminder')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a2 2 0 0 0 2 2h2l9 5V4l-9 5H5a2 2 0 0 0-2 2Z"/><path d="M7 15l2 6M19 9a4 4 0 0 1 0 6"/></svg>
                            @endif
                        </div>

                        <div class="notification-row-body">
                            <p class="notification-row-title">
                                {{ $notification->title }}
                                @if ($notification->read_at === null)
                                    <span class="notification-unread-indicator" aria-hidden="true"></span>
                                    <span class="visually-hidden">Unread</span>
                                @endif
                            </p>
                            <p class="notification-row-text">{{ $notification->body }}</p>
                            <time class="notification-row-time" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                        </div>

                        @if ($notification->read_at === null)
                            <button
                                type="button"
                                class="notification-mark-read-btn"
                                data-mark-read-url="{{ route('user.notifications.mark-read', $notification) }}"
                                aria-label="Mark as read"
                                title="Mark as read"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="pagination-wrapper">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>

    <div class="toast-container" id="toastContainer"></div>

@endsection

@push('scripts')
    <script src="{{ asset('js/user/notifications.js') }}" defer></script>
@endpush
