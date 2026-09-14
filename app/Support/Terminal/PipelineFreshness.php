<?php

namespace App\Support\Terminal;

use App\Models\PipelineRun;
use App\Services\DataPipeline\PipelineLog;
use Carbon\CarbonImmutable;

/**
 * Fraîcheur du pipeline quotidien, lue dans pipeline_runs.
 *
 * Le passage est attendu chaque jour à pipeline.schedule_time ; passé ce créneau
 * et un délai de grâce, l'absence de passage réussi pour la date du jour est
 * critique : les probabilités affichées ne sont pas celles du jour.
 */
class PipelineFreshness
{
    public const GRACE_MINUTES = 30;
    public const STALE_RUNNING_MINUTES = 120;

    /**
     * @return array{states: SystemState[], lastSuccess: ?PipelineRun, latest: ?PipelineRun}
     */
    public function current(): array
    {
        try {
            $latest = PipelineRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();
            $lastSuccess = PipelineRun::query()->succeeded()->orderByDesc('run_date')->orderByDesc('finished_at')->first();
        } catch (\Throwable $e) {
            PipelineLog::caught('Terminal : lecture de pipeline_runs', $e);

            return [
                'states' => [new SystemState(SystemState::CRITICAL, 'Pipeline', 'pipeline_runs illisible, fraîcheur inconnue', $e->getMessage())],
                'lastSuccess' => null,
                'latest' => null,
            ];
        }

        return [
            'states' => self::evaluate(
                $latest,
                $lastSuccess,
                CarbonImmutable::now('UTC'),
                (string) config('pipeline.schedule_time', '10:00'),
                (string) config('pipeline.schedule_timezone', 'UTC'),
            ),
            'lastSuccess' => $lastSuccess,
            'latest' => $latest,
        ];
    }

    /**
     * Logique pure, sans base : testable avec des modèles non enregistrés.
     *
     * @return SystemState[]
     */
    public static function evaluate(?PipelineRun $latest, ?PipelineRun $lastSuccess, CarbonImmutable $now, string $scheduleTime, string $timezone): array
    {
        if ($latest === null) {
            return [new SystemState(SystemState::CRITICAL, 'Pipeline', 'Aucun passage de pipeline:daily enregistré')];
        }

        $states = [];
        $local = $now->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $scheduleTime) + [1 => 0]);
        $due = $local->setTime($hour, $minute)->addMinutes(self::GRACE_MINUTES);
        $expectedDate = ($local->greaterThanOrEqualTo($due) ? $local : $local->subDay())->toDateString();

        $latestDate = $latest->run_date?->toDateString();
        $day = $latest->run_date?->format('d/m') ?? '?';
        $coversExpected = $latestDate !== null && $latestDate >= $expectedDate;

        switch ($latest->status) {
            case PipelineRun::RUNNING:
                $started = $latest->started_at ? CarbonImmutable::instance($latest->started_at) : null;
                if ($started !== null && $started->diffInMinutes($now, true) < self::STALE_RUNNING_MINUTES) {
                    $states[] = new SystemState(SystemState::NOTICE, 'Pipeline', "Passage du {$day} en cours depuis {$started->setTimezone($timezone)->format('H:i')}");
                } else {
                    $states[] = new SystemState(SystemState::CRITICAL, 'Pipeline', "Passage du {$day} interrompu, jamais terminé", 'Démarré le ' . ($started?->format('d/m H:i') ?? '?') . ' UTC');
                    $coversExpected = false;
                }
                break;
            case PipelineRun::FAILED:
                $states[] = new SystemState(SystemState::CRITICAL, 'Pipeline', "Passage du {$day} en échec, étapes suivantes non lancées", self::failedSteps($latest));
                break;
            case PipelineRun::INCOMPLETE:
                $states[] = new SystemState(SystemState::WARNING, 'Pipeline', "Passage du {$day} incomplet", self::failedSteps($latest));
                break;
        }

        // Retard : aucun passage pour la date attendue. Un passage en échec,
        // incomplet ou en cours pour cette date porte déjà son propre état.
        $successDate = $lastSuccess?->run_date?->toDateString();
        if (!$coversExpected && ($successDate === null || $successDate < $expectedDate)) {
            $states[] = new SystemState(
                SystemState::CRITICAL,
                'Pipeline',
                $lastSuccess === null
                    ? 'Aucun passage réussi enregistré'
                    : 'Dernier passage réussi : ' . $lastSuccess->run_date->format('d/m') . ', aucun pour le ' . CarbonImmutable::parse($expectedDate)->format('d/m'),
            );
        }

        return $states;
    }

    private static function failedSteps(PipelineRun $run): ?string
    {
        $failed = array_keys(array_filter($run->steps ?? [], fn ($step) => ($step['exit_code'] ?? 0) !== 0));

        return $failed === [] ? null : 'Étapes en échec : ' . implode(', ', $failed);
    }
}
