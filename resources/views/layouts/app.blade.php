@php
    $shell = [
        'area' => 'admin',
        'area_label' => 'Admin',
        'html_class' => 'admin-layout-shell',
        'profile_route' => 'admin.profile',
        'menu' => [
            ['section' => 'Main', 'label' => 'Overview', 'icon' => 'overview', 'route' => 'admin.dashboard'],
            ['section' => 'Main', 'label' => 'Widgets', 'icon' => 'widgets', 'route' => 'admin.widgets.index', 'active' => 'admin.widgets.*'],
            ['section' => 'Management', 'label' => 'Bookings', 'icon' => 'bookings', 'route' => 'bookings.index', 'active' => 'bookings.*'],
            ['section' => 'Management', 'label' => 'Courts', 'icon' => 'courts', 'route' => 'courts.index', 'active' => 'courts.*'],
            ['section' => 'Management', 'label' => 'Configuration', 'icon' => 'configuration', 'route' => 'admin.configuration.index', 'active' => 'admin.configuration.*'],
            ['section' => 'Management', 'label' => 'Memberships', 'icon' => 'customers', 'route' => 'admin.memberships.index', 'active' => 'admin.memberships.*'],
            ['section' => 'Management', 'label' => 'Profile', 'icon' => 'profile', 'route' => 'admin.profile'],
            ['section' => 'Management', 'label' => 'Equipment Availability', 'icon' => 'equipment', 'route' => 'admin.equipment.availability', 'active' => 'admin.equipment.availability'],
            ['section' => 'Users', 'label' => 'Customers', 'icon' => 'customers', 'route' => 'admin.customers.index', 'active' => 'admin.customers.*'],
            ['section' => 'Users', 'label' => 'User access', 'icon' => 'access', 'route' => 'admin.user-access.page', 'active' => 'admin.user-access.*', 'permission' => 'user_access.manage'],
            ['section' => 'Users', 'label' => 'Announcements', 'icon' => 'announcements', 'route' => 'admin.announcements.index', 'active' => 'admin.announcements.*'],
            ['section' => 'Audit Logs', 'label' => 'Reports', 'icon' => 'reports', 'route' => 'admin.reports.index', 'active' => 'admin.reports.*'],
            ['section' => 'Audit Logs', 'label' => 'Activity Logs', 'icon' => 'activity', 'route' => 'activity_logs.index', 'active' => 'activity_logs.*'],
        ],
    ];
@endphp
@include('layouts.partials.dashboard-shell', ['shell' => $shell])
