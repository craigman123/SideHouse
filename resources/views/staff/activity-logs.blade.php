@extends('staff.layouts.app')

@section('title', 'Activity Logs | Staff | Side House')
@section('page-title', 'Activity Logs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/activity-logs.css') . '?v=' . filemtime(public_path('css/shared/activity-logs.css')) }}">
@endpush

@section('content')
    @include('partials.activity-logs', [
        'logs' => $logs,
        'isStaff' => true,
        'filterRoute' => 'staff.activity-logs',
        'dataUrl' => $dataUrl,
    ])
@endsection

@push('scripts')
    <script src="{{ asset('js/staff/staff-activity-logs.js') . '?v=' . filemtime(public_path('js/staff/staff-activity-logs.js')) }}" defer></script>
@endpush
