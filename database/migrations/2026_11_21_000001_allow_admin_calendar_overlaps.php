<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Admin overrides may create active overlapping slots. Guest and user
        // paths still run their locked conflict checks before inserting slots.
        DB::statement('DROP INDEX IF EXISTS booking_slots_court_date_start_unique');
    }

    public function down(): void
    {
        DB::statement('CREATE UNIQUE INDEX booking_slots_court_date_start_unique '
            .'ON booking_slots (court_id, date, start_time) WHERE is_active = true');
    }
};
