@extends('layouts.app')

@section('title', 'Activity Logs | Side House')
@section('page-title', 'Activity Logs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/dashboard-base.css') . '?v=' . filemtime(public_path('css/shared/dashboard-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/activity-logs.css') . '?v=' . filemtime(public_path('css/shared/activity-logs.css')) }}">
@endpush

@section('content')
    @include('partials.activity-logs', [
        'logs' => $logs,
        'isStaff' => false,
        'filterRoute' => 'activity_logs.index',
        'dataUrl' => null,
    ])
@endsection
