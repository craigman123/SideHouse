@extends('layouts.app')

@section('title', 'Overview | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/shared/overview.css') . '?v=' . filemtime(public_path('css/shared/overview.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/shared/overview-border.css') . '?v=' . filemtime(public_path('css/shared/overview-border.css')) }}">
@endpush

@section('content')
    @include('partials.management.overview')
@endsection
