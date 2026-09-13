<?php

namespace App\Services\AI\Agents;

use App\Models\FootballMatch;
use App\Services\AI\ClaudeClient;
use App\Services\Probability\XGModelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Agent d'analyse contextuelle indépendant.
 *
 * Tourne AVANT Value Hunter et Risk Killer. Reçoit la prédiction du modèle
 * Poisson calibré et le contexte complet du match (forme, blessures, H2H,
 * enjeu, météo, pression coach) ; rend un verdict honnête sur la fiabilité
 * de la prédiction Under 2.5. Son verdict est consommé par Final Judge.
 */
class MatchAnalystService
{
    private const MODEL = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 800;
    private const CACHE_HOURS = 6;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are an independent football match analyst.
You know the Poisson model predictions but you are NOT obligated to agree with them.
Your job is to analyze the match context and give your honest opinion on Under 2.5.

You have access to:
- Model prediction (Poisson calibrated)
- All match context data
- Your football knowledge

Be direct, honest, and independent.
If you see signals that contradict the model → say it.
If you agree with the model → explain why.
Never validate the model just because it exists.

OUTPUT strictly valid JSON:
{
  "verdict": "CONFIRMS_UNDER|DOUBTS_UNDER|CONTRADICTS_UNDER",
  "confidence": 0,
  "key_factors": ["factor 1 that matters most", "factor 2", "factor 3"],
  "model_agreement": true,
  "main_risk": "biggest risk to Under 2.5",
  "context_score": 0,
  "reasoning": "2-3 sentences max, direct and honest"
}

RULES:
- If match has high stakes (relegation, title) → be very skeptical of Under
- If both teams have attacking form → CONTRADICTS_UNDER
- If weather is bad (wind/rain) → mention it
- If key strikers are injured → mention it
- confidence: 0-100 (your conviction in the verdict)
- context_score: 0-100 (how supportive context is to Under 2.5)
- model_agreement: true if your verdict aligns with model picking Under, false otherwise
- Be concise. No padding. Just the truth.
PROMPT;

    private ClaudeClient $claude;
    private XGModelService $xgModel;

    public function __construct(ClaudeClient $claude, XGModelService $xgModel)
    {
        $this->claude = $claude;
        $this->xgModel = $xgModel;
    }

    /**
     * Analyser le contexte d'un match et juger la prédiction Under 2.5.
     *
     * @return array ['output' => array|null, 'tokens' => ['input', 'output'], 'error' => string|null]
     */
    public function analyze(FootballMatch $match): array
    {
        $cacheKey = "ai_match_analyst_{$match->id}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $modelPrediction = $this->xgModel->predict($match);
        } catch (\Throwable $e) {
            Log::warning("MatchAnalyst: predict failed match #{$match->id}", ['error' => $e->getMessage()]);
            $modelPrediction = null;
        }

        $userMessage = $this->buildContext($match, $modelPrediction);

        Log::info("MatchAnalyst: analyse {$match->full_name}");

        $response = $this->claude->ask(
            self::SYSTEM_PROMPT,
            $userMessage,
            self::MAX_TOKENS,
            self::MODEL
        );

        $result = [
            'output' => $response['content'],
            'tokens' => [
                'input' => $response['input_tokens'],
                'output' => $response['output_tokens'],
            ],
            'error' => $response['error'],
        ];

        if ($result['output']) {
            Cache::put($cacheKey, $result, now()->addHours(self::CACHE_HOURS));
        }

