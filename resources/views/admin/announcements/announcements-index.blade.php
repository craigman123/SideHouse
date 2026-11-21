@extends('layouts.app')

@section('title', 'Announcements | Side House')
@section('page-title', 'Announcements')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/announcements.css') }}">
    <script src="{{ asset('js/shared/announcements.js') . '?v=' . filemtime(public_path('js/shared/announcements.js')) }}" defer></script>
@endpush

@section('content')
    @include('partials.management.announcements')
@endsection
