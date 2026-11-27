@php
    $shell = [
        'area' => 'owner',
        'area_label' => 'Owner',
        'html_class' => 'admin-layout-shell owner-layout-shell',
        'profile_route' => 'owner.profile',
        'menu' => [
            ['section' => 'Main', 'label' => 'Overview', 'icon' => 'overview', 'route' => 'owner.dashboard'],
            ['section' => 'Management', 'label' => 'Bookings', 'icon' => 'bookings', 'route' => 'owner.bookings'],
            ['section' => 'Management', 'label' => 'Courts', 'icon' => 'courts', 'route' => 'owner.courts'],
            ['section' => 'Management', 'label' => 'Configuration', 'icon' => 'configuration', 'route' => 'owner.configuration'],
            ['section' => 'Management', 'label' => 'Memberships', 'icon' => 'customers', 'route' => 'owner.memberships.index', 'active' => 'owner.memberships.*'],
            ['section' => 'Management', 'label' => 'Profile', 'icon' => 'profile', 'route' => 'owner.profile'],
            ['section' => 'Management', 'label' => 'Equipment Availability', 'icon' => 'equipment', 'route' => 'owner.equipment'],
            ['section' => 'Users', 'label' => 'Customers', 'icon' => 'customers', 'route' => 'owner.customers'],
            ['section' => 'Users', 'label' => 'User access', 'icon' => 'access', 'route' => 'owner.user-access', 'permission' => 'user_access.manage'],
            ['section' => 'Users', 'label' => 'Announcements', 'icon' => 'announcements', 'route' => 'owner.announcements'],
            ['section' => 'Audit Logs', 'label' => 'Reports', 'icon' => 'reports', 'route' => 'owner.reports'],
            ['section' => 'Audit Logs', 'label' => 'Activity Logs', 'icon' => 'activity', 'route' => 'owner.activity-logs'],
        ],
    ];
@endphp
@include('layouts.partials.dashboard-shell', ['shell' => $shell])
