<?php

namespace App\Services\Backtesting;

use App\Models\BacktestPrediction;
use App\Models\BacktestRun;
use App\Models\FootballMatch;
use App\Services\Api\ApiFootballService;
use App\Services\Probability\PoissonModelService;
use App\Services\Probability\XGModelService;
use Illuminate\Support\Facades\Log;

class BacktestEngine
{
    private ApiFootballService $apiFootball;
    private PoissonModelService $poisson;

    public function __construct(ApiFootballService $apiFootball, PoissonModelService $poisson)
    {
        $this->apiFootball = $apiFootball;
        $this->poisson = $poisson;
    }

    /**
     * Lancer un backtest complet.
     */
    public function run(array $config): BacktestRun
    {
        $run = BacktestRun::create([
            'config' => $config,
            'date_from' => $config['date_from'],
            'date_to' => $config['date_to'],
            'staking_strategy' => $config['staking_strategy'] ?? 'flat',
            'bankroll' => $config['bankroll'] ?? 1000,
            'unit_stake' => $config['unit_stake'] ?? 10,
            'status' => 'running',
        ]);

        try {
            $fixtures = $this->fetchFinishedFixtures($config);

            if (empty($fixtures)) {
                $run->update(['status' => 'completed', 'total_matches' => 0]);
                return $run;
            }

            $predictions = $this->simulatePredictions($run, $fixtures, $config);
            $this->calculateMetrics($run, $predictions);

            $run->update(['status' => 'completed']);

            Log::info("Backtest #{$run->id} termine", [
                'matches' => $run->total_matches,
                'predictions' => $run->total_predictions,
                'win_rate' => $run->win_rate,
                'roi' => $run->roi,
            ]);

        } catch (\Exception $e) {
            $run->update(['status' => 'failed']);
            Log::error("Backtest #{$run->id} echoue", ['error' => $e->getMessage()]);
            throw $e;
        }

        return $run;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RÉCUPÉRATION DES MATCHS TERMINÉS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Approche hybride :
     *   1. Matchs déjà en DB (importés par le pipeline quotidien) — historique complet
     *   2. Complément API pour J-0 et J-1 (seules dates accessibles plan gratuit)
     *   3. Mise à jour des scores via API pour les matchs en DB non complétés
     */
    private function fetchFinishedFixtures(array $config): array
    {
        $leagueIds = $config['leagues'] ?? [61];
        $dateFrom = $config['date_from'];
        $dateTo = $config['date_to'];
        $allFixtures = [];

        // ── Source 1 : matchs en DB avec scores connus ──
        $dbMatches = FootballMatch::where('data_source', 'api')
            ->whereIn('league_id', $leagueIds)
            ->where('completed', true)
            ->whereNotNull('score_home')
            ->whereNotNull('score_away')
            ->whereBetween('match_date', [$dateFrom, $dateTo . ' 23:59:59'])
            ->with('advancedData')
            ->get();

        foreach ($dbMatches as $m) {
            $allFixtures[] = $this->dbMatchToFixture($m);
        }

        Log::info("Backtest: {$dbMatches->count()} matchs termines en DB");

        // ── Source 2 : matchs en DB passés mais pas encore marqués completed ──
        $pendingMatches = FootballMatch::where('data_source', 'api')
            ->whereIn('league_id', $leagueIds)
            ->where('completed', false)
            ->where('match_date', '<', now())
            ->whereBetween('match_date', [$dateFrom, $dateTo . ' 23:59:59'])
            ->get();

        if ($pendingMatches->isNotEmpty()) {
            Log::info("Backtest: {$pendingMatches->count()} matchs en DB a verifier via API");

            // Tenter de récupérer les scores via API (J-0/J-1 seulement)
            foreach ($pendingMatches as $m) {
                $updated = $this->tryUpdateScore($m);
                if ($updated) {
                    $allFixtures[] = $this->dbMatchToFixture($m->fresh());
                }
            }
        }

        // ── Source 3 : compléter avec l'API pour les jours récents non en DB ──
        $recentDays = [now()->format('Y-m-d'), now()->subDay()->format('Y-m-d')];
        $dbDates = collect($allFixtures)->pluck('fixture.date')->map(fn($d) => substr($d, 0, 10))->unique();

        foreach ($recentDays as $day) {
            if ($day < $dateFrom || $day > $dateTo) continue;

            $dayFixtures = $this->apiFootball->getFixturesByDate($day);
            if (!$dayFixtures) continue;

            foreach ($dayFixtures as $f) {
                $fLeagueId = $f['league']['id'] ?? 0;
                $fId = $f['fixture']['id'] ?? 0;
                $status = $f['fixture']['status']['short'] ?? '';

                if (!in_array($fLeagueId, $leagueIds)) continue;
                if (!in_array($status, ['FT', 'AET', 'PEN'])) continue;

                // Éviter les doublons avec la DB
                $alreadyHave = collect($allFixtures)->contains(fn($x) => ($x['fixture']['id'] ?? 0) === $fId);
                if (!$alreadyHave) {
                    $allFixtures[] = $f;
                }
            }
        }

        // Trier par date
        usort($allFixtures, fn($a, $b) => ($a['fixture']['date'] ?? '') <=> ($b['fixture']['date'] ?? ''));

        $count = count($allFixtures);
        Log::info("Backtest: {$count} matchs termines au total", [
            'leagues' => $leagueIds,
            'period' => "{$dateFrom} -> {$dateTo}",
            'from_db' => $dbMatches->count(),
            'from_api' => $count - $dbMatches->count(),
        ]);

        return $allFixtures;
    }

    /**
     * Convertir un FootballMatch DB en format fixture API-Football.
     */
    private function dbMatchToFixture(FootballMatch $match): array
    {
        return [
            'fixture' => [
                'id' => $match->api_football_id ?? $match->id,
                'date' => $match->match_date->toIso8601String(),
                'status' => ['short' => 'FT'],
            ],
            'teams' => [
                'home' => ['name' => $match->home_team, 'id' => $match->home_team_id],
                'away' => ['name' => $match->away_team, 'id' => $match->away_team_id],
            ],
            'goals' => [
                'home' => $match->score_home,
                'away' => $match->score_away,
            ],
            'league' => [
                'id' => $match->league_id,
                'name' => $match->competition,
            ],
            '_source' => 'db',
            '_match_id' => $match->id,
            '_odds' => [
                'home' => (float) $match->odds_home,
                'draw' => (float) $match->odds_draw,
                'away' => (float) $match->odds_away,
            ],
            '_context' => $match->advancedData?->context_data,
        ];
    }

    /**
     * Tenter de mettre à jour le score d'un match via l'API.
     */
    private function tryUpdateScore(FootballMatch $match): bool
    {
        if (!$match->api_football_id) return false;

        $fixture = $this->apiFootball->getFixture($match->api_football_id);
        if (!$fixture) return false;

        $status = $fixture['fixture']['status']['short'] ?? '';
        if (!in_array($status, ['FT', 'AET', 'PEN'])) return false;

        $match->update([
            'score_home' => $fixture['goals']['home'] ?? null,
            'score_away' => $fixture['goals']['away'] ?? null,
            'completed' => true,
        ]);

        return true;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SIMULATION DES PRÉDICTIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function simulatePredictions(BacktestRun $run, array $fixtures, array $config): array
    {
        $markets = $config['markets'] ?? ['winner', 'overUnder', 'btts', 'doubleChance'];
        $minConfidence = $config['min_confidence'] ?? 0;
        $bankroll = (float) $run->bankroll;
        $unitStake = (float) $run->unit_stake;
        $strategy = $run->staking_strategy;
        $allPredictions = [];

        foreach ($fixtures as $fixture) {
            $homeTeam = $fixture['teams']['home']['name'] ?? '';
            $awayTeam = $fixture['teams']['away']['name'] ?? '';
            $scoreHome = $fixture['goals']['home'] ?? 0;
            $scoreAway = $fixture['goals']['away'] ?? 0;
            $league = $fixture['league']['name'] ?? '';
            $leagueId = $fixture['league']['id'] ?? 0;
            $matchDate = $fixture['fixture']['date'] ?? '';
            $fixtureId = $fixture['fixture']['id'] ?? 0;

            // Utiliser les cotes de la DB si disponibles (source hybride)
            $dbOdds = $fixture['_odds'] ?? null;
            $dbContext = $fixture['_context'] ?? null;

            // Estimer les λ depuis les meilleures données disponibles
            $lambdas = $this->estimateLambdas($dbOdds, $dbContext, $fixture);

            if (!$lambdas) {
                continue;
            }

            // Analyse Poisson
            $analysis = $this->poisson->fullAnalysis($lambdas['home'], $lambdas['away']);

            // Générer les prédictions par marché
            foreach ($markets as $market) {
                $pred = $this->makePrediction($market, $analysis);
                if (!$pred || $pred['confidence'] < $minConfidence) {
                    continue;
                }

                // Vérifier si la prédiction est correcte
                $won = $this->checkResult($market, $pred['pick'], $scoreHome, $scoreAway);

                // Calculer le profit
                $odds = $pred['odds'] ?? $this->estimateOddsFromProb($pred['probability']);
                $stake = $this->calculateStake($strategy, $bankroll, $unitStake, $pred, $odds);
                $profit = $won ? ($stake * $odds - $stake) : -$stake;
                $bankroll += $profit;

                $bp = BacktestPrediction::create([
                    'run_id' => $run->id,
                    'api_fixture_id' => $fixtureId,
                    'home_team' => $homeTeam,
                    'away_team' => $awayTeam,
                    'league' => $league,
                    'league_id' => $leagueId,
                    'match_date' => $matchDate,
                    'score_home' => $scoreHome,
                    'score_away' => $scoreAway,
                    'market' => $market,
                    'pick' => $pred['pick'],
                    'confidence' => $pred['confidence'],
                    'odds' => $odds,
                    'probability' => $pred['probability'],
                    'won' => $won,
                    'profit' => round($profit, 2),
                    'bankroll_after' => round($bankroll, 2),
                ]);

                $allPredictions[] = $bp;
            }
        }

        return $allPredictions;
    }

    /**
     * Estimer les λ depuis les meilleures données disponibles.
     *
     * Priorité :
     *   1. Reverse-Poisson depuis les cotes bookmakers (le plus fiable)
     *   2. Comparaison API-Football 7D (context_data)
     *   3. Moyenne de la ligue (fallback)
     */
    private function estimateLambdas(?array $dbOdds, ?array $dbContext, array $fixture): ?array
    {
        $homeLambda = null;
        $awayLambda = null;

        // Méthode 1 : reverse-Poisson depuis les cotes (identique à XGModelService)
        if ($dbOdds && ($dbOdds['home'] ?? 0) > 1 && ($dbOdds['draw'] ?? 0) > 1 && ($dbOdds['away'] ?? 0) > 1) {
            $rawH = 1 / $dbOdds['home'];
            $rawD = 1 / $dbOdds['draw'];
            $rawA = 1 / $dbOdds['away'];
            $overround = $rawH + $rawD + $rawA;
            $pH = $rawH / $overround;
            $pA = $rawA / $overround;

            // Grille de recherche des λ
            $bestH = 1.35;
            $bestA = 1.35;
            $bestErr = PHP_FLOAT_MAX;

            for ($h = 0.3; $h <= 3.5; $h += 0.1) {
                for ($a = 0.2; $a <= 3.0; $a += 0.1) {
                    $pred = $this->poisson->predict1X2($h, $a);
                    $err = abs($pred['home'] / 100 - $pH) + abs($pred['away'] / 100 - $pA);
                    if ($err < $bestErr) {
                        $bestErr = $err;
                        $bestH = $h;
                        $bestA = $a;
                    }
                }
            }

            $homeLambda = $bestH;
            $awayLambda = $bestA;
        }

        // Méthode 2 : depuis la comparaison API-Football
        if (!$homeLambda && $dbContext && isset($dbContext['comparison']['total'])) {
            $homeTotal = (float) str_replace('%', '', $dbContext['comparison']['total']['home'] ?? '50');
            $homeLambda = max(0.3, 1.35 * ($homeTotal / 50));
            $awayLambda = max(0.2, 1.35 * ((100 - $homeTotal) / 50));
        }

        // Méthode 3 : moyenne de la ligue
        if (!$homeLambda) {
            $leagueId = $fixture['league']['id'] ?? 0;
            $avg = match ($leagueId) {
                61 => 1.25, 39 => 1.40, 140 => 1.25, 135 => 1.30, 78 => 1.50, default => 1.35,
            };
            $homeLambda = $avg * 1.1;
            $awayLambda = $avg * 0.9;
        }

        return [
            'home' => round($homeLambda, 3),
            'away' => round($awayLambda, 3),
        ];
    }

    /**
     * Construire une prédiction pour un marché.
     */
    private function makePrediction(string $market, array $analysis): ?array
    {
        return match ($market) {
            'winner' => $this->predictWinner($analysis),
            'overUnder' => $this->predictOverUnder($analysis),
            'btts' => $this->predictBTTS($analysis),
            'doubleChance' => $this->predictDoubleChance($analysis),
            default => null,
        };
    }

    private function predictWinner(array $analysis): array
    {
        $p = $analysis['1x2'];
        $max = max($p['home'], $p['draw'], $p['away']);
        $pick = array_search($max, $p);
        $pick = match ($pick) { 'home' => '1', 'draw' => 'X', 'away' => '2' };

        return ['pick' => $pick, 'confidence' => (int) round($max), 'probability' => $max, 'odds' => null];
    }

    private function predictOverUnder(array $analysis): array
    {
        $ou = $analysis['overUnder25'];
        if ($ou['over'] >= $ou['under']) {
            return ['pick' => 'Over', 'confidence' => (int) round($ou['over']), 'probability' => $ou['over'], 'odds' => null];
        }
        return ['pick' => 'Under', 'confidence' => (int) round($ou['under']), 'probability' => $ou['under'], 'odds' => null];
    }

    private function predictBTTS(array $analysis): array
    {
        $b = $analysis['btts'];
        if ($b['yes'] >= $b['no']) {
            return ['pick' => 'Yes', 'confidence' => (int) round($b['yes']), 'probability' => $b['yes'], 'odds' => null];
        }
        return ['pick' => 'No', 'confidence' => (int) round($b['no']), 'probability' => $b['no'], 'odds' => null];
    }

    private function predictDoubleChance(array $analysis): array
    {
        $dc = $analysis['doubleChance'];
        $max = max($dc);
        $pick = array_search($max, $dc);
        return ['pick' => $pick, 'confidence' => (int) round($max), 'probability' => $max, 'odds' => null];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // VÉRIFICATION DES RÉSULTATS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function checkResult(string $market, string $pick, int $scoreH, int $scoreA): bool
    {
        return match ($market) {
            'winner' => match ($pick) {
                '1' => $scoreH > $scoreA,
                'X' => $scoreH === $scoreA,
                '2' => $scoreH < $scoreA,
                default => false,
            },
            'overUnder' => match ($pick) {
                'Over' => ($scoreH + $scoreA) > 2,
                'Under' => ($scoreH + $scoreA) < 3,
                default => false,
            },
            'btts' => match ($pick) {
                'Yes' => $scoreH >= 1 && $scoreA >= 1,
                'No' => $scoreH === 0 || $scoreA === 0,
                default => false,
            },
            'doubleChance' => match ($pick) {
                '1X' => $scoreH >= $scoreA,
                'X2' => $scoreH <= $scoreA,
                '12' => $scoreH !== $scoreA,
                default => false,
            },
            default => false,
        };
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // STAKING
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function calculateStake(string $strategy, float $bankroll, float $unitStake, array $pred, float $odds): float
    {
        return match ($strategy) {
            'kelly' => $this->kellyStake($bankroll, $pred['probability'] / 100, $odds),
            'proportional' => $unitStake * ($pred['confidence'] / 60),
            default => $unitStake, // flat
        };
    }

    private function kellyStake(float $bankroll, float $prob, float $odds): float
    {
        if ($odds <= 1 || $prob <= 0) return 0;
        $edge = ($prob * $odds) - 1;
        if ($edge <= 0) return 0;
        $kelly = ($edge / ($odds - 1)) * 0.25; // Quarter Kelly
        return round($bankroll * $kelly, 2);
    }

    private function estimateOddsFromProb(float $probability): float
    {
        if ($probability <= 0) return 10.0;
        return round(100 / $probability, 3);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CALCUL DES MÉTRIQUES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function calculateMetrics(BacktestRun $run, array $predictions): void
    {
        if (empty($predictions)) {
            $run->update([
                'total_matches' => 0,
                'total_predictions' => 0,
            ]);
            return;
        }

        $preds = collect($predictions);
        $totalMatches = $preds->unique('api_fixture_id')->count();
        $wins = $preds->where('won', true)->count();
        $losses = $preds->where('won', false)->count();
        $total = $preds->count();
        $winRate = $total > 0 ? round(($wins / $total) * 100, 2) : 0;

        $totalStaked = $preds->sum(fn($p) => $p->won ? ($p->profit / (($p->odds ?? 2) - 1)) : abs($p->profit));
        $totalProfit = $preds->sum('profit');
        $roi = $totalStaked > 0 ? round(($totalProfit / $totalStaked) * 100, 2) : 0;
        $yieldPct = $total > 0 ? round($totalProfit / ($run->unit_stake * $total) * 100, 2) : 0;

        // Max drawdown
        $maxDrawdown = 0;
        $peak = $run->bankroll;
        foreach ($preds->sortBy('id') as $p) {
            $peak = max($peak, (float) $p->bankroll_after);
            $dd = $peak - (float) $p->bankroll_after;
            $maxDrawdown = max($maxDrawdown, $dd);
        }

        // Brier score
        $brierSum = 0;
        foreach ($preds as $p) {
            $prob = (float) $p->probability / 100;
            $outcome = $p->won ? 1 : 0;
            $brierSum += pow($prob - $outcome, 2);
        }
        $brierScore = $total > 0 ? round($brierSum / $total, 4) : 0;

        $finalBankroll = $preds->sortByDesc('id')->first()->bankroll_after ?? $run->bankroll;

        // Segmentation
        $byLeague = $this->segmentBy($preds, 'league');
        $byMarket = $this->segmentBy($preds, 'market');
        $byConfidence = $this->segmentByRange($preds, 'confidence', [
            '50-59' => [50, 59], '60-69' => [60, 69], '70-79' => [70, 79], '80+' => [80, 100],
        ]);
        $byOddsRange = $this->segmentByRange($preds, 'odds', [
            '1.01-1.40' => [1.01, 1.40], '1.41-1.80' => [1.41, 1.80],
            '1.81-2.50' => [1.81, 2.50], '2.51+' => [2.51, 100],
        ]);

        // Calibration
        $calibration = $this->calculateCalibration($preds);

        // Courbe bankroll
        $bankrollCurve = $preds->sortBy('id')->values()->map(fn($p, $i) => [
            'index' => $i,
            'bankroll' => (float) $p->bankroll_after,
            'date' => $p->match_date->format('Y-m-d'),
        ])->values()->toArray();

        $run->update([
            'total_matches' => $totalMatches,
            'total_predictions' => $total,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $winRate,
            'roi' => $roi,
            'yield_pct' => $yieldPct,
            'max_drawdown' => round($maxDrawdown, 2),
            'brier_score' => $brierScore,
            'final_bankroll' => $finalBankroll,
            'by_league' => $byLeague,
            'by_market' => $byMarket,
            'by_confidence' => $byConfidence,
            'by_odds_range' => $byOddsRange,
            'calibration' => $calibration,
            'bankroll_curve' => $bankrollCurve,
        ]);
    }

    private function segmentBy($preds, string $field): array
    {
        return $preds->groupBy($field)->map(function ($group, $key) {
            $wins = $group->where('won', true)->count();
            $total = $group->count();
            return [
                'label' => $key,
                'total' => $total,
                'wins' => $wins,
                'losses' => $total - $wins,
                'win_rate' => $total > 0 ? round(($wins / $total) * 100, 1) : 0,
                'profit' => round($group->sum('profit'), 2),
            ];
        })->values()->toArray();
    }

    private function segmentByRange($preds, string $field, array $ranges): array
    {
        $result = [];
        foreach ($ranges as $label => [$min, $max]) {
            $group = $preds->filter(fn($p) => (float) $p->$field >= $min && (float) $p->$field <= $max);
            $wins = $group->where('won', true)->count();
            $total = $group->count();
            if ($total > 0) {
                $result[] = [
                    'label' => $label,
                    'total' => $total,
                    'wins' => $wins,
                    'win_rate' => round(($wins / $total) * 100, 1),
                    'profit' => round($group->sum('profit'), 2),
                ];
            }
        }
        return $result;
    }

    private function calculateCalibration($preds): array
    {
        $buckets = [];
        foreach ([30, 40, 50, 60, 70, 80, 90] as $bucket) {
            $group = $preds->filter(fn($p) => $p->confidence >= $bucket && $p->confidence < $bucket + 10);
            if ($group->count() >= 3) {
                $actualRate = $group->where('won', true)->count() / $group->count();
                $buckets[] = [
                    'predicted' => $bucket + 5, // milieu du bucket
                    'actual' => round($actualRate * 100, 1),
                    'count' => $group->count(),
                ];
            }
        }
        return $buckets;
    }
}
