@extends('layouts.app')

@section('title', 'Bookings | Court Booking')
@section('page-title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/bookings.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-bookings-board.css') }}">
@endpush

@section('content')

    <div class="bookings-workspace" id="bookingsWorkspace">
        <div class="bookings-view-tabs" role="tablist" aria-label="Bookings views">
            <button type="button" class="bookings-view-tab is-active" role="tab" aria-selected="true" aria-controls="bookingListView" data-bookings-view="list">
                Booking list
            </button>
            <button type="button" class="bookings-view-tab" role="tab" aria-selected="false" aria-controls="courtBoardView" data-bookings-view="board">
                Daily court board
            </button>
            <button type="button" class="bookings-view-tab" role="tab" aria-selected="false" aria-controls="courtMonthView" data-bookings-view="month">
                Month view
            </button>
        </div>

        <section id="bookingListView" class="bookings-view-panel" role="tabpanel">
            {{-- Existing filters and table are intentionally kept in this view. --}}
            <div class="panel filters-panel">
                <form method="GET" action="{{ route('bookings.index') }}" class="filters-form">

            <div class="filter-group">
                <label for="search">Customer</label>
                <input type="text" id="search" name="search" placeholder="Search by name..." value="{{ request('search') }}">
            </div>

            <div class="filter-group">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="{{ request('date') }}">
            </div>

            <div class="filter-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="all" {{ request('status', 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="{{ route('bookings.index') }}" class="btn-clear">Clear</a>
            </div>

                </form>
            </div>

            <div class="panel">
                <h2>All Bookings <span class="count-badge">{{ $bookings->total() }}</span></h2>

                <div class="table-responsive">
                    <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Court</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        <tr>
                            <td class="cell-name">{{ $booking->customer_name }}</td>
                            <td>{{ $booking->court_id ? 'Court ' . $booking->court_id : '—' }}</td>
                            <td>{{ \Carbon\Carbon::parse($booking->date)->format('M d, Y') }}</td>
                            <td>{{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}</td>
                            <td>₱{{ number_format($booking->amount, 2) }}</td>
                            <td>
                                <span class="status status-{{ $booking->status }}">
                                    {{ ucfirst($booking->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty-row">No bookings found.</td>
                        </tr>
                    @endforelse
                </tbody>
                    </table>
                </div>

                <p style="text-align: center">Showing {{ $bookings->firstItem() }} to {{ $bookings->lastItem() }} of {{ $bookings->total() }} results</p>
                @if ($bookings->hasPages())
                    <div class="pagination-wrapper">
                        {{ $bookings->links() }}
                    </div>
                @endif
            </div>
        </section>

        <section id="courtBoardView" class="bookings-view-panel" role="tabpanel" hidden>
            <div class="panel court-board-panel">
                <div class="court-board-toolbar">
                    <div>
                        <p class="court-board-eyebrow">Operations view</p>
                        <h2>Daily court board</h2>
                        <p class="court-board-subtitle">Live view of bookings, closures, and rented equipment.</p>
                    </div>
                    <div class="court-board-controls" aria-label="Choose board date">
                        <button type="button" class="court-board-nav" data-board-date="previous" aria-label="Previous day">‹</button>
                        <input type="date" id="courtBoardDate" value="{{ now()->toDateString() }}" min="{{ now()->subDay()->toDateString() }}" max="{{ now()->addMonths(6)->toDateString() }}">
                        <button type="button" class="court-board-nav" data-board-date="next" aria-label="Next day">›</button>
                        <button type="button" class="btn-clear court-board-today" data-board-date="today">Today</button>
                    </div>
                </div>

                <div class="court-board-legend" aria-label="Booking status legend">
                    <span><i class="legend-dot legend-paid"></i>Paid</span>
                    <span><i class="legend-dot legend-pending"></i>Pending</span>
                    <span><i class="legend-dot legend-cancelled"></i>Cancelled</span>
                    <span><i class="legend-dot legend-closure"></i>Closure</span>
                </div>

                <div id="courtBoardSummary" class="court-board-summary" aria-live="polite"></div>
                <div id="courtBoard" class="court-board" aria-live="polite"></div>
            </div>
        </section>
        <section id="courtMonthView" class="bookings-view-panel" role="tabpanel" hidden>
            <div class="panel court-board-panel">
                <div class="court-board-toolbar">
                    <div>
                        <p class="court-board-eyebrow">Operations view</p>
                        <h2>Month calendar</h2>
                        <p class="court-board-subtitle">Every booking for the month, laid out by date and time.</p>
                    </div>
                    <div class="court-board-controls" aria-label="Choose calendar month">
                        <button type="button" class="court-board-nav" data-month-nav="previous" aria-label="Previous month">‹</button>
                        <input type="month" id="courtMonthInput" value="{{ now()->format('Y-m') }}">
                        <button type="button" class="court-board-nav" data-month-nav="next" aria-label="Next month">›</button>
                        <button type="button" class="btn-clear court-board-today" data-month-nav="today">This month</button>
                    </div>
                </div>

                <div class="court-board-legend" aria-label="Booking status legend">
                    <span><i class="legend-dot legend-paid"></i>Paid</span>
                    <span><i class="legend-dot legend-pending"></i>Pending</span>
                    <span><i class="legend-dot legend-cancelled"></i>Cancelled</span>
                    <span><i class="legend-dot legend-closure"></i>Closure</span>
                </div>

                <div id="courtMonthSummary" class="court-board-summary" aria-live="polite"></div>
                <div class="court-month-scroll">
                    <div id="courtMonthGrid" class="court-month-grid" aria-live="polite"></div>
                </div>
            </div>
        </section>
    </div>

@endsection

@push('scripts')
    @php
        $courtBoardData = [
            'bookings'         => $courtBoardBookings,
            'closures'         => $courtBoardClosures,
            'courts'           => $courts,
            'businessSettings' => [
                'open_hour'       => $businessSettings->open_hour,
                'close_hour'      => $businessSettings->close_hour,
                'closed_weekdays' => $businessSettings->closed_weekdays,
            ],
        ];
    @endphp
    <script id="courtBoardData" type="application/json">{!! json_encode($courtBoardData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
    <script src="{{ asset('js/admin-bookings-board.js') }}" defer></script>
@endpush