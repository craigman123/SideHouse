@php
    $shell = [
        'area' => 'staff',
        'area_label' => 'Staff',
        'html_class' => 'admin-layout-shell staff-layout-shell',
        'profile_route' => 'staff.profile',
        'menu' => [
            ['section' => 'Main', 'label' => 'Overview', 'icon' => 'overview', 'route' => 'staff.dashboard'],
            ['section' => 'Management', 'label' => 'Bookings', 'icon' => 'bookings', 'route' => 'staff.bookings', 'permission' => 'bookings.view'],
            ['section' => 'Management', 'label' => 'Profile', 'icon' => 'profile', 'route' => 'staff.profile'],
            ['section' => 'Users', 'label' => 'Customers', 'icon' => 'customers', 'route' => 'staff.customers', 'permission' => 'customers.view'],
            ['section' => 'Audit Logs', 'label' => 'Activity Logs', 'icon' => 'activity', 'route' => 'staff.activity-logs', 'permission' => 'activity_logs.view'],
        ],
    ];
@endphp
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/staff/staff-consistency.css') . '?v=' . filemtime(public_path('css/staff/staff-consistency.css')) }}">
@endpush
@include('layouts.partials.dashboard-shell', ['shell' => $shell])
