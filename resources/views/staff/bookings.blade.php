@extends('staff.layouts.app')

@section('title', 'Staff Bookings | Side House')
@section('page-title', 'Bookings')

@php($showFinancials = false)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/staff/staff-layout.css') . '?v=' . filemtime(public_path('css/staff/staff-layout.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') . '?v=' . filemtime(public_path('css/user/dashboard.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/booking/bookings.css') . '?v=' . filemtime(public_path('css/booking/bookings.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-bookings-board.css') . '?v=' . filemtime(public_path('css/admin/admin-bookings-board.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/staff/staff-bookings.css') . '?v=' . filemtime(public_path('css/staff/staff-bookings.css')) }}">
@endpush

@section('content')
    @include('partials.bookings-board', ['permissions' => $permissions, 'showFinancials' => $showFinancials])
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-bookings.js') . '?v=' . filemtime(public_path('js/staff/staff-bookings.js')) }}" defer></script>
@endpush
