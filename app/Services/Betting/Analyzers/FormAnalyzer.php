<?php

namespace App\Services\Betting\Analyzers;

use App\Services\Betting\Layer2Coefficients;

/**
 * ANALYSEUR FORME RÉCENTE
 * Basé sur Dixon & Coles (1997)
 */
class FormAnalyzer
{
    /**
     * Analyse la forme récente des deux équipes
     */
    public function analyze(array $homeForm, array $awayForm): array
    {
        // Calcul scores
        $homeFormScore = $this->calculateStreakScore($homeForm, true);
        $awayFormScore = $this->calculateStreakScore($awayForm, false);
        
        // Avantage net
        $advantage = $homeFormScore - $awayFormScore;
        
        // Interprétations
        $homeInterpretation = $this->interpretFormScore($homeFormScore) . " ({$homeForm['streak']})";
        $awayInterpretation = $this->interpretFormScore($awayFormScore) . " ({$awayForm['streak']})";
        
        // Insight global
        $overallInsight = $this->generateOverallInsight($advantage);
        
        // Confiance (basée sur cohérence des résultats)
        $homeConsistency = $this->calculateConsistency($homeForm['streak']);
        $awayConsistency = $this->calculateConsistency($awayForm['streak']);
        $confidence = round(($homeConsistency + $awayConsistency) / 2);
        
        return [
            'homeFormScore' => $homeFormScore,
            'awayFormScore' => $awayFormScore,
            'advantage' => $advantage,
            'homeInterpretation' => $homeInterpretation,
            'awayInterpretation' => $awayInterpretation,
            'overallInsight' => $overallInsight,
            'confidence' => $confidence,
        ];
    }

    /**
     * Convertit un streak en score numérique
     */
    private function calculateStreakScore(array $form, bool $isHome): float
    {
        $streak = Layer2Coefficients::parseStreak($form['streak']);
        $points = Layer2Coefficients::FORM_POINTS;
        $timeDecay = Layer2Coefficients::FORM_TIME_DECAY;
        
        $total = 0;
        $weight = 1.0;
        
        // Parcourir du plus récent au plus ancien
        foreach ($streak as $result) {
            $matchPoints = 0;
            
            // Attribuer points selon résultat et localisation
            switch ($result) {
                case 'W':
                    $matchPoints = $isHome ? $points['W_HOME'] : $points['W_AWAY'];
                    break;
                case 'D':
                    $matchPoints = $isHome ? $points['D_HOME'] : $points['D_AWAY'];
                    break;
                case 'L':
                    $matchPoints = $isHome ? $points['L_HOME'] : $points['L_AWAY'];
                    break;
            }
            
            // Appliquer poids dégressif
            $total += $matchPoints * $weight;
            $weight *= $timeDecay; // Dégressivité temporelle
        }
        
        // Normaliser sur 100 (max théorique = 5 victoires away = 5 * 3.9 = 19.5)
        $maxPossible = 19.5;
        $normalized = ($total / $maxPossible) * 100;
        
        return Layer2Coefficients::normalize($normalized);
    }

    /**
     * Interpréter un score de forme
     */
    private function interpretFormScore(float $score): string
    {
        if ($score >= 80) return "Excellente forme";
        if ($score >= 65) return "Bonne forme";
        if ($score >= 50) return "Forme correcte";
        if ($score >= 35) return "Forme moyenne";
        return "Forme préoccupante";
    }

    /**
     * Générer l'insight global basé sur l'avantage
     */
    private function generateOverallInsight(float $advantage): string
    {
        if (abs($advantage) < 10) {
            return "⚖️ Forme équivalente - Pas d'avantage clair";
        }
        
        if ($advantage > 20) {
            return "✅ Avantage net domicile - Forme nettement supérieure";
        }
        
        if ($advantage > 10) {
            return "✅ Léger avantage domicile sur la forme";
        }
        
        if ($advantage < -20) {
            return "⚠️ Avantage net extérieur - Visiteurs en meilleure forme";
        }
        
        return "⚠️ Léger avantage extérieur sur la forme";
    }

    /**
     * Calcule la cohérence d'un streak (moins de variations = plus fiable)
     */
    private function calculateConsistency(string $streak): int
    {
        $results = Layer2Coefficients::parseStreak($streak);
        
        if (count($results) <= 1) {
            return 50; // Pas assez de données
        }
        
        // Compter transitions (W->L, L->W, etc.)
        $transitions = 0;
        for ($i = 0; $i < count($results) - 1; $i++) {
            if ($results[$i] !== $results[$i + 1]) {
                $transitions++;
            }
        }
        
        // Moins de transitions = plus cohérent = plus fiable
        $maxTransitions = count($results) - 1;
        $consistency = (($maxTransitions - $transitions) / $maxTransitions) * 100;
        
        return (int) round($consistency);
    }
}