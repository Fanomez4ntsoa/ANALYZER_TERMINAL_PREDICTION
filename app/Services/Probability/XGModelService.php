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
 *   4. Facteur domicile/extérieur — appliqué aux seuls signaux 1 et 2, le
 *      signal marché contenant déjà l'avantage du terrain
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
    private LeagueGoalAverages $leagueGoals;

    public function __construct(PoissonModelService $poisson, LeagueGoalAverages $leagueGoals)
    {
        $this->poisson = $poisson;
        $this->leagueGoals = $leagueGoals;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODE PRINCIPALE — Prédiction complète
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Générer les prédictions complètes pour un match.
     *
     * @param bool $marketOnly  Mode "marché seul" (backtest football-data) : seul le
     *                          signal cotes est collecté, la fusion se renormalise sur
     *                          ce seul poids, le reste du pipeline est inchangé. Lève
     *                          une exception si les cotes 1X2 manquent, pour que le
     *                          repli sur la moyenne de ligue ne soit jamais atteint.
     */
    public function predict(FootballMatch $match, bool $marketOnly = false): array
    {
        $advancedData = $marketOnly ? null : $match->advancedData;

        // 1. Estimer les λ depuis chaque signal
        $signals = $marketOnly
            ? $this->collectMarketSignalOnly($match)
            : $this->collectSignals($match, $advancedData);

        // 2. Fusionner les λ avec pondération. L'avantage domicile est appliqué
        //    dans la fusion, aux seuls signaux qui ne le contiennent pas.
        $lambdas = $this->fuseLambdas($signals);

        // 3. Ancien comportement (mesure seulement) : facteur domicile après fusion,
        //    donc aussi sur le signal marché qui contient déjà l'avantage du terrain.
        if (config('xg-model.legacy_home_advantage_after_fusion')) {
            $lambdas = $this->applyLegacyHomeAdvantage($lambdas);
        }

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
     * Mode marché seul : uniquement le signal cotes, sans moyenne de ligue.
     */
    private function collectMarketSignalOnly(FootballMatch $match): array
    {
        $market = $this->lambdasFromOdds($match);

        if ($market === null) {
            throw new \InvalidArgumentException(
                "Mode marché seul : cotes 1X2 incomplètes pour {$match->home_team} vs {$match->away_team}"
            );
        }

        return ['market' => $market];
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
     * Avec cotes Over/Under 2.5 (recalage conjoint) :
     *   1. le total λh + λa est fixé par P(Under 2.5) démarginalisée. Sous deux
     *      Poisson indépendantes, le nombre total de buts suit une Poisson de
     *      paramètre λh + λa : P(Under 2.5) ne dépend que du total ;
     *   2. le partage λh / total est recherché sur le 1X2 À CE TOTAL.
     * Sans cotes O/U : grille 1X2 sur (λh, λa), total libre. Si l'ancrage est
     * activé (désactivé par défaut), le total est fixé sur la moyenne du
     * championnat, puis le partage recherché de la même façon.
     *
     * Ancien comportement (config xg-model.legacy_constant_share_rescaling) :
     * partage de la grille conservé lors du recalage du total, ce qui déplace le 1X2.
     *
     * Limite connue, mesurée avant implémentation : à total fixé, deux Poisson
     * indépendantes ne reproduisent pas la probabilité de nul du marché (environ
     * 2 points de moins). Le recalage conjoint déplace ce résidu, il ne le supprime
     * pas ; la correction de Dixon-Coles sur les scores faibles est la piste.
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
        $fair = ['home' => $pHome, 'draw' => $pDraw, 'away' => $pAway];

        $legacyShare = (bool) config('xg-model.legacy_constant_share_rescaling');

        $oddsUnder = (float) $match->odds_under_2_5;
        $oddsOver = (float) $match->odds_over_2_5;
        $impliedUnder = null;
        if ($oddsUnder > 0 && $oddsOver > 0) {
            $rawUnder = 1 / $oddsUnder;
            $rawOver = 1 / $oddsOver;
            $impliedUnder = $rawUnder / ($rawUnder + $rawOver);
        }

        $totalAnchor = null;
        $totalSource = 'grid';

        if ($impliedUnder !== null && !$legacyShare) {
            // Recalage conjoint : total fixé par l'O/U, partage cherché sur le 1X2
            $total = $this->totalFromUnder25($impliedUnder);
            [$bestHome, $bestAway, $bestError] = $this->fitShareAtTotal($total, $fair);
            $totalSource = 'over_under';
        } else {
            [$bestHome, $bestAway, $bestError] = $this->gridSearch1X2($fair);

            if ($impliedUnder !== null && ($bestHome + $bestAway) > 0) {
                // Ancien comportement : total recalé à partage constant
                [$bestHome, $bestAway] = $this->rescaleAtConstantShare($bestHome, $bestAway, $this->totalFromUnder25Bisection($bestHome, $bestAway, $impliedUnder));
                $totalSource = 'over_under_constant_share';
            } elseif ($impliedUnder === null && config('xg-model.anchor_total_on_league_average') && ($bestHome + $bestAway) > 0) {
                $totalAnchor = $this->leagueGoals->totalFor($match->league_id);
                if ($totalAnchor === null && !$this->leagueGoals->isScoped()) {
                    // Production, ligue sans historique : constante de repli.
                    // Jamais en backtest : sans saison antérieure, pas d'ancre.
                    $totalAnchor = 2 * $this->getLeagueAvgXg($match->league_id);
                }
                if ($totalAnchor !== null) {
                    [$bestHome, $bestAway, $bestError] = $legacyShare
                        ? [...$this->rescaleAtConstantShare($bestHome, $bestAway, $totalAnchor), $bestError]
                        : $this->fitShareAtTotal($totalAnchor, $fair);
                    $totalSource = 'league_anchor';
                }
            }
        }

        return [
            'home' => round($bestHome, 3),
            'away' => round($bestAway, 3),
            'fit_error' => round($bestError, 4),
            'total_source' => $totalSource,
            'total_anchor' => $totalAnchor,
            'implied' => [
                'home' => round($pHome * 100, 1),
                'draw' => round($pDraw * 100, 1),
                'away' => round($pAway * 100, 1),
                'under_2_5' => $impliedUnder !== null ? round($impliedUnder * 100, 1) : null,
            ],
        ];
    }

    /**
     * Grille (λh, λa) minimisant l'erreur absolue sur le 1X2 démarginalisé.
     *
     * @return array{0: float, 1: float, 2: float}  λh, λa, erreur
     */
    private function gridSearch1X2(array $fair): array
    {
        $bestHome = self::LEAGUE_AVG_XG;
        $bestAway = self::LEAGUE_AVG_XG;
        $bestError = PHP_FLOAT_MAX;

        for ($h = 0.3; $h <= 3.5; $h += 0.05) {
            for ($a = 0.2; $a <= 3.0; $a += 0.05) {
                $pred = $this->poisson->predict1X2($h, $a);

                $error = abs($pred['home'] / 100 - $fair['home'])
                       + abs($pred['draw'] / 100 - $fair['draw'])
                       + abs($pred['away'] / 100 - $fair['away']);

                if ($error < $bestError) {
                    $bestError = $error;
                    $bestHome = $h;
                    $bestAway = $a;
                }
            }
        }

        return [$bestHome, $bestAway, $bestError];
    }

    /**
     * Partage λh / total qui minimise l'erreur absolue sur le 1X2 à total fixé.
     * Même critère que la grille, un seul paramètre libre. Balayage au pas de
     * 0,01 puis affinage au pas de 0,0005 autour du meilleur point.
     *
     * @return array{0: float, 1: float, 2: float}  λh, λa, erreur
     */
    private function fitShareAtTotal(float $total, array $fair): array
    {
        $error = function (float $share) use ($total, $fair): float {
            $p = $this->poisson->probabilities1X2($share * $total, (1 - $share) * $total);
            return abs($p['home'] - $fair['home']) + abs($p['draw'] - $fair['draw']) + abs($p['away'] - $fair['away']);
        };

        $bestShare = 0.5;
        $bestError = PHP_FLOAT_MAX;
        for ($i = 2; $i <= 98; $i++) {
            $s = $i / 100;
            $e = $error($s);
            if ($e < $bestError) {
                $bestError = $e;
                $bestShare = $s;
            }
        }
        $from = max(0.01, $bestShare - 0.01);
        $to = min(0.99, $bestShare + 0.01);
        for ($s = $from; $s <= $to + 1e-9; $s += 0.0005) {
            $e = $error($s);
            if ($e < $bestError) {
                $bestError = $e;
                $bestShare = $s;
            }
        }

        return [$bestShare * $total, (1 - $bestShare) * $total, $bestError];
    }

    /**
     * Total λh + λa tel que P(total ≤ 2) = P(Under 2.5) implicite.
     * Sous indépendance, le total suit une Poisson(λh + λa) : calcul exact,
     * identique à la somme de la matrice de scores (tous les scores de total ≤ 2
     * y figurent). P(Under) décroît avec le total : dichotomie.
     */
    private function totalFromUnder25(float $impliedUnder): float
    {
        $lo = 0.1;
        $hi = 10.0;
        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            $under = exp(-$mid) * (1 + $mid + $mid * $mid / 2);
            if ($under > $impliedUnder) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return ($lo + $hi) / 2;
    }

    /**
     * Ancien recalage (conservé pour la mesure) : dichotomie à partage constant sur
     * la sortie arrondie de predictOverUnder, tolérance 0,2 point, bornes 0,5 à 6.
     */
    private function totalFromUnder25Bisection(float $home, float $away, float $impliedUnder): float
    {
        $homeShare = $home / ($home + $away);
        $lo = 0.5;
        $hi = 6.0;
        $mid = $home + $away;

        for ($i = 0; $i < 40; $i++) {
            $mid = ($lo + $hi) / 2;
            $pred = $this->poisson->predictOverUnder($mid * $homeShare, $mid * (1 - $homeShare), 2.5);
            $pUnderModel = $pred['under'] / 100;

            if (abs($pUnderModel - $impliedUnder) < 0.002) {
                break;
            }
            if ($pUnderModel > $impliedUnder) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return $mid;
    }

    /** @return array{0: float, 1: float} */
    private function rescaleAtConstantShare(float $home, float $away, float $total): array
    {
        $share = $home / ($home + $away);

        return [$total * $share, $total * (1 - $share)];
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

        $legacy = (bool) config('xg-model.legacy_home_advantage_after_fusion');

        foreach (['xg_proxy', 'comparison', 'market'] as $key) {
            $signal = $signals[$key] ?? null;

            if (!$signal || !isset($signal['home'], $signal['away'])) {
                continue;
            }

            // Le signal marché contient déjà l'avantage du terrain (les cotes le
            // pricent). xg_proxy et comparison sont des forces neutres : c'est à
            // eux seuls que s'applique le facteur domicile, à pleine valeur.
            $home = $signal['home'];
            $away = $signal['away'];
            if ($key !== 'market' && !$legacy) {
                $home *= self::HOME_ADVANTAGE;
                $away *= self::AWAY_FACTOR;
            }

            $weight = self::SIGNAL_WEIGHTS[$key];
            $homeSum += $home * $weight;
            $awaySum += $away * $weight;
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
     * ANCIEN comportement, conservé pour la mesure (config xg-model.legacy_home_advantage_after_fusion).
     * Facteur partiel appliqué après fusion, donc aussi au signal marché qui contient
     * déjà l'avantage du terrain : +4,6 à +5,6 points sur la victoire à domicile
     * (run #2, dix divisions majeures).
     */
    private function applyLegacyHomeAdvantage(array $lambdas): array
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
