<?php

namespace App\Services\Betting;

use App\Services\Betting\Analyzers\H2HAnalyzer;
use App\Services\Betting\Analyzers\TacticalAnalyzer;

/**
 * LAYER 2 SERVICE — Version fusionnée.
 *
 * Consomme directement :
 *   - ContextEnricherService (fatigue, enjeu, météo, arbitre, pression) → déjà dans context_data
 *   - XGModelService → déjà dans footystats_data
 * Calcule en propre :
 *   - H2H (H2HAnalyzer)
 *   - Tactique (TacticalAnalyzer)
 */
class Layer2Service
{
    private H2HAnalyzer $h2hAnalyzer;
    private TacticalAnalyzer $tacticalAnalyzer;

    public function __construct()
    {
        $this->h2hAnalyzer = new H2HAnalyzer();
        $this->tacticalAnalyzer = new TacticalAnalyzer();
    }

    public function analyzeAdvanced(array $matchData, array $layer1Analysis): array
    {
        $dimensions = [];

        // H2H (calcul propre — seul analyser qui apporte une valeur unique)
        if (!empty($matchData['sofascoreData']['h2h'])) {
            $h2hResult = $this->h2hAnalyzer->analyze($matchData['sofascoreData']['h2h']);
            $dimensions['h2h'] = [
                'score' => (int) round($h2hResult['h2hScore']),
                'weight' => Layer2Coefficients::GLOBAL_WEIGHTS['h2h'],
                'confidence' => $h2hResult['confidence'],
                'insights' => [$h2hResult['overallInsight'], $h2hResult['recentTrend']],
                'warnings' => $h2hResult['warnings'],
            ];
        }

        // Tactique (calcul propre)
        if (!empty($matchData['tacticalData']['home']['formation']) && !empty($matchData['tacticalData']['away']['formation'])) {
            $tactResult = $this->tacticalAnalyzer->analyze(
                $matchData['tacticalData']['home']['formation'],
                $matchData['tacticalData']['away']['formation']
            );
            $dimensions['tactical'] = [
                'score' => $tactResult['tacticalScore'],
                'weight' => Layer2Coefficients::GLOBAL_WEIGHTS['tactical'],
                'confidence' => $tactResult['confidence'],
                'insights' => array_merge(
                    [$tactResult['tacticalInsight']],
                    array_slice($tactResult['homeAdvantages'] ?? [], 0, 2),
                    array_slice($tactResult['awayAdvantages'] ?? [], 0, 2)
                ),
                'warnings' => [],
                'overModifier' => $tactResult['overModifier'] ?? 0,
                'bttsModifier' => $tactResult['bttsModifier'] ?? 0,
            ];
        }

        // Contexte enrichi (consommé depuis context_data, déjà calculé par ContextEnricherService)
        $contextData = $matchData['contextData'] ?? [];
        $contextScore = 50;

        $fatigue = $contextData['fatigue'] ?? null;
        if ($fatigue && ($fatigue['available'] ?? false)) {
            $fatigueAdv = $fatigue['advantage'] ?? 0;
            $contextScore += $fatigueAdv * 0.15;
        }

        $weather = $contextData['weather'] ?? null;
        $weatherOverMod = $weather['over_modifier'] ?? 0;
        $weatherBttsMod = $weather['btts_modifier'] ?? 0;

        $stakes = $contextData['stakes'] ?? null;
        if ($stakes && ($stakes['available'] ?? false)) {
            $motDelta = $stakes['motivation_delta'] ?? 0;
            $contextScore += $motDelta * 0.1;
        }

        $dimensions['context'] = [
            'score' => (int) round(Layer2Coefficients::normalize($contextScore)),
            'weight' => Layer2Coefficients::GLOBAL_WEIGHTS['context'],
            'confidence' => 80,
            'insights' => [],
            'warnings' => [],
        ];

        // Calcul score Layer 2
        $layer2Score = $this->calculateLayer2Score($dimensions);
        $layer1Score = $layer1Analysis['globalConfidence'] ?? 50;
        $fusion = $this->fuseLayers($layer1Score, $layer2Score);

        // Enrichir les recommandations avec H2H + Tactique + Contexte
        $enrichedRecommendations = $this->enrichRecommendations(
            $layer1Analysis['recommendations'] ?? [],
            $dimensions,
            $weatherOverMod,
            $weatherBttsMod
        );

        return [
            'dimensions' => $dimensions,
            'globalScore' => $fusion['globalScore'],
            'layer1Score' => $layer1Score,
            'layer2Score' => $layer2Score,
            'convergence' => $fusion['convergence'],
            'tacticalInsights' => $this->buildTacticalInsights($dimensions),
            'originalRecommendations' => $layer1Analysis['recommendations'] ?? [],
            'enrichedRecommendations' => $enrichedRecommendations,
            'detailedExplanation' => null,
        ];
    }

