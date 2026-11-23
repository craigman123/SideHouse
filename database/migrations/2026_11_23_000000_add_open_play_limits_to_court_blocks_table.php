<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_blocks', function (Blueprint $table) {
            $table->unsignedInteger('max_players')->nullable();
            $table->decimal('price_per_player', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('court_blocks', function (Blueprint $table) {
            $table->dropColumn(['max_players', 'price_per_player']);
        });
    }
};
