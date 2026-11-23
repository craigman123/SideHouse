<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_court_closures', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday');
            $table->foreignId('court_id')->nullable()->constrained('courts')->nullOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'weekday', 'court_id'], 'weekly_court_closures_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_court_closures');
    }
};
