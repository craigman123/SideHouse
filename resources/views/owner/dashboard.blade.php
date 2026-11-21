@extends('owner.layouts.app')

@section('title', 'Overview | Owner | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-overview.css') . '?v=' . filemtime(public_path('css/owner/owner-overview.css')) }}">
@endpush

@section('content')
    @include('partials.management.overview')
@endsection
