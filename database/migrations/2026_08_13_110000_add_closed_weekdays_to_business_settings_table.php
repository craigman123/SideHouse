<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days of the week the business is closed every week (0 = Sunday ...
     * 6 = Saturday), on top of the one-off dates in court_closures.
     * Nullable/defaults to an empty array via the model cast, so existing
     * rows just mean "no weekly closures" until an admin sets one.
     */
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->json('closed_weekdays')->nullable()->after('max_duration_hours');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('closed_weekdays');
        });
    }
};
