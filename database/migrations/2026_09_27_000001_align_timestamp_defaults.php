<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Défaut explicite sur les TIMESTAMP NOT NULL (27/09/2026).
     *
     * Sans défaut déclaré, la structure dépend d'explicit_defaults_for_timestamp,
     * réglage global du serveur : 1 sur le portable (MariaDB 10.11), 0 sur le VPS
     * (10.6, non modifiable, autre application). À 0, le premier TIMESTAMP NOT NULL
     * d'une table reçoit en silence DEFAULT current_timestamp() ON UPDATE
     * current_timestamp() (pipeline_runs.started_at aurait été réécrit avec l'heure
     * de fin à chaque mise à jour du passage), les suivants un défaut 0000-00-00
     * refusé par le mode strict (predictions.computed_at : échec de migrate).
     *
     * Les migrations de création déclarent désormais useCurrent() ; celle-ci aligne
     * les bases déjà migrées : retire l'ON UPDATE sur le VPS, ajoute le défaut sur le
     * portable. Résultat identique partout : timestamp NOT NULL DEFAULT
     * current_timestamp(). Le code écrit toujours ces colonnes lui-même : le défaut
     * ne sert jamais.
     *
     * MariaDB/MySQL seulement : sous SQLite (tests), les créations donnent déjà la
     * structure finale.
     */
    private const COLUMNS = [
        'match_validations' => 'validated_at',
        'odds_movements' => 'snapshot_at',
        'predictions' => 'computed_at',
        'historical_matches' => 'imported_at',
        'pipeline_runs' => 'started_at',
    ];

    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->timestamp($column)->useCurrent()->change();
            });
        }
    }

    /**
     * Rien : revenir à une colonne sans défaut recréerait l'ON UPDATE silencieux sur
     * un serveur à explicit_defaults_for_timestamp=0.
     */
    public function down(): void
    {
    }
};
