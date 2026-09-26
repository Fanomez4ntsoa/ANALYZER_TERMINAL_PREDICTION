<?php

namespace App\Console\Commands;

use App\Models\PredictionLogEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manifeste des données de production : effectif et identifiant maximal de chaque
 * table transférée, effectifs du journal. Écrit sur le portable au moment du dump,
 * comparé sur le VPS après import (scripts/export-production.sh et
 * import-production.sh) : jamais de chiffres fixes, ils changent à chaque passage.
 *
 * Le backtest n'y figure pas : il est relancé sur le VPS (backtest:reference), ses
 * identifiants diffèrent.
 *
 * Repères horaires lus par Laravel (bornes des colonnes TIMESTAMP, et lignes du
 * journal dont le coup d'envoi ne vaut plus match_date, une colonne DATETIME) : un
 * décalage de fuseau au transfert changerait toutes ces heures sans changer aucun
 * effectif. Constaté le 26/09/2026 : 3 h d'écart sans --skip-tz-utc.
 */
class DataManifest extends Command
{
    protected $signature = 'data:manifest
                            {--compare= : Manifeste JSON à comparer à cette base (code de sortie 1 au premier écart)}';

    protected $description = 'Comptes des tables de production et du journal, en JSON, ou comparaison avec un manifeste';

    /** Tables du dump, dans l'ordre des clés étrangères, plus l'historique football-data. */
    public const TABLES = ['matches', 'odds_movements', 'advanced_data', 'predictions', 'prediction_log', 'pipeline_runs', 'historical_matches'];

    public function handle(): int
    {
        $current = self::build();

        $file = $this->option('compare');
        if ($file === null) {
            $this->line(json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $expected = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($expected)) {
            $this->error("Manifeste illisible : {$file}");

            return self::FAILURE;
        }

        $differences = [];
        foreach (['tables', 'journal', 'times'] as $section) {
            foreach ($expected[$section] ?? [] as $key => $value) {
                if (($current[$section][$key] ?? null) !== $value) {
                    $differences[] = sprintf('%s.%s : attendu %s, trouvé %s', $section, $key, json_encode($value), json_encode($current[$section][$key] ?? null));
                }
            }
        }

        $this->line("Manifeste du {$expected['generated_at']} ({$expected['host']}) comparé à cette base :");
        foreach ($current['journal'] as $key => $value) {
            $this->line("  journal.{$key} = {$value}");
        }
        if ($differences !== []) {
            foreach ($differences as $difference) {
                $this->error("  ÉCART {$difference}");
            }

            return self::FAILURE;
        }

        $this->info('  Identique : ' . count($expected['tables']) . ' tables et le journal.');

        return self::SUCCESS;
    }

    /**
     * @return array{generated_at: string, host: string, tables: array<string, array{rows: int, max_id: ?int}>, journal: array<string, int>, times: array<string, ?string>}
     */
    public static function build(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $max = DB::table($table)->max('id');
            $tables[$table] = ['rows' => DB::table($table)->count(), 'max_id' => $max === null ? null : (int) $max];
        }

        $settled = PredictionLogEntry::settled();

        return [
            'generated_at' => now('UTC')->toIso8601String(),
            'host' => gethostname() ?: '?',
            'tables' => $tables,
            'journal' => [
                'lines' => PredictionLogEntry::count(),
                'settled_lines' => (clone $settled)->count(),
                'settled_matches' => $settled->distinct()->count('match_id'),
                'voided_lines' => PredictionLogEntry::voided()->count(),
                'pending_lines' => PredictionLogEntry::pending()->count(),
                // Hors lignes reprogrammées, kickoff_at (TIMESTAMP) = match_date (DATETIME)
                'kickoff_mismatches' => DB::table('prediction_log')
                    ->join('matches', 'matches.id', '=', 'prediction_log.match_id')
                    ->where(fn ($q) => $q->whereNull('prediction_log.void_reason')->orWhere('prediction_log.void_reason', '!=', 'rescheduled'))
                    ->whereColumn('prediction_log.kickoff_at', '!=', 'matches.match_date')
                    ->count(),
            ],
            'times' => [
                'prediction_log.kickoff_at.min' => self::time(DB::table('prediction_log')->min('kickoff_at')),
                'prediction_log.kickoff_at.max' => self::time(DB::table('prediction_log')->max('kickoff_at')),
                'prediction_log.computed_at.max' => self::time(DB::table('prediction_log')->max('computed_at')),
                'prediction_log.settled_at.max' => self::time(DB::table('prediction_log')->max('settled_at')),
                'odds_movements.snapshot_at.max' => self::time(DB::table('odds_movements')->max('snapshot_at')),
                'pipeline_runs.started_at.max' => self::time(DB::table('pipeline_runs')->max('started_at')),
                'matches.match_date.max' => self::time(DB::table('matches')->max('match_date')),
            ],
        ];
    }

    private static function time(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
