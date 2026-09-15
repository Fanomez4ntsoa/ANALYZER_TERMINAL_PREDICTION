<?php

namespace App\Services\Probability;

/**
 * Probabilités équitables d'un ensemble de cotes d'un même bookmaker : 1 / cote,
 * normalisé pour sommer à 1 (marge retirée). Utilisé pour la cote du calcul
 * (PredictionService) et pour la clôture Pinnacle (journal des sélections).
 */
class FairProbabilities
{
    /**
     * Retourne null si une cote de l'ensemble manque ou n'est pas une cote (≤ 1).
     *
     * @param array<string, float|null> $oddsSet
     * @return array<string, float>|null
     */
    public static function fromOdds(array $oddsSet): ?array
    {
        foreach ($oddsSet as $odd) {
            if ($odd === null || (float) $odd <= 1.0) {
                return null;
            }
        }

        $raw = array_map(fn ($odd) => 1 / (float) $odd, $oddsSet);
        $overround = array_sum($raw);

        if ($overround <= 0) {
            return null;
        }

        return array_map(fn (float $p) => $p / $overround, $raw);
    }

    /**
     * Double chance dérivée d'un 1X2 équitable, jamais de ses propres cotes.
     *
     * @param array{1: float, X: float, 2: float}|null $fair1x2
     */
    public static function doubleChance(?array $fair1x2): ?array
    {
        return $fair1x2 === null ? null : [
            '1X' => $fair1x2['1'] + $fair1x2['X'],
            'X2' => $fair1x2['X'] + $fair1x2['2'],
            '12' => $fair1x2['1'] + $fair1x2['2'],
        ];
    }
}
