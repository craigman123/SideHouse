@extends('owner.layouts.app')
@section('title', 'Equipment Availability | Owner | Side House')
@section('page-title', 'Equipment Availability')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-equipment.css') . '?v=' . filemtime(public_path('css/owner/owner-equipment.css')) }}">
@endpush
@section('content')
    @include('partials.management.equipment')
@endsection
@push('scripts')
    <script src="{{ asset('js/shared/equipment-availability.js') . '?v=' . filemtime(public_path('js/shared/equipment-availability.js')) }}" defer></script>
@endpush
