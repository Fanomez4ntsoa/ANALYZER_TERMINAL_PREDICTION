<?php

namespace App\Console\Commands;

use App\Models\AIAnalysis;
use App\Models\FootballMatch;
use App\Services\AI\Agents\FinalJudgeService;
use App\Services\AI\Agents\RiskKillerService;
use App\Services\AI\Agents\ValueHunterService;
use App\Services\AI\ClaudeClient;
use Illuminate\Console\Command;

class TestAIAgents extends Command
{
    protected $signature = 'ai:test
                            {--match-id= : Match specifique}
                            {--agent=all : Agent a tester (value|risk|judge|all)}';

    protected $description = 'Tester les agents IA sur un match';

    public function handle(ValueHunterService $valueHunter, RiskKillerService $riskKiller, FinalJudgeService $finalJudge, ClaudeClient $claude): int
    {
        // Hors flux depuis la simplification (2026-09-13) : la table
        // `recommendations` n'est plus alimentée, ce test n'a plus d'entrée.
        $this->warn('Commande hors flux depuis la simplification : les agents IA sont débranchés du pipeline.');
        $this->line('Les fichiers app/Services/AI/* et la table ai_analysis sont conservés pour une éventuelle réintégration.');
        return self::SUCCESS;

        // @phpstan-ignore-next-line — code conservé volontairement (débranché)
        if (!$claude->isConfigured()) {
            $this->error('ANTHROPIC_API_KEY non configuree dans .env');
            return self::FAILURE;
        }

        $matchId = $this->option('match-id');
        $match = $matchId
            ? FootballMatch::with(['recommendations', 'advancedData'])->findOrFail($matchId)
            : FootballMatch::with(['recommendations', 'advancedData'])
                ->whereNotNull('global_confidence')
                ->whereHas('recommendations')
                ->where('odds_home', '>', 0)
                ->latest()
                ->first();

        if (!$match) {
            $this->error('Aucun match analyse en DB.');
            return self::FAILURE;
        }

        // Garde-fou : vérifier que le match a les données nécessaires
        $missingData = [];
        if ($match->global_confidence === null) $missingData[] = 'global_confidence';
        if ($match->recommendations->isEmpty()) $missingData[] = 'recommendations';
        if ((float) $match->odds_home <= 0) $missingData[] = 'odds';

        if (!empty($missingData)) {
            $this->error("Match #{$match->id} ({$match->home_team} vs {$match->away_team}) incomplet.");
            $this->line('Donnees manquantes : ' . implode(', ', $missingData));
            $this->newLine();

            $this->info('Matchs disponibles pour analyse IA :');
            $viable = FootballMatch::whereNotNull('global_confidence')
                ->whereHas('recommendations')
                ->where('odds_home', '>', 0)
                ->orderByDesc('match_date')
                ->take(15)
                ->get();

            if ($viable->isEmpty()) {
                $this->warn('Aucun match viable en DB.');
                $this->line('Pour preparer des matchs :');
                $this->line('  1. php artisan pipeline:run-sync ' . now()->format('Y-m-d'));
                $this->line('  2. Analyser via /analysis dans l\'interface');
                $this->line('  3. Relancer ai:test');
                return self::FAILURE;
            }

            $rows = [];
            foreach ($viable as $m) {
                $rows[] = [
                    $m->id,
                    substr($m->home_team, 0, 18) . ' v ' . substr($m->away_team, 0, 18),
                    $m->competition,
                    $m->global_confidence . '%',
                    $m->recommendations->count() . ' recs',
                ];
            }
            $this->table(['ID', 'Match', 'Ligue', 'Confiance', 'Recs'], $rows);
            $this->line("Reessayer : php artisan ai:test --match-id={$viable->first()->id}");
            return self::FAILURE;
        }

        $confDisplay = $match->global_confidence ?? 'N/A';
        $oddsH = (float) $match->odds_home > 0 ? number_format($match->odds_home, 2) : 'N/A';
        $oddsD = (float) $match->odds_draw > 0 ? number_format($match->odds_draw, 2) : 'N/A';
        $oddsA = (float) $match->odds_away > 0 ? number_format($match->odds_away, 2) : 'N/A';

        $this->info("{$match->home_team} vs {$match->away_team} ({$match->competition})");
        $this->line("Confiance: {$confDisplay}% | Cotes: {$oddsH}/{$oddsD}/{$oddsA}");
        $this->newLine();

        $analysisData = [
            'recommendations' => $match->recommendations->map(fn($r) => [
                'market' => $r->market,
                'bet' => $r->bet,
                'confidence' => $r->confidence,
                'score' => $r->score,
                'odds' => $r->odds,
            ])->toArray(),
        ];

        $agent = $this->option('agent');
        $totalInput = 0;
        $totalOutput = 0;
        $valueOutput = null;
        $riskOutput = null;
        $judgeOutput = null;

        // VALUE HUNTER
        if (in_array($agent, ['all', 'value'])) {
            $this->info('--- VALUE HUNTER ---');
            $result = $valueHunter->analyze($match, $analysisData);
            $totalInput += $result['tokens']['input'];
            $totalOutput += $result['tokens']['output'];

            if ($result['error']) {
                $this->error("Erreur: {$result['error']}");
            } else {
                $valueOutput = $result['output'];
                $score = $valueOutput['overall_value_score'] ?? 0;
                $this->line("Value Score: {$score}/100");

                $bets = $valueOutput['value_bets'] ?? [];
                if (empty($bets)) {
                    $this->warn('Aucune value detectee.');
                } else {
                    $rows = [];
                    foreach ($bets as $b) {
                        $rows[] = [
                            $b['market'] ?? '-',
                            $b['pick'] ?? '-',
                            ($b['model_probability'] ?? 0) . '%',
                            ($b['implied_probability'] ?? 0) . '%',
                            '+' . ($b['value_margin'] ?? 0) . '%',
                            $b['reason'] ?? '-',
                        ];
                    }
                    $this->table(['Marche', 'Pick', 'Modele', 'Marche', 'Margin', 'Raison'], $rows);
                }

                $best = $valueOutput['best_pick'] ?? null;
                if ($best) {
                    $bestPick = $best['pick'] ?? '';
                    $bestMargin = $best['value_margin'] ?? 0;
                    $this->info("Best pick: {$best['market']} {$bestPick} (margin: +{$bestMargin}%)");
                }

                $this->line("Tokens: {$result['tokens']['input']} in / {$result['tokens']['output']} out");
            }
            $this->newLine();
        }

        // RISK KILLER
        if (in_array($agent, ['all', 'risk'])) {
            $this->info('--- RISK KILLER ---');
            $result = $riskKiller->analyze($match, $analysisData);
            $totalInput += $result['tokens']['input'];
            $totalOutput += $result['tokens']['output'];

            if ($result['error']) {
                $this->error("Erreur: {$result['error']}");
            } else {
                $riskOutput = $result['output'];
                $riskScore = $riskOutput['risk_score'] ?? 0;
                $reco = $riskOutput['recommendation'] ?? '-';
                $color = $reco === 'PROCEED' ? 'info' : ($reco === 'AVOID' ? 'error' : 'warn');

                $this->line("Risk Score: {$riskScore}/100");
                $this->{$color}("Recommendation: {$reco}");

                $flags = $riskOutput['red_flags'] ?? [];
                if (!empty($flags)) {
                    $this->line("Red Flags:");
                    foreach ($flags as $f) {
                        $this->line("  - {$f}");
                    }
                }

                $traps = $riskOutput['trap_signals'] ?? [];
                if (!empty($traps)) {
                    $this->line("Trap Signals:");
                    foreach ($traps as $t) {
                        $this->line("  - {$t}");
                    }
                }

                $danger = $riskOutput['most_dangerous_assumption'] ?? '';
                if ($danger) {
                    $this->warn("Most dangerous assumption: {$danger}");
                }

                $this->line("Tokens: {$result['tokens']['input']} in / {$result['tokens']['output']} out");
            }
            $this->newLine();
        }

        // FINAL JUDGE (synthese Value + Risk)
        if (in_array($agent, ['all', 'judge']) && ($valueOutput || $riskOutput)) {
            $this->info('--- FINAL JUDGE ---');
            $result = $finalJudge->decide($match, $valueOutput, $riskOutput);
            $totalInput += $result['tokens']['input'];
            $totalOutput += $result['tokens']['output'];

            if ($result['error']) {
                $this->error("Erreur: {$result['error']}");
            } else {
                $judgeOutput = $result['output'];
                $decisionGlobal = $judgeOutput['decision_global'] ?? $judgeOutput['decision'] ?? '-';
                $decisionUnder = $judgeOutput['decision_under'] ?? '-';
                $underMargin = $judgeOutput['under_margin'] ?? 0;
                $underReason = $judgeOutput['under_reasoning'] ?? '';
                $confidence = $judgeOutput['confidence'] ?? 0;
                $edge = $judgeOutput['edge_quality'] ?? '-';
                $factor = $judgeOutput['dominant_factor'] ?? '-';

                $colorOf = fn($d) => match ($d) {
                    'BET' => 'info',
                    'NO BET' => 'error',
                    'LEAN' => 'warn',
                    default => 'line',
                };

                // Decision globale
                $this->{$colorOf($decisionGlobal)}("Decision globale: {$decisionGlobal}");
                $this->line("  Confidence: {$confidence}/100 | Edge: {$edge} | Dominant factor: {$factor}");

                // Decision Under 2.5 (seuils ajustes)
                $this->{$colorOf($decisionUnder)}("Decision Under 2.5: {$decisionUnder} (margin: +{$underMargin}%)");
                if ($underReason) {
                    $this->line("  {$underReason}");
                }

                $reasoning = $judgeOutput['reasoning'] ?? '';
                if ($reasoning) {
                    $this->newLine();
                    $this->line("Reasoning: {$reasoning}");
                }

                $failure = $judgeOutput['failure_scenario'] ?? '';
                if ($failure) {
                    $this->warn("Failure scenario: {$failure}");
                }

                $this->line("Tokens: {$result['tokens']['input']} in / {$result['tokens']['output']} out");
            }
            $this->newLine();
        }

        // Sauvegarder en DB
        if ($valueOutput || $riskOutput || $judgeOutput) {
            AIAnalysis::updateOrCreate(
                ['match_id' => $match->id],
                [
                    'value_output' => $valueOutput,
                    'risk_output' => $riskOutput,
                    'final_decision' => $judgeOutput,
                    'total_input_tokens' => $totalInput,
                    'total_output_tokens' => $totalOutput,
                ]
            );
            $this->info("Sauvegarde en DB (ai_analysis #{$match->id})");
        }

        $this->line("Total tokens: {$totalInput} in / {$totalOutput} out");

        return self::SUCCESS;
    }
}
