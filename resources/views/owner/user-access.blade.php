@extends('owner.layouts.app')
@section('title', 'User access | Owner | Side House')
@section('page-title', 'User access')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-user-access.css') . '?v=' . filemtime(public_path('css/owner/owner-user-access.css')) }}">
@endpush
@section('content')
    @include('partials.management.user-access')
@endsection
@push('scripts')
    <script src="{{ asset('js/shared/user-access.js') . '?v=' . filemtime(public_path('js/shared/user-access.js')) }}" defer></script>
@endpush
