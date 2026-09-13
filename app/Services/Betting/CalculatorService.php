<?php

namespace App\Services\Betting;

/**
 * Calcul de confiance pondérée et score de priorité.
 * Sources : D (modèle maison), E (API-Football), A/B/C (optionnelles)
 */
class CalculatorService
{
    private array $reliability;

    public function __construct()
    {
        $this->reliability = config('reliability.sources');
    }

    /**
     * Confiance pondérée par la fiabilité de chaque source.
     */
    public function calculateWeightedConfidence(string $market, array $predictions): float
    {
        $totalWeight = 0;
        $totalConfidence = 0;

        foreach ($predictions as $pred) {
            $source = $pred['source'];
            $reliability = $this->reliability[$source][$market] ?? 0.5;
            $confidence = $pred['confidence'];

            $totalConfidence += $confidence * $reliability;
            $totalWeight += $reliability;
        }

        return $totalWeight > 0 ? $totalConfidence / $totalWeight : 50;
    }

    /**
     * Score de priorité (0-100).
     */
    public function calculateScore(
        array $consensus,
        float $confidence,
        ?array $specialRule,
        string $market,
        bool $coherent
    ): int {
        $score = 0;

        // Consensus
        if ($consensus['type'] === 'TOTAL') {
            $score += 25;
        } elseif ($consensus['type'] === 'MAJORITÉ') {
            $score += 22;
        }

        // Confiance (max 25 pts)
        $score += $confidence * 0.25;

        // Cohérence (10 pts)
        if ($coherent) {
            $score += 10;
        }

        // Fiabilité du marché (max 15 pts)
        $score += $this->getAverageReliability($market) * 15;

        return (int) max(0, min(100, round($score)));
    }

    public function getAverageReliability(string $market): float
    {
        $reliabilities = [];
        foreach ($this->reliability as $markets) {
            $reliabilities[] = $markets[$market] ?? 0;
        }

        return count($reliabilities) > 0 ? array_sum($reliabilities) / count($reliabilities) : 0.5;
    }

    public function getReliability(string $source, string $market): float
    {
        return $this->reliability[$source][$market] ?? 0.5;
    }
}
