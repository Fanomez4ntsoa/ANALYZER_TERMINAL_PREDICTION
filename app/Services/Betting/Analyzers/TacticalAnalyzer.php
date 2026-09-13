<?php

namespace App\Services\Betting\Analyzers;

use App\Services\Betting\FormationProfiles;
use App\Services\Betting\Layer2Coefficients;

/**
 * ANALYSEUR TACTIQUE
 * Basé sur StatsBomb Research
 */
class TacticalAnalyzer
{
    /**
     * Analyse l'impact tactique de la confrontation
     */
    public function analyze(string $homeFormation, string $awayFormation): array
    {
        // Analyse matchup
        $matchup = $this->analyzeTacticalMatchup($homeFormation, $awayFormation);
        
        $homeAdvantages = [];
        $awayAdvantages = [];
        $keyMatchups = [];
        
        // Identifier avantages domicile
        if ($matchup['netOffensiveAdvantage'] > 10) {
            $homeAdvantages[] = "✅ Supériorité offensive (+{$matchup['netOffensiveAdvantage']} points)";
        }
        if ($matchup['netDefensiveAdvantage'] > 10) {
            $homeAdvantages[] = "🛡️ Supériorité défensive (+{$matchup['netDefensiveAdvantage']} points)";
        }
        
        foreach ($matchup['homeProfile']['strengths'] as $strength) {
            $homeAdvantages[] = "💪 {$strength}";
        }
        
        // Identifier avantages extérieur
        if ($matchup['netOffensiveAdvantage'] < -10) {
            $absVal = abs($matchup['netOffensiveAdvantage']);
            $awayAdvantages[] = "✅ Supériorité offensive (+{$absVal} points)";
        }
        if ($matchup['netDefensiveAdvantage'] < -10) {
            $absVal = abs($matchup['netDefensiveAdvantage']);
            $awayAdvantages[] = "🛡️ Supériorité défensive (+{$absVal} points)";
        }
        
        foreach ($matchup['awayProfile']['strengths'] as $strength) {
            $awayAdvantages[] = "💪 {$strength}";
        }
        
        // Matchups clés
        $keyMatchups[] = "⚔️ {$matchup['homeProfile']['description']} VS {$matchup['awayProfile']['description']}";
        
        // Exploiter faiblesses
        foreach ($matchup['homeProfile']['weaknesses'] as $weakness) {
            $keyMatchups[] = "⚠️ Domicile : {$weakness}";
        }
        foreach ($matchup['awayProfile']['weaknesses'] as $weakness) {
            $keyMatchups[] = "⚠️ Extérieur : {$weakness}";
        }
        
        // Score tactique (basé sur avantages nets)
        $tacticalScore = 50 + 
            ($matchup['netOffensiveAdvantage'] * 1.5) + 
            ($matchup['netDefensiveAdvantage'] * 1.2);
        
        // Modificateurs clean sheet
        $cleanSheetModifier = $this->calculateCleanSheetModifiers($matchup);
        
        return [
            'tacticalScore' => (int) round(Layer2Coefficients::normalize($tacticalScore)),
            'homeAdvantages' => $homeAdvantages,
            'awayAdvantages' => $awayAdvantages,
            'keyMatchups' => $keyMatchups,
            'tacticalInsight' => $matchup['tacticalInsight'],
            'overModifier' => $matchup['overModifier'],
            'bttsModifier' => $matchup['bttsModifier'],
            'cleanSheetModifier' => $cleanSheetModifier,
            'confidence' => 85, // Confiance élevée si formations connues
        ];
    }

