<?php

namespace App\Services\Betting;

/**
 * COEFFICIENTS PROFESSIONNELS - LAYER 2
 * Basés sur : Dixon-Coles (1997), Goddard (2005), Opta Standards, StatsBomb Research
 */
class Layer2Coefficients
{
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 1. ANALYSE FORME (Dixon & Coles 1997)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const FORM_POINTS = [
        'W_HOME' => 3.0,
        'W_AWAY' => 3.9,    // +30% car victoire extérieure plus difficile
        'D_HOME' => 1.0,
        'D_AWAY' => 1.3,    // +30% car match nul extérieur honorable
        'L_HOME' => 0.0,
        'L_AWAY' => -0.2,   // Légère pénalité mais moins grave qu'à domicile
    ];
    
    public const FORM_TIME_DECAY = 0.92;  // -8% par match d'ancienneté
    public const FORM_MAX_SCORE = 100;

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 2. ANALYSE BLESSURES (Opta + StatsBomb)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const INJURY_IMPACT = [
        'GK' => ['key' => -40, 'regular' => -16, 'rotation' => -6],
        'CB' => ['key' => -30, 'regular' => -12, 'rotation' => -5],
        'FB' => ['key' => -20, 'regular' => -8,  'rotation' => -3],
        'DM' => ['key' => -25, 'regular' => -10, 'rotation' => -4],
        'CM' => ['key' => -20, 'regular' => -8,  'rotation' => -3],
        'AM' => ['key' => -25, 'regular' => -10, 'rotation' => -4],
        'W'  => ['key' => -22, 'regular' => -9,  'rotation' => -3],
        'ST' => ['key' => -35, 'regular' => -14, 'rotation' => -5],
    ];
    
    public const INJURY_MULTIPLE_ABSENCES = [
        'TWO_KEY'   => -10,  // 2 joueurs clés absents
        'THREE_KEY' => -25,  // 3+ joueurs clés = catastrophe
    ];
    
    public const INJURY_BASE_SCORE = 100;

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 3. ANALYSE H2H (Goddard 2005)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const H2H_TIME_WEIGHTS = [
        'LAST_6_MONTHS' => 1.0,
        'LAST_1_YEAR'   => 0.8,
        'LAST_2_YEARS'  => 0.5,
        'LAST_3_YEARS'  => 0.3,
        'OLDER'         => 0.0,  // H2H > 3 ans ignoré
    ];
    
    public const H2H_LOCATION_WEIGHTS = [
        'SAME_VENUE'  => 1.0,
        'AWAY_VENUE'  => 0.3,
        'NEUTRAL'     => 0.5,
    ];
    
    public const H2H_DOMINANCE_BONUS = [
        'VERY_STRONG'  => 20,   // >70% victoires domicile
        'STRONG'       => 10,   // 60-70%
        'SLIGHT'       => 5,    // 50-60%
        'NEUTRAL'      => 0,    // 40-50%
        'DISADVANTAGE' => -10,  // <40%
    ];
    
    public const H2H_BASE_SCORE = 50;

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 4. ANALYSE TACTIQUE (StatsBomb Research)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const TACTICAL_STYLE_MATCHUP = [
        'ATTACKING_VS_ATTACKING' => [
            'overBonus' => 10,
            'bttsBonus' => 15,
            'description' => 'Duel offensif - Buts attendus',
        ],
        'ATTACKING_VS_DEFENSIVE' => [
            'overBonus' => 0,
            'bttsBonus' => -5,
            'description' => 'Attaque vs défense - Match serré',
        ],
        'DEFENSIVE_VS_DEFENSIVE' => [
            'overBonus' => -15,
            'bttsBonus' => -10,
            'description' => 'Match fermé - Peu de buts',
        ],
        'BALANCED_VS_BALANCED' => [
            'overBonus' => 0,
            'bttsBonus' => 0,
            'description' => 'Match équilibré - Standard',
        ],
    ];
    
