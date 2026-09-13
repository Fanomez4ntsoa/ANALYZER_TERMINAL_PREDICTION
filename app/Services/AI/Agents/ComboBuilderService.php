<?php

namespace App\Services\AI\Agents;

use App\Models\AIAnalysis;
use App\Models\FootballMatch;
use App\Services\AI\ClaudeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 6ème agent IA — Combo Builder.
 *
 * Reçoit les picks candidats par match (générés par ComboSelectorService) et choisit
 * intelligemment le marché à prendre sur chaque match pour construire le combo le
 * plus proche de la cote cible 2.00, tout en favorisant la sécurité.
 */
class ComboBuilderService
{
    private const MODEL = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 1500;
    private const CACHE_TTL_HOURS = 6;

    private ClaudeClient $claude;

    /**
     * Construire le system-prompt avec injection des contraintes min/max picks.
     * Dynamique car min_matches / max_matches sont config-driven (env COMBO_MIN/MAX_MATCHES).
     */
    private function buildSystemPrompt(int $minMatches, int $maxMatches): string
    {
        return <<<PROMPT
You are a combo builder for sports betting.
Your goal is to select the SAFEST possible market for each match that contributes to a total combo odds of approximately 2.00.

OUTPUT FORMAT — CRITICAL:
- Return ONLY a single valid JSON object. No markdown. No code fences. No commentary before or after.
- Keep all reasoning fields to ONE short sentence (max 25 words). Do not write paragraphs.
- Total output budget is small — be terse.

COMBO SIZE — STRICT:
- You must select exactly {$minMatches} to {$maxMatches} picks (one market per distinct match).
- If you cannot find at least {$minMatches} safe picks → return picks=[] with recommendation="AVOID".
- Never return more than {$maxMatches} picks.

PRIORITY RULES:
- If Under analysis is strong → prefer Under 3.5 over Under 2.5 (more margin, safer)
- If Under 3.5 odds > 1.60 → consider BTTS No or DC as alternative
- If match analysis shows defensive game → Under options preferred
- If match analysis shows one-sided → DC for stronger team preferred
- Never select Over markets unless explicitly justified by match analysis

COMBO TARGET:
- Total odds: 2.00 (acceptable range: 1.85 - 2.15)
- Pick exactly ONE market per match
- Never select two matches from the same league (anti-correlation)
- If no acceptable combo can be built, return picks=[] with recommendation="AVOID"

JSON SCHEMA (return EXACTLY these keys):
{
  "combo_confidence": 0,
  "target_odds": 2.00,
  "actual_odds": 0.00,
  "picks": [
    {"match_id": 0, "match": "Team A vs Team B", "market": "Under 3.5", "odds": 0.00, "reasoning": "one short sentence", "safety_score": 0}
  ],
  "weakest_pick": "match name",
  "overall_reasoning": "one short sentence",
  "recommendation": "BET",
  "failure_scenario": "one short sentence"
}

DECISION RULES:
- combo_confidence < 60 → recommendation = "AVOID"
- combo_confidence 60-75 → recommendation = "LEAN"
- combo_confidence > 75 → recommendation = "BET"
- safety_score: 0-100 (100 = quasi-certain win, 0 = coin flip)
PROMPT;
    }

    public function __construct(ClaudeClient $claude)
    {
        $this->claude = $claude;
    }

    /**
     * Construire un combo optimal à partir des candidats par match.
     *
     * @param array  $matchesWithCandidates  Chaque entrée = ['match' => FootballMatch, 'candidates' => [...]]
     * @param string $date                   Date du combo (clé de cache)
     * @param int    $minMatches             Minimum de picks attendu dans le combo IA
     * @param int    $maxMatches             Maximum de picks
     * @return array ['output' => array|null, 'tokens' => [...], 'error' => string|null]
     */
    public function build(array $matchesWithCandidates, string $date, int $minMatches = 3, int $maxMatches = 5): array
    {
        if (empty($matchesWithCandidates)) {
            return [
                'output' => null,
                'tokens' => ['input' => 0, 'output' => 0],
                'error' => 'Aucun candidat fourni au ComboBuilder',
            ];
        }

        $cacheKey = $this->cacheKey($matchesWithCandidates, $date, $minMatches, $maxMatches);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            Log::debug("ComboBuilder: cache hit ({$cacheKey})");
            return $cached;
        }

