<?php

namespace App\Services\Probability;

/**
 * Distribution de Poisson pour prédire les scores de football.
 *
 * Base théorique : Dixon & Coles (1997) — chaque équipe marque
 * indépendamment selon une distribution de Poisson avec paramètre λ (xG).
 *
 * P(X=k) = (λ^k * e^-λ) / k!
 */
class PoissonModelService
{
    // Score max à considérer dans la matrice (0..MAX_GOALS)
    private const MAX_GOALS = 6;

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MATRICE DE SCORES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer la matrice complète des probabilités de score.
     *
     * @param float $homeLambda xG attendu de l'équipe domicile
     * @param float $awayLambda xG attendu de l'équipe extérieur
     * @return array Matrice [home_goals][away_goals] => probability
     */
    public function scoreMatrix(float $homeLambda, float $awayLambda): array
    {
        $matrix = [];

        for ($h = 0; $h <= self::MAX_GOALS; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS; $a++) {
                $matrix[$h][$a] = $this->poisson($h, $homeLambda) * $this->poisson($a, $awayLambda);
            }
        }

        return $matrix;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PROBABILITÉS 1X2
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer les probabilités Home / Draw / Away.
     *
     * @return array ['home' => float, 'draw' => float, 'away' => float] (0-100)
     */
    public function predict1X2(float $homeLambda, float $awayLambda): array
    {
        $matrix = $this->scoreMatrix($homeLambda, $awayLambda);

        $home = 0;
        $draw = 0;
        $away = 0;

        for ($h = 0; $h <= self::MAX_GOALS; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS; $a++) {
                $p = $matrix[$h][$a];
                if ($h > $a) $home += $p;
                elseif ($h === $a) $draw += $p;
                else $away += $p;
            }
        }

        // Normaliser à 100%
        $total = $home + $draw + $away;
        if ($total <= 0) {
            return ['home' => 33.3, 'draw' => 33.3, 'away' => 33.3];
        }

        return [
            'home' => round(($home / $total) * 100, 1),
            'draw' => round(($draw / $total) * 100, 1),
            'away' => round(($away / $total) * 100, 1),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // OVER / UNDER
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer P(Over X.5) et P(Under X.5).
     *
     * @param float $line Ligne de buts (ex: 2.5, 1.5, 3.5)
     * @return array ['over' => float, 'under' => float] (0-100)
     */
    public function predictOverUnder(float $homeLambda, float $awayLambda, float $line = 2.5): array
    {
        $matrix = $this->scoreMatrix($homeLambda, $awayLambda);
        $threshold = (int) floor($line);

        $under = 0;

        for ($h = 0; $h <= self::MAX_GOALS; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS; $a++) {
                if (($h + $a) <= $threshold) {
                    $under += $matrix[$h][$a];
                }
            }
        }

        return [
            'over' => round((1 - $under) * 100, 1),
            'under' => round($under * 100, 1),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // BTTS (Both Teams To Score)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer P(BTTS Yes) et P(BTTS No).
     *
     * BTTS Yes = les deux équipes marquent ≥ 1 but
     * BTTS No  = au moins une équipe ne marque pas
     *
     * @return array ['yes' => float, 'no' => float] (0-100)
     */
    public function predictBTTS(float $homeLambda, float $awayLambda): array
    {
        // P(Home ≥ 1) = 1 - P(Home = 0)
        // P(Away ≥ 1) = 1 - P(Away = 0)
        // P(BTTS) = P(Home ≥ 1) * P(Away ≥ 1) — indépendance Poisson
        $pHomeScores = 1 - $this->poisson(0, $homeLambda);
        $pAwayScores = 1 - $this->poisson(0, $awayLambda);
        $bttsYes = $pHomeScores * $pAwayScores;

        return [
            'yes' => round($bttsYes * 100, 1),
            'no' => round((1 - $bttsYes) * 100, 1),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // DOUBLE CHANCE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer les probabilités Double Chance.
     *
     * @return array ['1X' => float, '12' => float, 'X2' => float] (0-100)
     */
    public function predictDoubleChance(float $homeLambda, float $awayLambda): array
    {
        $p = $this->predict1X2($homeLambda, $awayLambda);

        return [
            '1X' => round($p['home'] + $p['draw'], 1),
            '12' => round($p['home'] + $p['away'], 1),
            'X2' => round($p['draw'] + $p['away'], 1),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SCORES EXACTS LES PLUS PROBABLES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Retourner les N scores les plus probables.
     *
     * @param int $top Nombre de scores à retourner
     * @return array [['score' => '1-0', 'probability' => 12.3], ...]
     */
    public function topScores(float $homeLambda, float $awayLambda, int $top = 5): array
    {
        $matrix = $this->scoreMatrix($homeLambda, $awayLambda);
        $scores = [];

        for ($h = 0; $h <= self::MAX_GOALS; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS; $a++) {
                $scores[] = [
                    'score' => "{$h}-{$a}",
                    'probability' => round($matrix[$h][$a] * 100, 2),
                ];
            }
        }

        usort($scores, fn($a, $b) => $b['probability'] <=> $a['probability']);

        return array_slice($scores, 0, $top);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ANALYSE COMPLÈTE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Analyse Poisson complète pour un match.
     *
     * @return array Toutes les prédictions dérivées
     */
    public function fullAnalysis(float $homeLambda, float $awayLambda): array
    {
        $p1x2 = $this->predict1X2($homeLambda, $awayLambda);
        $ou25 = $this->predictOverUnder($homeLambda, $awayLambda, 2.5);
        $btts = $this->predictBTTS($homeLambda, $awayLambda);
        $dc = $this->predictDoubleChance($homeLambda, $awayLambda);
        $topScores = $this->topScores($homeLambda, $awayLambda);

        $totalExpected = round($homeLambda + $awayLambda, 2);

        return [
            'lambdas' => ['home' => $homeLambda, 'away' => $awayLambda],
            'totalExpected' => $totalExpected,
            '1x2' => $p1x2,
            'overUnder25' => $ou25,
            'btts' => $btts,
            'doubleChance' => $dc,
            'topScores' => $topScores,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FONCTION DE POISSON
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * P(X = k) pour une distribution de Poisson.
     *
     * @param int   $k      Nombre d'événements
     * @param float $lambda  Taux moyen attendu
     * @return float Probabilité (0-1)
     */
    private function poisson(int $k, float $lambda): float
    {
        if ($lambda <= 0) {
            return $k === 0 ? 1.0 : 0.0;
        }

        return (pow($lambda, $k) * exp(-$lambda)) / $this->factorial($k);
    }

    /**
     * Factorielle (optimisée pour k ≤ MAX_GOALS).
     */
    private function factorial(int $n): int
    {
        static $cache = [1, 1, 2, 6, 24, 120, 720];

        return $cache[$n] ?? array_product(range(1, $n));
    }
}
