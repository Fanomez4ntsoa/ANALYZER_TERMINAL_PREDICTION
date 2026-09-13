<?php

namespace App\Services\Probability;

/**
 * Distribution des scores de football à partir de λ domicile et λ extérieur.
 *
 * $rho = null : deux lois de Poisson indépendantes (Maher, 1982), comportement
 * historique conservé à l'identique pour reproduire les runs antérieurs.
 *
 * $rho = float : correction de Dixon & Coles (1997) sur les scores faibles.
 *   P(x, y) = τ(x, y) · Poisson(x; λ) · Poisson(y; μ), puis renormalisation de la
 *   matrice tronquée, avec
 *   τ(0,0) = 1 − λμρ   τ(0,1) = 1 + λρ   τ(1,0) = 1 + μρ   τ(1,1) = 1 − ρ
 *   et τ = 1 ailleurs. Les quatre corrections s'annulent en somme et portent
 *   toutes sur des scores d'au plus deux buts : P(Under 2.5) et P(Under 3.5) ne
 *   dépendent pas de ρ à λ fixés ; le nul, le BTTS et l'O/U 1.5 en dépendent.
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
    public function scoreMatrix(float $homeLambda, float $awayLambda, ?float $rho = null): array
    {
        $matrix = [];

        for ($h = 0; $h <= self::MAX_GOALS; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS; $a++) {
                $matrix[$h][$a] = $this->poisson($h, $homeLambda) * $this->poisson($a, $awayLambda);
            }
        }

        if ($rho === null) {
            return $matrix;
        }

        $matrix[0][0] *= self::tau(0, 0, $homeLambda, $awayLambda, $rho);
        $matrix[0][1] *= self::tau(0, 1, $homeLambda, $awayLambda, $rho);
        $matrix[1][0] *= self::tau(1, 0, $homeLambda, $awayLambda, $rho);
        $matrix[1][1] *= self::tau(1, 1, $homeLambda, $awayLambda, $rho);

        $total = 0.0;
        foreach ($matrix as $row) {
            $total += array_sum($row);
        }
        if ($total > 0) {
            foreach ($matrix as $h => $row) {
                foreach ($row as $a => $p) {
                    $matrix[$h][$a] = $p / $total;
                }
            }
        }

        return $matrix;
    }

    /**
     * Facteur de Dixon-Coles τ(x, y). Borné à 0 : un ρ hors du domaine valide pour
     * ces λ ne produit jamais de probabilité négative.
     */
    public static function tau(int $homeGoals, int $awayGoals, float $homeLambda, float $awayLambda, float $rho): float
    {
        $t = match (true) {
            $homeGoals === 0 && $awayGoals === 0 => 1 - $homeLambda * $awayLambda * $rho,
            $homeGoals === 0 && $awayGoals === 1 => 1 + $homeLambda * $rho,
            $homeGoals === 1 && $awayGoals === 0 => 1 + $awayLambda * $rho,
            $homeGoals === 1 && $awayGoals === 1 => 1 - $rho,
            default => 1.0,
        };

        return max(0.0, $t);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PROBABILITÉS 1X2
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer les probabilités Home / Draw / Away.
     *
     * @return array ['home' => float, 'draw' => float, 'away' => float] (0-100)
     */
    public function predict1X2(float $homeLambda, float $awayLambda, ?float $rho = null): array
    {
        $p = $this->probabilities1X2($homeLambda, $awayLambda, $rho);

        return [
            'home' => round($p['home'] * 100, 1),
            'draw' => round($p['draw'] * 100, 1),
            'away' => round($p['away'] * 100, 1),
        ];
    }

    /**
     * Probabilités 1X2 non arrondies (0-1), normalisées sur la matrice tronquée.
     * Sert aux ajustements fins sur les cotes ; predict1X2 en est l'arrondi.
     *
     * @return array ['home' => float, 'draw' => float, 'away' => float]
     */
    public function probabilities1X2(float $homeLambda, float $awayLambda, ?float $rho = null): array
    {
        $matrix = $this->scoreMatrix($homeLambda, $awayLambda, $rho);

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

        $total = $home + $draw + $away;
        if ($total <= 0) {
            return ['home' => 1 / 3, 'draw' => 1 / 3, 'away' => 1 / 3];
        }

        return ['home' => $home / $total, 'draw' => $draw / $total, 'away' => $away / $total];
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
    public function predictOverUnder(float $homeLambda, float $awayLambda, float $line = 2.5, ?float $rho = null): array
    {
        $matrix = $this->scoreMatrix($homeLambda, $awayLambda, $rho);
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
    public function predictBTTS(float $homeLambda, float $awayLambda, ?float $rho = null): array
    {
        if ($rho !== null) {
            $matrix = $this->scoreMatrix($homeLambda, $awayLambda, $rho);
            $bttsYes = 0.0;
            for ($h = 1; $h <= self::MAX_GOALS; $h++) {
                for ($a = 1; $a <= self::MAX_GOALS; $a++) {
                    $bttsYes += $matrix[$h][$a];
                }
            }

            return [
                'yes' => round($bttsYes * 100, 1),
                'no' => round((1 - $bttsYes) * 100, 1),
            ];
        }

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
    public function predictDoubleChance(float $homeLambda, float $awayLambda, ?float $rho = null): array
    {
        $p = $this->predict1X2($homeLambda, $awayLambda, $rho);

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
    public function topScores(float $homeLambda, float $awayLambda, int $top = 5, ?float $rho = null): array
    {
        $matrix = $this->scoreMatrix($homeLambda, $awayLambda, $rho);
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
    public function fullAnalysis(float $homeLambda, float $awayLambda, ?float $rho = null): array
    {
        $p1x2 = $this->predict1X2($homeLambda, $awayLambda, $rho);
        $ou25 = $this->predictOverUnder($homeLambda, $awayLambda, 2.5, $rho);
        $btts = $this->predictBTTS($homeLambda, $awayLambda, $rho);
        $dc = $this->predictDoubleChance($homeLambda, $awayLambda, $rho);
        $topScores = $this->topScores($homeLambda, $awayLambda, 5, $rho);

        $totalExpected = round($homeLambda + $awayLambda, 2);

        return [
            'lambdas' => ['home' => $homeLambda, 'away' => $awayLambda],
            'rho' => $rho,
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
