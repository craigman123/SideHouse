@extends('layouts.app')

@section('title', 'Configuration | Side House')
@section('page-title', 'Configuration')
    
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/specific-date-closures.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-schedule.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking/manual-booking.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-configuration.css') }}">
@endpush

@section('content')
    @include('partials.management.configuration')
@endsection

@push('scripts')
    <script src="{{ asset('js/shared/schedule.js') . '?v=' . filemtime(public_path('js/shared/schedule.js')) }}" defer></script>
    <script src="{{ asset('js/config/manual-booking.js') }}" defer></script>
    <script src="{{ asset('js/booking/closure-edit.js') }}" defer></script>
    <script src="{{ asset('js/user/specific-date-closures.js') }}" defer></script>
    <script type="application/json" id="sh-closures-data">{!! $closures->map(fn ($c) => [
        'id' => $c->id,
        'date' => $c->date->toDateString(),
        'court_id' => $c->court_id,
    ])->toJson() !!}</script>
@endpush
