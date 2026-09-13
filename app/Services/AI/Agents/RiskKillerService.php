<?php

namespace App\Services\AI\Agents;

use App\Models\FootballMatch;
use App\Services\AI\ClaudeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RiskKillerService
{
    private ClaudeClient $claude;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a risk elimination engine for football betting.
Your goal is to find reasons why a bet should NOT be taken.
You must be harsh, contrarian, and assume the model is overconfident.

Detect:
- Statistical overconfidence (sample size, regression to mean)
- Trap games (underdog motivation, schedule spots)
- Data inconsistencies (model says X but context says Y)
- Hidden risks the algorithm cannot see
- Why the bookmaker might be right and the model wrong

OUTPUT strictly valid JSON:
{
  "risk_score": 0,
  "red_flags": ["string"],
  "trap_signals": ["string"],
  "most_dangerous_assumption": "",
  "model_blind_spots": ["string"],
  "recommendation": "PROCEED|CAUTION|AVOID"
}

RULES:
- risk_score: 0-100 (0=safe, 100=extremely dangerous)
- Be pessimistic. Find problems.
- If injury data shows key absences, flag it prominently
- If the model confidence is high but odds don't reflect it, explain why the market might know something
- "PROCEED" only if risk_score < 30
- "AVOID" if risk_score > 70
- No positive bias. You are the devil's advocate.
PROMPT;

    public function __construct(ClaudeClient $claude)
    {
        $this->claude = $claude;
    }

    public function analyze(FootballMatch $match, array $analysisData): array
    {
        $cacheKey = "ai_risk_{$match->id}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $userMessage = $this->buildContext($match, $analysisData);

        Log::info("RiskKiller: analyse {$match->full_name}");

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
            Cache::put($cacheKey, $result, now()->addHours(6));
        }

        return $result;
    }

    private function buildContext(FootballMatch $match, array $analysisData): string
    {
        $recommendations = $analysisData['recommendations'] ?? [];
        $adv = $match->advancedData;
        $context = $adv?->context_data ?? [];
        $injuries = $adv?->sofascore_data['injuries'] ?? [];

        $msg = "MATCH: {$match->home_team} vs {$match->away_team}\n";
        $msg .= "COMPETITION: {$match->competition}\n";
        $msg .= "DATE: {$match->match_date}\n\n";

        // Cotes
        $msg .= "ODDS: Home={$match->odds_home} Draw={$match->odds_draw} Away={$match->odds_away}\n";
        $msg .= "MODEL CONFIDENCE: {$match->global_confidence}%\n\n";

        // Recommandations du modèle
        $msg .= "MODEL RECOMMENDATIONS:\n";
        foreach ($recommendations as $rec) {
            $msg .= "  {$rec['market']}: {$rec['bet']} (confidence={$rec['confidence']}% score={$rec['score']})\n";
        }

        // Blessures
        $homeInjuries = $injuries['home'] ?? [];
        $awayInjuries = $injuries['away'] ?? [];
        if (!empty($homeInjuries) || !empty($awayInjuries)) {
            $msg .= "\nINJURIES:\n";
            // Dédupliquer
            $homeNames = array_unique(array_column($homeInjuries, 'player'));
            $awayNames = array_unique(array_column($awayInjuries, 'player'));
            $msg .= "  Home ({$match->home_team}): " . (empty($homeNames) ? 'none' : implode(', ', $homeNames)) . "\n";
            $msg .= "  Away ({$match->away_team}): " . (empty($awayNames) ? 'none' : implode(', ', $awayNames)) . "\n";
        }

        // Contexte enrichi
        $weather = $context['weather'] ?? [];
        if (!empty($weather['condition']) && $weather['condition'] !== 'unknown') {
            $msg .= "\nWEATHER: {$weather['condition']}, {$weather['temperature']}C, wind {$weather['wind_speed']}km/h\n";
        }

        $fatigue = $context['fatigue'] ?? [];
        if ($fatigue['available'] ?? false) {
            $msg .= "FATIGUE: Home={$fatigue['home']['fatigue_score']}/100 Away={$fatigue['away']['fatigue_score']}/100\n";
        }

        $stakes = $context['stakes'] ?? [];
        if ($stakes['available'] ?? false) {
            $homeStake = $stakes['home']['description'] ?? 'unknown';
            $awayStake = $stakes['away']['description'] ?? 'unknown';
            $msg .= "STAKES: Home={$homeStake} Away={$awayStake}\n";
        }

        $pressure = $context['coachPressure'] ?? [];
        if ($pressure['available'] ?? false) {
            $homePress = $pressure['home']['description'] ?? '-';
            $awayPress = $pressure['away']['description'] ?? '-';
            $msg .= "COACH PRESSURE: Home={$homePress} Away={$awayPress}\n";
        }

        return $msg;
    }
}
