<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peak/night pricing — one recurring daily window (e.g. 5 PM–6 AM)
     * where every court's hourly rate gets a flat or percentage bump.
     * Lives on BusinessSetting since it's global (same for every court)
     * and there's only ever one active window, same as open_hour/close_hour.
     *
     * peak_end_hour <= peak_start_hour means the window crosses midnight —
     * identical convention to close_hour <= open_hour elsewhere in this table.
     */
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('peak_start_hour')->nullable()->after('closed_weekdays');
            $table->unsignedTinyInteger('peak_end_hour')->nullable()->after('peak_start_hour');
            // 'flat' = add peak_adjustment_value pesos per hour
            // 'percent' = multiply the hourly rate by (1 + peak_adjustment_value / 100)
            $table->string('peak_adjustment_type', 20)->nullable()->after('peak_end_hour');
            $table->decimal('peak_adjustment_value', 8, 2)->nullable()->after('peak_adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn([
                'peak_start_hour',
                'peak_end_hour',
                'peak_adjustment_type',
                'peak_adjustment_value',
            ]);
        });
    }
};
