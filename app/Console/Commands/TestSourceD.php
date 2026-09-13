<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Probability\PoissonModelService;
use App\Services\Probability\XGModelService;
use Illuminate\Console\Command;

class TestSourceD extends Command
{
    protected $signature = 'source-d:test
                            {--match-id= : Tester un match spécifique}
                            {--all : Tester tous les matchs API en DB}
                            {--compare : Comparer probabilités vs cotes bookmakers}';

    protected $description = 'Tester le modèle probabiliste Source D sur les matchs en base de données';

    public function handle(XGModelService $xgModel, PoissonModelService $poisson): int
    {
        if ($matchId = $this->option('match-id')) {
            return $this->testSingleMatch($xgModel, (int) $matchId);
        }

        if ($this->option('compare')) {
            return $this->compareWithMarket($xgModel);
        }

        return $this->testAllMatches($xgModel);
    }

    private function testSingleMatch(XGModelService $xgModel, int $matchId): int
    {
        $match = FootballMatch::with('advancedData')->find($matchId);

        if (!$match) {
            $this->error("Match #{$matchId} introuvable.");
            return self::FAILURE;
        }

        $this->info("Match : {$match->home_team} vs {$match->away_team}");
        $this->info("Competition : {$match->competition}");
        $this->info("Date : {$match->match_date}");
        $this->newLine();

        $result = $xgModel->predict($match);

        // Lambdas
        $this->info("Expected Goals estimés :");
        $this->table(['Équipe', 'λ (xG)'], [
            [$match->home_team, $result['lambdas']['home']],
            [$match->away_team, $result['lambdas']['away']],
            ['Total', round($result['lambdas']['home'] + $result['lambdas']['away'], 3)],
        ]);

        // Signaux détaillés
        $this->newLine();
        $this->info("Signaux utilisés :");
        foreach ($result['signals'] as $key => $signal) {
            if (!$signal) {
                $this->line("  {$key}: N/A");
                continue;
            }
            if (isset($signal['home'], $signal['away'])) {
                $this->line("  {$key}: home={$signal['home']} away={$signal['away']}");
            } else {
                $this->line("  {$key}: " . json_encode($signal));
            }
        }

        // Prédictions par marché
        $this->newLine();
        $analysis = $result['analysis'];

        $this->info("Probabilités 1X2 :");
        $this->table(['1 (Home)', 'X (Draw)', '2 (Away)'], [
            ["{$analysis['1x2']['home']}%", "{$analysis['1x2']['draw']}%", "{$analysis['1x2']['away']}%"],
        ]);

        $this->info("Over/Under 2.5 :");
        $this->table(['Over', 'Under'], [
            ["{$analysis['overUnder25']['over']}%", "{$analysis['overUnder25']['under']}%"],
        ]);

        $this->info("BTTS :");
        $this->table(['Yes', 'No'], [
            ["{$analysis['btts']['yes']}%", "{$analysis['btts']['no']}%"],
        ]);

        $this->info("Double Chance :");
        $this->table(['1X', '12', 'X2'], [
            ["{$analysis['doubleChance']['1X']}%", "{$analysis['doubleChance']['12']}%", "{$analysis['doubleChance']['X2']}%"],
        ]);

        $this->info("Top 5 scores les plus probables :");
        $rows = [];
        foreach ($analysis['topScores'] as $s) {
            $rows[] = [$s['score'], "{$s['probability']}%"];
        }
        $this->table(['Score', 'Probabilité'], $rows);

        // Picks Source D
        $this->newLine();
        $this->info("Picks Source D :");
        $pickRows = [];
        foreach ($result['predictions'] as $market => $pred) {
            $pickRows[] = [$market, $pred['pick'], "{$pred['confidence']}%"];
        }
        $this->table(['Marché', 'Pick', 'Confiance'], $pickRows);

        return self::SUCCESS;
    }

    private function testAllMatches(XGModelService $xgModel): int
    {
        $matches = FootballMatch::where('data_source', 'api')
            ->with('advancedData')
            ->orderBy('match_date')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn('Aucun match API en base de données.');
            return self::SUCCESS;
        }

        $this->info("Source D — Prédictions pour {$matches->count()} matchs");
        $this->newLine();

