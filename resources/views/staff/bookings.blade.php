@extends('staff.layouts.app')

@section('title', 'Staff Bookings | Side House')
@section('page-title', 'Bookings')

@php($showFinancials = false)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') . '?v=' . filemtime(public_path('css/user/dashboard.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/booking/bookings.css') . '?v=' . filemtime(public_path('css/booking/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/bookings-board.css') . '?v=' . filemtime(public_path('css/shared/bookings-board.css')) }}">
@endpush

@section('content')
    @include('partials.bookings-board', ['permissions' => $permissions, 'showFinancials' => $showFinancials])
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-bookings.js') . '?v=' . filemtime(public_path('js/staff/staff-bookings.js')) }}" defer></script>
@endpush
