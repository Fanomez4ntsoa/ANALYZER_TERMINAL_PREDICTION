<?php

namespace App\Services;

use App\Models\FootballMatch;

class ValueAnalyzer
{
    /**
     * Calculer la probabilité implicite d'une cote
     * 
     * Formule : Probabilité = 1 / Cote
     * 
     * @param float $odds
     * @return float Probabilité en pourcentage (0-100)
     */
    public function getImpliedProbability(float $odds): float
    {
        if ($odds <= 0) {
            return 0;
        }
        
        return (1 / $odds) * 100;
    }

    /**
     * Calculer l'edge (value) d'un pari
     * 
     * Formule : Edge = (Proba réelle × Cote) - 1
     * 
     * Si Edge > 0 → Pari rentable
     * Si Edge < 0 → Pari perdant
     * 
     * @param float $realProbability Probabilité estimée par ton algo (0-100)
     * @param float $odds Cote du bookmaker
     * @return float Edge en pourcentage
     */
    public function calculateEdge(float $realProbability, float $odds): float
    {
        if ($odds <= 0) {
            return -100;
        }
        
        // Convertir la probabilité en décimal (0-1)
        $probaDecimal = $realProbability / 100;
        
        // Formule de Kelly : Value = (Proba × Cote) - 1
        $edge = ($probaDecimal * $odds) - 1;
        
        // Retourner en pourcentage
        return $edge * 100;
    }

    /**
     * Vérifier si un pari a de la value
     * 
     * @param float $edge
     * @param float $minEdge Seuil minimum (défaut: 5%)
     * @return bool
     */
    public function hasValue(float $edge, float $minEdge = 5.0): bool
    {
        return $edge >= $minEdge;
    }

    /**
     * Détecter les cotes suspectes (trop belles pour être vraies)
     * 
     * Si edge > 30%, c'est souvent une erreur de cote ou un piège
     * 
     * @param float $edge
     * @return bool
     */
    public function isSuspicious(float $edge): bool
    {
        return $edge > 30;
    }

    /**
     * Calculer la mise optimale selon Kelly Criterion
     * 
     * Formule : Mise = (Bankroll × Edge) / (Cote - 1)
     * 
     * @param float $bankroll Bankroll total
     * @param float $edge Edge du pari (en décimal, ex: 0.144 pour 14.4%)
     * @param float $odds Cote
     * @param float $kellyFraction Fraction de Kelly (0.25 = 1/4 Kelly, plus safe)
     * @return float Mise recommandée
     */
    public function calculateKellyStake(
        float $bankroll, 
        float $edge, 
        float $odds, 
        float $kellyFraction = 0.25
    ): float {
        if ($odds <= 1 || $edge <= 0) {
            return 0;
        }
        
        // Convertir edge en décimal si nécessaire
        $edgeDecimal = $edge > 1 ? $edge / 100 : $edge;
        
        // Kelly Criterion
        $fullKelly = ($bankroll * $edgeDecimal) / ($odds - 1);
        
        // Appliquer la fraction de Kelly (plus safe)
        return $fullKelly * $kellyFraction;
    }

    /**
     * Analyser la value d'une recommandation
     * 
     * @param array $recommendation
     * @param FootballMatch $match
     * @return array
     */
    public function analyzeRecommendation(array $recommendation, FootballMatch $match): array
    {
        $market = $recommendation['market'] ?? '';
        $bet = $recommendation['bet'] ?? '';
        $confidence = $recommendation['confidence'] ?? 0;
        
        // Récupérer la cote correspondante
        $odds = $this->getOddsForBet($market, $bet, $match);
        
        if (!$odds) {
            return [
                'hasOdds' => false,
                'odds' => null,
                'impliedProbability' => null,
                'edge' => null,
                'hasValue' => false,
                'isSuspicious' => false,
                'verdict' => 'NO_ODDS',
            ];
        }
        
        // Calculer la probabilité implicite
        $impliedProba = $this->getImpliedProbability($odds);
        
        // Calculer l'edge
        $edge = $this->calculateEdge($confidence, $odds);
        
        // Analyser
        $hasValue = $this->hasValue($edge);
        $isSuspicious = $this->isSuspicious($edge);
        
        // Verdict
        $verdict = 'NO_VALUE';
        if ($isSuspicious) {
            $verdict = 'SUSPICIOUS';
        } elseif ($hasValue) {
            $verdict = 'VALUE';
        }
        
        return [
            'hasOdds' => true,
            'odds' => $odds,
            'impliedProbability' => round($impliedProba, 2),
            'edge' => round($edge, 2),
            'hasValue' => $hasValue,
            'isSuspicious' => $isSuspicious,
            'verdict' => $verdict,
            'kellyStake' => $hasValue ? $this->calculateKellyStake(1000, $edge, $odds) : 0,
        ];
    }

