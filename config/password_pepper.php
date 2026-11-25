<?php

return [
    'current_version' => 'v1',
    'peppers' => [
        'v1' => env('PASSWORD_PEPPER'),
    ],
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD'),
    'development_seed_password' => 'sidehouse-local-only-seed-password',
];
