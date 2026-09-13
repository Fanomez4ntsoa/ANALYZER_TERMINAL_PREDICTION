<?php

namespace App\Services\AI\Agents;

use App\Models\FootballMatch;
use App\Services\AI\ClaudeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ValueHunterService
{
    private ClaudeClient $claude;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a value betting detection engine.
Your goal is to identify bets where the model probability is higher than the implied probability from market odds.

You MUST:
- Compare model probabilities vs implied odds for each market
- Detect undervalued outcomes (positive expected value)
- Focus ONLY on mathematical edge, ignore narratives
- Return empty value_bets array if no value found

OUTPUT strictly valid JSON:
{
  "value_bets": [
    {
      "market": "winner|overUnder|btts|doubleChance",
      "pick": "",
      "model_probability": 0,
      "implied_probability": 0,
      "value_margin": 0,
      "odds": 0,
      "confidence": 0,
      "reason": ""
    }
  ],
  "best_pick": {"market": "", "pick": "", "value_margin": 0},
  "overall_value_score": 0
}

RULES:
- value_margin = model_probability - implied_probability (in percentage points)
- Only include bets with value_margin > 3%
- overall_value_score: 0-100 (0=no value anywhere, 100=extreme value)
- If no value found, return {"value_bets": [], "best_pick": null, "overall_value_score": 0}
- No safe picks. No hedging. Only positive EV.
PROMPT;

    public function __construct(ClaudeClient $claude)
    {
        $this->claude = $claude;
    }

    /**
     * Analyser un match pour détecter les value bets.
     *
     * @return array ['output' => array|null, 'tokens' => ['input' => int, 'output' => int], 'error' => string|null]
     */
    public function analyze(FootballMatch $match, array $analysisData): array
    {
        $cacheKey = "ai_value_{$match->id}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $userMessage = $this->buildContext($match, $analysisData);

        Log::info("ValueHunter: analyse {$match->full_name}");

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
        $odds = [
            'home' => (float) $match->odds_home,
            'draw' => (float) $match->odds_draw,
            'away' => (float) $match->odds_away,
            'over_2_5' => (float) $match->odds_over_2_5,
            'under_2_5' => (float) $match->odds_under_2_5,
        ];

        // Probabilités implicites des cotes (sans marge)
        $implied = [];
        $overround1x2 = 0;
        if ($odds['home'] > 0 && $odds['draw'] > 0 && $odds['away'] > 0) {
            $overround1x2 = (1 / $odds['home']) + (1 / $odds['draw']) + (1 / $odds['away']);
            $implied['home'] = round((1 / $odds['home']) / $overround1x2 * 100, 1);
            $implied['draw'] = round((1 / $odds['draw']) / $overround1x2 * 100, 1);
            $implied['away'] = round((1 / $odds['away']) / $overround1x2 * 100, 1);
        }
        if ($odds['over_2_5'] > 0 && $odds['under_2_5'] > 0) {
            $ouRound = (1 / $odds['over_2_5']) + (1 / $odds['under_2_5']);
            $implied['over'] = round((1 / $odds['over_2_5']) / $ouRound * 100, 1);
            $implied['under'] = round((1 / $odds['under_2_5']) / $ouRound * 100, 1);
        }

        // Probabilités du modèle depuis les recommandations
        $modelProbs = [];
        foreach ($recommendations as $rec) {
            $modelProbs[$rec['market']] = [
                'pick' => $rec['bet'],
                'confidence' => $rec['confidence'],
            ];
        }

        $context = "MATCH: {$match->home_team} vs {$match->away_team}\n";
        $context .= "COMPETITION: {$match->competition}\n";
        $context .= "DATE: {$match->match_date}\n\n";

        $context .= "BOOKMAKER ODDS:\n";
        $context .= "  1X2: Home={$odds['home']} Draw={$odds['draw']} Away={$odds['away']}\n";
        $context .= "  O/U 2.5: Over={$odds['over_2_5']} Under={$odds['under_2_5']}\n\n";

        $context .= "IMPLIED PROBABILITIES (margin removed):\n";
        foreach ($implied as $k => $v) {
            $context .= "  {$k}: {$v}%\n";
        }

        $context .= "\nMODEL PREDICTIONS:\n";
        foreach ($modelProbs as $market => $data) {
            $context .= "  {$market}: pick={$data['pick']} confidence={$data['confidence']}%\n";
        }

        $context .= "\nGLOBAL CONFIDENCE: {$match->global_confidence}%\n";

        return $context;
    }
}
