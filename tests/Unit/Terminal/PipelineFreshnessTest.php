<?php

namespace Tests\Unit\Terminal;

use App\Models\PipelineRun;
use App\Support\Terminal\PipelineFreshness;
use App\Support\Terminal\SystemState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Logique pure : modèles non enregistrés, aucune table lue. */
class PipelineFreshnessTest extends TestCase
{
    private function pipelineRun(string $date, string $status, string $startedAt, ?string $finishedAt = null, array $steps = []): PipelineRun
    {
        return new PipelineRun([
            'run_date' => CarbonImmutable::parse($date, 'UTC'),
            'status' => $status,
            'started_at' => CarbonImmutable::parse($startedAt, 'UTC'),
            'finished_at' => $finishedAt ? CarbonImmutable::parse($finishedAt, 'UTC') : null,
            'steps' => $steps,
        ]);
    }

    private function evaluate(?PipelineRun $latest, ?PipelineRun $lastSuccess, string $now): array
    {
        return PipelineFreshness::evaluate($latest, $lastSuccess, CarbonImmutable::parse($now, 'UTC'), '10:00', 'UTC');
    }

    private function severities(array $states): array
    {
        return array_map(fn (SystemState $s) => $s->severity, $states);
    }

    public function test_no_run_ever_is_critical(): void
    {
        $states = $this->evaluate(null, null, '2026-09-14 12:00');

        $this->assertSame([SystemState::CRITICAL], $this->severities($states));
    }

    public function test_todays_success_shows_nothing(): void
    {
        $run = $this->pipelineRun('2026-09-14', PipelineRun::SUCCESS, '2026-09-14 10:00', '2026-09-14 10:04');

        $this->assertSame([], $this->evaluate($run, $run, '2026-09-14 18:00'));
    }

    public function test_yesterdays_success_is_fine_before_the_grace_period_ends(): void
    {
        $run = $this->pipelineRun('2026-09-13', PipelineRun::SUCCESS, '2026-09-13 10:00', '2026-09-13 10:04');

        $this->assertSame([], $this->evaluate($run, $run, '2026-09-14 10:29'));
        $late = $this->evaluate($run, $run, '2026-09-14 10:30');
        $this->assertSame([SystemState::CRITICAL], $this->severities($late));
        $this->assertStringContainsString('13/09', $late[0]->message);
    }

    public function test_run_in_progress_is_a_notice_then_critical_when_stale(): void
    {
        $previous = $this->pipelineRun('2026-09-13', PipelineRun::SUCCESS, '2026-09-13 10:00', '2026-09-13 10:04');
        $running = $this->pipelineRun('2026-09-14', PipelineRun::RUNNING, '2026-09-14 10:00');

        $this->assertSame([SystemState::NOTICE], $this->severities($this->evaluate($running, $previous, '2026-09-14 10:45')));
        $this->assertSame(
            [SystemState::CRITICAL, SystemState::CRITICAL],
            $this->severities($this->evaluate($running, $previous, '2026-09-14 12:30'))
        );
    }

    public function test_incomplete_run_is_a_warning_naming_failed_steps(): void
    {
        $previous = $this->pipelineRun('2026-09-13', PipelineRun::SUCCESS, '2026-09-13 10:00', '2026-09-13 10:04');
        $incomplete = $this->pipelineRun('2026-09-14', PipelineRun::INCOMPLETE, '2026-09-14 10:00', '2026-09-14 10:06', [
            'pipeline:run-sync' => ['exit_code' => 0],
            'context:enrich' => ['exit_code' => 1],
        ]);

        $states = $this->evaluate($incomplete, $previous, '2026-09-14 15:00');

        $this->assertSame([SystemState::WARNING], $this->severities($states));
        $this->assertSame('Étapes en échec : context:enrich', $states[0]->detail);
    }

    public function test_failed_run_is_one_critical_state(): void
    {
        $previous = $this->pipelineRun('2026-09-13', PipelineRun::SUCCESS, '2026-09-13 10:00', '2026-09-13 10:04');
        $failed = $this->pipelineRun('2026-09-14', PipelineRun::FAILED, '2026-09-14 10:00', '2026-09-14 10:01', [
            'pipeline:run-sync' => ['exit_code' => 1],
        ]);

        $this->assertSame([SystemState::CRITICAL], $this->severities($this->evaluate($failed, $previous, '2026-09-14 15:00')));
    }
}
