@extends('owner.layouts.app')
@section('title', 'Announcements | Owner | Side House')
@section('page-title', 'Announcements')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-announcements.css') . '?v=' . filemtime(public_path('css/owner/owner-announcements.css')) }}">
@endpush
@section('content')
    @include('partials.management.announcements')
@endsection
@push('scripts')
    <script src="{{ asset('js/shared/announcements.js') . '?v=' . filemtime(public_path('js/shared/announcements.js')) }}" defer></script>
@endpush
