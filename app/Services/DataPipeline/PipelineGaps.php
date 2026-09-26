<?php

namespace App\Services\DataPipeline;

use App\Models\PipelineRun;
use App\Models\PredictionLogEntry;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Jours sans passage du pipeline, du premier calcul du journal à la veille :
 * aucun passage enregistré dans pipeline_runs (missing), ou seulement des passages
 * échoués ou interrompus (failed). Leurs matchs n'ont jamais été importés ni
 * calculés : aucun autre compteur ne les voit, et ils ne se rattrapent pas (règle 5).
 * Lu par log:report et system:status.
 */
class PipelineGaps
{
    /**
     * @return array{since: ?string, missing: list<string>, failed: list<string>}
     */
    public function compute(): array
    {
        $firstComputed = PredictionLogEntry::min('computed_at');
        if ($firstComputed === null) {
            return ['since' => null, 'missing' => [], 'failed' => []];
        }

        $since = Carbon::parse($firstComputed)->startOfDay();
        $until = now()->subDay()->startOfDay();

        // Table minuscule (un passage par jour) : filtrée en PHP, run_date n'a pas le
        // même format stocké sous MariaDB et SQLite
        $runs = PipelineRun::get(['run_date', 'status'])
            ->groupBy(fn (PipelineRun $run) => $run->run_date->format('Y-m-d'));

        $missing = [];
        $failed = [];
        if ($since->lte($until)) {
            foreach (CarbonPeriod::create($since, $until) as $day) {
                $dayRuns = $runs->get($day->format('Y-m-d'));
                if ($dayRuns === null) {
                    $missing[] = $day->format('Y-m-d');
                } elseif (!$dayRuns->contains(fn (PipelineRun $run) => in_array($run->status, [PipelineRun::SUCCESS, PipelineRun::INCOMPLETE], true))) {
                    $failed[] = $day->format('Y-m-d');
                }
            }
        }

        return ['since' => $since->format('Y-m-d'), 'missing' => $missing, 'failed' => $failed];
    }
}
