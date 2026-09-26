<?php

namespace Tests\Feature;

use App\Jobs\FetchMatchDataJob;
use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Models\PredictionLogEntry;
use App\Services\Api\ApiFootballException;
use App\Services\Api\ApiFootballService;
use App\Services\Market\CLVTrackerService;
use App\Services\PredictionLog\PredictionLogSettler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Rattrapage des scores : la veille par date sans jamais créer de match, puis les
 * matchs plus anciens un par un (/fixtures?id=), plafonné, les plus récents
 * d'abord, seulement ceux dont le journal attend une clôture. Un match reprogrammé
 * perd les cotes de son ancien coup d'envoi.
 */
class ScoreCatchupTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-26';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00:00', 'UTC'));
        config([
            'logging.channels.pipeline' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'api-football.leagues' => [140],
            'api-football.inactive_leagues' => [],
            'api-football.odds_leagues' => [140],
            'api-football.budget.optional_reserve' => 10,
            'pipeline.match_start_hour' => 0,
            'pipeline.match_end_hour' => 23,
            'pipeline.score_catchup.max_requests' => 20,
            'pipeline.score_catchup.window_days' => 60,
            'pipeline.closing.window_minutes' => 10,
            'odds-api.clv_bookmaker' => 'pinnacle',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function match(int $fixtureId, string $home, string $away, string $kickoff, array $overrides = []): FootballMatch
    {
        return FootballMatch::create($overrides + [
            'api_football_id' => $fixtureId,
            'home_team' => $home,
            'away_team' => $away,
            'match_date' => $kickoff,
            'competition' => 'La Liga',
            'league_id' => 140,
            'data_source' => 'api',
            'completed' => false,
        ]);
    }

    private function logLine(FootballMatch $match, string $outcome = '1'): PredictionLogEntry
    {
        return PredictionLogEntry::create([
            'match_id' => $match->id,
            'league_id' => 140,
            'home_team' => $match->home_team,
            'away_team' => $match->away_team,
            'kickoff_at' => $match->match_date,
            'trigger' => PredictionLogEntry::TRIGGER_PIPELINE,
            'market' => 'winner',
            'outcome' => $outcome,
            'model_probability' => 0.5,
            'model_mode' => 'market_only',
            'lambda_home' => 1.4,
            'lambda_away' => 1.1,
            'odds' => 2.0,
            'bookmaker' => 'Bet365',
            'fair_probability' => 0.48,
            'computed_at' => $match->match_date->copy()->subHours(8),
        ]);
    }

    private function fixture(int $id, string $home, string $away, string $date, string $status, ?int $goalsHome = null, ?int $goalsAway = null): array
    {
        return [
            'fixture' => ['id' => $id, 'date' => $date, 'status' => ['short' => $status]],
            'league' => ['id' => 140, 'name' => 'La Liga', 'season' => 2026],
            'teams' => ['home' => ['id' => $id * 10, 'name' => $home], 'away' => ['id' => $id * 10 + 1, 'name' => $away]],
            'goals' => ['home' => $goalsHome, 'away' => $goalsAway],
        ];
    }

    /**
     * API-Football simulée : aucun match aujourd'hui, $previousDay pour la veille,
     * $byId pour le rattrapage (toute autre requête par id fait échouer le test).
     */
    private function api(array $previousDay, array $byId, int $remaining = 90): void
    {
        $this->mock(ApiFootballService::class, function (MockInterface $mock) use ($previousDay, $byId, $remaining) {
            $mock->shouldReceive('getDailyUsage')->andReturn(['current' => 100 - $remaining, 'limit' => 100, 'remaining' => $remaining, 'status_current' => 0, 'local_current' => 0]);
            $mock->shouldReceive('lastKnownDailyRemaining')->andReturn($remaining);
            $mock->shouldReceive('getFixturesByDate')->with(self::TODAY)->andReturn([]);
            $mock->shouldReceive('getFixturesByDate')->with('2026-09-25')->andReturn($previousDay);
            foreach ($byId as $id => $response) {
                $expectation = $mock->shouldReceive('getFixtureById')->with($id)->once();
                $response instanceof \Throwable ? $expectation->andThrow($response) : $expectation->andReturn($response);
            }
        });
    }

    private function runJob(): array
    {
        FetchMatchDataJob::dispatchSync(self::TODAY);

        return FetchMatchDataJob::$lastSummary;
    }

    public function test_candidates_are_pending_journal_matches_older_than_yesterday_newest_first(): void
    {
        $betis = $this->match(1570386, 'Real Betis', 'Getafe', '2026-09-17 17:00:00');
        $malaga = $this->match(1570390, 'Malaga', 'Villarreal', '2026-09-17 19:30:00');
        $levante = $this->match(1570389, 'Levante', 'Athletic Club', '2026-09-16 19:30:00');
        foreach ([$betis, $malaga, $levante] as $m) {
            $this->logLine($m);
        }
        // Veille : laissée à l'appel par date
        $this->logLine($this->match(1, 'Sevilla', 'Osasuna', '2026-09-25 19:00:00'));
        // Sans ligne au journal : aucune requête
        $this->match(2, 'Celtic', 'Ferencvaros', '2026-09-17 19:00:00');
        // Statut final sans score à attendre, ou déjà reprogrammé : laissés à log:settle
        $this->logLine($this->match(3, 'Girona', 'Elche', '2026-09-17 19:00:00', ['api_status' => 'PST']));
        $moved = $this->match(4, 'Alaves', 'Oviedo', '2026-09-18 19:00:00');
        $this->logLine($moved);
        $moved->update(['match_date' => '2026-10-20 19:00:00']);
        // Hors fenêtre de 60 jours
        $this->logLine($this->match(5, 'Cadiz', 'Mallorca', '2026-07-20 19:00:00'));
        // Terminé ou journal déjà clôturé
        $this->logLine($this->match(6, 'Valencia', 'Espanyol', '2026-09-17 19:00:00', ['completed' => true, 'score_home' => 1, 'score_away' => 1]));
        $settled = $this->match(7, 'Rayo', 'Celta', '2026-09-17 19:00:00');
        $this->logLine($settled)->update(['score_home' => 0, 'score_away' => 0, 'outcome_occurred' => false, 'settled_at' => now()]);

        $candidates = FetchMatchDataJob::scoreCatchupCandidates(Carbon::parse('2026-09-25'), now()->subDays(60));

        $this->assertSame([$malaga->id, $betis->id, $levante->id], $candidates->pluck('id')->all());
    }

    public function test_catch_up_fetches_by_id_within_the_cap_and_yesterday_never_creates_matches(): void
    {
        config(['pipeline.score_catchup.max_requests' => 2]);
        $betis = $this->match(1570386, 'Real Betis', 'Getafe', '2026-09-17 17:00:00');
        $malaga = $this->match(1570390, 'Malaga', 'Villarreal', '2026-09-17 19:30:00');
        $levante = $this->match(1570389, 'Levante', 'Athletic Club', '2026-09-16 19:30:00');
        foreach ([$betis, $malaga, $levante] as $m) {
            $this->logLine($m);
        }
        $sevilla = $this->match(1, 'Sevilla', 'Osasuna', '2026-09-25 19:00:00');

        $this->api(
            previousDay: [
                $this->fixture(1, 'Sevilla', 'Osasuna', '2026-09-25T19:00:00+00:00', 'FT', 2, 0),
                // Hors du créneau du passage de la veille, jamais importé : pas créé
                $this->fixture(999, 'Barcelona', 'Getafe', '2026-09-25T10:00:00+00:00', 'FT', 3, 0),
            ],
            byId: [
                1570390 => $this->fixture(1570390, 'Malaga', 'Villarreal', '2026-09-17T19:30:00+00:00', 'FT', 1, 3),
                1570386 => $this->fixture(1570386, 'Real Betis', 'Getafe', '2026-09-17T17:00:00+00:00', 'FT', 1, 0),
            ],
        );

        $summary = $this->runJob();

        $this->assertSame([1, 3, true, 'FT'], [$malaga->fresh()->score_home, $malaga->fresh()->score_away, $malaga->fresh()->completed, $malaga->fresh()->api_status]);
        $this->assertSame([1, 0], [$betis->fresh()->score_home, $betis->fresh()->score_away]);
        $this->assertFalse($levante->fresh()->completed);
        $this->assertSame([2, 0], [$sevilla->fresh()->score_home, $sevilla->fresh()->score_away]);
        $this->assertNull(FootballMatch::where('api_football_id', 999)->first());

        $catchup = $summary['score_catchup'];
        $this->assertSame(3, $catchup['candidates']);
        $this->assertSame(2, $catchup['requests']);
        $this->assertSame(['Levante vs Athletic Club'], $catchup['deferred']);
        $this->assertStringStartsWith('1 match(s) terminé(s) sur 1 incomplet(s) du 2026-09-25', $summary['previous_day']);
        $this->assertTrue($summary['indispensable_complete']);
    }

    public function test_catch_up_keeps_the_optional_reserve_and_stops_on_quota(): void
    {
        $betis = $this->match(1570386, 'Real Betis', 'Getafe', '2026-09-17 17:00:00');
        $this->logLine($betis);
        $this->api([], [], remaining: 10);

        $summary = $this->runJob();

        $this->assertSame(0, $summary['score_catchup']['requests']);
        $this->assertSame(['Real Betis vs Getafe'], $summary['score_catchup']['deferred']);

        // Quota épuisé en route : les suivants attendent le passage suivant
        $malaga = $this->match(1570390, 'Malaga', 'Villarreal', '2026-09-17 19:30:00');
        $this->logLine($malaga);
        $this->api([], [1570390 => new ApiFootballException(ApiFootballException::DAILY_QUOTA, 'quota', '/fixtures')]);

        $summary = $this->runJob();

        $this->assertSame(['Malaga vs Villarreal'], $summary['score_catchup']['failed']);
        $this->assertSame(['Real Betis vs Getafe'], $summary['score_catchup']['deferred']);
        $this->assertFalse($betis->fresh()->completed);
    }

    public function test_rescheduled_match_loses_old_kickoff_odds_and_its_lines_are_voided(): void
    {
        $levante = $this->match(1570389, 'Levante', 'Athletic Club', '2026-09-16 19:30:00', [
            'odds_home' => 3.4, 'odds_draw' => 3.5, 'odds_away' => 2.1, 'odds_btts_yes' => 1.67,
            'odds_fetched_at' => '2026-09-16 16:18:58', 'odds_bookmaker' => 'bet365',
            'odds_at_pred_home' => 3.5, 'odds_at_pred_draw' => 3.56, 'odds_at_pred_away' => 2.16,
            'predicted_at' => '2026-09-16 16:20:09', 'odds_api_event_id' => 'ev-levante',
        ]);
        $lines = [$this->logLine($levante, '1'), $this->logLine($levante, 'X')];
        $september = OddsMovement::create([
            'match_id' => $levante->id, 'bookmaker' => 'pinnacle',
            'odds_home' => 3.5, 'odds_draw' => 3.56, 'odds_away' => 2.16,
            'snapshot_at' => '2026-09-16 19:25:00', 'quoted_at' => '2026-09-16 19:24:30', 'reliable' => true,
        ]);

        $this->api([], [1570389 => $this->fixture(1570389, 'Levante', 'Athletic Club', '2026-10-21T18:00:00+00:00', 'NS')]);
        $this->runJob();

        $levante->refresh();
        $this->assertSame('2026-10-21 18:00:00', $levante->match_date->format('Y-m-d H:i:s'));
        $this->assertSame('NS', $levante->api_status);
        foreach (['odds_home', 'odds_draw', 'odds_away', 'odds_btts_yes', 'odds_fetched_at', 'odds_bookmaker', 'odds_at_pred_home', 'predicted_at', 'odds_api_event_id'] as $column) {
            $this->assertNull($levante->{$column}, $column);
        }
        $this->assertFalse($levante->post_kickoff_data);

        // Le relevé de septembre ne sert jamais de clôture au match d'octobre
        $this->assertNull(app(CLVTrackerService::class)->closingSnapshot($levante));
        $this->assertTrue($september->fresh()->exists);

        $report = app(PredictionLogSettler::class)->settleUpTo('2026-09-25');

        $this->assertSame(['rescheduled' => 2], $report['voided']);
        $this->assertSame(0, $report['failures']);
        foreach ($lines as $line) {
            $line->refresh();
            $this->assertSame('rescheduled', $line->void_reason);
            $this->assertNull($line->outcome_occurred);
            $this->assertNotNull($line->settled_at);
        }
        $this->assertSame(0, PredictionLogEntry::pending()->count());
    }

    public function test_finished_match_whose_time_is_corrected_keeps_its_odds(): void
    {
        $match = $this->match(1570386, 'Real Betis', 'Getafe', '2026-09-17 17:00:00', ['odds_home' => 2.0, 'odds_at_pred_home' => 2.1]);

        app(\App\Services\DataPipeline\MatchEnricherService::class)
            ->upsertFromApiFootball($this->fixture(1570386, 'Real Betis', 'Getafe', '2026-09-17T17:15:00+00:00', 'FT', 1, 0));

        $this->assertEqualsWithDelta(2.0, (float) $match->fresh()->odds_home, 1e-9);
        $this->assertEqualsWithDelta(2.1, (float) $match->fresh()->odds_at_pred_home, 1e-9);
    }
}
