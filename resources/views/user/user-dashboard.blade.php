@extends('layouts.user')

@section('title', 'Dashboard | Side House')
@section('page-title', 'Good afternoon, ' . explode(' ', $user->name)[0])

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
@endpush

@section('content')

    <section class="hero-next-game">
        <div class="hero-info">
            <span class="hero-eyebrow">Your next game</span>
            @if ($nextBooking)
                <h2>Court {{ $nextBooking->court_id }} &middot; {{ ucfirst($nextBooking->status) }}</h2>
                <p class="hero-meta">
                    {{ \Carbon\Carbon::parse($nextBooking->date)->format('l, j F') }} &middot;
                    {{ \Carbon\Carbon::parse($nextBooking->start_time)->format('g:i A') }} &ndash;
                    {{ \Carbon\Carbon::parse($nextBooking->end_time)->format('g:i A') }}
                </p>
            @else
                <h2>No upcoming games yet</h2>
                <p class="hero-meta">Book a court to see it here.</p>
            @endif

            <div class="countdown" id="countdown" aria-live="polite" data-next="{{ $nextBooking ? \Carbon\Carbon::parse($nextBooking->date . ' ' . $nextBooking->start_time)->toIso8601String() : '' }}">
                <div class="countdown-unit">
                    <span id="cdHours">00</span>
                    <label>Hours</label>
                </div>
                <div class="countdown-unit">
                    <span id="cdMinutes">00</span>
                    <label>Minutes</label>
                </div>
                <div class="countdown-unit">
                    <span id="cdSeconds">00</span>
                    <label>Seconds</label>
                </div>
            </div>

            <a href="/book" class="btn-hero-cta">Book another court</a>
        </div>

        <div class="hero-court" aria-hidden="true">
            <svg viewBox="0 0 240 320" xmlns="http://www.w3.org/2000/svg">
                <rect x="4" y="4" width="232" height="312" rx="10" class="court-surface" />
                <rect x="20" y="20" width="200" height="280" class="court-boundary" />
                <line x1="20" y1="160" x2="220" y2="160" class="court-line net-line" />
                <line x1="20" y1="100" x2="220" y2="100" class="court-line" />
                <line x1="20" y1="220" x2="220" y2="220" class="court-line" />
                <line x1="120" y1="20" x2="120" y2="300" class="court-line" />
                <circle cx="120" cy="70" r="7" class="court-ball" />
            </svg>
        </div>
    </section>

    <div class="card-grid">
        <div class="card">
            <p class="label">Upcoming Bookings</p>
            <p class="value bookings">{{ $upcomingBookings->count() }}</p>
        </div>
        <div class="card">
            <p class="label">Hours Played This Month</p>
            <p class="value income">{{ number_format($recentBookings->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count() * 1, 1) }}</p>
        </div>
        <div class="card">
            <p class="label">Favorite Court</p>
            <p class="value bookings">
                {{ $recentBookings->groupBy('court_id')->sortByDesc(fn($g) => $g->count())->keys()->first() ? 'Court ' . $recentBookings->groupBy('court_id')->sortByDesc(fn($g) => $g->count())->keys()->first() : '—' }}
            </p>
        </div>
    </div>

    <div class="panel">
        <h2>Available Courts</h2>
        <div class="court-grid">
            @forelse ($courts as $court)
                <div class="court-card">
                    <div class="court-card-graphic" aria-hidden="true">
                        <svg viewBox="0 0 120 160" xmlns="http://www.w3.org/2000/svg">
                            <rect x="2" y="2" width="116" height="156" rx="6" class="court-mini-surface" />
                            <rect x="12" y="12" width="96" height="136" class="court-mini-boundary" />
                            <line x1="12" y1="80" x2="108" y2="80" class="court-mini-line court-mini-net" />
                            <line x1="12" y1="48" x2="108" y2="48" class="court-mini-line" />
                            <line x1="12" y1="112" x2="108" y2="112" class="court-mini-line" />
                            <line x1="60" y1="12" x2="60" y2="148" class="court-mini-line" />
                        </svg>
                    </div>
                    <div class="court-card-body">
                        <h3>{{ $court->name }}</h3>
                        <span class="court-type-badge">{{ $court->type }}</span>
                        <p class="court-dimensions">{{ $court->length }}m &times; {{ $court->width }}m</p>
                        <p class="court-price">&#8369;{{ number_format($court->price, 0) }} <span>/ hour</span></p>
                    </div>
                </div>
            @empty
                <p class="empty-state">No courts available right now.</p>
            @endforelse
        </div>
    </div>

    <div class="panel">
        <h2>Upcoming Bookings</h2>
        <table>
            <thead>
                <tr>
                    <th>Court</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="upcomingBody">
                @forelse ($upcomingBookings as $booking)
                    <tr data-booking-id="{{ $booking->id }}" data-booking="Court {{ $booking->court_id }} · {{ \Carbon\Carbon::parse($booking->date)->format('D j M') }}, {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}">
                        <td class="cell-name">Court {{ $booking->court_id }}</td>
                        <td>{{ \Carbon\Carbon::parse($booking->date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }} &ndash; {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}</td>
                        <td>
                            <span class="status status-{{ $booking->status === 'confirmed' ? 'paid' : ($booking->status === 'cancelled' ? 'cancelled' : 'pending') }}">
                                {{ ucfirst($booking->status) }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="btn-icon btn-delete" data-cancel>Cancel</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-row">No upcoming bookings.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h2>Recent Activity</h2>
        <table>
            <thead>
                <tr>
                    <th>Court</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentBookings as $booking)
                    <tr>
                        <td class="cell-name">Court {{ $booking->court_id }}</td>
                        <td>{{ \Carbon\Carbon::parse($booking->date)->format('d M Y') }}</td>
                        <td>&#8369;{{ number_format($booking->amount, 0) }}</td>
                        <td>
                            <span class="status status-{{ $booking->status === 'confirmed' ? 'paid' : ($booking->status === 'cancelled' ? 'cancelled' : 'pending') }}">
                                {{ ucfirst($booking->status) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-row">No activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination-wrapper">
            <nav aria-label="Booking history pages">
                <span class="count-badge">{{ $recentBookings->count() }} recent bookings</span>
            </nav>
        </div>
    </div>

    <div class="modal-overlay" id="cancelModal">
        <div class="modal-box modal-box-sm">
            <div class="modal-header">
                <h3>Cancel this booking?</h3>
                <button type="button" class="modal-close" id="modalClose" aria-label="Close">&times;</button>
            </div>
            <p class="modal-text" id="modalText">This will free up the slot for other players. This can't be undone.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="modalKeep">Keep booking</button>
                <button type="button" class="btn btn-danger" id="modalConfirm">Cancel booking</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

@endsection

@push('scripts')
    <script src="{{ asset('js/user-dashboard.js') }}" defer></script>
@endpush