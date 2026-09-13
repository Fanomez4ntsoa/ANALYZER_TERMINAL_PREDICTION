<?php

namespace App\Services\Betting;

use App\Models\AIAnalysis;
use App\Models\DailyCombo;
use App\Models\FootballMatch;
use App\Services\AI\Agents\ComboBuilderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ComboSelectorService
{
    private float $minOdds;
    private float $maxOdds;
    private float $targetOdds;
    private int $minMatches;
    private int $maxMatches;
    private int $minConfidence;
    private float $overSafeThreshold;

    /**
     * Ligues autorisees pour figurer dans les combos (TOUS marches confondus).
     * Exclus : 113/119/103 (debut saison), 271/106/197 (echantillon trop faible).
     */
    private const COMBO_ALLOWED_LEAGUES = [
        61,  // Ligue 1
        39,  // Premier League
        140, // La Liga
        135, // Serie A
        78,  // Bundesliga
        40,  // Championship
        144, // Jupiler Pro League
        88,  // Eredivisie
        94,  // Primeira Liga
        62,  // Ligue 2
        136, // Serie B
    ];

    private ComboBuilderService $aiBuilder;

    public function __construct(ComboBuilderService $aiBuilder)
    {
        // Fenêtre élargie pour permettre la sélection multi-marchés (spec: 1.85 - 2.20)
        $this->minOdds = (float) env('COMBO_MIN_ODDS', 1.85);
        $this->maxOdds = (float) env('COMBO_MAX_ODDS', 2.20);
        $this->targetOdds = (float) env('COMBO_TARGET_ODDS', 2.00);
        $this->minMatches = (int) env('COMBO_MIN_MATCHES', 3);
        $this->maxMatches = (int) env('COMBO_MAX_MATCHES', 5);
        $this->minConfidence = (int) env('COMBO_MIN_CONFIDENCE', 65);
        // Seuil cote pour considérer Over 2.5 "safe" (sinon chercher 2.0/2.25)
        $this->overSafeThreshold = (float) env('COMBO_OVER_SAFE_MAX_ODDS', 1.45);
        $this->aiBuilder = $aiBuilder;
    }

    /**
     * Générer les top 3 combos pour une date.
     *
     * @return array ['combos' => DailyCombo[], 'stats' => [...]]
     */
    public function generateForDate(string $date): array
    {
        // 1. Récupérer les matchs éligibles avec leurs candidats multi-marchés
        $eligible = $this->getEligibleMatchesWithCandidates($date);

        $eligibleCount = $eligible->count();
        $candidateCount = $eligible->sum(fn($m) => count($m['candidates']));

        if ($eligibleCount < $this->minMatches) {
            Log::info("ComboSelector: pas assez de matchs eligibles ({$eligibleCount} < {$this->minMatches}) — pas de combo (ni algo, ni IA)");

            // Nettoyer les anciens combos de la date pour éviter qu'un combo périmé persiste
            DailyCombo::where('date', $date)->delete();

            return [
                'combos' => [],
                'ai_combo' => null,
                'stats' => [
                    'eligible_matches' => $eligibleCount,
                    'candidate_picks' => $candidateCount,
                    'combinations' => 0,
                    'valid' => 0,
                    'saved' => 0,
                    'ai_recommendation' => null,
                    'ai_confidence' => null,
                    'ai_tokens' => null,
                    'ai_error' => 'eligible_below_min_matches',
                ],
            ];
        }

        // 2. Générer toutes les combinaisons multi-marchés possibles
        $allCombos = $this->generateMultiMarketCombinations($eligible);

        // 3. Filtrer (cote dans la fenêtre — l'anti-corrélation est déjà appliquée à la génération)
        $validCombos = $this->filterCombos($allCombos);

        // 4. Scorer et trier
        $scoredCombos = $this->scoreCombos($validCombos);

        // 5. Garder le top 3 (en évitant les doublons match-set)
        $top3 = $this->dedupByMatchSet($scoredCombos)->take(3);

        // 6. Sauvegarder en DB
        DailyCombo::where('date', $date)->delete();

        $saved = [];
        foreach ($top3->values() as $index => $combo) {
            $saved[] = DailyCombo::create([
                'date' => $date,
                'rank' => $index + 1,
                'picks' => $combo['picks'],
                'match_count' => count($combo['picks']),
                'total_odds' => $combo['total_odds'],
                'combo_score' => $combo['score'],
                'avg_confidence' => $combo['avg_confidence'],
                'logic' => $combo['logic'],
            ]);
        }

        Log::info("ComboSelector: {$date} — {$eligibleCount} matchs / {$candidateCount} candidats, " . count($allCombos) . " combos générés, {$validCombos->count()} valides, " . count($saved) . " sauvegardés");

        // 7. Couche IA — ComboBuilderService optimise et valide la sélection finale.
        //    Sauvegardé en rank=0 (combo IA distinct des combos algorithmiques rank 1-3).
        $aiResult = $this->buildAiCombo($eligible, $date);

        return [
            'combos' => $saved,
            'ai_combo' => $aiResult['combo'] ?? null,
            'stats' => [
                'eligible_matches' => $eligibleCount,
                'candidate_picks' => $candidateCount,
                'combinations' => count($allCombos),
                'valid' => $validCombos->count(),
                'saved' => count($saved),
                'ai_recommendation' => $aiResult['recommendation'] ?? null,
                'ai_confidence' => $aiResult['confidence'] ?? null,
                'ai_tokens' => $aiResult['tokens'] ?? null,
                'ai_error' => $aiResult['error'] ?? null,
            ],
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CONSTRUCTION DES CANDIDATS PAR MATCH
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function getEligibleMatchesWithCandidates(string $date): Collection
    {
        $matches = FootballMatch::with(['recommendations', 'advancedData'])
            ->whereDate('match_date', $date)
            ->whereNotNull('global_confidence')
            ->whereHas('recommendations')
            ->get();

        $aiDecisions = AIAnalysis::whereIn('match_id', $matches->pluck('id'))
            ->get()
            ->keyBy('match_id');

        $result = collect();

        foreach ($matches as $match) {
            $aiDecision = $aiDecisions->get($match->id);
            $candidates = $this->buildCandidatesForMatch($match, $aiDecision);

            if (empty($candidates)) {
                continue;
            }

            $result->push([
                'match_id' => $match->id,
                'match' => $match,
                'candidates' => $candidates,
            ]);
        }

        return $result;
    }

    /**
     * Construire la liste des picks possibles pour un match selon les marchés disponibles
     * et les décisions de l'IA. Chaque candidat porte sa propre stratégie + raison de sélection.
     *
     * @return array Liste de picks (chaque pick = array)
     */
    private function buildCandidatesForMatch(FootballMatch $match, ?AIAnalysis $aiDecision): array
    {
        // ─── Filtre #1 (global) : whitelist de ligues — exclut 113/119/103/271/106/197 ───
        if (!in_array($match->league_id, self::COMBO_ALLOWED_LEAGUES)) {
            return [];
        }

        $finalDecision = $aiDecision?->final_decision ?? null;
        $decisionUnder = $finalDecision['decision_under'] ?? null;
        $decisionGlobal = $finalDecision['decision_global'] ?? null;

        $candidates = [];

        // Recommandation générale (meilleure rec selon le score)
        $bestRec = $match->recommendations->sortByDesc('score')->first();
        if (!$bestRec) {
            return [];
        }

        $confidence = (int) $bestRec->confidence;
        $bestRecPassesConfidence = $confidence >= $this->minConfidence;

        $underRec = $match->recommendations
            ->where('market', 'overUnder')
            ->filter(fn($r) => str_contains(strtolower($r->bet), 'under'))
            ->first();

        $overRec = $match->recommendations
            ->where('market', 'overUnder')
            ->filter(fn($r) => str_contains(strtolower($r->bet), 'over'))
            ->first();

        $bttsNoRec = $match->recommendations
            ->where('market', 'btts')
            ->filter(fn($r) => str_contains(strtolower($r->bet), 'no'))
            ->first();

        // ─── 1. Pick "général" : meilleure rec si elle a une cote stockée ET passe le seuil ───
        if ($bestRecPassesConfidence && (float) $bestRec->odds > 1.0) {
            $candidates[] = $this->buildPick($match, $aiDecision, $bestRec, [
                'market' => $bestRec->market,
                'pick' => $bestRec->bet,
                'odds' => (float) $bestRec->odds,
                'confidence' => $confidence,
                'score' => $bestRec->score,
                'strategy' => 'general',
                'selection_reason' => "Meilleure recommandation (score={$bestRec->score}, conf={$confidence}%)",
            ]);
        }

        // ─── 2. Stratégie Under (uniquement si IA = BET ou LEAN) ───
        // Under 3.5 prioritaire (plus safe : exclut seulement les matchs à 4+ buts).
        // Sinon Under 2.5 standard. Under 2.0 / 2.25 retirés (cf. correctifs précédents).
        $aiBoostsUnder = in_array($decisionUnder, ['BET', 'LEAN']);
        $underScore = $underRec?->score ?? $bestRec->score;
        $underConf = $underRec?->confidence ?? $confidence;

        if ($aiBoostsUnder) {
            $under35 = $match->odds_under_3_5 !== null ? (float) $match->odds_under_3_5 : null;
            $under25 = $match->odds_under_2_5 !== null ? (float) $match->odds_under_2_5 : null;

            if ($under35 !== null && $under35 > 1.0) {
                // Under 3.5 — ligne la plus sûre quand l'IA pousse pour Under
                $candidates[] = $this->buildPick($match, $aiDecision, $underRec ?? $bestRec, [
                    'market' => 'overUnder',
                    'pick' => 'Under 3.5',
                    'odds' => $under35,
                    'confidence' => $underConf,
                    'score' => $underScore,
                    'strategy' => 'under_safe',
                    'selection_reason' => "Under 3.5 — ligne la plus sûre (IA: {$decisionUnder} Under)",
                ]);
            } elseif ($under25 !== null && $under25 > 1.0) {
                // Fallback : Under 2.5 standard si 3.5 indisponible
                $candidates[] = $this->buildPick($match, $aiDecision, $underRec ?? $bestRec, [
                    'market' => 'overUnder',
                    'pick' => 'Under 2.5',
                    'odds' => $under25,
                    'confidence' => $underConf,
                    'score' => $underScore,
                    'strategy' => 'under_standard',
                    'selection_reason' => "Under 2.5 — Under 3.5 indisponible (IA: {$decisionUnder} Under)",
                ]);
            }

            // BTTS No — aligné avec la stratégie Under (corrélation directe)
            if ($match->odds_btts_no !== null && (float) $match->odds_btts_no > 1.0) {
                $bttsConf = $bttsNoRec?->confidence ?? $underConf;
                $bttsScore = $bttsNoRec?->score ?? $underScore;
                $candidates[] = $this->buildPick($match, $aiDecision, $bttsNoRec ?? $underRec ?? $bestRec, [
                    'market' => 'btts',
                    'pick' => 'BTTS No',
                    'odds' => (float) $match->odds_btts_no,
                    'confidence' => $bttsConf,
                    'score' => $bttsScore,
                    'strategy' => 'btts_no_under_aligned',
                    'selection_reason' => "BTTS Non — corrélé avec direction Under (IA: {$decisionUnder} Under)",
                ]);
            }
        }

        // ─── 3. Stratégie Over (uniquement si decision_global = BET/LEAN ET overRec présent) ───
        // Over 2.5 si cote ≤ 1.45 (très probable). Sinon Over 1.5 (fallback safe).
        // Comme DC : Over exige une décision IA globale tranchée (LEAN ou BET).
        $aiBoostsOver = $overRec !== null
            && in_array($decisionGlobal, ['LEAN', 'BET'], true);

        if ($aiBoostsOver) {
            $overConf = $overRec?->confidence ?? $confidence;
            $overScore = $overRec?->score ?? $bestRec->score;

            $over25 = $match->odds_over_2_5 !== null ? (float) $match->odds_over_2_5 : null;
            $over15 = $match->odds_over_1_5 !== null ? (float) $match->odds_over_1_5 : null;

            if ($over25 !== null && $over25 <= $this->overSafeThreshold && $over25 > 1.0) {
                // Over 2.5 quand la cote est suffisamment basse → on prend le risque
                $candidates[] = $this->buildPick($match, $aiDecision, $overRec ?? $bestRec, [
                    'market' => 'overUnder',
                    'pick' => 'Over 2.5',
                    'odds' => $over25,
                    'confidence' => $overConf,
                    'score' => $overScore,
                    'strategy' => 'over_safe',
                    'selection_reason' => "Over 2.5 — cote ≤ {$this->overSafeThreshold} (très probable)",
                ]);
            } elseif ($over15 !== null && $over15 > 1.0) {
                // Over 2.5 trop risqué → fallback Over 1.5 (plus safe)
                $candidates[] = $this->buildPick($match, $aiDecision, $overRec ?? $bestRec, [
                    'market' => 'overUnder',
                    'pick' => 'Over 1.5',
                    'odds' => $over15,
                    'confidence' => $overConf,
                    'score' => $overScore,
                    'strategy' => 'over_safe_fallback',
                    'selection_reason' => "Over 1.5 — Over 2.5 trop risqué (cote " . ($over25 ?? 'NA') . " > {$this->overSafeThreshold})",
                ]);
            }
        }

        // ─── 4. Double Chance : UNIQUEMENT si decision_global = LEAN ou BET, cote ≥ 1.10 ───
        $dcEligible = in_array($decisionGlobal, ['LEAN', 'BET'], true);

        if ($dcEligible) {
            $betLower = strtolower($bestRec->bet);
            $favorsHome = str_contains($betLower, 'home') || $bestRec->bet === '1' || $bestRec->bet === $match->home_team;
            $favorsAway = str_contains($betLower, 'away') || $bestRec->bet === '2' || $bestRec->bet === $match->away_team;
            $isDcRec = $bestRec->market === 'doubleChance';
            $reasonTag = "décision globale {$decisionGlobal}";

            $dc1x = $match->odds_dc_1x !== null ? (float) $match->odds_dc_1x : null;
            $dcX2 = $match->odds_dc_x2 !== null ? (float) $match->odds_dc_x2 : null;

            if (($favorsHome || (!$favorsHome && !$favorsAway) || $isDcRec)
                && $dc1x !== null && $dc1x >= 1.10) {
                $candidates[] = $this->buildPick($match, $aiDecision, $bestRec, [
                    'market' => 'doubleChance',
                    'pick' => '1X (Home or Draw)',
                    'odds' => $dc1x,
                    'confidence' => $confidence,
                    'score' => $bestRec->score,
                    'strategy' => 'dc_safe',
                    'selection_reason' => "Double Chance 1X — {$reasonTag} (couvre nul)",
                ]);
            }

            if (($favorsAway || (!$favorsHome && !$favorsAway) || $isDcRec)
                && $dcX2 !== null && $dcX2 >= 1.10) {
                $candidates[] = $this->buildPick($match, $aiDecision, $bestRec, [
                    'market' => 'doubleChance',
                    'pick' => 'X2 (Draw or Away)',
                    'odds' => $dcX2,
                    'confidence' => $confidence,
                    'score' => $bestRec->score,
                    'strategy' => 'dc_safe',
                    'selection_reason' => "Double Chance X2 — {$reasonTag} (couvre nul)",
                ]);
            }
        }

        // ─── Filtre final : exclure les picks à cote < 1.10 (trop évident, ne paie pas) ───
        $candidates = array_values(array_filter(
            $candidates,
            fn(array $c) => (float) $c['odds'] >= 1.10
        ));

        // Déduplication par (market + pick) — ex: la rec générale peut être Under 2.5
        $seen = [];
        $unique = [];
        foreach ($candidates as $c) {
            $key = $c['market'] . '|' . $c['pick'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $c;
            }
        }

        return $unique;
    }

    /**
     * Construire un pick complet (sortie sérialisable pour DailyCombo.picks).
     */
    private function buildPick(FootballMatch $match, ?AIAnalysis $aiDecision, $sourceRec, array $override): array
    {
        $clv = null;
        if ($match->odds_at_pred_home && $match->odds_closing_home) {
            $clv = ((float) $match->odds_at_pred_home / (float) $match->odds_closing_home - 1) * 100;
        }

        $aiScore = $match->advancedData?->ai_score ?? null;
        $finalDecision = $aiDecision?->final_decision ?? null;

        return array_merge([
            'match_id' => $match->id,
            'home' => $match->home_team,
            'away' => $match->away_team,
            'competition' => $match->competition,
            'league_id' => $match->league_id,
            'ai_score' => $aiScore,
            'clv' => $clv,
            'decision_global' => $finalDecision['decision_global'] ?? null,
            'decision_under' => $finalDecision['decision_under'] ?? null,
        ], $override);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // GÉNÉRATION DES COMBINAISONS MULTI-MARCHÉS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Pour chaque combinaison de 3-5 matchs distincts (anti-corrélation par ligue),
     * tester toutes les combinaisons possibles de marchés (1 candidat par match).
     */
    private function generateMultiMarketCombinations(Collection $matchesWithCandidates): array
    {
        $all = [];
        $items = $matchesWithCandidates->values()->toArray();
        $n = count($items);

        for ($size = $this->minMatches; $size <= min($this->maxMatches, $n); $size++) {
            $matchSubsets = [];
            $this->combineMatches($items, $size, 0, [], $matchSubsets);

            foreach ($matchSubsets as $subset) {
                // Anti-corrélation : pas 2 matchs du même championnat
                $leagues = array_map(fn($m) => $m['match']->league_id, $subset);
                if (count($leagues) !== count(array_unique($leagues))) {
                    continue;
                }

                $this->expandMarketCombinations($subset, 0, [], $all);
            }
        }

        return $all;
    }

    private function combineMatches(array $items, int $size, int $start, array $current, array &$results): void
    {
        if (count($current) === $size) {
            $results[] = $current;
            return;
        }

        $remaining = $size - count($current);
        for ($i = $start; $i <= count($items) - $remaining; $i++) {
            $current[] = $items[$i];
            $this->combineMatches($items, $size, $i + 1, $current, $results);
            array_pop($current);
        }
    }

    /**
     * Cartesian product des candidats pour chaque match du sous-ensemble.
     * Pruning : abandonner les branches dont le produit dépasse maxOdds.
     */
    private function expandMarketCombinations(array $subset, int $idx, array $currentPicks, array &$results): void
    {
        if ($idx === count($subset)) {
            $total = $this->calculateTotalOdds($currentPicks);
            if ($total >= $this->minOdds && $total <= $this->maxOdds) {
                $results[] = $currentPicks;
            }
            return;
        }

        $matchEntry = $subset[$idx];
        foreach ($matchEntry['candidates'] as $candidate) {
            $newPicks = $currentPicks;
            $newPicks[] = $candidate;

            // Pruning : si la cote courante dépasse déjà maxOdds, inutile de continuer
            $running = $this->calculateTotalOdds($newPicks);
            if ($running > $this->maxOdds) {
                continue;
            }

            $this->expandMarketCombinations($subset, $idx + 1, $newPicks, $results);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FILTRAGE DES COMBOS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function filterCombos(array $combos): Collection
    {
        return collect($combos)->filter(function (array $picks) {
            $totalOdds = $this->calculateTotalOdds($picks);
            return $totalOdds >= $this->minOdds && $totalOdds <= $this->maxOdds;
        });
    }

    private function calculateTotalOdds(array $picks): float
    {
        $total = 1.0;
        foreach ($picks as $pick) {
            $total *= $pick['odds'];
        }
        return round($total, 3);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SCORING DES COMBOS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function scoreCombos(Collection $combos): Collection
    {
        return $combos->map(function (array $picks) {
            $totalOdds = $this->calculateTotalOdds($picks);
            $avgConf = collect($picks)->avg('confidence');
            $avgScore = collect($picks)->avg('score');

            // Score du combo : pondération confiance + score algo
            $comboScore = $avgConf * 0.4 + $avgScore * 0.3;

            // Bonus si CLV positif sur tous les picks
            $clvValues = collect($picks)->pluck('clv')->filter(fn($v) => $v !== null);
            if ($clvValues->isNotEmpty() && $clvValues->min() > 0) {
                $comboScore += 10;
            }

            // Bonus IA
            $aiScores = collect($picks)->pluck('ai_score')->filter(fn($v) => $v !== null);
            if ($aiScores->isNotEmpty()) {
                $comboScore += $aiScores->avg() * 0.2;
            }

            // Bonus PRINCIPAL : proximité de la cible (2.00) — c'est le critère discriminant
            // Plus la cote est proche de 2.00, plus le bonus est élevé.
            $distFromTarget = abs($totalOdds - $this->targetOdds);
            $comboScore += max(0, 30 - $distFromTarget * 100);

            // Construire la logique détaillée (par match : marché choisi + raison)
            $parts = [];
            foreach ($picks as $p) {
                $marketLabel = $this->formatMarketLabel($p['market'], $p['pick']);
                $reason = $p['selection_reason'] ?? '-';
                $parts[] = "{$p['home']} v {$p['away']} → {$marketLabel} @{$p['odds']} ({$p['confidence']}%) [{$reason}]";
            }
            $logic = implode(' | ', $parts) . " || Total: @{$totalOdds} (cible {$this->targetOdds}) Score: " . round($comboScore, 1);

            // Identifiant stable du combo (pour dédup) = ensemble des match_id triés
            $matchSetKey = collect($picks)->pluck('match_id')->sort()->values()->implode('-');

            return [
                'picks' => $picks,
                'total_odds' => $totalOdds,
                'avg_confidence' => round($avgConf, 1),
                'score' => round($comboScore, 1),
                'logic' => $logic,
                'match_set_key' => $matchSetKey,
            ];
        })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Garder le meilleur combo par sous-ensemble de matchs (sinon le top 3 serait
     * dominé par 3 variantes de marchés sur les 3 mêmes matchs).
     */
    private function dedupByMatchSet(Collection $combos): Collection
    {
        $seen = [];
        $unique = [];

        foreach ($combos as $combo) {
            $key = $combo['match_set_key'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                unset($combo['match_set_key']);
                $unique[] = $combo;
            }
        }

        return collect($unique);
    }

    private function formatMarketLabel(string $market, string $pick): string
    {
        $marketNames = [
            'winner' => '1X2',
            'overUnder' => 'O/U',
            'btts' => 'BTTS',
            'doubleChance' => 'DC',
        ];
        $label = $marketNames[$market] ?? $market;
        return "{$label}: {$pick}";
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // COUCHE IA — ComboBuilderService (6ème agent)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Appeler le ComboBuilderService pour optimiser/valider le combo, et persister
     * le résultat en daily_combos avec rank=0 (distinct des combos algorithmiques rank 1-3).
     *
     * @param Collection $eligible  Matchs éligibles avec leurs candidats (issus de getEligibleMatchesWithCandidates)
     */
    private function buildAiCombo(Collection $eligible, string $date): array
    {
        if ($eligible->isEmpty()) {
            return ['combo' => null, 'recommendation' => null, 'confidence' => null, 'tokens' => null, 'error' => 'no_candidates'];
        }

        $built = $this->aiBuilder->build($eligible->all(), $date, $this->minMatches, $this->maxMatches);
        $output = $built['output'] ?? null;
        $tokens = $built['tokens'] ?? null;
        $error = $built['error'] ?? null;

        if (!$output) {
            Log::warning('ComboSelector: ComboBuilder a échoué', ['error' => $error]);
            return ['combo' => null, 'recommendation' => null, 'confidence' => null, 'tokens' => $tokens, 'error' => $error];
        }

        $picks = $output['picks'] ?? [];
        $recommendation = $output['recommendation'] ?? 'AVOID';
        $confidence = (int) ($output['combo_confidence'] ?? 0);
        $totalOdds = (float) ($output['actual_odds'] ?? 0);

        Log::info("ComboSelector: ComboBuilder résultat", [
            'recommendation' => $recommendation,
            'confidence' => $confidence,
            'actual_odds' => $totalOdds,
            'picks_count' => count($picks),
            'tokens' => $tokens,
        ]);

        // AVOID ou pas de picks → on ne sauvegarde rien en rank=0
        if ($recommendation === 'AVOID' || empty($picks)) {
            return ['combo' => null, 'recommendation' => $recommendation, 'confidence' => $confidence, 'tokens' => $tokens, 'error' => null];
        }

        // Garde stricte : le combo IA doit respecter le minimum (et le maximum) de matchs configurés
        $picksCount = count($picks);
        if ($picksCount < $this->minMatches || $picksCount > $this->maxMatches) {
            Log::warning('ComboSelector: combo IA rejeté (taille hors limites)', [
                'picks_count' => $picksCount,
                'min_matches' => $this->minMatches,
                'max_matches' => $this->maxMatches,
            ]);
            return [
                'combo' => null,
                'recommendation' => $recommendation,
                'confidence' => $confidence,
                'tokens' => $tokens,
                'error' => "ai_picks_count_out_of_bounds ({$picksCount} not in [{$this->minMatches},{$this->maxMatches}])",
            ];
        }

        // Sauvegarder en daily_combos rank=0 — supprime d'abord l'ancien combo IA du jour
        DailyCombo::where('date', $date)->where('rank', 0)->delete();

        // Construire la logique lisible
        $logicParts = [];
        foreach ($picks as $p) {
            $logicParts[] = ($p['match'] ?? '?') . ' → ' . ($p['market'] ?? '?')
                . ' @' . ($p['odds'] ?? '?')
                . ' [safety ' . ($p['safety_score'] ?? '?') . '] '
                . ($p['reasoning'] ?? '');
        }
        $logic = "[AI ComboBuilder | {$recommendation} | conf {$confidence}%] "
            . implode(' | ', $logicParts)
            . " || Total: @{$totalOdds} — " . ($output['overall_reasoning'] ?? '')
            . " — Failure: " . ($output['failure_scenario'] ?? '?');

        $combo = DailyCombo::create([
            'date' => $date,
            'rank' => 0,
            'picks' => $picks,
            'match_count' => count($picks),
            'total_odds' => $totalOdds > 0 ? $totalOdds : 1,
            'combo_score' => $confidence,
            'avg_confidence' => $confidence,
            'logic' => $logic,
        ]);

        return [
            'combo' => $combo,
            'recommendation' => $recommendation,
            'confidence' => $confidence,
            'tokens' => $tokens,
            'error' => null,
        ];
    }
}
