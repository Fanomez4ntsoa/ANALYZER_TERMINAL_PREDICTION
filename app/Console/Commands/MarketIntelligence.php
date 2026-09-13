<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Services\Market\CLVTrackerService;
use Illuminate\Console\Command;

class MarketIntelligence extends Command
{
    protected $signature = 'market:track
                            {action : snapshot|close|clv|movements|summary}
                            {--date= : Date cible (YYYY-MM-DD, défaut: aujourd\'hui)}
                            {--match-id= : Match spécifique}';

    protected $description = 'Intelligence de marché — snapshots de cotes, CLV, mouvements, sharp money';

    public function handle(CLVTrackerService $clvTracker): int
    {
        return match ($this->argument('action')) {
            'snapshot' => $this->snapshot($clvTracker),
            'close' => $this->close($clvTracker),
            'clv' => $this->showCLV($clvTracker),
            'movements' => $this->showMovements(),
            'summary' => $this->showSummary($clvTracker),
            default => $this->error("Action inconnue. Utilisez: snapshot|close|clv|movements|summary") ?? self::FAILURE,
        };
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function snapshot(CLVTrackerService $clvTracker): int
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $this->info("Snapshot des cotes pour le {$date}...");

        $count = $clvTracker->snapshotOdds($date);
        $this->info("{$count} snapshot(s) créé(s).");

        // Afficher les alertes sharp money
        $alerts = OddsMovement::where('sharp_alert', true)
            ->where('snapshot_at', '>=', now()->subHours(4))
            ->with('match')
            ->get();

        if ($alerts->isNotEmpty()) {
            $this->newLine();
            $this->warn("ALERTES SHARP MONEY :");
            foreach ($alerts as $alert) {
                $this->line(sprintf(
                    "  [score:%d] %s — Home:%.1f%% Draw:%.1f%% Away:%.1f%%",
                    $alert->sharp_score,
                    $alert->match->full_name,
                    $alert->move_home_pct ?? 0,
                    $alert->move_draw_pct ?? 0,
                    $alert->move_away_pct ?? 0,
                ));
            }
        }

        return self::SUCCESS;
    }

    private function close(CLVTrackerService $clvTracker): int
    {
        $this->info("Clôture des cotes pour les matchs commencés...");
        $count = $clvTracker->markClosingOdds();
        $this->info("{$count} match(s) clôturé(s).");

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
            $this->line("  (attendre que les matchs commencent)");
            $this->line("  php artisan market:track close");
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
                    $m->sharp_alert ? "!! {$m->sharp_score}" : '-',
                ];
            }

            $this->table(['Heure', '1', 'Δ1', 'X', 'ΔX', '2', 'Δ2', 'Sharp'], $rows);
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

        // Alertes sharp
        $sharpAlerts = OddsMovement::whereHas('match', fn($q) => $q->whereDate('match_date', $date))
            ->where('sharp_alert', true)
            ->count();
        $this->line("Alertes sharp money : {$sharpAlerts}");

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
