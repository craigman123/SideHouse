@extends('owner.layouts.app')
@section('title', 'Customers | Owner | Side House')
@section('page-title', 'Customers')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-customers.css') . '?v=' . filemtime(public_path('css/owner/owner-customers.css')) }}">
@endpush
@section('content')
    @include('partials.customer-directory', ['showFinancials' => true])
@endsection
@push('scripts')
    <script src="{{ asset('js/shared/customers.js') . '?v=' . filemtime(public_path('js/shared/customers.js')) }}" defer></script>
@endpush
