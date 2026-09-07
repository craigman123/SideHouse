<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paymongo_maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->text('raw_body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymongo_maintenance_windows');
    }
};
