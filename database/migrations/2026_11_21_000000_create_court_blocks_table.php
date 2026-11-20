<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->nullable()->constrained('courts')->restrictOnDelete();
            $table->enum('type', ['closed', 'maintenance', 'open_play']);
            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('block_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('created_by')->constrained('users', 'user_id')->restrictOnDelete();
            $table->timestamps();

            $table->index(['court_id', 'block_date', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_blocks');
    }
};
