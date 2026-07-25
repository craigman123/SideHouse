@extends('layouts.app')

@section('title', 'Overview | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@endpush

@section('content')

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
                        <td>{{ $booking->customer_name }}</td>
                        <td>{{ $booking->date }}</td>
                        <td>{{ $booking->start_time }} - {{ $booking->end_time }}</td>
                        <td>₱{{ number_format($booking->amount, 2) }}</td>
                        <td>
                            <span class="status {{ $booking->status === 'paid' ? 'status-paid' : 'status-pending' }}">
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

@endsection
