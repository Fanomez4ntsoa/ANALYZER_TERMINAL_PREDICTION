<?php

namespace App\Services\Probability;

use App\Models\FootballMatch;

/**
 * Modèle probabiliste maison basé sur les xG et données API-Football.
 *
 * Combine 3 signaux pour estimer les λ (expected goals) de chaque équipe :
 *   1. Comparaison API-Football (context_data.comparison) — 7 dimensions
 *   2. Probabilités implicites des cotes — reverse-engineering du marché
 *   3. Blessures — modificateur multiplicatif
 * Le facteur domicile/extérieur ne s'applique qu'au signal comparaison, le
 * signal marché contenant déjà l'avantage du terrain.
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

    private PoissonModelService $poisson;
    private LeagueGoalAverages $leagueGoals;
    private DixonColesRho $dixonColes;

    public function __construct(PoissonModelService $poisson, LeagueGoalAverages $leagueGoals, DixonColesRho $dixonColes)
    {
        $this->poisson = $poisson;
        $this->leagueGoals = $leagueGoals;
        $this->dixonColes = $dixonColes;
    }

    /**
     * Estimateurs de paramètres sur données historiques utilisés par ce modèle. Le
     * moteur de backtest vérifie qu'ils sont tous bornés aux saisons antérieures.
     *
     * @return \App\Services\Backtesting\SeasonScopedEstimator[]
     */
    public function seasonScopedEstimators(): array
    {
        return [$this->leagueGoals, $this->dixonColes];
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

        // ρ de Dixon-Coles de la population du match ; null = Poisson indépendantes
        $rho = config('xg-model.dixon_coles_low_score_correction')
            ? $this->dixonColes->rhoFor($match->league_id)
            : null;

        // 1. Estimer les λ depuis chaque signal
        $signals = $marketOnly
            ? $this->collectMarketSignalOnly($match, $rho)
            : $this->collectSignals($match, $advancedData, $rho);

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
        $analysis = $this->poisson->fullAnalysis($lambdas['home'], $lambdas['away'], $rho);

        // 6. Construire les prédictions au format Source (pick + confidence)
        $predictions = $this->buildPredictions($analysis);

        return [
            'lambdas' => $lambdas,
            'rho' => $rho,
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
    private function collectSignals(FootballMatch $match, $advancedData, ?float $rho = null): array
    {
        $leagueAvg = $this->getLeagueAvgXg($match->league_id);

        $signals = [];

        // Signal 1 : Comparaison API-Football 7D
        $signals['comparison'] = $this->lambdasFromComparison($advancedData, $leagueAvg);

        // Signal 2 : Probabilités implicites des cotes bookmakers
        $signals['market'] = $this->lambdasFromOdds($match, $rho);

        // Signal 3 : Ajustement blessures
        $signals['injuries'] = $this->injuryModifier($advancedData);

        $signals['_league_avg'] = $leagueAvg;

        return $signals;
    }

    /**
     * Mode marché seul : uniquement le signal cotes, sans moyenne de ligue.
     */
    private function collectMarketSignalOnly(FootballMatch $match, ?float $rho = null): array
    {
        $market = $this->lambdasFromOdds($match, $rho);

        if ($market === null) {
            throw new \InvalidArgumentException(
                "Mode marché seul : cotes 1X2 incomplètes pour {$match->home_team} vs {$match->away_team}"
            );
        }

        return ['market' => $market];
    }

    /**
     * Signal 1 : λ depuis les comparaisons API-Football.
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
     * Signal 2 : λ estimés depuis les cotes bookmakers.
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
     * Avec ρ (Dixon-Coles), toutes les probabilités viennent de la matrice corrigée
     * et renormalisée. P(Under 2.5) ne dépend pas de ρ, mais la renormalisation de la
     * matrice tronquée la déplace de quelques centièmes : le total est alors affiné
     * sur la matrice au partage trouvé, puis le partage recherché à nouveau.
     *
     * Limite des Poisson indépendantes (ρ = null) : à total fixé, elles ne
     * reproduisent pas la probabilité de nul du marché (environ 2 points de moins).
     */
    private function lambdasFromOdds(FootballMatch $match, ?float $rho = null): ?array
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
            [$bestHome, $bestAway, $bestError] = $this->fitShareAtTotal($total, $fair, $rho);
            if ($rho !== null) {
                for ($pass = 0; $pass < 2; $pass++) {
                    $total = $this->totalAtShareFromUnder25($bestHome / ($bestHome + $bestAway), $impliedUnder, $rho);
                    [$bestHome, $bestAway, $bestError] = $this->fitShareAtTotal($total, $fair, $rho);
                }
            }
            $totalSource = 'over_under';
        } else {
            [$bestHome, $bestAway, $bestError] = $this->gridSearch1X2($fair, $rho);

            if ($impliedUnder !== null && ($bestHome + $bestAway) > 0) {
                // Ancien comportement : total recalé à partage constant
                [$bestHome, $bestAway] = $this->rescaleAtConstantShare($bestHome, $bestAway, $this->totalFromUnder25Bisection($bestHome, $bestAway, $impliedUnder, $rho));
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
                        : $this->fitShareAtTotal($totalAnchor, $fair, $rho);
                    $totalSource = 'league_anchor';
                }
            }
        }

        return [
            'home' => round($bestHome, 3),
            'away' => round($bestAway, 3),
            'fit_error' => round($bestError, 4),
            'total_source' => $totalSource,
            'rho' => $rho,
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
    private function gridSearch1X2(array $fair, ?float $rho = null): array
    {
        $bestHome = self::LEAGUE_AVG_XG;
        $bestAway = self::LEAGUE_AVG_XG;
        $bestError = PHP_FLOAT_MAX;

        for ($h = 0.3; $h <= 3.5; $h += 0.05) {
            for ($a = 0.2; $a <= 3.0; $a += 0.05) {
                $pred = $this->poisson->predict1X2($h, $a, $rho);

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
    private function fitShareAtTotal(float $total, array $fair, ?float $rho = null): array
    {
        $error = function (float $share) use ($total, $fair, $rho): float {
            $p = $this->poisson->probabilities1X2($share * $total, (1 - $share) * $total, $rho);
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
     * Total tel que P(Under 2.5) de la matrice (corrigée par ρ, renormalisée) égale
     * la probabilité implicite, au partage donné. Dichotomie, P(Under) décroissante.
     */
    private function totalAtShareFromUnder25(float $share, float $impliedUnder, ?float $rho): float
    {
        $lo = 0.1;
        $hi = 10.0;
        for ($i = 0; $i < 50; $i++) {
            $mid = ($lo + $hi) / 2;
            $matrix = $this->poisson->scoreMatrix($mid * $share, $mid * (1 - $share), $rho);
            $under = 0.0;
            for ($h = 0; $h <= 2; $h++) {
                for ($a = 0; $a <= 2 - $h; $a++) {
                    $under += $matrix[$h][$a];
                }
            }
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
    private function totalFromUnder25Bisection(float $home, float $away, float $impliedUnder, ?float $rho = null): float
    {
        $homeShare = $home / ($home + $away);
        $lo = 0.5;
        $hi = 6.0;
        $mid = $home + $away;

        for ($i = 0; $i < 40; $i++) {
            $mid = ($lo + $hi) / 2;
            $pred = $this->poisson->predictOverUnder($mid * $homeShare, $mid * (1 - $homeShare), 2.5, $rho);
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
     * Signal 3 : Modificateur basé sur les blessures.
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

        foreach (['comparison', 'market'] as $key) {
            $signal = $signals[$key] ?? null;

            if (!$signal || !isset($signal['home'], $signal['away'])) {
                continue;
            }

            // Le signal marché contient déjà l'avantage du terrain (les cotes le
            // pricent). comparison est une force neutre : c'est à lui seul que
            // s'applique le facteur domicile, à pleine valeur.
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
