@extends('layouts.user')

@section('title', 'Notifications | Side House')
@section('page-title', 'Notifications')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/notifications.css') }}">
@endpush

@section('content')

    <div
        id="flash-data"
        data-success="{{ session('success') }}"
    ></div>

    <div class="notifications-page">
        <div class="notifications-header">
            @if ($notifications->contains(fn ($n) => $n->read_at === null))
                <form method="POST" action="{{ route('user.notifications.mark-all-read') }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Mark all as read</button>
                </form>
            @endif
        </div>

        @if ($notifications->isEmpty())
            <div class="notifications-empty">
                <p>You're all caught up — nothing here yet.</p>
            </div>
        @else
            <div class="notifications-list">
                @foreach ($notifications as $notification)
                    <div class="notification-row notification-type-{{ $notification->type }} {{ $notification->read_at === null ? 'is-unread' : '' }}">
                        <div class="notification-row-icon" aria-hidden="true">
                            @if ($notification->type === 'booking_status')
                                📅
                            @elseif ($notification->type === 'booking_reminder')
                                ⏰
                            @else
                                📢
                            @endif
                        </div>

                        <div class="notification-row-body">
                            <p class="notification-row-title">{{ $notification->title }}</p>
                            <p class="notification-row-text">{{ $notification->body }}</p>
                            <p class="notification-row-time">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>

                        @if ($notification->read_at === null)
                            <button
                                type="button"
                                class="notification-mark-read-btn"
                                data-mark-read-url="{{ route('user.notifications.mark-read', $notification) }}"
                                aria-label="Mark as read"
                            ></button>
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
    <script src="{{ asset('js/notifications.js') }}" defer></script>
@endpush
