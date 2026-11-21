@extends('layouts.app')

@section('title', 'Activity Logs | Side House')
@section('page-title', 'Activity Logs')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-activity-logs.css') }}">
@endpush

@section('content')
    @include('partials.activity-logs', [
        'logs' => $logs,
        'isStaff' => false,
        'filterRoute' => 'activity_logs.index',
        'dataUrl' => null,
    ])
@endsection
