@extends('layouts.user')

@section('title', 'My Bookings | Side House')
@section('page-title', 'My Bookings')
@section('page-class', 'my-bookings-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/bookings-index.css') }}">
@endpush

@section('content')

    <div class="panel my-bookings-panel">
        <div class="bookings-toolbar">
            <div>
                <h2 class="bookings-toolbar-title" id="bookingHistoryTitle">Booking History</h2>
                <span class="count-badge">{{ $bookings->total() }} booking(s)</span>
            </div>

            <form method="GET" action="{{ route('user.bookings.index') }}" class="status-filter-form">
                <label for="statusFilter" class="status-filter-label">Status</label>
                <select name="status" id="statusFilter" class="status-filter-select" onchange="this.form.submit()">
                    <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="paid" {{ $status === 'paid' ? 'selected' : '' }}>Confirmed</option>
                    <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </form>
        </div>

        @if (session('error'))
            <div class="bookings-message bookings-error" role="alert">{{ session('error') }}</div>
        @endif

        <div class="bookings-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>

        <div class="table-responsive" aria-busy="true">
            <table>
                <thead>
                    <tr>
                        <th>Court</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        @php
                            $isCancellable = $booking->status === 'pending'
                                && $booking->date->copy()->startOfDay()->gte(today());
                            $isExpired = $booking->status === 'pending'
                                && $booking->expires_at
                                && $booking->expires_at->isPast();
                            $displayStatus = $isExpired ? 'expired' : ($booking->status === 'paid' ? 'confirmed' : $booking->status);
                            $statusClass = $isExpired ? 'expired' : $booking->status;
                        @endphp
                        <tr
                            data-booking-id="{{ $booking->id }}"
                            data-booking="Court {{ $booking->court_id }} &middot; {{ $booking->date->format('D j M') }}, {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}"
                            class="booking-row booking-row-{{ $displayStatus }}"
                            @if ($booking->expires_at) data-book-again-url="{{ route('book.index', ['openBooking' => 1, 'court_id' => $booking->court_id]) }}" @endif
                        >
                            <td class="cell-name" data-label="Court">{{ $booking->court?->name ?? 'Court ' . $booking->court_id }}</td>
                            <td data-label="Date">{{ $booking->date->format('d M Y') }}</td>
                            <td data-label="Time">
                                {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}
                                &ndash;
                                {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                            </td>
                            <td data-label="Total">&#8369;{{ number_format($booking->amount, 2) }}</td>
                            <td class="cell-muted" data-label="Payment">
                                {{ match ($booking->payment_method) {
                                    'gcash' => 'GCash',
                                    'landbank' => 'Landbank',
                                    'maya' => 'Maya',
                                    default => ucfirst($booking->payment_method ?? 'Unknown'),
                                } }}
                            </td>
                            <td data-label="Status">
                                <span class="status status-{{ $statusClass }}">
                                    {{ $displayStatus === 'confirmed' ? 'Confirmed' : ucfirst($displayStatus) }}
                                </span>
                                @if ($booking->status === 'pending' && !$isExpired && $booking->expires_at)
                                    <span class="booking-countdown" data-expires-at="{{ $booking->expires_at->toIso8601String() }}">Time remaining <strong>--:--</strong></span>
                                @endif
                            </td>
                            <td class="actions-cell" data-label="Actions">
                                @if ($booking->status === 'pending' && !$isExpired)
                                    <a class="booking-action booking-pay-action" href="{{ route('book.waiting', ['booking' => $booking->id]) }}">Pay now / View QR</a>
                                    @if ($isCancellable)
                                    <button type="button" class="btn-icon btn-delete" data-cancel>Cancel</button>
                                    @endif
                                @elseif ($isExpired)
                                    <a class="booking-action booking-retry-action" href="{{ route('book.index', ['openBooking' => 1, 'court_id' => $booking->court_id]) }}">Book again</a>
                                @elseif ($booking->status === 'cancelled')
                                    <span class="cell-muted">&mdash;</span>
                                @else
                                    <span class="cell-muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty-row">
                                @if ($status !== 'all')
                                    No {{ $status === 'paid' ? 'confirmed' : $status }} bookings found.
                                @else
                                    <span class="empty-bookings-title">No bookings yet</span>
                                    <a class="booking-action booking-pay-action" href="{{ route('book.index') }}">Book a court</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($bookings->hasPages())
            <div class="pagination-wrapper">
                <nav aria-label="Booking history pages" class="simple-pagination">
                    @if ($bookings->onFirstPage())
                        <span class="page-btn page-btn-disabled">&larr; Prev</span>
                    @else
                        <a href="{{ $bookings->previousPageUrl() }}" class="page-btn">&larr; Prev</a>
                    @endif

                    <span class="page-info">Page {{ $bookings->currentPage() }} of {{ $bookings->lastPage() }}</span>

                    @if ($bookings->hasMorePages())
                        <a href="{{ $bookings->nextPageUrl() }}" class="page-btn">Next &rarr;</a>
                    @else
                        <span class="page-btn page-btn-disabled">Next &rarr;</span>
                    @endif
                </nav>
            </div>
        @endif
    </div>

    <div class="modal-overlay" id="cancelModal" data-cancel-url="{{ route('user.bookings.cancel', ':id') }}">
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
    <script src="{{ asset('js/config/cancel-booking.js') }}" defer></script>
    <script src="{{ asset('js/user/my-bookings.js') }}" defer></script>
@endpush
