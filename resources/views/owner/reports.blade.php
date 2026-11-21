@extends('owner.layouts.app')
@section('title', 'Income Reports | Owner | Side House')
@section('page-title', 'Income Reports')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/owner/owner-base.css') . '?v=' . filemtime(public_path('css/owner/owner-base.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/owner/owner-reports.css') . '?v=' . filemtime(public_path('css/owner/owner-reports.css')) }}">
@endpush
@section('content')
    @include('partials.management.reports', ['ownerView' => true])
@endsection
@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
    <script src="{{ asset('js/owner/owner-reports.js') . '?v=' . filemtime(public_path('js/owner/owner-reports.js')) }}" defer></script>
@endpush
