@extends('layouts.app')

@section('title', 'Widgets | Side House')
@section('page-title', 'Widgets')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-schedule.css') }}">
@endpush

@section('content')
    <div class="schedule-page admin-widgets-page">
        <div class="schedule-header">
            <div>
                <h1 class="schedule-title">Widgets</h1>
                <p class="schedule-subtitle">Manage and review widgets for the Side House dashboard.</p>
            </div>
        </div>

        <div class="schedule-panel">
            <p class="schedule-panel-note">Widgets will appear here.</p>
        </div>
    </div>
@endsection
