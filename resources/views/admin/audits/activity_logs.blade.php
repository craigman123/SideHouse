@extends('layouts.app')

@section('title', 'Activity Logs | Logs')
@section('page-title', 'Activity Logs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/bookings.css') }}">
@endpush

@section('content')

    {{-- Filters --}}
    <div class="panel filters-panel">
        <form method="GET" action="{{ route('activity_logs.index') }}" class="filters-form">

            <div class="filter-group">
                <label for="user">User</label>
                <input type="text" id="user" name="user" placeholder="Search by user..." value="{{ request('user') }}">
            </div>

            <div class="filter-group">
                <label for="action">Action</label>
                <input type="text" id="action" name="action" placeholder="e.g. booking.created" value="{{ request('action') }}">
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="{{ route('activity_logs.index') }}" class="btn-clear">Clear</a>
            </div>

        </form>
    </div>

    {{-- Log table --}}
    <div class="panel">
        <h2>Activity Logs <span class="count-badge">{{ $logs->total() }}</span></h2>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Subject</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="cell-muted">{{ $log->created_at?->format('d M Y, g:i A') ?? '—' }}</td>
                            <td class="cell-name">{{ $log->user_name ?? 'Guest' }}</td>
                            <td><code>{{ $log->action }}</code></td>
                            <td>{{ $log->description }}</td>
                            <td class="cell-muted">{{ $log->subject_type ?? '—' }}</td>
                            <td class="cell-muted">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty-row">No activity recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <p style="text-align: center">Showing {{ $logs->firstItem() }} to {{ $logs->lastItem() }} of {{ $logs->total() }} results</p>
        @if ($logs->hasPages())
            <div class="pagination-wrapper">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

@endsection