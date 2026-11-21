@extends('layouts.app')

@section('title', 'User access | Side House')
@section('page-title', 'User access')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-user-access.css') . '?v=' . filemtime(public_path('css/admin/admin-user-access.css')) }}">
@endpush

@section('content')
    @include('partials.management.user-access')
@endsection

@push('scripts')
    <script src="{{ asset('js/shared/user-access.js') . '?v=' . filemtime(public_path('js/shared/user-access.js')) }}" defer></script>
@endpush
