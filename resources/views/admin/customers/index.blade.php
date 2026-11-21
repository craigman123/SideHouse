@extends('layouts.app')

@section('title', 'Customers | Side House')
@section('page-title', 'Customers')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/customers.css') . '?v=' . filemtime(public_path('css/shared/customers.css')) }}">
@endpush

@section('content')
    @include('partials.customer-directory', ['showFinancials' => true])
@endsection

@push('scripts')
    <script src="{{ asset('js/shared/customers.js') . '?v=' . filemtime(public_path('js/shared/customers.js')) }}" defer></script>
@endpush
