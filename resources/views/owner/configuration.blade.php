@extends('owner.layouts.app')
@section('title', 'Configuration | Owner | Side House')
@section('page-title', 'Configuration')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-specific-date-closures.css') . '?v=' . filemtime(public_path('css/owner/owner-specific-date-closures.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-schedule.css') . '?v=' . filemtime(public_path('css/owner/owner-schedule.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/booking/manual-booking.css') }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-configuration.css') . '?v=' . filemtime(public_path('css/owner/owner-configuration.css')) }}">
@endpush
@section('content')
    @include('partials.management.configuration')
@endsection
@push('scripts')
    <script src="{{ asset('js/shared/schedule.js') . '?v=' . filemtime(public_path('js/shared/schedule.js')) }}" defer></script>
    <script src="{{ asset('js/config/manual-booking.js') }}" defer></script>
    <script src="{{ asset('js/booking/closure-edit.js') }}" defer></script>
    <script src="{{ asset('js/user/specific-date-closures.js') }}" defer></script>
    <script type="application/json" id="sh-closures-data">{!! $closures->map(fn ($c) => ['id' => $c->id, 'date' => $c->date->toDateString(), 'court_id' => $c->court_id])->toJson() !!}</script>
@endpush
