{{--
    NOTE: I don't have your admin layout file, so this assumes the same
    @extends/@section convention your other admin views likely use
    (matching Admin_DashboardController's view('admin.admin_dashboard')).
    If your layout uses different names, just swap the two lines below —
    everything else on this page is self-contained.
--}}
@extends('layouts.app')

@section('title', 'Income Reports | Side House')
@section('page-title', 'Income Reports')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/admin/admin-base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/admin-reports.css') }}">
@endpush

@section('content')
    @include('partials.management.reports')
@endsection

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
    <script src="{{ asset('js/admin/admin-reports.js') }}" defer></script>
@endpush
