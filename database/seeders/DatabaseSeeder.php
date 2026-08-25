<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    // DatabaseSeeder.php
    public function run()
    {
        // Remove hardcoded password
        // Use a secure random password and output it during seeding
        $password = Str::random(16);
        
        User::create([
            'name' => 'Admin',
            'email' => 'admin@sidehouse.com',
            'password' => Hash::make($password),
            'is_admin' => true,
        ]);
        
        $this->command->info("Admin password: {$password}");
    }
}
