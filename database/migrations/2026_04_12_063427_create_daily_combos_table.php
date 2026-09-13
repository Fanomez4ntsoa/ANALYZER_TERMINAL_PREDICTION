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
        Schema::create('daily_combos', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('rank');         // 1, 2, 3

            // Picks du combo (JSON array d'objets)
            // [{match_id, home, away, competition, market, pick, odds, confidence}]
            $table->json('picks');
            $table->unsignedTinyInteger('match_count');

            // Métriques
            $table->decimal('total_odds', 6, 3);
            $table->decimal('combo_score', 5, 1);        // 0-100
            $table->decimal('avg_confidence', 5, 1);
            $table->text('logic')->nullable();

            // Résultat (rempli après les matchs)
            $table->boolean('won')->nullable();
            $table->decimal('profit', 8, 2)->nullable();

            $table->timestamps();

            $table->index(['date', 'rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_combos');
    }
};