        $rows = [];
        foreach ($matches as $match) {
            try {
                $result = $xgModel->predict($match);
                $p = $result['analysis']['1x2'];
                $ou = $result['analysis']['overUnder25'];
                $btts = $result['analysis']['btts'];
                $lambdas = $result['lambdas'];

                $rows[] = [
                    substr($match->home_team, 0, 12),
                    substr($match->away_team, 0, 12),
                    round($lambdas['home'], 2),
                    round($lambdas['away'], 2),
                    "{$p['home']}%",
                    "{$p['draw']}%",
                    "{$p['away']}%",
                    $ou['over'] > $ou['under'] ? "O {$ou['over']}%" : "U {$ou['under']}%",
                    $btts['yes'] > $btts['no'] ? "Y {$btts['yes']}%" : "N {$btts['no']}%",
                ];
            } catch (\Exception $e) {
                $rows[] = [
                    substr($match->home_team, 0, 12),
                    substr($match->away_team, 0, 12),
                    '-', '-', '-', '-', '-', '-', '-',
                ];
            }
        }

        $this->table(
            ['Home', 'Away', 'λH', 'λA', '1', 'X', '2', 'O/U 2.5', 'BTTS'],
            $rows
        );

        return self::SUCCESS;
    }

    private function compareWithMarket(XGModelService $xgModel): int
    {
        $matches = FootballMatch::where('data_source', 'api')
            ->where('odds_home', '>', 0)
            ->with('advancedData')
            ->orderBy('match_date')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn('Aucun match avec cotes en base.');
            return self::SUCCESS;
        }

        $this->info("Comparaison Source D vs Marché — {$matches->count()} matchs");
        $this->newLine();

        $rows = [];
        $totalEdge1x2 = 0;
        $totalEdgeOU = 0;
        $count = 0;

        foreach ($matches as $match) {
            try {
                $result = $xgModel->predict($match);
                $p = $result['analysis']['1x2'];
                $ou = $result['analysis']['overUnder25'];

                // Probabilités implicites des cotes (sans marge)
                $rawH = 1 / (float) $match->odds_home;
                $rawD = 1 / (float) $match->odds_draw;
                $rawA = 1 / (float) $match->odds_away;
                $overround = $rawH + $rawD + $rawA;
                $mktHome = round(($rawH / $overround) * 100, 1);
                $mktDraw = round(($rawD / $overround) * 100, 1);
                $mktAway = round(($rawA / $overround) * 100, 1);

                // Écart modèle vs marché
                $diffHome = round($p['home'] - $mktHome, 1);
                $diffDraw = round($p['draw'] - $mktDraw, 1);
                $diffAway = round($p['away'] - $mktAway, 1);

                // Over/Under implicite
                $mktOver = null;
                $diffOU = '-';
                if ((float) $match->odds_over_2_5 > 0 && (float) $match->odds_under_2_5 > 0) {
                    $rawO = 1 / (float) $match->odds_over_2_5;
                    $rawU = 1 / (float) $match->odds_under_2_5;
                    $ouRound = $rawO + $rawU;
                    $mktOver = round(($rawO / $ouRound) * 100, 1);
                    $diffOU = round($ou['over'] - $mktOver, 1);
                    $totalEdgeOU += abs($diffOU);
                }

                $totalEdge1x2 += abs($diffHome) + abs($diffDraw) + abs($diffAway);
                $count++;

                $formatDiff = fn($v) => $v > 0 ? "+{$v}" : (string) $v;

                $rows[] = [
                    substr($match->home_team, 0, 10) . ' v ' . substr($match->away_team, 0, 10),
                    "{$p['home']}%",
                    "{$mktHome}%",
                    $formatDiff($diffHome),
                    "{$p['draw']}%",
                    "{$mktDraw}%",
                    $formatDiff($diffDraw),
                    $diffOU !== '-' ? $formatDiff($diffOU) : '-',
                ];

            } catch (\Exception $e) {
                continue;
            }
        }

        $this->table(
            ['Match', 'D:1', 'M:1', 'Δ1', 'D:X', 'M:X', 'ΔX', 'ΔOU'],
            $rows
        );

        if ($count > 0) {
            $avgEdge = round($totalEdge1x2 / $count / 3, 1);
            $avgEdgeOU = $totalEdgeOU > 0 ? round($totalEdgeOU / $count, 1) : '-';
            $this->newLine();
            $this->info("Écart moyen 1X2 par issue : {$avgEdge}% | Écart moyen O/U : {$avgEdgeOU}%");
            $this->line("D = Source D (modèle) | M = Marché (cotes normalisées) | Δ = écart");

            if ($avgEdge < 5) {
                $this->info("Le modèle est bien calibré par rapport au marché (écart < 5%).");
            } elseif ($avgEdge < 10) {
                $this->warn("Écart modéré — le modèle diverge légèrement du consensus marché.");
            } else {
                $this->error("Écart important — vérifier les pondérations des signaux.");
            }
        }

        return self::SUCCESS;
    }
}
