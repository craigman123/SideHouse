<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->string('category', 50)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            // users.user_id is the actual primary key on that table (see
            // App\Models\User::$primaryKey), not the default 'id'.
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
