<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = config('password_pepper.seed_admin_password');
        if (app()->environment('production')) {
            if (! is_string($password) || trim($password) === '' || trim($password) === 'password') {
                throw new RuntimeException(
                    'SEED_ADMIN_PASSWORD must be set to a non-empty value other than "password" before production seeding.'
                );
            }
        } elseif (! is_string($password) || $password === '') {
            $password = config('password_pepper.development_seed_password');
        }

        User::firstOrCreate(
            ['email' => 'admin@sidehouse.com'],
            [
                'username' => 'admin',
                'name' => 'Admin',
                'password' => $password,
                'role' => 'admin',
            ],
        );
    }
}
