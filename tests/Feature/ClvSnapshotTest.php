<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Services\Api\OddsApiService;
use App\Services\Market\CLVTrackerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Relevés du CLV : jamais depuis le cache, cote datée et récente, raison de
 * chaque absence, échec de la commande quand un match attendu manque.
 */
class ClvSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-09-14 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
        config([
            'odds-api.key' => 'test',
            'odds-api.clv_bookmaker' => 'pinnacle',
            'pipeline.closing.leagues' => [135],
            'pipeline.odds_snapshot.max_quote_age_minutes' => 10,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function match(string $eventId = 'ev-como', string $home = 'Como', string $away = 'Parma'): FootballMatch
    {
        return FootballMatch::create([
            'home_team' => $home,
            'away_team' => $away,
            'match_date' => '2026-09-14 16:30:00',
            'competition' => 'Serie A',
            'league_id' => 135,
            'data_source' => 'api',
            'odds_api_event_id' => $eventId,
        ]);
    }

    private function event(string $id, string $home, string $away, array $bookmakers): array
    {
        return ['id' => $id, 'home_team' => $home, 'away_team' => $away, 'commence_time' => '2026-09-14T16:30:00Z', 'bookmakers' => $bookmakers];
    }

    private function bookmaker(string $key, string $lastUpdate, array $prices = [1.23, 6.34, 13.61]): array
    {
        return ['key' => $key, 'last_update' => $lastUpdate, 'markets' => [
            ['key' => 'h2h', 'last_update' => $lastUpdate, 'outcomes' => [
                ['name' => 'Como', 'price' => $prices[0]], ['name' => 'Draw', 'price' => $prices[1]], ['name' => 'Parma', 'price' => $prices[2]],
            ]],
        ]];
    }

    private function fakeOdds(array $events, int $used = 30, int $remaining = 470): void
    {
        Http::fake(['*/sports/soccer_italy_serie_a/odds*' => Http::response($events, 200, [
            'x-requests-used' => (string) $used, 'x-requests-remaining' => (string) $remaining, 'x-requests-last' => '2',
        ])]);
    }

    public function test_fresh_quote_is_stored_as_reliable_with_its_quote_time(): void
    {
        $match = $this->match();
        $this->fakeOdds([$this->event('ev-como', 'Como', 'Parma', [$this->bookmaker('pinnacle', '2026-09-14T11:59:20Z')])]);

        $report = app(CLVTrackerService::class)->snapshotOdds('2026-09-14');

        $this->assertSame(['expected' => 1, 'stored' => 1, 'missing' => []], $report);
        $row = OddsMovement::sole();
        $this->assertTrue($row->reliable);
        $this->assertSame('2026-09-14 11:59:20', $row->quoted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertEqualsWithDelta(1.23, (float) $match->fresh()->odds_at_pred_home, 1e-9);
    }

    public function test_every_snapshot_calls_the_api_even_when_a_response_is_cached(): void
    {
        $this->match();
        $this->fakeOdds([$this->event('ev-como', 'Como', 'Parma', [$this->bookmaker('pinnacle', '2026-09-14T11:59:20Z')])]);

        app(CLVTrackerService::class)->snapshotOdds('2026-09-14');
        app(CLVTrackerService::class)->snapshotOdds('2026-09-14');

        Http::assertSentCount(2);
    }

    public function test_stale_quote_is_refused_and_no_movement_is_recorded(): void
    {
        $this->match();
        // Réponse recyclée : cote de 06:19 servie à 12:00
        $this->fakeOdds([$this->event('ev-como', 'Como', 'Parma', [$this->bookmaker('pinnacle', '2026-09-14T06:19:00Z')])]);

        $report = app(CLVTrackerService::class)->snapshotOdds('2026-09-14');

        $this->assertSame(0, OddsMovement::count());
        $this->assertSame(CLVTrackerService::MISSING_STALE_QUOTE, $report['missing'][0]['reason']);
        $this->assertSame(341.0, $report['missing'][0]['quote_age_minutes']);
    }

    public function test_event_not_found_and_bookmaker_absent_are_distinguished(): void
    {
        $this->match('ev-como', 'Como', 'Parma');
        $this->match('ev-inter', 'Inter', 'Udinese');
        $this->fakeOdds([
            $this->event('ev-como', 'Como', 'Parma', [$this->bookmaker('betfair_ex_eu', '2026-09-14T11:59:20Z'), $this->bookmaker('williamhill', '2026-09-14T11:59:20Z')]),
        ]);

        $report = app(CLVTrackerService::class)->snapshotOdds('2026-09-14');

        $byMatch = collect($report['missing'])->keyBy('match');
        $this->assertSame(CLVTrackerService::MISSING_BOOKMAKER_ABSENT, $byMatch['Como vs Parma']['reason']);
        $this->assertSame(2, $byMatch['Como vs Parma']['bookmakers_present']);
        $this->assertSame(CLVTrackerService::MISSING_EVENT_NOT_FOUND, $byMatch['Inter vs Udinese']['reason']);
        $this->assertSame(1, $byMatch['Inter vs Udinese']['events_in_response']);
    }

    public function test_movement_is_computed_against_the_last_reliable_snapshot_only(): void
    {
        $match = $this->match();
        // Relevé recyclé d'avant la correction : non fiable, cote différente
        OddsMovement::create(['match_id' => $match->id, 'bookmaker' => 'pinnacle', 'odds_home' => 1.50, 'odds_draw' => 5.0, 'odds_away' => 9.0, 'snapshot_at' => '2026-09-14 11:00:00']);
        $this->fakeOdds([$this->event('ev-como', 'Como', 'Parma', [$this->bookmaker('pinnacle', '2026-09-14T11:59:20Z')])]);

        app(CLVTrackerService::class)->snapshotOdds('2026-09-14');

        $this->assertNull(OddsMovement::reliable()->sole()->move_home_pct);
    }

    public function test_command_fails_when_an_expected_match_has_no_snapshot(): void
    {
        $this->match();
        $this->fakeOdds([$this->event('ev-como', 'Como', 'Parma', [$this->bookmaker('williamhill', '2026-09-14T11:59:20Z')])]);

        $this->artisan('market:track', ['action' => 'snapshot', '--date' => '2026-09-14'])
            ->expectsOutputToContain('événement trouvé, bookmaker absent (1 bookmakers présents)')
            ->assertExitCode(1);
    }

    public function test_command_succeeds_when_nothing_is_expected(): void
    {
        Http::fake();

        $this->artisan('market:track', ['action' => 'snapshot', '--date' => '2026-09-14'])->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_quota_comes_from_api_headers_not_a_local_counter(): void
    {
        $api = app(OddsApiService::class);
        $this->assertNull($api->getMonthlyUsage()['used']);

        $this->match();
        $headers = fn (int $used, int $remaining) => ['x-requests-used' => (string) $used, 'x-requests-remaining' => (string) $remaining, 'x-requests-last' => '2'];
        Http::fake(['*/sports/soccer_italy_serie_a/odds*' => Http::sequence()
            ->push([], 200, $headers(27, 473))
            ->push([], 200, $headers(500, 0))]);

        app(CLVTrackerService::class)->snapshotOdds('2026-09-14');
        $usage = $api->getMonthlyUsage();
        $this->assertSame([27, 473, 500], [$usage['used'], $usage['remaining'], $usage['limit']]);
        $this->assertFalse($api->isQuotaExhausted());

        app(CLVTrackerService::class)->snapshotOdds('2026-09-14');
        $this->assertTrue($api->isQuotaExhausted());
    }
}
