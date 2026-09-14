<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Heure réelle de la cote et fiabilité du relevé.
     *
     * Jusqu'au 14/09/2026, les relevés passaient par le cache de The Odds API
     * (2 h) : un relancement recyclait une réponse ancienne sous un snapshot_at
     * récent et ajoutait une variation nulle fictive. Constaté ce jour-là : les
     * relevés de 06:57 et 07:32 reprenaient la réponse de 06:19.
     *
     * quoted_at : last_update du bookmaker de référence (le plus ancien des marchés
     * relevés), l'heure de la cote et non celle de l'enregistrement.
     * reliable : vrai seulement pour un relevé sans cache dont la cote a moins de
     * pipeline.odds_snapshot.max_quote_age_minutes. Défaut faux : tous les relevés
     * antérieurs sont non fiables et ne servent pas au test de mouvement de ligne.
     * Aucune donnée n'est modifiée ni supprimée.
     */
    public function up(): void
    {
        Schema::table('odds_movements', function (Blueprint $table) {
            $table->timestamp('quoted_at')->nullable()->after('snapshot_at');
            $table->boolean('reliable')->default(false)->after('quoted_at');
            $table->index(['match_id', 'reliable', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::table('odds_movements', function (Blueprint $table) {
            $table->dropIndex(['match_id', 'reliable', 'snapshot_at']);
            $table->dropColumn(['quoted_at', 'reliable']);
        });
    }
};
