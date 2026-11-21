@extends('layouts.app')

@section('page-title', 'Equipment Availability')
@section('title', 'Equipment Availability | Side House')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-equipment-availability.css') }}">
@endpush

@section('content')
    @include('partials.management.equipment')
@endsection

@push('scripts')
    <script src="{{ asset('js/shared/equipment-availability.js') . '?v=' . filemtime(public_path('js/shared/equipment-availability.js')) }}" defer></script>
@endpush
