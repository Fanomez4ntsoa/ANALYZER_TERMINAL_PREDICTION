<?php

namespace App\Services\Betting\Analyzers;

use App\Services\Betting\Layer2Coefficients;

/**
 * ANALYSEUR BLESSURES & ABSENCES
 * Basé sur Opta Pro Standards
 */
class InjuryAnalyzer
{
    /**
     * Analyse les blessures et absences
     */
    public function analyze(array $homeAbsences, array $awayAbsences): array
    {
        // Calculer impacts
        $homeAnalysis = $this->calculateInjuryImpact($homeAbsences);
        $awayAnalysis = $this->calculateInjuryImpact($awayAbsences);
        
        // Scores finaux (100 = effectif complet)
        $baseScore = Layer2Coefficients::INJURY_BASE_SCORE;
        $homeInjuryScore = Layer2Coefficients::normalize($baseScore + $homeAnalysis['totalImpact']);
        $awayInjuryScore = Layer2Coefficients::normalize($baseScore + $awayAnalysis['totalImpact']);
        
        // Avantage net
        $netAdvantage = $homeInjuryScore - $awayInjuryScore;
        
        // Insight global
        $overallInsight = $this->generateOverallInsight(
            $homeAnalysis['criticalCount'],
            $awayAnalysis['criticalCount']
        );
        
        // Confiance (100% si données complètes)
        $confidence = 100;
        
        return [
            'homeInjuryScore' => $homeInjuryScore,
            'awayInjuryScore' => $awayInjuryScore,
            'homeImpact' => $homeAnalysis['totalImpact'],
            'awayImpact' => $awayAnalysis['totalImpact'],
            'homeCriticalAbsences' => $homeAnalysis['criticalCount'],
            'awayCriticalAbsences' => $awayAnalysis['criticalCount'],
            'netAdvantage' => $netAdvantage,
            'homeDetails' => $homeAnalysis['details'],
            'awayDetails' => $awayAnalysis['details'],
            'overallInsight' => $overallInsight,
            'confidence' => $confidence,
        ];
    }

    /**
     * Calcule l'impact des absences pour une équipe
     */
    private function calculateInjuryImpact(array $absences): array
    {
        $impactCoeffs = Layer2Coefficients::INJURY_IMPACT;
        $multipleAbsences = Layer2Coefficients::INJURY_MULTIPLE_ABSENCES;
        
        $totalImpact = 0;
        $criticalCount = 0;
        $details = [];
        
        // Calculer impact de chaque absence
        foreach ($absences as $absence) {
            $position = $absence['position'] ?? 'CM';
            $importance = $absence['importance'] ?? 'regular';
            $name = $absence['name'] ?? 'Joueur inconnu';
            
            // Vérifier que la position existe
            if (!isset($impactCoeffs[$position])) {
                $position = 'CM'; // Fallback
            }
            
            $impactValue = $impactCoeffs[$position][$importance] ?? -8;
            $totalImpact += $impactValue;
            
            // Générer détail selon importance
            switch ($importance) {
                case 'key':
                    $criticalCount++;
                    $details[] = "🔴 {$name} ({$position}) - Absent clé ({$impactValue} points)";
                    break;
                case 'regular':
                    $details[] = "🟠 {$name} ({$position}) - Titulaire régulier ({$impactValue} points)";
                    break;
                default:
                    $details[] = "🟡 {$name} ({$position}) - Rotation ({$impactValue} points)";
                    break;
            }
        }
        
        // Pénalité supplémentaire si multiples absences clés
        if ($criticalCount >= 3) {
            $penalty = $multipleAbsences['THREE_KEY'];
            $totalImpact += $penalty;
            $details[] = "⚠️ Pénalité multiples absences : {$penalty} points";
        } elseif ($criticalCount >= 2) {
            $penalty = $multipleAbsences['TWO_KEY'];
            $totalImpact += $penalty;
            $details[] = "⚠️ Pénalité multiples absences : {$penalty} points";
        }
        
        return [
            'totalImpact' => $totalImpact,
            'criticalCount' => $criticalCount,
            'details' => $details,
        ];
    }

    /**
     * Générer l'insight global
     */
    private function generateOverallInsight(int $homeCritical, int $awayCritical): string
    {
        if ($homeCritical === 0 && $awayCritical === 0) {
            return "✅ Aucune absence majeure - Effectifs au complet";
        }
        
        if ($homeCritical > 0 && $awayCritical === 0) {
            return "⚠️ Domicile affaibli ({$homeCritical} absent(s) clé(s)) - Avantage net extérieur";
        }
        
        if ($awayCritical > 0 && $homeCritical === 0) {
            return "⚠️ Extérieur affaibli ({$awayCritical} absent(s) clé(s)) - Avantage net domicile";
        }
        
        return "⚠️ Les deux équipes affaiblies ({$homeCritical} vs {$awayCritical} absents clés)";
    }
}