    /**
     * Analyse l'impact tactique d'une confrontation de formations
     */
    private function analyzeTacticalMatchup(string $homeFormation, string $awayFormation): array
    {
        $homeProfile = FormationProfiles::get($homeFormation);
        $awayProfile = FormationProfiles::get($awayFormation);
        
        // Calcul avantages nets
        $netOffensiveAdvantage = $homeProfile['offensiveImpact'] - $awayProfile['defensiveImpact'];
        $netDefensiveAdvantage = $homeProfile['defensiveImpact'] - $awayProfile['offensiveImpact'];
        
        // Modificateurs Over/BTTS de base
        $overModifier = $homeProfile['overLikelihood'] + $awayProfile['overLikelihood'];
        $bttsModifier = $homeProfile['bttsLikelihood'] + $awayProfile['bttsLikelihood'];
        
        // Détection patterns tactiques
        $tacticalInsight = $this->generateTacticalInsight(
            $homeProfile['style'],
            $awayProfile['style'],
            $overModifier,
            $bttsModifier
        );
        
        return [
            'homeProfile' => $homeProfile,
            'awayProfile' => $awayProfile,
            'netOffensiveAdvantage' => $netOffensiveAdvantage,
            'netDefensiveAdvantage' => $netDefensiveAdvantage,
            'tacticalInsight' => $tacticalInsight['insight'],
            'overModifier' => (int) round($tacticalInsight['overModifier']),
            'bttsModifier' => (int) round($tacticalInsight['bttsModifier']),
        ];
    }

    /**
     * Génère l'insight tactique selon les styles
     */
    private function generateTacticalInsight(string $homeStyle, string $awayStyle, float $overMod, float $bttsMod): array
    {
        $insight = '';
        
        if ($homeStyle === 'attacking' && $awayStyle === 'attacking') {
            $insight = '🔥 Duel offensif attendu - Over et BTTS fortement favorisés';
            $overMod += 10;
            $bttsMod += 15;
        } elseif ($homeStyle === 'defensive' && $awayStyle === 'defensive') {
            $insight = '🔒 Match fermé attendu - Under favorisé, peu de buts';
            $overMod -= 15;
            $bttsMod -= 10;
        } elseif ($homeStyle === 'attacking' && $awayStyle === 'defensive') {
            $insight = '⚔️ Attaque vs Défense - Match serré probable, domination sans efficacité';
        } elseif ($homeStyle === 'defensive' && $awayStyle === 'attacking') {
            $insight = '🛡️ Défense vs Attaque - Contre-attaques dangereuses, match ouvert possible';
        } else {
            $insight = '⚖️ Confrontation équilibrée - Match standard attendu';
        }
        
        return [
            'insight' => $insight,
            'overModifier' => $overMod,
            'bttsModifier' => $bttsMod,
        ];
    }

    /**
     * Calcule les modificateurs clean sheet
     */
    private function calculateCleanSheetModifiers(array $matchup): array
    {
        $csModifiers = Layer2Coefficients::TACTICAL_CLEAN_SHEET_MODIFIER;
        
        $homeCSBase = $matchup['homeProfile']['cleanSheetLikelihood'] ?? 0;
        $awayCSBase = $matchup['awayProfile']['cleanSheetLikelihood'] ?? 0;
        
        $homeCSModifier = $homeCSBase;
        $awayCSModifier = $awayCSBase;
        
        // Appliquer modificateurs selon style
        $homeStyle = $matchup['homeProfile']['style'];
        $awayStyle = $matchup['awayProfile']['style'];
        
        if ($homeStyle === 'defensive') {
            $homeCSModifier *= $csModifiers['DEFENSIVE'];
        } elseif ($homeStyle === 'attacking') {
            $homeCSModifier *= $csModifiers['ATTACKING'];
        } else {
            $homeCSModifier *= $csModifiers['BALANCED'];
        }
        
        if ($awayStyle === 'defensive') {
            $awayCSModifier *= $csModifiers['DEFENSIVE'];
        } elseif ($awayStyle === 'attacking') {
            $awayCSModifier *= $csModifiers['ATTACKING'];
        } else {
            $awayCSModifier *= $csModifiers['BALANCED'];
        }
        
        return [
            'home' => (int) round($homeCSModifier),
            'away' => (int) round($awayCSModifier),
        ];
    }
}