        $systemPrompt = $this->buildSystemPrompt($minMatches, $maxMatches);
        $userMessage = $this->buildContext($matchesWithCandidates, $date, $minMatches, $maxMatches);

        Log::info('ComboBuilder: appel Claude', [
            'date' => $date,
            'matches' => count($matchesWithCandidates),
            'min_matches' => $minMatches,
            'max_matches' => $maxMatches,
            'model' => self::MODEL,
        ]);

        $response = $this->claude->ask($systemPrompt, $userMessage, self::MAX_TOKENS, self::MODEL);

        $result = [
            'output' => $response['content'],
            'tokens' => [
                'input' => $response['input_tokens'] ?? 0,
                'output' => $response['output_tokens'] ?? 0,
            ],
            'error' => $response['error'] ?? null,
        ];

        if ($result['output']) {
            $result['output'] = $this->normalizeOutput($result['output']);
            Cache::put($cacheKey, $result, now()->addHours(self::CACHE_TTL_HOURS));
        }

        return $result;
    }

    /**
     * Construire la clé de cache (stable pour le même set de matchs + mêmes contraintes).
     */
    private function cacheKey(array $matchesWithCandidates, string $date, int $minMatches, int $maxMatches): string
    {
        $ids = collect($matchesWithCandidates)
            ->pluck('match.id')
            ->sort()
            ->values()
            ->implode('-');

        return "ai_combo_builder_{$date}_min{$minMatches}_max{$maxMatches}_{$ids}";
    }

    /**
     * Construire le user-message avec toutes les données nécessaires à l'agent.
     */
    private function buildContext(array $matchesWithCandidates, string $date, int $minMatches = 3, int $maxMatches = 5): string
    {
        $aiDecisions = AIAnalysis::whereIn('match_id', collect($matchesWithCandidates)->pluck('match.id'))
            ->get()
            ->keyBy('match_id');

        $blocks = [];
        $blocks[] = "DATE: {$date}";
        $blocks[] = "TARGET COMBO ODDS: 2.00 (acceptable 1.85-2.15)";
        $blocks[] = "REQUIRED COMBO SIZE: between {$minMatches} and {$maxMatches} picks (return picks=[] with recommendation=AVOID if not feasible)";
        $blocks[] = "NUMBER OF MATCHES PROVIDED: " . count($matchesWithCandidates);
        $blocks[] = '';

        foreach ($matchesWithCandidates as $idx => $entry) {
            /** @var FootballMatch $match */
            $match = $entry['match'];
            $candidates = $entry['candidates'];
            $ai = $aiDecisions->get($match->id);
            $finalDecision = $ai?->final_decision ?? [];

            $blocks[] = "===== MATCH " . ($idx + 1) . " =====";
            $blocks[] = "match_id: {$match->id}";
            $blocks[] = "fixture: {$match->home_team} vs {$match->away_team}";
            $blocks[] = "league_id: {$match->league_id} ({$match->competition})";
            $blocks[] = "kickoff: {$match->match_date}";
            $blocks[] = '';

            // Décisions IA
            $blocks[] = 'AI DECISIONS:';
            $blocks[] = '  decision_global: ' . ($finalDecision['decision_global'] ?? '-');
            $blocks[] = '  decision_under:  ' . ($finalDecision['decision_under'] ?? '-');
            if (isset($finalDecision['under_margin'])) {
                $blocks[] = '  under_margin:    ' . $finalDecision['under_margin'] . '%';
            }
            $blocks[] = '  edge_quality:    ' . ($finalDecision['edge_quality'] ?? '-');
            $blocks[] = '  reasoning:       ' . ($finalDecision['reasoning'] ?? '-');
            $blocks[] = '  failure_scenario: ' . ($finalDecision['failure_scenario'] ?? '-');
            $blocks[] = '';

            // Toutes les cotes disponibles
            $blocks[] = 'AVAILABLE ODDS:';
            $blocks[] = '  Match Winner: 1=' . $this->fmt($match->odds_home)
                . ' X=' . $this->fmt($match->odds_draw)
                . ' 2=' . $this->fmt($match->odds_away);
            $blocks[] = '  Goals Over/Under:';
            $blocks[] = '    1.5: Over=' . $this->fmt($match->odds_over_1_5) . ' Under=' . $this->fmt($match->odds_under_1_5);
            $blocks[] = '    2.5: Over=' . $this->fmt($match->odds_over_2_5) . ' Under=' . $this->fmt($match->odds_under_2_5);
            $blocks[] = '    3.5: Over=' . $this->fmt($match->odds_over_3_5) . ' Under=' . $this->fmt($match->odds_under_3_5);
            $blocks[] = '    4.5: Over=' . $this->fmt($match->odds_over_4_5) . ' Under=' . $this->fmt($match->odds_under_4_5);
            $blocks[] = '  BTTS: Yes=' . $this->fmt($match->odds_btts_yes) . ' No=' . $this->fmt($match->odds_btts_no);
            $blocks[] = '  Double Chance: 1X=' . $this->fmt($match->odds_dc_1x)
                . ' 12=' . $this->fmt($match->odds_dc_12)
                . ' X2=' . $this->fmt($match->odds_dc_x2);
            $blocks[] = '';

            // Candidats pré-sélectionnés par ComboSelectorService
            $blocks[] = 'PRESELECTED CANDIDATES (from ComboSelector):';
            foreach ($candidates as $c) {
                $blocks[] = "  - [{$c['strategy']}] {$c['market']}={$c['pick']} @{$c['odds']} "
                    . "(conf {$c['confidence']}%) — " . ($c['selection_reason'] ?? '');
            }
            $blocks[] = '';
        }

        $blocks[] = "INSTRUCTIONS:";
        $blocks[] = "Choose ONE market per match. Pick the safest legitimate option.";
        $blocks[] = "You MUST return between {$minMatches} and {$maxMatches} picks.";
        $blocks[] = "Total combo odds must land between 1.85 and 2.15.";
        $blocks[] = "If you cannot find {$minMatches}+ safe picks (or odds out of range), return picks=[] with recommendation=AVOID and explain in failure_scenario.";

        return implode("\n", $blocks);
    }

    private function fmt(mixed $value): string
    {
        if ($value === null || $value === '') return '-';
        return (string) (float) $value;
    }

    /**
     * Normaliser la sortie : recalculer actual_odds depuis les picks pour vérifier la cohérence,
     * forcer la recommendation selon les règles, etc.
     */
    private function normalizeOutput(array $output): array
    {
        $picks = $output['picks'] ?? [];

        // Recalcul actual_odds (Claude peut se tromper)
        $product = 1.0;
        foreach ($picks as $p) {
            $product *= (float) ($p['odds'] ?? 1.0);
        }
        $output['actual_odds'] = round($product, 3);

        // Forcer la recommendation selon les seuils stricts
        $confidence = (int) ($output['combo_confidence'] ?? 0);
        if ($confidence < 60) {
            $forced = 'AVOID';
        } elseif ($confidence <= 75) {
            $forced = 'LEAN';
        } else {
            $forced = 'BET';
        }
        if (($output['recommendation'] ?? null) !== $forced) {
            Log::info('ComboBuilder: recommendation forcée', [
                'claude' => $output['recommendation'] ?? null,
                'forced' => $forced,
                'confidence' => $confidence,
            ]);
            $output['recommendation'] = $forced;
        }

        return $output;
    }
}
