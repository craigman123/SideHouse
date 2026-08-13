@extends('layouts.app')

@section('title', 'Bookings | Court Booking')
@section('page-title', 'Bookings')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/bookings.css') }}">
@endpush

@section('content')

    {{-- Filters --}}
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

    {{-- Bookings Table --}}
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

        {{-- Pagination --}}
        <p style="text-align: center">Showing {{ $bookings->firstItem() }} to {{ $bookings->lastItem() }} of {{ $bookings->total() }} results</p>
        @if ($bookings->hasPages())
            <div class="pagination-wrapper">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>

@endsection