<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumi_suggestion_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('suggestion_key', 191);
            $table->timestamp('dismissed_until');
            $table->timestamps();

            $table->unique(['user_id', 'suggestion_key']);
            $table->index(['user_id', 'dismissed_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumi_suggestion_dismissals');
    }
};
