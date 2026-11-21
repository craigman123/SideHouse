@extends('owner.layouts.app')
@section('title', 'Activity Logs | Owner | Side House')
@section('page-title', 'Activity Logs')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-activity-logs.css') . '?v=' . filemtime(public_path('css/owner/owner-activity-logs.css')) }}">
@endpush
@section('content')
    @include('partials.activity-logs', ['logs' => $logs, 'isStaff' => false, 'filterRoute' => 'owner.activity-logs', 'dataUrl' => null])
@endsection
