@extends('staff.layouts.app')

@section('title', 'Staff Customers | Side House')
@section('page-title', 'Customers')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/staff/staff-layout.css') . '?v=' . filemtime(public_path('css/staff/staff-layout.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/staff/staff-customers.css') . '?v=' . filemtime(public_path('css/staff/staff-customers.css')) }}">
@endpush

@section('content')
    @include('partials.customer-directory', ['showFinancials' => $showFinancials])
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-customers.js') . '?v=' . filemtime(public_path('js/staff/staff-customers.js')) }}" defer></script>
@endpush
