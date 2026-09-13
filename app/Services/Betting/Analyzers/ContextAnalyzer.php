<?php

namespace App\Services\Betting\Analyzers;

use App\Services\Betting\Layer2Coefficients;

/**
 * ANALYSEUR CONTEXTE — Version enrichie
 *
 * Dimensions analysées :
 *   1. Importance / Enjeu du match (titre, relégation, etc.)
 *   2. Repos / Jours de récupération
 *   3. Météo (vent, pluie, température)
 *   4. Fatigue calendaire (matchs sur 7/14/21 jours)
 *   5. Arbitre (style, cartons, penalties)
 *   6. Pression entraîneur (série de défaites)
 */
class ContextAnalyzer
{
    /**
     * Analyse le contexte du match.
     */
    public function analyze(array $context): array
    {
        $insights = [];
        $warnings = [];

        // Dimensions de base (existantes)
        $importance = $context['importance'] ?? 'medium';
        $reason = $context['reason'] ?? null;
        $homeRestDays = $context['restDays']['home'] ?? 5;
        $awayRestDays = $context['restDays']['away'] ?? 5;
        $weather = $context['weather'] ?? null;

        // Nouvelles dimensions enrichies
        $fatigue = $context['fatigue'] ?? null;
        $stakes = $context['stakes'] ?? null;
        $referee = $context['referee'] ?? null;
        $coachPressure = $context['coachPressure'] ?? null;

        $contextScore = 50; // Neutre

        // 1. Enjeu (original + enrichi)
        if ($stakes && ($stakes['available'] ?? false)) {
            $stakesEval = $this->evaluateStakes($stakes);
            $insights[] = $stakesEval['insight'];
            $contextScore += $stakesEval['impact'];
            if ($stakesEval['warning']) {
                $warnings[] = $stakesEval['warning'];
            }
        } else {
            $importanceEval = $this->evaluateImportance($importance, $reason);
            $insights[] = $importanceEval['insight'];
            $contextScore += $importanceEval['impact'] * 0.3;
        }

        // 2. Repos
        $restEval = $this->evaluateRest($homeRestDays, $awayRestDays);
        $insights[] = $restEval['insight'];
        $contextScore += $restEval['advantage'];

        if ($homeRestDays <= 2) {
            $warnings[] = "Domicile tres fatigue ({$homeRestDays}j repos)";
        }
        if ($awayRestDays <= 2) {
            $warnings[] = "Exterieur tres fatigue ({$awayRestDays}j repos)";
        }

        // 3. Météo (enrichie avec modifieurs)
        $weatherEval = $this->evaluateWeatherEnriched($weather);
        if (!empty($weatherEval['insight'])) {
            $insights[] = $weatherEval['insight'];
        }
        $contextScore += $weatherEval['impact'];
        if ($weatherEval['warning']) {
            $warnings[] = $weatherEval['warning'];
        }

        // 4. Fatigue calendaire (NOUVEAU)
        if ($fatigue && ($fatigue['available'] ?? false)) {
            $fatigueEval = $this->evaluateFatigue($fatigue);
            $insights[] = $fatigueEval['insight'];
            $contextScore += $fatigueEval['impact'];
            if ($fatigueEval['warning']) {
                $warnings[] = $fatigueEval['warning'];
            }
        }

        // 5. Arbitre (NOUVEAU)
        if ($referee && ($referee['available'] ?? false)) {
            $refereeEval = $this->evaluateReferee($referee);
            $insights[] = $refereeEval['insight'];
            $contextScore += $refereeEval['impact'];
        }

        // 6. Pression entraîneur (NOUVEAU)
        if ($coachPressure && ($coachPressure['available'] ?? false)) {
            $pressureEval = $this->evaluateCoachPressure($coachPressure);
            $insights[] = $pressureEval['insight'];
            $contextScore += $pressureEval['impact'];
            if ($pressureEval['warning']) {
                $warnings[] = $pressureEval['warning'];
            }
        }

        $contextScore = (int) round(Layer2Coefficients::normalize($contextScore));

        return [
            'contextScore' => $contextScore,
            'importanceImpact' => $stakes['motivation_delta'] ?? 0,
            'restAdvantage' => (int) round($restEval['advantage']),
            'weatherImpact' => $weatherEval['impact'],
            'fatigueAdvantage' => $fatigue['advantage'] ?? 0,
            'insights' => $insights,
            'warnings' => $warnings,
            'overallInsight' => $this->generateOverallInsight($importance, $restEval['advantage'], $fatigue, $coachPressure),
            'confidence' => 90,
            // Modifieurs pour les marchés (consommés par Layer2Service)
            'overModifier' => $weatherEval['over_modifier'] + ($referee['impact']['over_modifier'] ?? 0),
            'bttsModifier' => $weatherEval['btts_modifier'],
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ÉVALUATEURS DE DIMENSIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function evaluateStakes(array $stakes): array
    {
        $homeStake = $stakes['home']['stake'] ?? 'unknown';
        $awayStake = $stakes['away']['stake'] ?? 'unknown';
        $homeDesc = $stakes['home']['description'] ?? '';
        $awayDesc = $stakes['away']['description'] ?? '';
        $homeMot = $stakes['home']['motivation'] ?? 50;
        $awayMot = $stakes['away']['motivation'] ?? 50;

        $impact = ($homeMot - $awayMot) * 0.1; // Différentiel de motivation → impact domicile
        $warning = null;

        $insight = "Enjeu: {$homeDesc} vs {$awayDesc}";

        if ($homeStake === 'relegation' || $awayStake === 'relegation') {
            $warning = "Match de maintien - imprevisibilite accrue";
        }
        if (abs($homeMot - $awayMot) >= 30) {
            $side = $homeMot > $awayMot ? 'domicile' : 'exterieur';
            $insight .= " | Fort desequilibre motivation ({$side})";
        }

        return ['insight' => $insight, 'impact' => $impact, 'warning' => $warning];
    }

    private function evaluateImportance(string $importance, ?string $reason): array
    {
        $impactCoeffs = Layer2Coefficients::CONTEXT_IMPORTANCE_IMPACT;
        $reasonText = $reason ? " ({$reason})" : '';

        return match ($importance) {
            'critical' => ['impact' => ($impactCoeffs['CRITICAL'] - 1) * 100, 'insight' => "Enjeu CRITIQUE{$reasonText}"],
            'high' => ['impact' => ($impactCoeffs['HIGH'] - 1) * 100, 'insight' => "Enjeu IMPORTANT{$reasonText}"],
            'low' => ['impact' => ($impactCoeffs['LOW'] - 1) * 100, 'insight' => "Enjeu faible{$reasonText}"],
            default => ['impact' => 0, 'insight' => "Match standard"],
        };
    }

    private function evaluateRest(int $homeDays, int $awayDays): array
    {
        $restImpact = Layer2Coefficients::CONTEXT_REST_IMPACT;
        $diffBonus = Layer2Coefficients::CONTEXT_REST_DIFFERENTIAL_BONUS;

        $homeImpact = match (true) {
            $homeDays >= 7 => $restImpact['WELL_RESTED'],
            $homeDays >= 4 => $restImpact['NORMAL'],
            $homeDays >= 3 => $restImpact['TIRED'],
            default => $restImpact['VERY_TIRED'],
        };

        $awayImpact = match (true) {
            $awayDays >= 7 => $restImpact['WELL_RESTED'],
            $awayDays >= 4 => $restImpact['NORMAL'],
            $awayDays >= 3 => $restImpact['TIRED'],
            default => $restImpact['VERY_TIRED'],
        };

        $daysDiff = abs($homeDays - $awayDays);
        $diffAdvantage = $daysDiff * $diffBonus;
        $netAdvantage = $homeImpact - $awayImpact;
        $netAdvantage += ($homeDays > $awayDays) ? $diffAdvantage : -$diffAdvantage;

        $insight = "Repos: {$homeDays}j vs {$awayDays}j";
        if ($daysDiff >= 3) {
            $side = $homeDays > $awayDays ? 'domicile' : 'exterieur';
            $insight .= " | Avantage {$side}";
        }

        return ['homeImpact' => $homeImpact, 'awayImpact' => $awayImpact, 'advantage' => $netAdvantage, 'insight' => $insight];
    }

    private function evaluateWeatherEnriched(?array $weather): array
    {
        if (!$weather || ($weather['condition'] ?? 'unknown') === 'unknown') {
            return ['impact' => 0, 'insight' => '', 'warning' => null, 'over_modifier' => 0, 'btts_modifier' => 0];
        }

        $impact = match ($weather['impact'] ?? 'none') {
            'significant' => Layer2Coefficients::CONTEXT_WEATHER_IMPACT['EXTREME'],
            'minor' => Layer2Coefficients::CONTEXT_WEATHER_IMPACT['MINOR'],
            default => 0,
        };

        $warning = null;
        if (($weather['impact'] ?? 'none') === 'significant') {
            $warning = "Meteo extreme ({$weather['description']})";
        }

        return [
            'impact' => $impact,
            'insight' => $weather['description'] ?? '',
            'warning' => $warning,
            'over_modifier' => $weather['over_modifier'] ?? 0,
            'btts_modifier' => $weather['btts_modifier'] ?? 0,
        ];
    }

    private function evaluateFatigue(array $fatigue): array
    {
        $homeFatigue = $fatigue['home']['fatigue_score'] ?? 0;
        $awayFatigue = $fatigue['away']['fatigue_score'] ?? 0;
        $advantage = $fatigue['advantage'] ?? 0;

        $impact = $advantage * 0.15; // Conversion en points de score contexte
        $warning = null;

        $homeM14 = $fatigue['home']['matches_14d'] ?? 0;
        $awayM14 = $fatigue['away']['matches_14d'] ?? 0;

        $insight = "Charge calendaire 14j: {$homeM14} vs {$awayM14} matchs";

        if ($fatigue['home']['european'] ?? false) {
            $insight .= " | Domicile joue en Europe";
        }
        if ($fatigue['away']['european'] ?? false) {
            $insight .= " | Exterieur joue en Europe";
        }

        if ($homeFatigue >= 50 || $awayFatigue >= 50) {
            $side = $homeFatigue > $awayFatigue ? 'Domicile' : 'Exterieur';
            $warning = "{$side} en surcharge calendaire (score fatigue: " . max($homeFatigue, $awayFatigue) . "/100)";
        }

        return ['impact' => $impact, 'insight' => $insight, 'warning' => $warning];
    }

    private function evaluateReferee(array $referee): array
    {
        $style = $referee['style'] ?? 'moderate';
        $yellows = $referee['yellow_per_game'] ?? 4;
        $name = $referee['name'] ?? 'Inconnu';

        $impact = match ($style) {
            'strict' => -2, // Jeu plus haché = moins de buts
            'lenient' => 2,  // Jeu fluide = plus de buts
            default => 0,
        };

        $insight = "Arbitre: {$name} ({$style}, {$yellows} jaunes/match)";

        return ['impact' => $impact, 'insight' => $insight];
    }

    private function evaluateCoachPressure(array $pressure): array
    {
        $homePressure = $pressure['home'] ?? [];
        $awayPressure = $pressure['away'] ?? [];
        $homeScore = $homePressure['score'] ?? 0;
        $awayScore = $awayPressure['score'] ?? 0;

        $impact = ($awayScore - $homeScore) * 0.08; // Pression adverse = avantage domicile
        $warning = null;

        $homeDesc = $homePressure['description'] ?? '';
        $awayDesc = $awayPressure['description'] ?? '';

        $insight = "Pression: {$homeDesc} vs {$awayDesc}";

        if ($homeScore >= 50) {
            $warning = "Coach domicile sous forte pression ({$homePressure['consecutive_losses']} defaites)";
        }
        if ($awayScore >= 50) {
            $warning = "Coach exterieur sous forte pression ({$awayPressure['consecutive_losses']} defaites)";
        }

        return ['impact' => $impact, 'insight' => $insight, 'warning' => $warning];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function generateOverallInsight(string $importance, float $restAdvantage, ?array $fatigue, ?array $pressure): string
    {
        $parts = [];

        if ($importance === 'critical') {
            $parts[] = "Contexte haute pression";
        }

        if (abs($restAdvantage) > 10) {
            $side = $restAdvantage > 0 ? 'domicile' : 'exterieur';
            $parts[] = "Avantage repos {$side}";
        }

        if ($fatigue && ($fatigue['available'] ?? false) && abs($fatigue['advantage'] ?? 0) > 20) {
            $side = ($fatigue['advantage'] ?? 0) > 0 ? 'domicile' : 'exterieur';
            $parts[] = "Avantage calendaire {$side}";
        }

        $homeP = $pressure['home']['score'] ?? 0;
        $awayP = $pressure['away']['score'] ?? 0;
        if ($homeP >= 50 || $awayP >= 50) {
            $parts[] = "Coach sous pression";
        }

        return implode(' | ', $parts) ?: "Contexte equilibre";
    }
}