    /**
     * Récupérer la cote correspondant à un pari
     * 
     * @param string $market
     * @param string $bet
     * @param FootballMatch $match
     * @return float|null
     */
    private function getOddsForBet(string $market, string $bet, FootballMatch $match): ?float
    {
        // Winner (1X2) — accepter les deux formats
        if ($market === 'winner' || $market === 'Winner') {
            if ($bet === '1') return $match->odds_home;
            if ($bet === 'X') return $match->odds_draw;
            if ($bet === '2') return $match->odds_away;
        }

        // Over/Under 2.5
        if ($market === 'overUnder' || $market === 'Over/Under 2.5') {
            if (str_contains($bet, 'Over')) return $match->odds_over_2_5;
            if (str_contains($bet, 'Under')) return $match->odds_under_2_5;
        }

        // BTTS
        if ($market === 'btts' || $market === 'BTTS') {
            if (str_contains($bet, 'Yes')) return $match->odds_btts_yes;
            if (str_contains($bet, 'No')) return $match->odds_btts_no;
        }

        // Double Chance
        if ($market === 'doubleChance' || $market === 'Double Chance') {
            if ($bet === '1X') return $match->odds_dc_1x;
            if ($bet === '12') return $match->odds_dc_12;
            if ($bet === 'X2') return $match->odds_dc_x2;
        }

        // Exact Score
        if ($market === 'exactScore' || $market === 'Exact Score') {
            return null;
        }

        // Total Domicile
        if (str_contains($market, 'Total 1')) {
            if (str_contains($bet, 'Over 0.5')) return $match->odds_home_over_0_5;
            if (str_contains($bet, 'Under 0.5')) return $match->odds_home_under_0_5;
            if (str_contains($bet, 'Over 1.5')) return $match->odds_home_over_1_5;
            if (str_contains($bet, 'Under 1.5')) return $match->odds_home_under_1_5;
        }

        // Total Extérieur
        if (str_contains($market, 'Total 2')) {
            if (str_contains($bet, 'Over 0.5')) return $match->odds_away_over_0_5;
            if (str_contains($bet, 'Under 0.5')) return $match->odds_away_under_0_5;
            if (str_contains($bet, 'Over 1.5')) return $match->odds_away_over_1_5;
            if (str_contains($bet, 'Under 1.5')) return $match->odds_away_under_1_5;
        }
        
        return null;
    }

    /**
     * Filtrer les recommandations selon la value
     * 
     * @param array $recommendations
     * @param FootballMatch $match
     * @param float $minEdge Seuil minimum (défaut: 5%)
     * @return array
     */
    public function filterByValue(array $recommendations, FootballMatch $match, float $minEdge = 5.0): array
    {
        $filtered = [];
        
        foreach ($recommendations as $rec) {
            $valueAnalysis = $this->analyzeRecommendation($rec, $match);
            
            // Ajouter l'analyse de value à la recommandation
            $rec['valueAnalysis'] = $valueAnalysis;
            
            // Filtrer selon la value
            if ($valueAnalysis['hasOdds'] && $valueAnalysis['hasValue'] && !$valueAnalysis['isSuspicious']) {
                $filtered[] = $rec;
            }
        }
        
        return $filtered;
    }

    /**
     * Scorer les recommandations avec bonus de value
     * 
     * @param array $recommendations
     * @param FootballMatch $match
     * @return array
     */
    public function scoreWithValue(array $recommendations, FootballMatch $match): array
    {
        foreach ($recommendations as &$rec) {
            $valueAnalysis = $this->analyzeRecommendation($rec, $match);
            $rec['valueAnalysis'] = $valueAnalysis;
            
            // Bonus de score si value détectée
            if ($valueAnalysis['hasValue'] && !$valueAnalysis['isSuspicious']) {
                $edge = $valueAnalysis['edge'];
                
                // Bonus proportionnel à l'edge (max +15 points)
                $bonus = min($edge, 15);
                $rec['score'] = ($rec['score'] ?? 0) + $bonus;
                $rec['valueBonus'] = $bonus;
            }
        }
        
        return $recommendations;
    }
}