<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Services\Market\CLVTrackerService;
use Illuminate\Console\Command;

class MarketIntelligence extends Command
{
    protected $signature = 'market:track
                            {action : snapshot|closing|close|clv|movements|summary}
                            {--date= : Date cible (YYYY-MM-DD, défaut: aujourd\'hui)}
                            {--match-id= : Match spécifique}';

    protected $description = 'Relevés de cotes Pinnacle, clôtures, écart de clôture et variations brutes';

    public function handle(CLVTrackerService $clvTracker): int
    {
        return match ($this->argument('action')) {
            'snapshot' => $this->snapshot($clvTracker),
            'closing' => $this->closing($clvTracker),
            'close' => $this->close($clvTracker),
            'clv' => $this->showCLV($clvTracker),
            'movements' => $this->showMovements(),
            'summary' => $this->showSummary($clvTracker),
            default => $this->error("Action inconnue. Utilisez: snapshot|closing|close|clv|movements|summary") ?? self::FAILURE,
        };
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Relevé de prédiction. Échec (code 1) si un match attendu n'a pas de relevé :
     * pipeline:daily enregistre alors un passage incomplet, visible dans l'interface.
     */
    private function snapshot(CLVTrackerService $clvTracker): int
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');
        $this->info("Relevé des cotes pour le {$date}...");

        return $this->report($clvTracker->snapshotOdds($date), 'relevé(s) de prédiction');
    }

    /** Relevé de clôture, même règle d'échec. */
    private function closing(CLVTrackerService $clvTracker): int
    {
        return $this->report($clvTracker->snapshotClosingOdds(), 'relevé(s) de clôture');
    }

    private function report(array $report, string $label): int
    {
        $this->info("{$report['stored']} {$label} sur {$report['expected']} attendu(s).");

        foreach ($report['missing'] as $m) {
            $detail = match ($m['reason']) {
                CLVTrackerService::MISSING_EVENT_NOT_FOUND => "événement introuvable ({$m['events_in_response']} événements dans la réponse)",
                CLVTrackerService::MISSING_BOOKMAKER_ABSENT => "événement trouvé, bookmaker absent ({$m['bookmakers_present']} bookmakers présents)",
                CLVTrackerService::MISSING_STALE_QUOTE => 'cote trop ancienne (' . ($m['quote_age_minutes'] ?? '?') . " min, maximum {$m['max_quote_age_minutes']})",
                CLVTrackerService::MISSING_NO_RESPONSE => 'aucune réponse de The Odds API',
                CLVTrackerService::MISSING_QUOTA => 'quota insuffisant',
                default => $m['reason'],
            };
            $this->warn("  {$m['match']} : {$detail}");
        }

        return $report['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Report de la clôture. Échec si un match commencé n'a pas de relevé de clôture fiable. */
    private function close(CLVTrackerService $clvTracker): int
    {
        $this->info("Clôture des cotes pour les matchs commencés...");
        $result = $clvTracker->markClosingOdds();
        $this->info("{$result['closed']} match(s) clôturé(s).");

        if ($result['missing'] !== []) {
            $this->warn('Sans clôture fiable : matchs #' . implode(', #', $result['missing']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showCLV(CLVTrackerService $clvTracker): int
    {
        if ($matchId = $this->option('match-id')) {
            return $this->showCLVForMatch($clvTracker, (int) $matchId);
        }

        $summary = $clvTracker->getSummary();

        if ($summary['total_matches'] === 0) {
            $this->warn("Aucun match avec données CLV. Lancez d'abord :");
            $this->line("  php artisan market:track snapshot");
            $this->line("  (clôture automatique par le planificateur : market:track closing puis close)");
            return self::SUCCESS;
        }

        $this->info("CLV Summary — {$summary['total_matches']} matchs");
        $this->newLine();

        $this->table(['Métrique', 'Valeur'], [
            ['CLV moyen', $this->formatCLV($summary['avg_clv'])],
            ['CLV positif (%)', "{$summary['clv_positive_pct']}%"],
            ['Matchs CLV > 0', $summary['clv_positive']],
            ['Matchs CLV < 0', $summary['clv_negative']],
            ['CLV moyen Home', $this->formatCLV($summary['avg_clv_home'])],
            ['CLV moyen Draw', $this->formatCLV($summary['avg_clv_draw'])],
            ['CLV moyen Away', $this->formatCLV($summary['avg_clv_away'])],
        ]);

        if (!empty($summary['details'])) {
            $this->newLine();
            $rows = [];
            foreach ($summary['details'] as $d) {
                $rows[] = [
                    $d['match'],
                    $d['date'],
                    $this->formatCLV($d['clv_home']),
                    $this->formatCLV($d['clv_draw']),
                    $this->formatCLV($d['clv_away']),
                    $d['clv_over'] !== null ? $this->formatCLV($d['clv_over']) : '-',
                    $this->formatCLV($d['clv_avg']),
                ];
            }
            $this->table(['Match', 'Date', 'CLV 1', 'CLV X', 'CLV 2', 'CLV O', 'Moy'], $rows);
        }

        $this->newLine();
        if ($summary['avg_clv'] > 0) {
            $this->info("Tu bats le marché (CLV moyen positif).");
        } elseif ($summary['avg_clv'] > -1) {
            $this->warn("CLV proche de 0 — neutre.");
        } else {
            $this->error("CLV négatif — le marché est contre toi.");
        }

        return self::SUCCESS;
    }

    private function showCLVForMatch(CLVTrackerService $clvTracker, int $matchId): int
    {
        $match = FootballMatch::find($matchId);
        if (!$match) {
            $this->error("Match #{$matchId} introuvable.");
            return self::FAILURE;
        }

        $this->info("{$match->full_name} ({$match->competition})");

        $clv = $clvTracker->calculateCLV($match);

        if (!$clv) {
            $this->warn("Pas de données CLV pour ce match.");
            $this->line("  Prédiction: " . ($match->odds_at_pred_home ? 'Oui' : 'Non'));
            $this->line("  Clôture: " . ($match->odds_closing_home ? 'Oui' : 'Non'));
            return self::SUCCESS;
        }

        $this->table(['Marché', 'Cote prédiction', 'Cote clôture', 'CLV'], [
            ['Home', $match->odds_at_pred_home, $match->odds_closing_home, $this->formatCLV($clv['home'])],
            ['Draw', $match->odds_at_pred_draw, $match->odds_closing_draw, $this->formatCLV($clv['draw'])],
            ['Away', $match->odds_at_pred_away, $match->odds_closing_away, $this->formatCLV($clv['away'])],
            ['Over 2.5', $match->odds_at_pred_over ?? '-', $match->odds_closing_over ?? '-', isset($clv['over']) ? $this->formatCLV($clv['over']) : '-'],
        ]);

        $this->info("CLV moyen : " . $this->formatCLV($clv['average']));

        return self::SUCCESS;
    }

    private function showMovements(): int
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $movements = OddsMovement::whereHas('match', function ($q) use ($date) {
            $q->whereDate('match_date', $date);
        })
            ->with('match')
            ->orderBy('snapshot_at', 'desc')
            ->get();

        if ($movements->isEmpty()) {
            $this->warn("Aucun mouvement enregistré pour le {$date}.");
            return self::SUCCESS;
        }

        $this->info("Mouvements de cotes — {$date}");
        $this->newLine();

        // Grouper par match
        $grouped = $movements->groupBy('match_id');

        foreach ($grouped as $matchId => $matchMovements) {
            $match = $matchMovements->first()->match;
            $this->info("{$match->full_name} ({$match->competition}) — {$matchMovements->count()} snapshot(s)");

            $rows = [];
            foreach ($matchMovements->sortBy('snapshot_at') as $m) {
                $rows[] = [
                    $m->snapshot_at->format('H:i'),
                    $m->odds_home,
                    $m->move_home_pct !== null ? $this->formatPct($m->move_home_pct) : '-',
                    $m->odds_draw,
                    $m->move_draw_pct !== null ? $this->formatPct($m->move_draw_pct) : '-',
                    $m->odds_away,
                    $m->move_away_pct !== null ? $this->formatPct($m->move_away_pct) : '-',
                ];
            }

            $this->table(['Heure', '1', 'Δ1', 'X', 'ΔX', '2', 'Δ2'], $rows);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function showSummary(CLVTrackerService $clvTracker): int
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $this->info("Intelligence de marché — Résumé {$date}");
        $this->newLine();

        // Matchs du jour
        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $date)
            ->get();

        $this->line("Matchs du jour : {$matches->count()}");

        // Snapshots
        $snapshots = OddsMovement::whereHas('match', fn($q) => $q->whereDate('match_date', $date))->count();
        $this->line("Snapshots enregistrés : {$snapshots}");

        // Matchs avec prédiction
        $predicted = $matches->whereNotNull('odds_at_pred_home')->count();
        $this->line("Matchs avec snapshot prédiction : {$predicted}");

        // Matchs clôturés
        $closed = $matches->whereNotNull('odds_closing_home')->count();
        $this->line("Matchs clôturés : {$closed}");

        // Quota Odds API
        $this->newLine();
        $quota = app(\App\Services\Api\OddsApiService::class)->getMonthlyUsage();
        $this->table(['Quota Odds API', 'Valeur'], [
            ['Utilisé', $quota['used']],
            ['Restant', $quota['remaining']],
            ['Limite', $quota['limit']],
        ]);

        return self::SUCCESS;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function formatCLV(?float $value): string
    {
        if ($value === null) return '-';
        $prefix = $value >= 0 ? '+' : '';
        return "{$prefix}{$value}%";
    }

    private function formatPct(?float $value): string
    {
        if ($value === null) return '-';
        $prefix = $value >= 0 ? '+' : '';
        return "{$prefix}" . round($value, 1) . '%';
    }
}
