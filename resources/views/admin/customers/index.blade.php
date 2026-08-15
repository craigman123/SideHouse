@extends('layouts.app')

@section('title', 'Customers | Side House')
@section('page-title', 'Customers')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin-customers.css') }}">
@endpush

@section('content')
    <div class="customers-page is-loading" id="customersPage" data-customers-url="{{ route('admin.customers.data') }}">

        <div class="customers-header">
            <div>
                <p class="customers-subtitle">Ranked by number of bookings, most to least.</p>
            </div>
            <div class="customers-search-wrap">
                <input
                    type="search"
                    id="customerSearch"
                    class="customers-search-input"
                    placeholder="Search by name, email, or phone..."
                    autocomplete="off"
                />
            </div>
        </div>

        <div class="customers-table-wrap">
            <table class="customers-table">
                <thead>
                    <tr>
                        <th class="col-rank">#</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th class="col-num">Bookings</th>
                        <th class="col-num">Total Spent</th>
                        <th>Last Booking</th>
                        <th class="col-type">Type</th>
                    </tr>
                </thead>
                <tbody id="customersTableBody">
                    <tr class="customers-skeleton-row"><td colspan="7"><span class="skeleton"></span></td></tr>
                    <tr class="customers-skeleton-row"><td colspan="7"><span class="skeleton"></span></td></tr>
                    <tr class="customers-skeleton-row"><td colspan="7"><span class="skeleton"></span></td></tr>
                    <tr class="customers-skeleton-row"><td colspan="7"><span class="skeleton"></span></td></tr>
                </tbody>
            </table>

            <p class="customers-empty" id="customersEmpty" hidden>No customers match your search.</p>
            <p class="customers-error" id="customersError" hidden>Couldn't load customers. Please refresh the page.</p>
        </div>
    </div>

    {{-- Booking history modal for a single customer --}}
    <div class="modal-overlay" id="customerBookingsModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="customerBookingsModalLabel" hidden>
        <div class="modal-box" role="document">
            <div class="modal-header">
                <h3 id="customerBookingsModalLabel">Bookings</h3>
                <button type="button" class="modal-close" id="customerBookingsModalClose" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="customerBookingsList" class="customer-bookings-list"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-customers.js') }}" defer></script>
@endpush