    public const TACTICAL_CLEAN_SHEET_MODIFIER = [
        'DEFENSIVE' => 1.25,   // +25% probabilité clean sheet
        'BALANCED'  => 1.0,
        'ATTACKING' => 0.85,   // -15% probabilité clean sheet
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 5. ANALYSE xG (Poisson + StatsBomb)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const XG_RELIABILITY = [
        'GAMES_5'  => 0.60,
        'GAMES_10' => 0.80,
        'GAMES_15' => 0.90,
        'GAMES_20' => 0.95,
    ];
    
    public const XG_THRESHOLDS = [
        'VERY_HIGH' => 2.0,
        'HIGH'      => 1.5,
        'MEDIUM'    => 1.0,
        'LOW'       => 0.7,
        'VERY_LOW'  => 0.5,
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 6. ANALYSE CONTEXTE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const CONTEXT_IMPORTANCE_IMPACT = [
        'CRITICAL' => 1.20,  // Finale, derby, maintien
        'HIGH'     => 1.10,
        'MEDIUM'   => 1.0,
        'LOW'      => 0.95,
    ];
    
    public const CONTEXT_REST_IMPACT = [
        'WELL_RESTED' => 5,    // 7+ jours
        'NORMAL'      => 0,    // 4-6 jours
        'TIRED'       => -5,   // 3 jours
        'VERY_TIRED'  => -10,  // ≤2 jours
    ];
    
    public const CONTEXT_REST_DIFFERENTIAL_BONUS = 2;  // +2 pts par jour de différence
    
    public const CONTEXT_WEATHER_IMPACT = [
        'EXTREME' => -10,
        'MINOR'   => -3,
        'NONE'    => 0,
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 7. PONDÉRATION GLOBALE DES DIMENSIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const GLOBAL_WEIGHTS = [
        'injuries'        => 0.28,  // 28% - LE PLUS IMPORTANT
        'form'            => 0.22,  // 22%
        'xg'              => 0.18,  // 18%
        'tactical'        => 0.15,  // 15%
        'h2h'             => 0.08,  // 8%
        'context'         => 0.07,  // 7%
        'league_position' => 0.02,  // 2%
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 8. SEUILS DE CONFIANCE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const CONFIDENCE_THRESHOLDS = [
        'VERY_HIGH' => 85,
        'HIGH'      => 75,
        'MEDIUM'    => 65,
        'LOW'       => 55,
        'VERY_LOW'  => 45,
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 9. CONVERGENCE LAYER 1 / LAYER 2
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    public const CONVERGENCE_THRESHOLDS = [
        'HIGH'   => 5,    // ≤5 points d'écart
        'MEDIUM' => 15,   // 6-15 points
        'LOW'    => 30,   // >15 points
    ];
    
    public const CONVERGENCE_BONUS = [
        'HIGH'   => 3,
        'MEDIUM' => 0,
        'LOW'    => -5,
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 10. MÉTHODES UTILITAIRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    /**
     * Normaliser un score entre 0 et 100
     */
    public static function normalize(float $value): float
    {
        return max(0, min(100, $value));
    }
    
    /**
     * Calculer moyenne pondérée
     */
    public static function weightedAverage(array $values, array $weights): float
    {
        if (count($values) !== count($weights)) {
            throw new \InvalidArgumentException('Values and weights must have same length');
        }
        
        $sum = 0;
        $totalWeight = 0;
        
        foreach ($values as $i => $val) {
            $sum += $val * $weights[$i];
            $totalWeight += $weights[$i];
        }
        
        return $totalWeight > 0 ? $sum / $totalWeight : 0;
    }
    
    /**
     * Convertir streak string en tableau
     */
    public static function parseStreak(string $streak): array
    {
        return str_split($streak);
    }
    
    /**
     * Calculer jours depuis une date
     */
    public static function daysSince(string $dateString): int
    {
        $matchDate = new \DateTime($dateString);
        $now = new \DateTime();
        return (int) $now->diff($matchDate)->days;
    }
    
    /**
     * Probabilité Poisson P(X≥1) = 1 - e^(-xG)
     */
    public static function poissonProbability(float $xg): float
    {
        return 1 - exp(-$xg);
    }
    
    /**
     * Obtenir le niveau de convergence
     */
    public static function getConvergenceLevel(float $layer1Score, float $layer2Score): string
    {
        $diff = abs($layer1Score - $layer2Score);
        
        if ($diff <= self::CONVERGENCE_THRESHOLDS['HIGH']) {
            return 'HIGH';
        } elseif ($diff <= self::CONVERGENCE_THRESHOLDS['MEDIUM']) {
            return 'MEDIUM';
        }
        return 'LOW';
    }
    
    /**
     * Obtenir le bonus de convergence
     */
    public static function getConvergenceBonus(string $level): int
    {
        return self::CONVERGENCE_BONUS[$level] ?? 0;
    }
    
    /**
     * Obtenir le label de confiance
     */
    public static function getConfidenceLabel(float $score): string
    {
        if ($score >= self::CONFIDENCE_THRESHOLDS['VERY_HIGH']) return 'VERY_HIGH';
        if ($score >= self::CONFIDENCE_THRESHOLDS['HIGH']) return 'HIGH';
        if ($score >= self::CONFIDENCE_THRESHOLDS['MEDIUM']) return 'MEDIUM';
        if ($score >= self::CONFIDENCE_THRESHOLDS['LOW']) return 'LOW';
        return 'VERY_LOW';
    }
}