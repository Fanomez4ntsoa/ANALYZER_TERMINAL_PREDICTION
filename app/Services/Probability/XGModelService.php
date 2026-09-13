<?php

namespace App\Services\Probability;

use App\Models\FootballMatch;

/**
 * Modèle probabiliste maison basé sur les xG et données API-Football.
 *
 * Combine 4 signaux pour estimer les λ (expected goals) de chaque équipe :
 *   1. xG proxy (footystats_data) — données brutes du pipeline
 *   2. Comparaison API-Football (context_data.comparison) — 7 dimensions
 *   3. Probabilités implicites des cotes — reverse-engineering du marché
 *   4. Facteur domicile/extérieur — biais historique
 *
 * Les λ sont ensuite injectés dans PoissonModelService pour dériver
 * toutes les probabilités par marché.
 */
class XGModelService
{
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PONDÉRATIONS DES SIGNAUX
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    // Poids de chaque signal dans le calcul final des λ
    private const SIGNAL_WEIGHTS = [
        'xg_proxy'    => 0.20,  // xG brut du pipeline (peu granulaire, 3 buckets)
        'comparison'  => 0.30,  // Comparaison API-Football 7D (riche)
        'market'      => 0.40,  // Probabilités implicites des cotes (le plus fiable)
        'injuries'    => 0.10,  // Ajustement blessures
    ];

    // Avantage domicile moyen en football européen (études Dixon-Coles)
    private const HOME_ADVANTAGE = 1.20; // +20% d'xG pour le domicile
    private const AWAY_FACTOR = 0.88;    // -12% d'xG pour l'extérieur

    // xG moyen par équipe et par match (fallback si ligue inconnue)
    private const LEAGUE_AVG_XG = 1.40;

    // xG moyen par équipe par ligue (id API-Football → buts/match/équipe)
    // Calibration basée sur les moyennes historiques des saisons récentes
    private const LEAGUE_AVG_XG_BY_ID = [
        39  => 1.55, // Premier League
        78  => 1.60, // Bundesliga
        135 => 1.40, // Serie A
        140 => 1.45, // La Liga
        61  => 1.40, // Ligue 1
        88  => 1.55, // Eredivisie
        40  => 1.45, // Championship
        136 => 1.30, // Serie B
        62  => 1.30, // Ligue 2
        144 => 1.45, // Jupiler Pro League
        203 => 1.50, // Süper Lig
    ];

    // Seuil sous lequel un xG proxy est considéré comme "bucket dégénéré"
    // et remplacé par la moyenne de ligue (cf. footystats_data sortant 0.30)
    private const XG_PROXY_FLOOR = 0.5;

    private PoissonModelService $poisson;