        return $result;
    }

    private function buildContext(FootballMatch $match, ?array $modelPrediction): string
    {
        $adv = $match->advancedData;
        $sofa = $adv?->sofascore_data ?? [];
        $ctx = $adv?->context_data ?? [];

        $msg = "MATCH: {$match->home_team} vs {$match->away_team}\n";
        $msg .= "LEAGUE: {$match->competition}\n";
        $msg .= "KICKOFF: {$match->match_date}\n\n";

        // ─── Modèle Poisson calibré ───
        if ($modelPrediction) {
            $lambdas = $modelPrediction['lambdas'];
            $analysis = $modelPrediction['analysis'];
            $msg .= "POISSON MODEL (calibrated):\n";
            $msg .= sprintf("  lambda_home = %.2f, lambda_away = %.2f, total = %.2f\n",
                $lambdas['home'], $lambdas['away'], $lambdas['home'] + $lambdas['away']);
            $msg .= sprintf("  P(Under 2.5) = %.1f%%, P(Over 2.5) = %.1f%%\n",
                $analysis['overUnder25']['under'], $analysis['overUnder25']['over']);
            $msg .= sprintf("  P(BTTS Yes) = %.1f%%, top score = %s\n\n",
                $analysis['btts']['yes'], $analysis['topScores'][0]['score'] ?? '?');
        }

        // ─── Cotes bookmaker ───
        $msg .= "BOOKMAKER ODDS:\n";
        $msg .= sprintf("  1X2: %.2f / %.2f / %.2f\n",
            $match->odds_home, $match->odds_draw, $match->odds_away);
        if ($match->odds_under_2_5 > 0 && $match->odds_over_2_5 > 0) {
            $rawU = 1 / (float) $match->odds_under_2_5;
            $rawO = 1 / (float) $match->odds_over_2_5;
            $impliedU = round($rawU / ($rawU + $rawO) * 100, 1);
            $msg .= sprintf("  O/U 2.5: Under=%.2f Over=%.2f → P(Under) implied = %.1f%%\n",
                $match->odds_under_2_5, $match->odds_over_2_5, $impliedU);
        }
        $msg .= "\n";

        // ─── Forme 5 derniers matchs ───
        $form = $sofa['recentForm'] ?? null;
        if ($form && (!empty($form['home']) || !empty($form['away']))) {
            $msg .= "RECENT FORM (last 5):\n";
            $msg .= "  Home ({$match->home_team}): " . $this->summarizeForm($form['home'] ?? []) . "\n";
            $msg .= "  Away ({$match->away_team}): " . $this->summarizeForm($form['away'] ?? []) . "\n\n";
        }

        // ─── Blessures ───
        $injuries = $sofa['injuries'] ?? [];
        if (!empty($injuries['home']) || !empty($injuries['away'])) {
            $homeNames = array_unique(array_column($injuries['home'] ?? [], 'player'));
            $awayNames = array_unique(array_column($injuries['away'] ?? [], 'player'));
            $msg .= "INJURIES:\n";
            $msg .= "  Home: " . (empty($homeNames) ? 'none' : implode(', ', array_slice($homeNames, 0, 8))) . "\n";
            $msg .= "  Away: " . (empty($awayNames) ? 'none' : implode(', ', array_slice($awayNames, 0, 8))) . "\n\n";
        }

        // ─── H2H 2 ans ───
        $h2h = $sofa['h2h'] ?? [];
        if (!empty($h2h)) {
            $cutoff = strtotime('-2 years');
            $recent = array_filter($h2h, fn($g) => strtotime($g['date'] ?? '0') >= $cutoff);
            $recent = array_slice(array_values($recent), 0, 6);
            if (!empty($recent)) {
                $msg .= "HEAD-TO-HEAD (last 2 years):\n";
                $totalGoals = 0; $under25 = 0;
                foreach ($recent as $g) {
                    $hg = $g['homeGoals'] ?? 0;
                    $ag = $g['awayGoals'] ?? 0;
                    $totalGoals += $hg + $ag;
                    if (($hg + $ag) <= 2) $under25++;
                    $date = substr($g['date'] ?? '', 0, 10);
                    $msg .= "  {$date}: {$g['home']} {$hg}-{$ag} {$g['away']}\n";
                }
                $n = count($recent);
                $msg .= sprintf("  → avg goals %.2f, Under 2.5 rate %d/%d\n\n",
                    $totalGoals / $n, $under25, $n);
            }
        }

        // ─── Enjeu ───
        $stakes = $ctx['stakes'] ?? [];
        if (($stakes['available'] ?? false)) {
            $homeStake = $stakes['home']['description'] ?? 'unknown';
            $awayStake = $stakes['away']['description'] ?? 'unknown';
            $msg .= "STAKES:\n  Home: {$homeStake}\n  Away: {$awayStake}\n\n";
        }

        // ─── Météo ───
        $weather = $ctx['weather'] ?? [];
        if (!empty($weather['condition']) && $weather['condition'] !== 'unknown') {
            $msg .= sprintf("WEATHER: %s, %s°C, wind %skm/h\n",
                $weather['condition'],
                $weather['temperature'] ?? '?',
                $weather['wind_speed'] ?? '?'
            );
            if (!empty($weather['impact_summary'])) {
                $msg .= "  Impact: {$weather['impact_summary']}\n";
            }
            $msg .= "\n";
        }

        // ─── Pression coach ───
        $pressure = $ctx['coachPressure'] ?? [];
        if (($pressure['available'] ?? false)) {
            $homePress = $pressure['home']['description'] ?? '-';
            $awayPress = $pressure['away']['description'] ?? '-';
            $msg .= "COACH PRESSURE:\n  Home: {$homePress}\n  Away: {$awayPress}\n\n";
        }

        $msg .= "Give your honest, independent verdict on Under 2.5.";

        return $msg;
    }

    /**
     * Résumé compact de la forme : "WWDLW (12 BF, 7 BC)".
     */
    private function summarizeForm(array $games): string
    {
        if (empty($games)) {
            return 'no data';
        }

        $games = array_slice($games, 0, 5);
        $results = '';
        $goalsFor = 0;
        $goalsAgainst = 0;

        foreach ($games as $g) {
            $r = strtoupper($g['result'] ?? $g['outcome'] ?? '?');
            $results .= $r[0] ?? '?';
            $goalsFor += (int) ($g['goalsFor'] ?? $g['scoredGoals'] ?? 0);
            $goalsAgainst += (int) ($g['goalsAgainst'] ?? $g['concededGoals'] ?? 0);
        }

        return "{$results} ({$goalsFor} BF, {$goalsAgainst} BC)";
    }
}
