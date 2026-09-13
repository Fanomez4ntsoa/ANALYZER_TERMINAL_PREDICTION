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
        Schema::table('matches', function (Blueprint $table) {
            // Cotes au moment de la prédiction (snapshot initial)
            $table->decimal('odds_at_pred_home', 6, 3)->nullable()->after('odds_away_under_1_5');
            $table->decimal('odds_at_pred_draw', 6, 3)->nullable()->after('odds_at_pred_home');
            $table->decimal('odds_at_pred_away', 6, 3)->nullable()->after('odds_at_pred_draw');
            $table->decimal('odds_at_pred_over', 6, 3)->nullable()->after('odds_at_pred_away');

            // Cotes de clôture (dernier snapshot avant kick-off)
            $table->decimal('odds_closing_home', 6, 3)->nullable()->after('odds_at_pred_over');
            $table->decimal('odds_closing_draw', 6, 3)->nullable()->after('odds_closing_home');
            $table->decimal('odds_closing_away', 6, 3)->nullable()->after('odds_closing_draw');
            $table->decimal('odds_closing_over', 6, 3)->nullable()->after('odds_closing_away');

            // Timestamp de la prédiction
            $table->timestamp('predicted_at')->nullable()->after('odds_closing_over');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'odds_at_pred_home', 'odds_at_pred_draw', 'odds_at_pred_away', 'odds_at_pred_over',
                'odds_closing_home', 'odds_closing_draw', 'odds_closing_away', 'odds_closing_over',
                'predicted_at',
            ]);
        });
    }
};
