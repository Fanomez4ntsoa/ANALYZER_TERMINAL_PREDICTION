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
        Schema::create('odds_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();

            // Snapshot des cotes à cet instant
            $table->decimal('odds_home', 6, 3)->nullable();
            $table->decimal('odds_draw', 6, 3)->nullable();
            $table->decimal('odds_away', 6, 3)->nullable();
            $table->decimal('odds_over_2_5', 6, 3)->nullable();
            $table->decimal('odds_under_2_5', 6, 3)->nullable();

            // Nombre de bookmakers au moment du snapshot
            $table->unsignedSmallInteger('bookmaker_count')->default(0);

            // Mouvement détecté par rapport au snapshot précédent
            $table->decimal('move_home_pct', 6, 2)->nullable();    // % de variation
            $table->decimal('move_draw_pct', 6, 2)->nullable();
            $table->decimal('move_away_pct', 6, 2)->nullable();
            $table->decimal('move_over_pct', 6, 2)->nullable();

            // Alerte sharp money
            $table->boolean('sharp_alert')->default(false);
            $table->unsignedTinyInteger('sharp_score')->default(0); // 0-100

            $table->timestamp('snapshot_at');
            $table->timestamps();

            $table->index(['match_id', 'snapshot_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odds_movements');
    }
};
