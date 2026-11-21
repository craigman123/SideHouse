<?php

return [
    'permissions' => [
        'bookings.view' => ['label' => 'View bookings', 'group' => 'Bookings'],
        'bookings.create' => ['label' => 'Create walk-ins', 'group' => 'Bookings'],
        'bookings.edit' => ['label' => 'Edit and move bookings', 'group' => 'Bookings'],
        'bookings.cancel' => ['label' => 'Cancel bookings', 'group' => 'Bookings'],
        'calendar_events.manage' => ['label' => 'Manage calendar events', 'group' => 'Calendar events'],
        'user_access.manage' => ['label' => 'Manage user access', 'group' => 'User access'],
        'user_access.manage_owners' => ['label' => 'Manage owner accounts', 'group' => 'User access'],
    ],

    'staff_permissions' => [
        'bookings.view',
        'bookings.create',
        'bookings.edit',
        'bookings.cancel',
        'calendar_events.manage',
    ],

    'presets' => [
        'owner' => ['*'],
        'admin' => [
            'bookings.view',
            'bookings.create',
            'bookings.edit',
            'bookings.cancel',
            'calendar_events.manage',
            'user_access.manage',
        ],
        'staff' => [
            'bookings.view',
            'bookings.create',
            'bookings.edit',
            'bookings.cancel',
            'calendar_events.manage',
        ],
        'user' => [],
    ],
];
