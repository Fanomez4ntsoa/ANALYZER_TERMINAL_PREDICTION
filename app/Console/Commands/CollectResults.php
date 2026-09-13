<?php

namespace App\Console\Commands;

use App\Models\AIAnalysis;
use App\Models\FootballMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CollectResults extends Command
{
    protected $signature = 'results:collect
                            {--date= : Date cible (YYYY-MM-DD, defaut: aujourd\'hui)}';

    protected $description = 'Collecter les resultats reels des matchs analyses par IA et calculer Under/Over';

    public function handle(): int
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $matches = FootballMatch::with('advancedData')
            ->whereDate('match_date', $date)
            ->whereHas('aiAnalysis')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn("Aucun match avec analyse IA pour le {$date}");
            return self::SUCCESS;
        }

        // Vérification de sécurité : si des matchs sont passés mais pas marqués completed
        // → fetcher l'API et mettre à jour les scores avant de collecter
        $needsRefresh = $matches->filter(fn($m) =>
            !$m->completed && $m->match_date < now()
        );

        if ($needsRefresh->isNotEmpty()) {
            $this->info("Rattrapage : {$needsRefresh->count()} matchs passes sans score → fetch API...");
            $this->refreshScoresFromApi($date);

            // Recharger les matchs après update
            $matches = FootballMatch::with('advancedData')
                ->whereDate('match_date', $date)
                ->whereHas('aiAnalysis')
                ->get();
        }

        $aiAnalyses = AIAnalysis::whereIn('match_id', $matches->pluck('id'))->get()->keyBy('match_id');

        $matchesData = [];
        $stats = ['gagne' => 0, 'perdu' => 0, 'en_attente' => 0];
        $betGagne = 0;
        $betPerdu = 0;
        $leanGagne = 0;
        $leanPerdu = 0;

        foreach ($matches as $match) {
            $ai = $aiAnalyses->get($match->id);
            if (!$ai) continue;

            $finalDecision = $ai->final_decision ?? [];
            $decisionUnder = $finalDecision['decision_under'] ?? 'NO BET';
            $underMargin = $finalDecision['under_margin'] ?? 0;

            // Calcul du resultat Under 2.5
            $scoreFinal = null;
            $underResult = 'EN ATTENTE';

            if ($match->completed && $match->score_home !== null && $match->score_away !== null) {
                $scoreFinal = "{$match->score_home}-{$match->score_away}";
                $totalGoals = (int) $match->score_home + (int) $match->score_away;
                $underResult = $totalGoals <= 2 ? 'GAGNE' : 'PERDU';
            }

            $stats[strtolower(str_replace(' ', '_', $underResult))]++;

            // Suivi BET vs LEAN performance
            if ($decisionUnder === 'BET') {
                if ($underResult === 'GAGNE') $betGagne++;
                elseif ($underResult === 'PERDU') $betPerdu++;
            } elseif ($decisionUnder === 'LEAN') {
                if ($underResult === 'GAGNE') $leanGagne++;
                elseif ($underResult === 'PERDU') $leanPerdu++;
            }

            $matchesData[] = [
                'match_id' => $match->id,
                'teams' => "{$match->home_team} vs {$match->away_team}",
                'league' => $match->competition,
                'match_date' => $match->match_date->toIso8601String(),
                'decision_under' => $decisionUnder,
                'under_margin' => (float) $underMargin,
                'score_final' => $scoreFinal,
                'under_result' => $underResult,
            ];
        }

        // Win rate par decision
        $betTotal = $betGagne + $betPerdu;
        $leanTotal = $leanGagne + $leanPerdu;

        $report = [
            'date' => $date,
            'total_matches' => count($matchesData),
            'summary' => [
                'gagne' => $stats['gagne'],
                'perdu' => $stats['perdu'],
                'en_attente' => $stats['en_attente'],
            ],
            'performance' => [
                'BET' => [
                    'total' => $betTotal,
                    'gagne' => $betGagne,
                    'perdu' => $betPerdu,
                    'win_rate' => $betTotal > 0 ? round(($betGagne / $betTotal) * 100, 1) : null,
                ],
                'LEAN' => [
                    'total' => $leanTotal,
                    'gagne' => $leanGagne,
                    'perdu' => $leanPerdu,
                    'win_rate' => $leanTotal > 0 ? round(($leanGagne / $leanTotal) * 100, 1) : null,
                ],
            ],
            'matches' => $matchesData,
        ];

        $path = "reports/ai_results_{$date}.json";
        Storage::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Affichage terminal
        $this->info("Resultats collectes pour le {$date}");
        $this->table(['Statut Under 2.5', 'Nombre'], [
            ['GAGNE', $stats['gagne']],
            ['PERDU', $stats['perdu']],
            ['EN ATTENTE', $stats['en_attente']],
            ['Total', count($matchesData)],
        ]);

        if ($betTotal > 0 || $leanTotal > 0) {
            $this->newLine();
            $this->info('Performance par decision IA :');
            $rows = [];
            if ($betTotal > 0) {
                $wr = round(($betGagne / $betTotal) * 100, 1);
                $rows[] = ['BET', "{$betGagne}/{$betTotal}", "{$wr}%"];
            }
            if ($leanTotal > 0) {
                $wr = round(($leanGagne / $leanTotal) * 100, 1);
                $rows[] = ['LEAN', "{$leanGagne}/{$leanTotal}", "{$wr}%"];
            }
            $this->table(['Decision', 'Wins/Total', 'Win Rate'], $rows);
        }

        $this->info("Rapport JSON : storage/app/{$path}");

        return self::SUCCESS;
    }

    /**
     * Récupère les fixtures de la date depuis l'API et met à jour les scores
     * pour tous les matchs FT/AET/PEN trouvés en DB.
     */
    private function refreshScoresFromApi(string $date): void
    {
        $api = app(\App\Services\Api\ApiFootballService::class);
        $enricher = app(\App\Services\DataPipeline\MatchEnricherService::class);
        $trackedLeagues = config('api-football.leagues');

        $fixtures = $api->getFixturesByDate($date);
        if (!$fixtures) {
            $this->warn('  API n\'a retourne aucune fixture');
            return;
        }

        $updated = 0;
        foreach ($fixtures as $fixture) {
            $leagueId = $fixture['league']['id'] ?? 0;
            if (!in_array($leagueId, $trackedLeagues)) continue;

            $status = $fixture['fixture']['status']['short'] ?? '';
            if (!in_array($status, ['FT', 'AET', 'PEN'])) continue;

            $enricher->upsertFromApiFootball($fixture);
            $updated++;
        }

        $this->info("  {$updated} matchs mis a jour avec scores FT");
    }
}
