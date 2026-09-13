<?php

namespace App\Services\AI\Agents;

use App\Models\FootballMatch;
use App\Services\AI\ClaudeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalJudgeService
{
    private ClaudeClient $claude;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the final decision engine for a football betting system.
You receive analyses from two specialized agents: a Value Hunter (finds positive EV) and a Risk Killer (finds reasons to avoid).
Your job is to synthesize their views into a single actionable decision.

HARD RULES (non-negotiable):
- If risk_score > 80 → decision MUST be "NO BET"
- If value_score > 70 AND risk_score < 50 → decision is "BET"
- Otherwise → "LEAN" with clear explanation of which side is slightly favored

OUTPUT strictly valid JSON:
{
  "decision": "BET|NO BET|LEAN",
  "confidence": 0,
  "dominant_factor": "value|risk|market|narrative",
  "edge_quality": "strong|medium|weak|none",
  "reasoning": "",
  "failure_scenario": ""
}

RULES:
- confidence: 0-100 (how sure you are of your decision)
- dominant_factor: which input swayed your decision most
- edge_quality: strong=clear EV with low risk, medium=EV but caveats, weak=marginal, none=no edge
- reasoning: 1-2 sentences explaining WHY
- failure_scenario: 1 sentence describing what would need to happen for this bet to fail
- If doubt → NO BET. If risk is high → NO BET. Avoid overconfidence.
- Do not repeat the input data, synthesize it.
PROMPT;

    public function __construct(ClaudeClient $claude)
    {
        $this->claude = $claude;
    }

    public function decide(FootballMatch $match, ?array $valueOutput, ?array $riskOutput, ?array $matchAnalystOutput = null): array
    {
        $cacheKey = "ai_judge_{$match->id}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $valueScore = $valueOutput['overall_value_score'] ?? 0;
        $riskScore = $riskOutput['risk_score'] ?? 0;

        // Hard rule globale (anciens seuils)
        $hardRuleDecision = $this->applyHardRules($valueScore, $riskScore);

        // Decision specifique Under 2.5 (calibration v2 — apres weekend 25-26 avril)
        $modelConfidence = (int) ($match->global_confidence ?? 0);
        $underDecision = $this->decideUnderMarket($valueOutput, $riskScore, $modelConfidence, $matchAnalystOutput);

        $userMessage = $this->buildContext($match, $valueOutput, $riskOutput, $hardRuleDecision, $matchAnalystOutput);

        Log::info("FinalJudge: decision {$match->full_name}", [
            'value_score' => $valueScore,
            'risk_score' => $riskScore,
            'hard_rule' => $hardRuleDecision,
            'under_decision' => $underDecision['decision_under'],
            'under_margin' => $underDecision['under_margin'],
            'analyst_verdict' => $matchAnalystOutput['verdict'] ?? null,
        ]);

        $response = $this->claude->ask(self::SYSTEM_PROMPT, $userMessage);

        $result = [
            'output' => $response['content'],
            'tokens' => [
                'input' => $response['input_tokens'],
                'output' => $response['output_tokens'],
            ],
            'error' => $response['error'],
        ];

        if ($result['output']) {
            // Hard rule globale forcée si Claude s'en écarte
            if ($hardRuleDecision && ($result['output']['decision'] ?? '') !== $hardRuleDecision) {
                Log::warning("FinalJudge: Claude a ignore la hard rule globale", [
                    'claude_decision' => $result['output']['decision'] ?? null,
                    'hard_rule' => $hardRuleDecision,
                ]);
                $result['output']['decision'] = $hardRuleDecision;
                $result['output']['reasoning'] = '[Hard rule appliquee] ' . ($result['output']['reasoning'] ?? '');
            }

            // Renommer 'decision' en 'decision_global' et ajouter 'decision_under'
            $result['output']['decision_global'] = $result['output']['decision'] ?? 'NO BET';
            $result['output']['decision_under'] = $underDecision['decision_under'];
            $result['output']['under_margin'] = $underDecision['under_margin'];
            $result['output']['under_reasoning'] = $underDecision['under_reasoning'];
            $result['output']['analyst_verdict'] = $underDecision['analyst_verdict'] ?? null;

            Cache::put($cacheKey, $result, now()->addHours(6));
        }

        return $result;
    }

    /**
     * Décision spécifique pour le marché Under 2.5 (seuils ajustés).
     *
     * Calibration : Under 2.5 atteint 72% de réussite réelle dans nos backtests,
     * mais le Risk Killer avait tendance à bloquer ce marché trop systématiquement.
     * Seuils plus permissifs uniquement pour ce marché.
     *
     * Le verdict de Match Analyst module l'edge et le risque :
     *   CONFIRMS_UNDER    → margin +5, riskScore -10
     *   DOUBTS_UNDER      → neutre
     *   CONTRADICTS_UNDER → margin -10, riskScore +15
     */
    private function decideUnderMarket(?array $valueOutput, int $riskScore, int $modelConfidence = 0, ?array $analystOutput = null): array
    {
        $bets = $valueOutput['value_bets'] ?? [];
        $underBet = collect($bets)->first(function ($b) {
            $market = strtolower($b['market'] ?? '');
            $pick = strtolower($b['pick'] ?? '');
            return ($market === 'overunder' || $market === 'over/under') && str_contains($pick, 'under');
        });

        if (!$underBet) {
            return [
                'decision_under' => 'NO BET',
                'under_margin' => 0,
                'under_reasoning' => 'Pas de value detectee sur Under 2.5',
                'analyst_verdict' => $analystOutput['verdict'] ?? null,
            ];
        }

        $margin = (float) ($underBet['value_margin'] ?? 0);
        $analystVerdict = $analystOutput['verdict'] ?? null;
        $analystNote = '';

        if ($analystVerdict === 'CONFIRMS_UNDER') {
            $margin += 5;
            $riskScore = max(0, $riskScore - 10);
            $analystNote = ' [Analyst CONFIRMS]';
        } elseif ($analystVerdict === 'CONTRADICTS_UNDER') {
            $margin -= 10;
            $riskScore = min(100, $riskScore + 15);
            $analystNote = ' [Analyst CONTRADICTS]';
        } elseif ($analystVerdict === 'DOUBTS_UNDER') {
            $analystNote = ' [Analyst DOUBTS]';
        }

        // Calibration v2 (post weekend 25-26 avril) :
        // - BET necessite margin > 20% ET risk < 80 ET model_confidence >= 57%
        // - LEAN si conditions BET sauf confidence (>= 57%)
        // - LEAN aussi si risk >= 85 mais margin > 20% (edge tres fort)
        // - LEAN entre seuils intermediaires
        // - NO BET sinon

        // Cas 1 : edge fort (>20%) avec risk acceptable (<80)
        if ($margin > 20 && $riskScore < 80) {
            if ($modelConfidence >= 57) {
                return [
                    'decision_under' => 'BET',
                    'under_margin' => $margin,
                    'under_reasoning' => "Edge fort (+{$margin}%), risque acceptable ({$riskScore}/100), confiance modele {$modelConfidence}%{$analystNote}",
                    'analyst_verdict' => $analystVerdict,
                ];
            }
            return [
                'decision_under' => 'LEAN',
                'under_margin' => $margin,
                'under_reasoning' => "Edge fort (+{$margin}%) mais confiance modele insuffisante ({$modelConfidence}% < 57%) — downgrade BET vers LEAN{$analystNote}",
                'analyst_verdict' => $analystVerdict,
            ];
        }

        // Cas 2 : edge tres fort (>20%) malgre risque eleve (>= 85)
        // Hard rule assouplie : on ne BET jamais si risk >= 85, mais LEAN possible
        if ($margin > 20 && $riskScore >= 85) {
            return [
                'decision_under' => 'LEAN',
                'under_margin' => $margin,
                'under_reasoning' => "Edge tres fort (+{$margin}%) mais risque eleve ({$riskScore}/100) — LEAN au lieu de NO BET{$analystNote}",
                'analyst_verdict' => $analystVerdict,
            ];
        }

        // Cas 3 : edge significatif (>15%) avec risque modere (<85)
        if ($margin > 15 && $riskScore < 85) {
            return [
                'decision_under' => 'LEAN',
                'under_margin' => $margin,
                'under_reasoning' => "Edge significatif (+{$margin}%), risque modere ({$riskScore}/100){$analystNote}",
                'analyst_verdict' => $analystVerdict,
            ];
        }

        // Cas 4 : risque eleve (>= 85) ET edge insuffisant (<= 20%)
        if ($riskScore >= 85) {
            return [
                'decision_under' => 'NO BET',
                'under_margin' => $margin,
                'under_reasoning' => "Risque trop eleve ({$riskScore}/100) et edge insuffisant (+{$margin}% <= 20%){$analystNote}",
                'analyst_verdict' => $analystVerdict,
            ];
        }

        return [
            'decision_under' => 'NO BET',
            'under_margin' => $margin,
            'under_reasoning' => "Edge insuffisant (+{$margin}% < 15%){$analystNote}",
            'analyst_verdict' => $analystVerdict,
        ];
    }

    /**
     * Appliquer les règles dures avant d'appeler l'IA.
     */
    private function applyHardRules(int $valueScore, int $riskScore): ?string
    {
        if ($riskScore > 80) return 'NO BET';
        if ($valueScore > 70 && $riskScore < 50) return 'BET';
        return null; // Laisser Claude decider (LEAN probablement)
    }

    private function buildContext(FootballMatch $match, ?array $valueOutput, ?array $riskOutput, ?string $hardRule, ?array $analystOutput = null): string
    {
        $msg = "MATCH: {$match->home_team} vs {$match->away_team}\n";
        $msg .= "COMPETITION: {$match->competition}\n\n";

        // Match Analyst output (independent context analyst)
        if ($analystOutput) {
            $msg .= "--- MATCH ANALYST (independent) ---\n";
            $msg .= "Verdict: " . ($analystOutput['verdict'] ?? '?') . "\n";
            $msg .= "Confidence: " . ($analystOutput['confidence'] ?? 0) . "/100\n";
            $msg .= "Context score for Under: " . ($analystOutput['context_score'] ?? 0) . "/100\n";
            if (!empty($analystOutput['main_risk'])) {
                $msg .= "Main risk: " . $analystOutput['main_risk'] . "\n";
            }
            if (!empty($analystOutput['key_factors']) && is_array($analystOutput['key_factors'])) {
                $msg .= "Key factors: " . implode(' | ', array_slice($analystOutput['key_factors'], 0, 3)) . "\n";
            }
            if (!empty($analystOutput['reasoning'])) {
                $msg .= "Reasoning: " . $analystOutput['reasoning'] . "\n";
            }
            $msg .= "\n";
        }

        // Value Hunter output
        if ($valueOutput) {
            $msg .= "--- VALUE HUNTER ---\n";
            $msg .= "Overall value score: " . ($valueOutput['overall_value_score'] ?? 0) . "/100\n";

            $bets = $valueOutput['value_bets'] ?? [];
            if (empty($bets)) {
                $msg .= "No value bets found.\n";
            } else {
                $msg .= count($bets) . " value bet(s) found:\n";
                foreach ($bets as $b) {
                    $market = $b['market'] ?? '?';
                    $pick = $b['pick'] ?? '?';
                    $margin = $b['value_margin'] ?? 0;
                    $reason = $b['reason'] ?? '';
                    $msg .= "  - {$market} {$pick}: +{$margin}% edge ({$reason})\n";
                }
            }

            $best = $valueOutput['best_pick'] ?? null;
            if ($best) {
                $bestMarket = $best['market'] ?? '?';
                $bestPick = $best['pick'] ?? '';
                $bestMargin = $best['value_margin'] ?? 0;
                $msg .= "Best pick: {$bestMarket} {$bestPick} (+{$bestMargin}%)\n";
            }
        } else {
            $msg .= "--- VALUE HUNTER ---\nNo analysis available.\n";
        }

        $msg .= "\n";

        // Risk Killer output
        if ($riskOutput) {
            $msg .= "--- RISK KILLER ---\n";
            $msg .= "Risk score: " . ($riskOutput['risk_score'] ?? 0) . "/100\n";
            $msg .= "Recommendation: " . ($riskOutput['recommendation'] ?? '?') . "\n";

            $flags = $riskOutput['red_flags'] ?? [];
            if (!empty($flags)) {
                $msg .= "Red flags (" . count($flags) . "):\n";
                foreach (array_slice($flags, 0, 5) as $f) {
                    $msg .= "  - {$f}\n";
                }
            }

            $traps = $riskOutput['trap_signals'] ?? [];
            if (!empty($traps)) {
                $msg .= "Trap signals (" . count($traps) . "):\n";
                foreach (array_slice($traps, 0, 3) as $t) {
                    $msg .= "  - {$t}\n";
                }
            }

            $danger = $riskOutput['most_dangerous_assumption'] ?? '';
            if ($danger) {
                $msg .= "Most dangerous assumption: {$danger}\n";
            }
        } else {
            $msg .= "--- RISK KILLER ---\nNo analysis available.\n";
        }

        if ($hardRule) {
            $msg .= "\nHARD RULE TRIGGERED: Decision must be \"{$hardRule}\".\n";
        }

        $msg .= "\nSynthesize and decide.";

        return $msg;
    }
}
