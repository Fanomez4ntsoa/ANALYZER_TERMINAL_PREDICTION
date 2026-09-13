<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Services\Betting\CalculatorService;
use App\Services\Probability\XGModelService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AnalyzerService
{
    private CalculatorService $calculator;
    private ValueAnalyzer $valueAnalyzer;
    private XGModelService $xgModel;
    private array $markets;

    public function __construct(ValueAnalyzer $valueAnalyzer, XGModelService $xgModel)
    {
        $this->calculator = new CalculatorService();
        $this->valueAnalyzer = $valueAnalyzer;
        $this->xgModel = $xgModel;
        $this->markets = config('analyzer.markets');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FONCTION PRINCIPALE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function analyze(FootballMatch $match): array
    {
        // Générer les prédictions automatiques
        $sourceD = $this->generateSourceD($match);
        $sourceE = $this->generateSourceE($match);

        // Sources A/B/C optionnelles (saisie manuelle, si présentes en DB)
        $manualSources = $match->sources;
        $manualPredictions = $this->extractManualPredictions($manualSources);

        if (empty($sourceD) && empty($sourceE) && empty($manualPredictions)) {
            throw new \Exception('Aucune source disponible pour ce match');
        }

        $context = $this->detectMatchContext($match);

        // Analyser chaque marché
        $recommendations = collect();
        $allSources = ['D' => $sourceD, 'E' => $sourceE, 'manual' => $manualPredictions];

        foreach ($this->markets as $market) {
            $recommendation = $this->analyzeMarket($match, $market, $context, $allSources);
            if ($recommendation) {
                $recommendations->push($recommendation);
            }
        }

        $recommendations = $this->applyValueAnalysis($recommendations, $match);
        $recommendations = $recommendations->sortByDesc('score')->values();
        $recommendations = $this->assignLevels($recommendations);

        $globalConfidence = $this->calculateGlobalConfidence($recommendations);

        return [
            'recommendations' => $recommendations->toArray(),
            'globalConfidence' => $globalConfidence,
            'context' => $context,
            'sourceD' => $sourceD,
            'sourceE' => $sourceE,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SOURCES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function generateSourceD(FootballMatch $match): ?array
    {
        if (!$match->advancedData && (float) $match->odds_home <= 0) {
            return null;
        }

        try {
            $result = $this->xgModel->predict($match);
            return $result['predictions'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Source D: erreur', ['match' => $match->full_name, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function generateSourceE(FootballMatch $match): ?array
    {
        $contextData = $match->advancedData?->context_data ?? null;
        if (!$contextData) return null;

        $comparison = $contextData['comparison'] ?? null;
        $advice = $contextData['apiFootballAdvice'] ?? '';
        if (!$comparison) return null;

        $predictions = [];

        // 1X2
        $homeTotal = (float) str_replace('%', '', $comparison['total']['home'] ?? '50');
        $awayTotal = 100 - $homeTotal;
        $gap = abs($homeTotal - $awayTotal);
        $drawProb = max(15, 35 - $gap);
        $remaining = 100 - $drawProb;
        $homeProb = round($remaining * ($homeTotal / 100), 1);
        $awayProb = round($remaining * ($awayTotal / 100), 1);

        if ($homeProb >= $awayProb && $homeProb >= $drawProb) {
            $predictions['winner'] = ['pick' => '1', 'confidence' => (int) round($homeProb)];
        } elseif ($awayProb >= $homeProb && $awayProb >= $drawProb) {
            $predictions['winner'] = ['pick' => '2', 'confidence' => (int) round($awayProb)];
        } else {
            $predictions['winner'] = ['pick' => 'X', 'confidence' => (int) round($drawProb)];
        }

        // Double Chance
        $dc1X = round($homeProb + $drawProb, 1);
        $dcX2 = round($awayProb + $drawProb, 1);
        $dc12 = round($homeProb + $awayProb, 1);
        $maxDC = max($dc1X, $dcX2, $dc12);
        $pickDC = $maxDC === $dc1X ? '1X' : ($maxDC === $dcX2 ? 'X2' : '12');
        $predictions['doubleChance'] = ['pick' => $pickDC, 'confidence' => (int) round($maxDC)];

        // Over/Under depuis le conseil
        $adviceLower = strtolower($advice);
        if (str_contains($adviceLower, 'goals') || str_contains($adviceLower, 'over')) {
            $predictions['overUnder'] = ['pick' => 'Over', 'confidence' => 55];
        } elseif (str_contains($adviceLower, 'under') || str_contains($adviceLower, '-2.5') || str_contains($adviceLower, '-3.5')) {
            $predictions['overUnder'] = ['pick' => 'Under', 'confidence' => 55];
        }

        return $predictions;
    }

    /**
     * Extraire les prédictions manuelles (Sources A/B/C) si présentes.
     * Retourne un tableau indexé par marché, chaque entrée = array de picks.
     */
    private function extractManualPredictions(Collection $sources): array
    {
        if ($sources->isEmpty()) return [];

        $byMarket = [];
        foreach ($sources as $source) {
            foreach ($this->markets as $market) {
                $pick = $source->getPick($market);
                if ($pick === null) continue;

                $confidence = $source->getConfidence($market) ?? 50;
                if ($source->source_type === 'A') $confidence = 50;

                $byMarket[$market][] = [
                    'source' => $source->source_type,
                    'value' => $pick,
                    'confidence' => $confidence,
                ];
            }
        }
        return $byMarket;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ANALYSE D'UN MARCHÉ
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function analyzeMarket(FootballMatch $match, string $market, array $context, array $allSources): ?array
    {
        // Assembler les prédictions de toutes les sources
        $predictions = [];

        // Source D
        if (isset($allSources['D'][$market])) {
            $d = $allSources['D'][$market];
            if (($d['pick'] ?? null) !== null) {
                $predictions[] = ['source' => 'D', 'value' => $d['pick'], 'confidence' => $d['confidence'] ?? 50];
            }
        }

        // Source E
        if (isset($allSources['E'][$market])) {
            $e = $allSources['E'][$market];
            if (($e['pick'] ?? null) !== null) {
                $predictions[] = ['source' => 'E', 'value' => $e['pick'], 'confidence' => $e['confidence'] ?? 50];
            }
        }

        // Sources manuelles A/B/C (si présentes)
        foreach ($allSources['manual'][$market] ?? [] as $manualPred) {
            $predictions[] = $manualPred;
        }

        if (empty($predictions)) return null;

        $consensus = $this->detectConsensus($predictions);
        $finalBet = $this->getBetFromConsensus($predictions, $consensus);

        $confidence = $this->calculator->calculateWeightedConfidence($market, $predictions);
        $score = $this->calculator->calculateScore($consensus, $confidence, null, $market, true);
        $odds = $this->estimateOdds($match, $market, $finalBet);
        $logic = $this->generateLogic($predictions, $consensus, $context);

        return [
            'market' => $market,
            'bet' => $finalBet,
            'confidence' => (int) round($confidence),
            'score' => $score,
            'odds' => $odds,
            'consensus_type' => $consensus['type'],
            'consensus_agreement' => $consensus['agreement'],
            'predictions' => $predictions,
            'special_rule' => null,
            'special_rule_reason' => null,
            'logic' => $logic,
            'warnings' => [],
            'coherent' => true,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CONSENSUS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function detectConsensus(array $predictions): array
    {
        $values = array_column($predictions, 'value');
        $unique = array_unique($values);
        $total = count($values);

        if (count($unique) === 1) {
            return ['type' => 'TOTAL', 'agreement' => $total];
        }

        $counts = array_count_values($values);
        $maxCount = max($counts);

        if ($maxCount >= 2) {
            return ['type' => 'MAJORITÉ', 'agreement' => $maxCount];
        }

        return ['type' => 'CONFLIT', 'agreement' => 1];
    }

    private function getBetFromConsensus(array $predictions, array $consensus): string
    {
        if ($consensus['type'] === 'TOTAL') {
            return $predictions[0]['value'];
        }

        if ($consensus['type'] === 'MAJORITÉ') {
            $counts = array_count_values(array_column($predictions, 'value'));
            arsort($counts);
            return array_key_first($counts);
        }

        // CONFLIT : prioriser D > E > B > C > A
        foreach (['D', 'E', 'B', 'C', 'A'] as $key) {
            $pred = collect($predictions)->firstWhere('source', $key);
            if ($pred) return $pred['value'];
        }

        return $predictions[0]['value'];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // COTES + LOGIQUE + NIVEAUX + CONFIANCE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function estimateOdds(FootballMatch $match, string $market, string $pick): ?float
    {
        return match ($market) {
            'winner' => match ($pick) {
                '1' => (float) $match->odds_home ?: null,
                'X' => (float) $match->odds_draw ?: null,
                '2' => (float) $match->odds_away ?: null,
                default => null,
            },
            'overUnder' => str_contains($pick, 'Over')
                ? ((float) $match->odds_over_2_5 ?: null)
                : ((float) $match->odds_under_2_5 ?: null),
            'btts' => str_contains($pick, 'Yes')
                ? ((float) $match->odds_btts_yes ?: null)
                : ((float) $match->odds_btts_no ?: null),
            'doubleChance' => match ($pick) {
                '1X' => (float) $match->odds_dc_1x ?: null,
                '12' => (float) $match->odds_dc_12 ?: null,
                'X2' => (float) $match->odds_dc_x2 ?: null,
                default => null,
            },
            default => null,
        };
    }

    private function generateLogic(array $predictions, array $consensus, array $context): string
    {
        $totalSources = count($predictions);
        $sourceList = implode(',', array_column($predictions, 'source'));

        $parts = [];

        if ($consensus['type'] === 'TOTAL') {
            $parts[] = "Consensus total ({$consensus['agreement']}/{$totalSources} [{$sourceList}])";
        } elseif ($consensus['type'] === 'MAJORITÉ') {
            $parts[] = "Majorite ({$consensus['agreement']}/{$totalSources} [{$sourceList}])";
        } else {
            $parts[] = "Conflit [{$sourceList}]";
        }

        $parts[] = $context['description'] ?? 'Match standard';

        return implode(' | ', $parts);
    }

    private function assignLevels(Collection $recommendations): Collection
    {
        return $recommendations->map(function ($rec) {
            $score = $rec['score'];
            $rec['level'] = $score >= 85 ? 1 : ($score >= 70 ? 2 : ($score >= 55 ? 3 : 4));
            return $rec;
        });
    }

    private function calculateGlobalConfidence(Collection $recommendations): int
    {
        if ($recommendations->isEmpty()) return 0;
        return (int) round($recommendations->avg('confidence'));
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // VALUE ANALYSIS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function applyValueAnalysis(Collection $recommendations, FootballMatch $match): Collection
    {
        return $recommendations->map(function ($rec) use ($match) {
            $valueAnalysis = $this->valueAnalyzer->analyzeRecommendation($rec, $match);
            $rec['valueAnalysis'] = $valueAnalysis;

            if (!$valueAnalysis['hasOdds']) {
                $rec['valueVerdict'] = 'NO_ODDS';
                $rec['valueWarning'] = 'Aucune cote disponible';
            } elseif ($valueAnalysis['isSuspicious']) {
                $rec['valueVerdict'] = 'SUSPICIOUS';
                $rec['valueWarning'] = sprintf('Value > 30%% (%.1f%%) - Cote suspecte', $valueAnalysis['edge']);
                $rec['score'] = max(0, $rec['score'] - 10);
            } elseif ($valueAnalysis['hasValue']) {
                $bonus = min($valueAnalysis['edge'], 15);
                $rec['valueVerdict'] = 'VALUE';
                $rec['valueBonus'] = round($bonus, 1);
                $rec['score'] = $rec['score'] + $bonus;
                $rec['valueMessage'] = sprintf('Value: +%.1f%% edge (Cote: %.2f)', $valueAnalysis['edge'], $valueAnalysis['odds']);
            } else {
                $rec['valueVerdict'] = 'NO_VALUE';
                $rec['valueWarning'] = sprintf('Pas de value (Edge: %.1f%% < 5%%)', $valueAnalysis['edge']);
                $rec['score'] = max(0, $rec['score'] - 5);
            }

            return $rec;
        });
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CONTEXTE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function detectMatchContext(FootballMatch $match): array
    {
        $oddsHome = (float) $match->odds_home;
        $oddsAway = (float) $match->odds_away;

        if ($oddsHome < 1.50 || $oddsAway < 1.50) {
            $favorite = $oddsHome < $oddsAway ? 'home' : 'away';
            return [
                'type' => 'clear_favorite',
                'favorite' => $favorite,
                'description' => 'Favori clair (' . ($favorite === 'home' ? 'Domicile' : 'Exterieur') . ')',
            ];
        }

        if ($oddsHome > 0 && $oddsAway > 0 && abs($oddsHome - $oddsAway) < 0.5) {
            return ['type' => 'balanced', 'favorite' => null, 'description' => 'Match equilibre'];
        }

        return ['type' => 'standard', 'favorite' => null, 'description' => 'Match standard'];
    }
}
