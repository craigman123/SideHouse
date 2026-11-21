@extends('layouts.app')

@section('title', 'Overview | Side House')
@section('page-title', 'Overview')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-overview.css') }}">
@endpush

@section('content')
    @include('partials.management.overview')
@endsection
