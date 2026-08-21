<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Historical no-op. The application uses pending/paid/cancelled,
        // which is already declared by the bookings create migration. The
        // removed PostgreSQL-only constraint rewrite made fresh SQLite test
        // databases impossible to build.
    }

    public function down(): void
    {
        // See up().
    }
};
