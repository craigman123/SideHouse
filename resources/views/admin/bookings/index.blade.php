@extends('layouts.app')

@section('title', 'Bookings | Side House')
@section('page-title', 'Bookings')

@php
    $permissions = app(\App\Services\UserAccessService::class)->flagsFor(auth()->user());
    $showFinancials = true;
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/bookings.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-bookings-board.css') . '?v=' . filemtime(public_path('css/admin/admin-bookings-board.css')) }}">
@endpush

@section('content')
    @include('partials.bookings-board', ['permissions' => $permissions, 'showFinancials' => $showFinancials])
@endsection