    public function __construct(PoissonModelService $poisson)
    {
        $this->poisson = $poisson;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODE PRINCIPALE — Prédiction complète
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Générer les prédictions complètes pour un match.
     * C'est cette méthode qui sera appelée pour créer la Source D.
     */
    public function predict(FootballMatch $match): array
    {
        $advancedData = $match->advancedData;

        // 1. Estimer les λ depuis chaque signal
        $signals = $this->collectSignals($match, $advancedData);

        // 2. Fusionner les λ avec pondération
        $lambdas = $this->fuseLambdas($signals);

        // 3. Appliquer l'ajustement domicile/extérieur
        $lambdas = $this->applyHomeAdvantage($lambdas);

        // 4. Clamp les λ dans des bornes raisonnables
        $lambdas['home'] = max(0.3, min(3.5, $lambdas['home']));
        $lambdas['away'] = max(0.2, min(3.0, $lambdas['away']));

        // 5. Poisson → probabilités par marché
        $analysis = $this->poisson->fullAnalysis($lambdas['home'], $lambdas['away']);

        // 6. Construire les prédictions au format Source (pick + confidence)
        $predictions = $this->buildPredictions($analysis);

        return [
            'lambdas' => $lambdas,
            'signals' => $signals,
            'analysis' => $analysis,
            'predictions' => $predictions,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // COLLECTE DES SIGNAUX
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Moyenne d'xG par équipe pour la ligue (fallback générique sinon).
     */
    public function getLeagueAvgXg(?int $leagueId): float
    {
        if ($leagueId === null) {
            return self::LEAGUE_AVG_XG;
        }

        return self::LEAGUE_AVG_XG_BY_ID[$leagueId] ?? self::LEAGUE_AVG_XG;
    }

    /**
     * Collecter les λ estimés depuis chaque source de données.
     */
    private function collectSignals(FootballMatch $match, $advancedData): array
    {
        $leagueAvg = $this->getLeagueAvgXg($match->league_id);

        $signals = [];

        // Signal 1 : xG proxy du pipeline
        $signals['xg_proxy'] = $this->lambdasFromXGProxy($advancedData, $leagueAvg);

        // Signal 2 : Comparaison API-Football 7D
        $signals['comparison'] = $this->lambdasFromComparison($advancedData, $leagueAvg);

        // Signal 3 : Probabilités implicites des cotes bookmakers
        $signals['market'] = $this->lambdasFromOdds($match);

        // Signal 4 : Ajustement blessures
        $signals['injuries'] = $this->injuryModifier($advancedData);

        $signals['_league_avg'] = $leagueAvg;

        return $signals;
    }

    /**
     * Signal 1 : λ depuis les xG proxy (footystats_data).
     *
     * Le pipeline footystats produit des buckets dégénérés
     * ({0.0, 0.30, 0.90, 1.05, 1.35, 1.50}). Toute valeur sous le plancher
     * XG_PROXY_FLOOR est remplacée par la moyenne de ligue pour éviter
     * un biais Under structurel (avg total observé 1.66 → corrigé ~2.7).
     */
    private function lambdasFromXGProxy($advancedData, float $leagueAvg): ?array
    {
        $xgData = $advancedData?->footystats_data['expectedGoals'] ?? null;

        if (!$xgData) {
            return null;
        }

        $homeXG = (float) ($xgData['home']['xGFor'] ?? $leagueAvg);
        $awayXG = (float) ($xgData['away']['xGFor'] ?? $leagueAvg);

        if ($homeXG < self::XG_PROXY_FLOOR) {
            $homeXG = $leagueAvg;
        }
        if ($awayXG < self::XG_PROXY_FLOOR) {
            $awayXG = $leagueAvg;
        }

        return ['home' => $homeXG, 'away' => $awayXG];
    }

    /**
     * Signal 2 : λ depuis les comparaisons API-Football.
     *
     * Utilise les dimensions 'att', 'def', 'poisson_distribution', 'form'
     * pour estimer un xG pondéré.
     */
    private function lambdasFromComparison($advancedData, float $leagueAvg): ?array
    {
        $comparison = $advancedData?->context_data['comparison'] ?? null;

        if (!$comparison) {
            return null;
        }

        // Extraire les pourcentages
        $att = $this->parsePercent($comparison['att']['home'] ?? '50%');
        $def = $this->parsePercent($comparison['def']['home'] ?? '50%');
        $poisson = $this->parsePercent($comparison['poisson_distribution']['home'] ?? '50%');
        $form = $this->parsePercent($comparison['form']['home'] ?? '50%');
        $goals = $this->parsePercent($comparison['goals']['home'] ?? '50%');

        // Score composite domicile (0-100)
        // Attaque + Poisson pèsent plus que forme et défense
        $homeStrength = ($att * 0.30) + ($poisson * 0.30) + ($form * 0.20) + ($goals * 0.20);

        // Convertir en λ autour de la moyenne de ligue
        // 50% → leagueAvg, 70% → ~1.4 × leagueAvg, 30% → ~0.6 × leagueAvg
        $homeLambda = $leagueAvg * ($homeStrength / 50);
        $awayLambda = $leagueAvg * ((100 - $homeStrength) / 50);

        // Ajuster avec la dimension défensive (def forte = λ adverse réduit)
        $defModifier = (100 - $def) / 100; // def% du domicile → inverse pour l'adversaire
        $awayLambda *= (0.7 + $defModifier * 0.6);
        $homeLambda *= (0.7 + (1 - $defModifier) * 0.6);

        return [
            'home' => round($homeLambda, 3),
            'away' => round($awayLambda, 3),
        ];
    }

    /**
     * Signal 3 : λ estimés depuis les cotes bookmakers.
     *
     * Phase 1 : grid search sur 1X2 → ratio λh/λa (force relative)
     * Phase 2 : si cotes Over/Under 2.5 disponibles → binary search sur le
     *           total λ pour matcher P(Under 2.5) implicite. Le 1X2 seul
     *           sous-contraint le total des buts (un même split 1X2 est
     *           compatible avec une plage de totaux), ce qui produisait
     *           un biais Under structurel de +5 à +11 pp.
     */
    private function lambdasFromOdds(FootballMatch $match): ?array
    {
        $oddsHome = (float) $match->odds_home;
        $oddsDraw = (float) $match->odds_draw;
        $oddsAway = (float) $match->odds_away;

        if ($oddsHome <= 0 || $oddsDraw <= 0 || $oddsAway <= 0) {
            return null;
        }

        // Probabilités implicites brutes (avec marge bookmaker)
        $rawHome = 1 / $oddsHome;
        $rawDraw = 1 / $oddsDraw;
        $rawAway = 1 / $oddsAway;
        $overround = $rawHome + $rawDraw + $rawAway;

        // Normaliser (supprimer la marge)
        $pHome = $rawHome / $overround;
        $pDraw = $rawDraw / $overround;
        $pAway = $rawAway / $overround;

        // Phase 1 : grid search 1X2 — fournit le ratio λh/λa
        $bestHome = self::LEAGUE_AVG_XG;
        $bestAway = self::LEAGUE_AVG_XG;
        $bestError = PHP_FLOAT_MAX;

        for ($h = 0.3; $h <= 3.5; $h += 0.05) {
            for ($a = 0.2; $a <= 3.0; $a += 0.05) {
                $pred = $this->poisson->predict1X2($h, $a);

                $error = abs($pred['home'] / 100 - $pHome)
                       + abs($pred['draw'] / 100 - $pDraw)
                       + abs($pred['away'] / 100 - $pAway);

                if ($error < $bestError) {
                    $bestError = $error;
                    $bestHome = $h;
                    $bestAway = $a;
                }
            }
        }

        // Phase 2 : ajuster le total avec les cotes O/U 2.5 si disponibles
        $oddsUnder = (float) $match->odds_under_2_5;
        $oddsOver = (float) $match->odds_over_2_5;
        $impliedUnder = null;

        if ($oddsUnder > 0 && $oddsOver > 0 && ($bestHome + $bestAway) > 0) {
            $rawUnder = 1 / $oddsUnder;
            $rawOver = 1 / $oddsOver;
            $impliedUnder = $rawUnder / ($rawUnder + $rawOver);

            $homeShare = $bestHome / ($bestHome + $bestAway);
            $awayShare = 1 - $homeShare;

            // Binary search : λ_total tel que P(Under 2.5) ≈ implicite bookmaker
            $lo = 0.5;
            $hi = 6.0;
            $mid = $bestHome + $bestAway;

            for ($i = 0; $i < 40; $i++) {
                $mid = ($lo + $hi) / 2;
                $h = $mid * $homeShare;
                $a = $mid * $awayShare;
                $pred = $this->poisson->predictOverUnder($h, $a, 2.5);
                $pUnderModel = $pred['under'] / 100;

                if (abs($pUnderModel - $impliedUnder) < 0.002) {
                    break;
                }

                // P(Under) décroît avec λ_total → si modèle trop haut, monter total
                if ($pUnderModel > $impliedUnder) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }

            $bestHome = $mid * $homeShare;
            $bestAway = $mid * $awayShare;
        }

        return [
            'home' => round($bestHome, 3),
            'away' => round($bestAway, 3),
            'fit_error' => round($bestError, 4),
            'implied' => [
                'home' => round($pHome * 100, 1),
                'draw' => round($pDraw * 100, 1),
                'away' => round($pAway * 100, 1),
                'under_2_5' => $impliedUnder !== null ? round($impliedUnder * 100, 1) : null,
            ],
        ];
    }

    /**
     * Signal 4 : Modificateur basé sur les blessures.
     *
     * Retourne un facteur multiplicatif pour chaque équipe.
     * Plus il y a de blessés, plus le λ est réduit.
     */
    private function injuryModifier($advancedData): ?array
    {
        $injuries = $advancedData?->sofascore_data['injuries'] ?? null;

        if (!$injuries) {
            return null;
        }

        // Dédupliquer (bug connu : chaque joueur apparaît 2 fois)
        $homeCount = count(array_unique(
            array_column($injuries['home'] ?? [], 'player')
        ));
        $awayCount = count(array_unique(
            array_column($injuries['away'] ?? [], 'player')
        ));

        // Chaque blessé réduit le λ de ~3%
        $homePenalty = 1 - ($homeCount * 0.03);
        $awayPenalty = 1 - ($awayCount * 0.03);

        return [
            'home' => round(max(0.7, $homePenalty), 3),
            'away' => round(max(0.7, $awayPenalty), 3),
            'home_injuries' => $homeCount,
            'away_injuries' => $awayCount,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FUSION DES SIGNAUX
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Fusionner les λ de chaque signal en un λ final pondéré.
     */
    private function fuseLambdas(array $signals): array
    {
        $homeSum = 0;
        $awaySum = 0;
        $totalWeight = 0;

        foreach (['xg_proxy', 'comparison', 'market'] as $key) {
            $signal = $signals[$key] ?? null;

            if (!$signal || !isset($signal['home'], $signal['away'])) {
                continue;
            }

            $weight = self::SIGNAL_WEIGHTS[$key];
            $homeSum += $signal['home'] * $weight;
            $awaySum += $signal['away'] * $weight;
            $totalWeight += $weight;
        }

        // Fallback si aucun signal
        if ($totalWeight <= 0) {
            $fallback = $signals['_league_avg'] ?? self::LEAGUE_AVG_XG;
            return ['home' => $fallback, 'away' => $fallback];
        }

        $homeLambda = $homeSum / $totalWeight;
        $awayLambda = $awaySum / $totalWeight;

        // Appliquer le modificateur de blessures
        $injuryMod = $signals['injuries'] ?? null;
        if ($injuryMod) {
            $homeLambda *= $injuryMod['home'];
            $awayLambda *= $injuryMod['away'];
        }

        return [
            'home' => round($homeLambda, 3),
            'away' => round($awayLambda, 3),
        ];
    }

    /**
     * Appliquer l'avantage domicile.
     * Le signal 'market' inclut déjà cet avantage implicitement (via les cotes),
     * mais xg_proxy et comparison non. On applique un facteur partiel.
     */
    private function applyHomeAdvantage(array $lambdas): array
    {
        // Facteur réduit car le signal 'market' (35% du poids) l'intègre déjà
        $adjustedFactor = 1 + (self::HOME_ADVANTAGE - 1) * 0.4; // +10% au lieu de +25%
        $adjustedAway = 1 - (1 - self::AWAY_FACTOR) * 0.4;       // -6% au lieu de -15%

        return [
            'home' => round($lambdas['home'] * $adjustedFactor, 3),
            'away' => round($lambdas['away'] * $adjustedAway, 3),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CONSTRUCTION DES PRÉDICTIONS SOURCE D
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Construire les prédictions au format attendu par AnalyzerService.
     *
     * Format identique aux Sources A/B/C pour une intégration transparente :
     * ['winner' => ['pick' => '1', 'confidence' => 65], ...]
     */
    private function buildPredictions(array $analysis): array
    {
        $predictions = [];

        // 1X2
        $p1x2 = $analysis['1x2'];
        $maxKey = array_keys($p1x2, max($p1x2))[0];
        $pick1x2 = match ($maxKey) {
            'home' => '1',
            'draw' => 'X',
            'away' => '2',
        };
        $predictions['winner'] = [
            'pick' => $pick1x2,
            'confidence' => (int) round(max($p1x2)),
            'probHome' => $p1x2['home'],
            'probDraw' => $p1x2['draw'],
            'probAway' => $p1x2['away'],
        ];

        // Over/Under 2.5
        $ou = $analysis['overUnder25'];
        $predictions['overUnder'] = [
            'pick' => $ou['over'] > $ou['under'] ? 'Over' : 'Under',
            'confidence' => (int) round(max($ou['over'], $ou['under'])),
        ];

        // BTTS
        $btts = $analysis['btts'];
        $predictions['btts'] = [
            'pick' => $btts['yes'] > $btts['no'] ? 'Yes' : 'No',
            'confidence' => (int) round(max($btts['yes'], $btts['no'])),
        ];

        // Double Chance
        $dc = $analysis['doubleChance'];
        $maxDC = array_keys($dc, max($dc))[0];
        $predictions['doubleChance'] = [
            'pick' => $maxDC,
            'confidence' => (int) round(max($dc)),
        ];

        // Score exact (top 1)
        $topScore = $analysis['topScores'][0] ?? null;
        if ($topScore) {
            $predictions['exactScore'] = [
                'pick' => $topScore['score'],
                'confidence' => (int) round($topScore['probability']),
            ];
        }

        return $predictions;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // UTILITAIRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Parser un pourcentage string ("56%") en float (56.0).
     */
    private function parsePercent(string $value): float
    {
        return (float) str_replace('%', '', $value);
    }
}
