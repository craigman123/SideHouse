@extends('layouts.app')

@section('title', 'Overview | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-overview.css') }}">
@endpush

@section('content')

    <section class="admin-overview-page" aria-label="Admin overview">
    <p class="overview-intro">A quick look at bookings and earnings at <strong>Side House</strong>.</p>

    <div class="card-grid">
        <div class="card">
            <p class="label">Total Income</p>
            <p class="value income">₱{{ number_format($totalIncome, 2) }}</p>
        </div>

        <div class="card">
            <p class="label">Today's Income</p>
            <p class="value income">₱{{ number_format($todayIncome, 2) }}</p>
        </div>

        <div class="card">
            <p class="label">Total Bookings</p>
            <p class="value bookings">{{ $totalBookings }}</p>
        </div>

        <div class="card">
            <p class="label">Today's Bookings</p>
            <p class="value bookings">{{ $todayBookings }}</p>
        </div>
    </div>

    <div class="panel">
    <h2>Upcoming Bookings</h2>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($upcomingBookings as $booking)
                    <tr>
                        <td data-label="Customer">{{ $booking->customer_name }}</td>
                        <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($booking->date)->format('M j, Y') }}</td>
                        <td data-label="Time">{{ \Illuminate\Support\Carbon::parse($booking->start_time)->format('g:i A') }} - {{ \Illuminate\Support\Carbon::parse($booking->end_time)->format('g:i A') }}</td>
                        <td data-label="Amount">₱{{ number_format($booking->amount, 2) }}</td>
                        <td data-label="Status">
                            <span class="status {{ $booking->status === 'paid' ? 'status-paid' : 'status-pending' }} {{ $booking->status === 'confirmed' ? 'status-confirmed' : '' }} {{ $booking->status === 'cancelled' ? 'status-cancelled' : '' }} {{ $booking->status === 'expired' ? 'status-expired' : '' }}">
                                {{ ucfirst($booking->status) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="empty-row">No upcoming bookings yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</section>

@endsection
