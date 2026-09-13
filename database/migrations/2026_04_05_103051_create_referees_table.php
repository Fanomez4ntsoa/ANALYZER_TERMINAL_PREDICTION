<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('referees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_football_id')->unique()->nullable();
            $table->string('name');
            $table->string('country')->nullable();

            // Stats agrégées (mises à jour via API-Football)
            $table->unsignedSmallInteger('games_officiated')->default(0);
            $table->decimal('yellow_per_game', 4, 2)->default(0);
            $table->decimal('red_per_game', 4, 2)->default(0);
            $table->decimal('penalties_per_game', 4, 2)->default(0);
            $table->decimal('fouls_per_game', 4, 2)->default(0);

            // Style déduit
            $table->enum('style', ['strict', 'moderate', 'lenient'])->default('moderate');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referees');
    }
};
