<?php

namespace App\Services\Betting\Analyzers;

use App\Services\Betting\Layer2Coefficients;

/**
 * ANALYSEUR H2H (HEAD TO HEAD)
 * Basé sur Goddard (2005)
 */
class H2HAnalyzer
{
    /**
     * Analyse les confrontations directes
     */
    public function analyze(array $h2h): array
    {
        $baseScore = Layer2Coefficients::H2H_BASE_SCORE;
        $dominanceBonus = Layer2Coefficients::H2H_DOMINANCE_BONUS;
        $locationWeights = Layer2Coefficients::H2H_LOCATION_WEIGHTS;
        
        $warnings = [];
        $h2hScore = $baseScore;
        
        // Taux de victoires
        $totalGames = $h2h['totalGames'] ?? 0;
        $homeWins = $h2h['homeWins'] ?? 0;
        $awayWins = $h2h['awayWins'] ?? 0;
        $atHomeAdvantage = $h2h['atHomeAdvantage'] ?? 50;
        $lastMatches = $h2h['lastMatches'] ?? [];
        
        $homeWinRate = $totalGames > 0 ? ($homeWins / $totalGames) * 100 : 0;
        $awayWinRate = $totalGames > 0 ? ($awayWins / $totalGames) * 100 : 0;
        
        // Ajustement selon domination domicile
        if ($atHomeAdvantage > 70) {
            $h2hScore += $dominanceBonus['VERY_STRONG'];
        } elseif ($atHomeAdvantage > 60) {
            $h2hScore += $dominanceBonus['STRONG'];
        } elseif ($atHomeAdvantage > 50) {
            $h2hScore += $dominanceBonus['SLIGHT'];
        } elseif ($atHomeAdvantage >= 40) {
            $h2hScore += $dominanceBonus['NEUTRAL'];
        } else {
            $h2hScore += $dominanceBonus['DISADVANTAGE'];
        }
        
        // Analyser matchs récents avec pondération
        $weightedScore = 0;
        $totalWeight = 0;
        $recentHomeWins = 0;
        $recentAwayWins = 0;
        
        foreach ($lastMatches as $match) {
            $timeWeight = $this->calculateMatchWeight($match['date'] ?? '');
            
            if ($timeWeight === 0.0) {
                $warnings[] = "⚠️ Match du {$match['date']} ignoré (>3 ans)";
                continue;
            }
            
            // Poids selon localisation
            $location = $match['location'] ?? 'neutral';
            $locationWeight = match($location) {
                'home' => $locationWeights['SAME_VENUE'],
                'away' => $locationWeights['AWAY_VENUE'],
                default => $locationWeights['NEUTRAL'],
            };
            
            $finalWeight = $timeWeight * $locationWeight;
            $winner = $match['winner'] ?? 'draw';
            
            // Comptabiliser
            if ($winner === 'home' && $location === 'home') {
                $weightedScore += 10 * $finalWeight;
                $recentHomeWins++;
            } elseif ($winner === 'away' && $location === 'home') {
                $weightedScore -= 10 * $finalWeight;
                $recentAwayWins++;
            } elseif ($winner === 'draw') {
                $weightedScore += 5 * $finalWeight;
            }
            
            $totalWeight += $finalWeight;
        }
        
        // Ajuster score selon matchs récents pondérés
        if ($totalWeight > 0) {
            $avgWeightedScore = $weightedScore / $totalWeight;
            $h2hScore += $avgWeightedScore;
        }
        
        // Normaliser
        $h2hScore = Layer2Coefficients::normalize($h2hScore);
        
        // Tendance récente
        $recentTrend = $this->calculateRecentTrend($lastMatches, $recentHomeWins, $recentAwayWins, $warnings);
        
        // Insight global
        $overallInsight = $this->generateOverallInsight($atHomeAdvantage);
        
        // Confiance (dépend du nombre de matchs et de leur récence)
        $confidence = $this->calculateConfidence($lastMatches, $totalGames, $totalWeight, $warnings);
        
        return [
            'h2hScore' => $h2hScore,
            'homeWinRate' => $homeWinRate,
            'awayWinRate' => $awayWinRate,
            'atHomeAdvantage' => $atHomeAdvantage,
            'recentTrend' => $recentTrend,
            'overallInsight' => $overallInsight,
            'confidence' => $confidence,
            'warnings' => $warnings,
        ];
    }

    /**
     * Calcule le poids d'un match H2H selon son ancienneté
     */
    private function calculateMatchWeight(string $matchDate): float
    {
        if (empty($matchDate)) {
            return 0.0;
        }
        
        $daysSince = Layer2Coefficients::daysSince($matchDate);
        $timeWeights = Layer2Coefficients::H2H_TIME_WEIGHTS;
        
        if ($daysSince <= 180) return $timeWeights['LAST_6_MONTHS'];
        if ($daysSince <= 365) return $timeWeights['LAST_1_YEAR'];
        if ($daysSince <= 730) return $timeWeights['LAST_2_YEARS'];
        if ($daysSince <= 1095) return $timeWeights['LAST_3_YEARS'];
        
        return $timeWeights['OLDER']; // 0.0 = Ignoré
    }

    /**
     * Calcule la tendance récente
     */
    private function calculateRecentTrend(array $lastMatches, int $recentHomeWins, int $recentAwayWins, array &$warnings): string
    {
        if (count($lastMatches) >= 3) {
            if ($recentHomeWins >= 3) {
                return "🔥 Domination totale domicile sur matchs récents";
            }
            if ($recentHomeWins >= 2) {
                return "✅ Avantage domicile sur matchs récents";
            }
            if ($recentAwayWins >= 2) {
                return "⚠️ Extérieur performant sur matchs récents";
            }
            return "⚖️ Matchs récents équilibrés";
        }
        
        $warnings[] = "⚠️ Historique H2H limité (<3 matchs récents)";
        return "ℹ️ Peu de matchs récents disponibles";
    }

    /**
     * Génère l'insight global
     */
    private function generateOverallInsight(float $atHomeAdvantage): string
    {
        $pct = number_format($atHomeAdvantage, 0);
        
        if ($atHomeAdvantage > 60) {
            return "✅ Historique domicile favorable ({$pct}% victoires)";
        }
        if ($atHomeAdvantage < 35) {
            return "⚠️ Historique domicile défavorable ({$pct}% victoires seulement)";
        }
        return "⚖️ Historique équilibré ({$pct}% victoires domicile)";
    }

    /**
     * Calcule la confiance
     */
    private function calculateConfidence(array $lastMatches, int $totalGames, float $totalWeight, array &$warnings): int
    {
        if ($totalGames === 0) {
            $warnings[] = "⚠️ Aucun H2H disponible";
            return 0;
        }
        
        if (count($lastMatches) >= 5 && $totalWeight > 2) {
            return 80;
        }
        if (count($lastMatches) >= 3 && $totalWeight > 1) {
            return 65;
        }
        
        return 50;
    }
}