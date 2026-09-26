<?php

namespace App\Services\DataPipeline;

use Illuminate\Support\Facades\DB;

/**
 * Fuseaux de la base, lus par system:status (setup.sh fait la même mesure avant
 * toute écriture).
 *
 * Laravel écrit ses heures en UTC ; MariaDB convertit les colonnes TIMESTAMP selon
 * le fuseau de la session, jamais les DATETIME. Une session hors UTC stocke donc les
 * heures décalées (portable en EAT jusqu'au 26/09/2026 : 3 h). Deux mesures :
 *
 * - le décalage effectif de la session (NOW() contre UTC_TIMESTAMP()), quel que soit
 *   le nom du fuseau ;
 * - l'invariant des données : une ligne clôturée du journal a été vérifiée égale à
 *   son match à la clôture, donc kickoff_at (TIMESTAMP) = match_date (DATETIME). Si
 *   le fuseau de session change après coup, toutes ces lignes divergent d'un coup :
 *   détecte aussi une corruption passée, pas seulement le réglage du moment.
 */
class TimeZoneCheck
{
    /**
     * @return array{measurable: bool, driver: string, offset_minutes: ?int, session: ?string, global: ?string, system: ?string, configured: ?string, settled_mismatches: int}
     */
    public function inspect(): array
    {
        $driver = DB::connection()->getDriverName();
        $configured = config('database.connections.' . config('database.default') . '.timezone');
        $mismatches = DB::table('prediction_log')
            ->join('matches', 'matches.id', '=', 'prediction_log.match_id')
            ->whereNotNull('prediction_log.outcome_occurred')
            ->whereColumn('prediction_log.kickoff_at', '!=', 'matches.match_date')
            ->count();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return ['measurable' => false, 'driver' => $driver, 'offset_minutes' => null, 'session' => null, 'global' => null,
                'system' => null, 'configured' => $configured, 'settled_mismatches' => $mismatches];
        }

        $row = DB::selectOne('SELECT @@session.time_zone s, @@global.time_zone g, @@system_time_zone y, TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) o');

        return [
            'measurable' => true,
            'driver' => $driver,
            'offset_minutes' => (int) $row->o,
            'session' => (string) $row->s,
            'global' => (string) $row->g,
            'system' => (string) $row->y,
            'configured' => $configured,
            'settled_mismatches' => $mismatches,
        ];
    }
}