    private function calculateLayer2Score(array $dimensions): int
    {
        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($dimensions as $dim) {
            $weightedSum += $dim['score'] * $dim['weight'];
            $totalWeight += $dim['weight'];
        }

        return $totalWeight > 0 ? (int) round($weightedSum / $totalWeight) : 50;
    }

    private function fuseLayers(float $layer1Score, float $layer2Score): array
    {
        $convergence = Layer2Coefficients::getConvergenceLevel($layer1Score, $layer2Score);
        $bonus = Layer2Coefficients::getConvergenceBonus($convergence);

        $globalScore = ($layer1Score * 0.6) + ($layer2Score * 0.4) + $bonus;
        $globalScore = (int) round(Layer2Coefficients::normalize($globalScore));

        return [
            'globalScore' => $globalScore,
            'convergence' => strtolower($convergence),
        ];
    }

    private function enrichRecommendations(array $recommendations, array $dimensions, float $weatherOverMod, float $weatherBttsMod): array
    {
        return array_map(function ($rec) use ($dimensions, $weatherOverMod, $weatherBttsMod) {
            $enriched = $rec;
            $adjustment = 0;
            $reasons = [];

            // H2H
            $h2h = $dimensions['h2h'] ?? null;
            if ($h2h) {
                $market = $rec['market'] ?? '';
                $bet = $rec['bet'] ?? '';

                if ($h2h['score'] >= 75 && $market === 'winner' && $bet === '1') {
                    $adjustment += 6;
                    $reasons[] = 'H2H favorable domicile (+6%)';
                } elseif ($h2h['score'] <= 35 && $market === 'winner' && $bet === '2') {
                    $adjustment += 6;
                    $reasons[] = 'H2H favorable exterieur (+6%)';
                }
            }

            // Tactique
            $tact = $dimensions['tactical'] ?? null;
            if ($tact) {
                if ($tact['score'] >= 70) {
                    $adjustment += 3;
                    $reasons[] = 'Avantage tactique (+3%)';
                } elseif ($tact['score'] <= 35) {
                    $adjustment -= 3;
                    $reasons[] = 'Desavantage tactique (-3%)';
                }
            }

            // Météo (modifieurs directs)
            $market = $rec['market'] ?? '';
            if ($market === 'overUnder' && $weatherOverMod !== 0) {
                $adj = round($weatherOverMod * 0.3);
                $adjustment += $adj;
                $reasons[] = "Meteo Over modifier ({$adj}%)";
            }
            if ($market === 'btts' && $weatherBttsMod !== 0) {
                $adj = round($weatherBttsMod * 0.3);
                $adjustment += $adj;
                $reasons[] = "Meteo BTTS modifier ({$adj}%)";
            }

            $adjustment = max(-20, min(20, $adjustment));
            $enriched['confidence'] = max(0, min(100, ($enriched['confidence'] ?? 50) + $adjustment));
            $enriched['score'] = max(0, min(100, ($enriched['score'] ?? 50) + $adjustment));

            if (!empty($reasons)) {
                $enriched['logic'] = ($enriched['logic'] ?? '') . ' | L2: ' . implode(', ', $reasons);
            }

            return $enriched;
        }, $recommendations);
    }

    private function buildTacticalInsights(array $dimensions): array
    {
        $homeAdvantages = [];
        $awayAdvantages = [];
        $riskFactors = [];

        $h2h = $dimensions['h2h'] ?? null;
        if ($h2h && $h2h['score'] > 65) {
            $homeAdvantages[] = 'Historique favorable';
        }

        $tact = $dimensions['tactical'] ?? null;
        if ($tact) {
            foreach ($tact['insights'] ?? [] as $insight) {
                $homeAdvantages[] = $insight;
            }
        }

        return [
            'homeAdvantages' => $homeAdvantages,
            'awayAdvantages' => $awayAdvantages,
            'keyMatchups' => [],
            'riskFactors' => $riskFactors,
        ];
    }
}
