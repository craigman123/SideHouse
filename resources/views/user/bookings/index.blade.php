@extends('layouts.user')

@section('title', 'My Bookings | Side House')
@section('page-title', 'My Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/bookings-index.css') }}">
@endpush

@section('content')

    <div class="panel">
        <div class="bookings-toolbar">
            <div>
                <h2 class="bookings-toolbar-title">Booking History</h2>
                <span class="count-badge">{{ $bookings->total() }} booking(s)</span>
            </div>

            <form method="GET" action="{{ route('user.bookings.index') }}" class="status-filter-form">
                <label for="statusFilter" class="status-filter-label">Status</label>
                <select name="status" id="statusFilter" class="status-filter-select" onchange="this.form.submit()">
                    <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="paid" {{ $status === 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </form>
        </div>

        <div class="table-responsive">
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
                        @endphp
                        <tr
                            data-booking-id="{{ $booking->id }}"
                            data-booking="Court {{ $booking->court_id }} &middot; {{ $booking->date->format('D j M') }}, {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}"
                        >
                            <td class="cell-name">Court {{ $booking->court_id }}</td>
                            <td>{{ $booking->date->format('d M Y') }}</td>
                            <td>
                                {{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }}
                                &ndash;
                                {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}
                            </td>
                            <td>&#8369;{{ number_format($booking->amount, 0) }}</td>
                            <td class="cell-muted">
                                {{ match ($booking->payment_method) {
                                    'gcash' => 'GCash',
                                    'landbank' => 'Landbank',
                                    'maya' => 'Maya',
                                    default => ucfirst($booking->payment_method ?? 'Unknown'),
                                } }}
                            </td>
                            <td>
                                <span class="status status-{{ $booking->status === 'paid' ? 'paid' : ($booking->status === 'cancelled' ? 'cancelled' : 'pending') }}">
                                    {{ ucfirst($booking->status) }}
                                </span>
                            </td>
                            <td class="actions-cell">
                                @if ($isCancellable)
                                    <button type="button" class="btn-icon btn-delete" data-cancel>Cancel</button>
                                @else
                                    <span class="cell-muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty-row">
                                @if ($status !== 'all')
                                    No {{ $status }} bookings found.
                                @else
                                    No bookings yet — <a href="{{ route('book.index') }}">book a court</a> to get started.
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
    <script src="{{ asset('js/cancel-booking.js') }}" defer></script>
@endpush
