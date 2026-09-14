<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paramètres du calcul affiché et configuration complète séparée.
     *
     * - model_mode : configuration qui a produit model_probability (market_only
     *   depuis le 14/09/2026, seule configuration mesurée par le backtest).
     * - lambda_home, lambda_away, rho : paramètres de CE calcul, identiques sur les
     *   lignes d'un même match. L'animation Monte-Carlo les lit ; les recalculer à
     *   l'affichage pourrait montrer d'autres paramètres que la probabilité à l'écran.
     * - full_model_probability, full_model_signals : ancienne fusion marché +
     *   comparaison + blessures, calculée et stockée à part, jamais dans
     *   model_probability. Pour comparer les deux configurations sur matchs réels.
     *
     * Nullables : les lignes antérieures sont recalculées après la migration.
     */
    public function up(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->string('model_mode', 16)->nullable()->after('model_probability');
            $table->decimal('lambda_home', 6, 3)->nullable()->after('model_mode');
            $table->decimal('lambda_away', 6, 3)->nullable()->after('lambda_home');
            $table->decimal('rho', 7, 5)->nullable()->after('lambda_away');
            $table->decimal('full_model_probability', 6, 5)->nullable()->after('rho');
            $table->json('full_model_signals')->nullable()->after('full_model_probability');
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropColumn(['model_mode', 'lambda_home', 'lambda_away', 'rho', 'full_model_probability', 'full_model_signals']);
        });
    }
};
