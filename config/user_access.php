<?php

return [
    'permissions' => [
        'bookings.view' => ['label' => 'View bookings', 'group' => 'Bookings'],
        'bookings.create' => ['label' => 'Create walk-ins', 'group' => 'Bookings'],
        'bookings.edit' => ['label' => 'Edit and move bookings', 'group' => 'Bookings'],
        'bookings.cancel' => ['label' => 'Cancel bookings', 'group' => 'Bookings'],
        'calendar_events.manage' => ['label' => 'Manage calendar events', 'group' => 'Calendar events'],
        'customers.view' => ['label' => 'View customers', 'group' => 'Customers'],
        'customers.view_spending' => ['label' => 'View customer spending', 'group' => 'Customers'],
        'activity_logs.view' => ['label' => 'View activity logs', 'group' => 'Activity logs'],
        'reports.system' => ['label' => 'View system reports', 'group' => 'Reports'],
        'user_access.manage' => ['label' => 'Manage user access', 'group' => 'User access'],
        'user_access.manage_owners' => ['label' => 'Manage owner accounts', 'group' => 'User access'],
    ],

    'staff_permissions' => [
        'bookings.view',
        'bookings.create',
        'bookings.edit',
        'bookings.cancel',
        'calendar_events.manage',
        'customers.view',
        'customers.view_spending',
        'activity_logs.view',
    ],

    'presets' => [
        'owner' => ['*'],
        'admin' => [
            'bookings.view',
            'bookings.create',
            'bookings.edit',
            'bookings.cancel',
            'calendar_events.manage',
            'customers.view',
            'customers.view_spending',
            'activity_logs.view',
            'reports.system',
            'user_access.manage',
        ],
        'staff' => [
            'bookings.view',
            'bookings.create',
            'bookings.edit',
            'bookings.cancel',
            'calendar_events.manage',
            'customers.view',
            'activity_logs.view',
        ],
        'user' => [],
    ],

    'staff_navigation' => [
        ['label' => 'Overview', 'route' => 'staff.dashboard', 'permission' => null],
        ['label' => 'Bookings', 'route' => 'staff.bookings', 'permission' => 'bookings.view'],
        ['label' => 'Customers', 'route' => 'staff.customers', 'permission' => 'customers.view'],
        ['label' => 'Activity Logs', 'route' => 'staff.activity-logs', 'permission' => 'activity_logs.view'],
    ],
];
