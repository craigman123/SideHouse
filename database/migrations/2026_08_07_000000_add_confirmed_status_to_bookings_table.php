<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres has no MySQL-style ENUM MODIFY — Laravel's enum() on
        // pgsql is actually a varchar with a CHECK constraint, so we drop
        // and recreate that constraint instead. GuestBookingController and
        // GcashWebhookController both write 'confirmed', but the original
        // constraint only allows pending/paid/cancelled.

        // Move any existing 'paid' rows over to 'confirmed' first — doing
        // this before we tighten the constraint means the update itself
        // isn't blocked by the constraint we're about to drop/add.
        DB::table('bookings')->where('status', 'paid')->update(['status' => 'confirmed']);

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('pending', 'confirmed', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');

        DB::table('bookings')->where('status', 'confirmed')->update(['status' => 'paid']);

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('pending', 'paid', 'cancelled'))");
    }
};