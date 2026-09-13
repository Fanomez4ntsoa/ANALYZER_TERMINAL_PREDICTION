<?php

namespace Tests\Unit\Backtesting;

use App\Models\HistoricalMatch;
use App\Services\Backtesting\FootballData\CalibrationBacktestService;
use App\Services\Backtesting\SeasonScopedEstimator;
use App\Services\Probability\DixonColesRho;
use App\Services\Probability\LeagueGoalAverages;
use App\Services\Probability\PoissonModelService;
use App\Services\Probability\XGModelService;
use Tests\TestCase;

class SeasonScopedEstimatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'football-data.work_seasons' => ['2122', '2223', '2324'],
            'football-data.holdout_seasons' => ['2425', '2526'],
            'xg-model.anchor_total_on_league_average' => false,
        ]);
    }

    private function goals(): LeagueGoalAverages
    {
        return new LeagueGoalAverages([
            '2122' => [39 => ['goals' => 1000.0, 'matches' => 380]],
            '2223' => [39 => ['goals' => 1100.0, 'matches' => 380]],
            '2324' => [39 => ['goals' => 1200.0, 'matches' => 380]],
            '2425' => [39 => ['goals' => 9999.0, 'matches' => 380]], // réservée : jamais lue
        ]);
    }

    public function test_only_strictly_prior_work_seasons_are_used(): void
    {
        $g = $this->goals();
        $this->assertSame(round(3300 / 1140, 3), $g->totalFor(39)); // production : saisons de travail

        $g->scopeToSeasonsBefore('2122');
        $this->assertSame([], $g->allowedSeasons());
        $this->assertNull($g->totalFor(39));

        $g->scopeToSeasonsBefore('2324');
        $this->assertSame(['2122', '2223'], $g->allowedSeasons());
        $this->assertSame(round(2100 / 760, 3), $g->totalFor(39));

        $g->scopeToSeasonsBefore('2526');
        $this->assertSame(['2122', '2223', '2324'], $g->allowedSeasons()); // jamais 2425
    }

    public function test_unscoped_access_throws_when_scope_is_required(): void
    {
        $g = $this->goals();
        $g->requireScope(true);
        $this->expectException(\LogicException::class);
        $g->totalFor(39);
    }

    public function test_engine_scopes_estimators_to_each_match_season_and_resets(): void
    {
        $spy = new class implements SeasonScopedEstimator {
            public array $log = [];
            public function scopeToSeasonsBefore(?string $season): void { $this->log[] = "scope:" . ($season ?? 'null'); }
            public function requireScope(bool $required): void { $this->log[] = 'require:' . ($required ? '1' : '0'); }
        };
        $goals = new LeagueGoalAverages([]);
        $rho = new DixonColesRho([]);
        $service = new CalibrationBacktestService(
            new XGModelService(new PoissonModelService(), $goals, $rho),
            new PoissonModelService(),
            [$spy, $goals, $rho],
        );

        $make = fn (string $season) => new HistoricalMatch([
            'season' => $season, 'div' => 'E0', 'league_id' => 39, 'match_date' => '2023-01-01', 'home_team' => 'A', 'away_team' => 'B',
            'fthg' => 2, 'ftag' => 1, 'b365_open_home' => 2.0, 'b365_open_draw' => 3.4, 'b365_open_away' => 3.8,
        ]);

        $service->evaluate([$make('2223'), $make('2324')], 'b365');

        $this->assertSame(['require:1', 'scope:2223', 'scope:2324', 'scope:null', 'require:0'], $spy->log);
    }

    public function test_engine_rejects_non_scoped_estimators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CalibrationBacktestService(
            new XGModelService(new PoissonModelService(), new LeagueGoalAverages([]), new DixonColesRho([])),
            new PoissonModelService(),
            [new \stdClass()],
        );
    }

    public function test_engine_refuses_a_model_estimator_it_does_not_scope(): void
    {
        $goals = new LeagueGoalAverages([]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DixonColesRho');
        new CalibrationBacktestService(
            new XGModelService(new PoissonModelService(), $goals, new DixonColesRho([])),
            new PoissonModelService(),
            [$goals],
        );
    }

    public function test_container_scopes_every_estimator_used_by_the_model(): void
    {
        $service = app(CalibrationBacktestService::class);
        $this->assertInstanceOf(CalibrationBacktestService::class, $service);
        $classes = array_map('get_class', app(XGModelService::class)->seasonScopedEstimators());
        $this->assertSame([LeagueGoalAverages::class, DixonColesRho::class], $classes);
    }
}
