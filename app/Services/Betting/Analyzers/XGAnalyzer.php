<?php

namespace App\Services\Betting\Analyzers;

use App\Services\Betting\Layer2Coefficients;

/**
 * ANALYSEUR EXPECTED GOALS (xG)
 * Basé sur modèle Poisson + StatsBomb
 */
class XGAnalyzer
{
    /**
     * Analyse les Expected Goals
     */
    public function analyze(array $homeXG, array $awayXG, int $sampleSize = 10): array
    {
        $warnings = [];
        
        // Déterminer fiabilité selon taille échantillon
        $reliability = $this->getReliability($sampleSize, $warnings);
        
        // Extraire les valeurs xG
        $homeXGFor = $homeXG['xGFor'] ?? 1.2;
        $homeXGAgainst = $homeXG['xGAgainst'] ?? 1.2;
        $awayXGFor = $awayXG['xGFor'] ?? 1.2;
        $awayXGAgainst = $awayXG['xGAgainst'] ?? 1.2;
        
        // Classification
        $homeXGRating = $this->classifyXG($homeXGFor);
        $awayXGRating = $this->classifyXG($awayXGFor);
        
        // Forces offensives/défensives (0-100)
        $homeOffensiveStrength = (int) min(100, ($homeXGFor / 2.5) * 100);
        $awayOffensiveStrength = (int) min(100, ($awayXGFor / 2.5) * 100);
        $homeDefensiveStrength = (int) max(0, 100 - (($homeXGAgainst / 2.5) * 100));
        $awayDefensiveStrength = (int) max(0, 100 - (($awayXGAgainst / 2.5) * 100));
        
        // Prédiction expected goals pour ce match
        $homeExpected = ($homeXGFor + $awayXGAgainst) / 2;
        $awayExpected = ($awayXGFor + $homeXGAgainst) / 2;
        $totalExpected = $homeExpected + $awayExpected;
        
        // BTTS likelihood
        $homeScoringProb = $this->calculateScoringProbability($homeExpected);
        $awayScoringProb = $this->calculateScoringProbability($awayExpected);
        $bttsLikelihood = (int) round(($homeScoringProb * $awayScoringProb) / 100);
        
        // Over 2.5 likelihood
        $overLikelihood = (int) round($this->calculateOverProbability($homeExpected, $awayExpected));
        
        // Score xG global
        $xgScore = $this->calculateXGScore(
            $homeOffensiveStrength,
            $awayOffensiveStrength,
            $homeDefensiveStrength,
            $awayDefensiveStrength
        );
        
        // Insight global
        $overallInsight = $this->generateOverallInsight($totalExpected, $bttsLikelihood);
        
        return [
            'xgScore' => $xgScore,
            'homeXGRating' => $homeXGRating,
            'awayXGRating' => $awayXGRating,
            'homeOffensiveStrength' => $homeOffensiveStrength,
            'awayOffensiveStrength' => $awayOffensiveStrength,
            'homeDefensiveStrength' => $homeDefensiveStrength,
            'awayDefensiveStrength' => $awayDefensiveStrength,
            'expectedGoalsPrediction' => [
                'homeExpected' => round($homeExpected, 1),
                'awayExpected' => round($awayExpected, 1),
                'totalExpected' => round($totalExpected, 1),
            ],
            'bttsLikelihood' => $bttsLikelihood,
            'overLikelihood' => $overLikelihood,
            'overallInsight' => $overallInsight,
            'confidence' => (int) round($reliability * 100),
            'warnings' => $warnings,
        ];
    }

    /**
     * Détermine la fiabilité selon l'échantillon
     */
    private function getReliability(int $sampleSize, array &$warnings): float
    {
        $reliability = Layer2Coefficients::XG_RELIABILITY;
        
        if ($sampleSize >= 20) {
            return $reliability['GAMES_20'];
        }
        if ($sampleSize >= 15) {
            return $reliability['GAMES_15'];
        }
        if ($sampleSize >= 10) {
            return $reliability['GAMES_10'];
        }
        if ($sampleSize >= 5) {
            $warnings[] = "⚠️ Échantillon xG limité (<10 matchs) - Fiabilité réduite";
            return $reliability['GAMES_5'];
        }
        
        $warnings[] = "🔴 Échantillon xG très limité (<5 matchs) - Fiabilité faible";
        return 0.4;
    }

    /**
     * Classe un xG
     */
    private function classifyXG(float $xg): string
    {
        $thresholds = Layer2Coefficients::XG_THRESHOLDS;
        
        if ($xg >= $thresholds['VERY_HIGH']) return "Très offensif";
        if ($xg >= $thresholds['HIGH']) return "Offensif";
        if ($xg >= $thresholds['MEDIUM']) return "Moyen";
        if ($xg >= $thresholds['LOW']) return "Faible";
        return "Très faible";
    }

    /**
     * Calcule la probabilité qu'une équipe marque au moins 1 but
     * P(X≥1) = 1 - e^(-xG)
     */
    private function calculateScoringProbability(float $xg): float
    {
        return Layer2Coefficients::poissonProbability($xg) * 100;
    }

    /**
     * Calcule la probabilité Over 2.5
     * P(X > 2.5) = 1 - P(X ≤ 2) = 1 - [P(0) + P(1) + P(2)]
     */
    private function calculateOverProbability(float $homeXG, float $awayXG): float
    {
        $lambda = $homeXG + $awayXG;
        
        $p0 = exp(-$lambda);
        $p1 = $lambda * exp(-$lambda);
        $p2 = (pow($lambda, 2) / 2) * exp(-$lambda);
        
        $pUnder = $p0 + $p1 + $p2;
        $pOver = 1 - $pUnder;
        
        return $pOver * 100;
    }

    /**
     * Calcule le score xG global
     */
    private function calculateXGScore(int $homeOff, int $awayOff, int $homeDef, int $awayDef): int
    {
        $xgScore = 50;
        
        // Avantage offensif domicile
        $offensiveAdvantage = $homeOff - $awayDef;
        $xgScore += $offensiveAdvantage * 0.3;
        
        // Avantage défensif domicile
        $defensiveAdvantage = $homeDef - $awayOff;
        $xgScore += $defensiveAdvantage * 0.2;
        
        return (int) round(Layer2Coefficients::normalize($xgScore));
    }

    /**
     * Génère l'insight global
     */
    private function generateOverallInsight(float $totalExpected, int $bttsLikelihood): string
    {
        $insight = '';
        
        if ($totalExpected > 3.0) {
            $insight = "🔥 Match à fort potentiel offensif - Over fortement favorisé";
        } elseif ($totalExpected > 2.5) {
            $insight = "✅ Match équilibré offensivement - Over légèrement favorisé";
        } elseif ($totalExpected > 2.0) {
            $insight = "⚖️ Match standard - Entre 2 et 3 buts attendus";
        } else {
            $insight = "🔒 Match serré - Peu de buts attendus";
        }
        
        if ($bttsLikelihood > 70) {
            $insight .= " | BTTS très probable";
        } elseif ($bttsLikelihood < 40) {
            $insight .= " | Clean sheet probable";
        }
        
        return $insight;
    }
}