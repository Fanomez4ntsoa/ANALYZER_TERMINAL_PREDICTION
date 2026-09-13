<?php

namespace App\Console\Commands;

use App\Models\AIAnalysis;
use App\Models\DailyCombo;
use App\Models\FootballMatch;
use App\Services\AI\Agents\FinalJudgeService;
use App\Services\AI\Agents\MatchAnalystService;
use App\Services\AI\Agents\RiskKillerService;
use App\Services\AI\Agents\ValueHunterService;
use App\Services\AI\ClaudeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AiBatchAnalyze extends Command
{
    protected $signature = 'ai:batch
                            {--date= : Date cible (YYYY-MM-DD, defaut: aujourd\'hui)}
                            {--force : Re-analyser meme si une analyse existe deja}';

    protected $description = 'Lancer les 3 agents IA en batch sur tous les matchs analyses du jour';

    public function handle(
        MatchAnalystService $matchAnalyst,
        ValueHunterService $valueHunter,
        RiskKillerService $riskKiller,
        FinalJudgeService $finalJudge,
        ClaudeClient $claude
    ): int {
        // Hors flux depuis la simplification (2026-09-13) : la table
        // `recommendations` n'est plus alimentée, ce batch n'a plus d'entrée.
        $this->warn('Commande hors flux depuis la simplification : les agents IA sont débranchés du pipeline.');
        $this->line('Les fichiers app/Services/AI/* et la table ai_analysis sont conservés pour une éventuelle réintégration.');
        return self::SUCCESS;

        // @phpstan-ignore-next-line — code conservé volontairement (débranché)
        if (!$claude->isConfigured()) {
            $this->error('ANTHROPIC_API_KEY non configuree');
            return self::FAILURE;
        }

        $date = $this->option('date') ?? now()->format('Y-m-d');
        $force = $this->option('force');

        $matches = FootballMatch::with(['recommendations', 'advancedData'])
            ->whereDate('match_date', $date)
            ->whereNotNull('global_confidence')
            ->whereHas('recommendations')
            ->where('odds_home', '>', 0)
            ->orderBy('match_date')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn("Aucun match analyse avec cotes pour le {$date}");
            return self::SUCCESS;
        }

        $this->info("Batch IA pour le {$date} — {$matches->count()} matchs");
        $bar = $this->output->createProgressBar($matches->count());
        $bar->start();

        $picks = [];
        $totalInput = 0;
        $totalOutput = 0;
        $errors = 0;

        foreach ($matches as $match) {
            // Skip si deja analyse et pas --force
            $existing = AIAnalysis::where('match_id', $match->id)->first();
            if ($existing && !$force && !empty($existing->final_decision)) {
                $picks[] = $this->buildPickFromExisting($match, $existing);
                $bar->advance();
                continue;
            }

            $analysisData = [
                'recommendations' => $match->recommendations->map(fn($r) => [
                    'market' => $r->market,
                    'bet' => $r->bet,
                    'confidence' => $r->confidence,
                    'score' => $r->score,
                    'odds' => $r->odds,
                ])->toArray(),
            ];

            try {
                // Match Analyst tourne en premier — son verdict influence les agents suivants
                $analystResult = $matchAnalyst->analyze($match);
                $totalInput += $analystResult['tokens']['input'];
                $totalOutput += $analystResult['tokens']['output'];

                $valueResult = $valueHunter->analyze($match, $analysisData);
                $totalInput += $valueResult['tokens']['input'];
                $totalOutput += $valueResult['tokens']['output'];

                $riskResult = $riskKiller->analyze($match, $analysisData);
                $totalInput += $riskResult['tokens']['input'];
                $totalOutput += $riskResult['tokens']['output'];

                $judgeResult = $finalJudge->decide($match, $valueResult['output'], $riskResult['output'], $analystResult['output']);
                $totalInput += $judgeResult['tokens']['input'];
                $totalOutput += $judgeResult['tokens']['output'];

                AIAnalysis::updateOrCreate(
                    ['match_id' => $match->id],
                    [
                        'match_analyst_output' => $analystResult['output'],
                        'value_output' => $valueResult['output'],
                        'risk_output' => $riskResult['output'],
                        'final_decision' => $judgeResult['output'],
                        'total_input_tokens' => $totalInput,
                        'total_output_tokens' => $totalOutput,
                    ]
                );

                $picks[] = $this->buildPick($match, $valueResult['output'], $riskResult['output'], $judgeResult['output']);
            } catch (\Exception $e) {
                $errors++;
                \Illuminate\Support\Facades\Log::error("ai:batch echec match #{$match->id}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $betUnder = collect($picks)->where('decision_under', 'BET')->count();
        $leanUnder = collect($picks)->where('decision_under', 'LEAN')->count();
        $noBet = collect($picks)->where('decision_under', 'NO BET')->count();

        // Recuperer les combos du jour (s'ils existent)
        $combos = DailyCombo::where('date', $date)->orderBy('rank')->get()->map(fn($c) => [
            'rank' => $c->rank,
            'total_odds' => (float) $c->total_odds,
            'combo_score' => (float) $c->combo_score,
            'avg_confidence' => (float) $c->avg_confidence,
            'picks' => $c->picks,
        ])->toArray();

        // Construire le rapport JSON
        $report = [
            'date' => $date,
            'total_matches' => count($picks),
            'errors' => $errors,
            'tokens' => [
                'input' => $totalInput,
                'output' => $totalOutput,
            ],
            'summary' => [
                'bet_under' => $betUnder,
                'lean_under' => $leanUnder,
                'no_bet' => $noBet,
            ],
            'picks' => $picks,
            'combos' => $combos,
        ];

        $path = "reports/ai_batch_{$date}.json";
        Storage::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Affichage terminal — uniquement résumé
        $this->table(['Metrique', 'Valeur'], [
            ['Total matchs analyses', count($picks)],
            ['BET Under', $betUnder],
            ['LEAN Under', $leanUnder],
            ['NO BET', $noBet],
            ['Combos generes', count($combos)],
            ['Tokens input', $totalInput],
            ['Tokens output', $totalOutput],
            ['Erreurs', $errors],
        ]);

        $this->info("Rapport JSON : storage/app/{$path}");

        return self::SUCCESS;
    }

    private function buildPick(FootballMatch $match, ?array $value, ?array $risk, ?array $judge): array
    {
        return [
            'match_id' => $match->id,
            'teams' => "{$match->home_team} vs {$match->away_team}",
            'league' => $match->competition,
            'match_date' => $match->match_date->toIso8601String(),
            'odds' => sprintf('%.2f/%.2f/%.2f', $match->odds_home, $match->odds_draw, $match->odds_away),
            'confidence' => (int) $match->global_confidence,
            'value_score' => $value['overall_value_score'] ?? 0,
            'risk_score' => $risk['risk_score'] ?? 0,
            'decision_global' => $judge['decision_global'] ?? $judge['decision'] ?? 'NO BET',
            'decision_under' => $judge['decision_under'] ?? 'NO BET',
            'under_margin' => $judge['under_margin'] ?? 0,
            'under_reasoning' => $judge['under_reasoning'] ?? null,
            'edge_quality' => $judge['edge_quality'] ?? 'none',
            'dominant_factor' => $judge['dominant_factor'] ?? null,
            'reasoning' => $judge['reasoning'] ?? null,
        ];
    }

    private function buildPickFromExisting(FootballMatch $match, AIAnalysis $analysis): array
    {
        return $this->buildPick($match, $analysis->value_output, $analysis->risk_output, $analysis->final_decision);
    }
}
