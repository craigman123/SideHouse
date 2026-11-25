<?php

return [
    'current_version' => 'v1',
    'peppers' => [
        'v1' => env('PASSWORD_PEPPER'),
        // Add each retained version here before rotating the current version.
        // 'v2' => env('PASSWORD_PEPPER_V2'),
    ],
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD'),
    'development_seed_password' => 'sidehouse-local-only-seed-password',
];
