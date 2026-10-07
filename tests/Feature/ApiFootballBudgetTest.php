<?php

namespace Tests\Feature;

use App\Jobs\FetchMatchDataJob;
use App\Services\Api\ApiFootballException;
use App\Services\Api\ApiFootballService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Budget API-Football : le plus pessimiste de /status, de l'en-tête et du
 * compteur local. Filtrage des matchs : une seule définition, partagée par le
 * pipeline et api-football:test --date.
 */
class ApiFootballBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'UTC'));
        config([
            'api-football.key' => 'test',
            'api-football.rate_limit.requests_per_minute' => 600,
            'api-football.leagues' => [39, 113, 61],
            'api-football.inactive_leagues' => [113],
            'pipeline.match_start_hour' => 0,
            'pipeline.match_end_hour' => 23,
        ]);
        Cache::flush();
        $this->resetTransportState();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetTransportState();
        parent::tearDown();
    }

    /** L'état du transport est statique, partagé par tout le processus de test. */
    private function resetTransportState(): void
    {
        $class = new \ReflectionClass(ApiFootballService::class);
        $class->setStaticPropertyValue('lastRequestAt', 0.0);
        $class->setStaticPropertyValue('dailyRemaining', null);
        $class->setStaticPropertyValue('dailyLimit', null);
    }

    private function fakeStatus(int $bodyCurrent, ?int $headerRemaining): void
    {
        $headers = $headerRemaining === null ? [] : ['x-ratelimit-requests-remaining' => (string) $headerRemaining];
        Http::fake([
            '*/status*' => Http::response(['errors' => [], 'response' => ['requests' => ['current' => $bodyCurrent, 'limit_day' => 100]]], 200, $headers),
        ]);
    }

    private function fixture(int $id, int $leagueId, string $date = '2026-09-30T15:00:00+00:00'): array
    {
        return [
            'fixture' => ['id' => $id, 'date' => $date, 'status' => ['short' => 'NS']],
            'league' => ['id' => $leagueId, 'name' => "Ligue {$leagueId}", 'country' => 'Pays'],
            'teams' => ['home' => ['name' => "Dom {$id}"], 'away' => ['name' => "Ext {$id}"]],
        ];
    }

    public function test_header_wins_when_status_body_lags(): void
    {
        // Constaté le 30/09/2026 : corps de /status à 1, en-tête à 97 restantes
        $this->fakeStatus(1, 97);

        $usage = app(ApiFootballService::class)->getDailyUsage();

        $this->assertSame(3, $usage['current']);
        $this->assertSame(97, $usage['remaining']);
        $this->assertSame(1, $usage['status_current']);
        $this->assertSame(3, $usage['header_current']);
    }

    public function test_local_counter_wins_when_both_api_counters_lag(): void
    {
        $this->fakeStatus(1, 97);
        Cache::put('api_football_requests_2026-09-30', 10);

        $this->assertSame(90, app(ApiFootballService::class)->getDailyUsage()['remaining']);
    }

    public function test_status_body_still_counts_without_header(): void
    {
        $this->fakeStatus(5, null);

        $usage = app(ApiFootballService::class)->getDailyUsage();

        $this->assertNull($usage['header_current']);
        $this->assertSame(95, $usage['remaining']);
    }

    public function test_header_of_an_earlier_call_is_not_reused(): void
    {
        (new \ReflectionClass(ApiFootballService::class))->setStaticPropertyValue('dailyRemaining', 50);
        $this->fakeStatus(5, null);

        $this->assertSame(95, app(ApiFootballService::class)->getDailyUsage()['remaining']);
    }

    public function test_system_status_shows_the_pessimistic_value(): void
    {
        $this->fakeStatus(1, 97);

        $this->artisan('system:status')
            ->expectsOutputToContain('97 requête(s) restante(s) sur 100')
            ->run();
    }

    public function test_select_fixtures_sorts_every_fixture_by_reason(): void
    {
        config(['pipeline.match_start_hour' => 12, 'pipeline.match_end_hour' => 22]);
        $fixtures = [
            $this->fixture(1, 39),
            $this->fixture(2, 113),
            $this->fixture(3, 964),
            $this->fixture(4, 61, '2026-09-30T09:00:00+00:00'),
        ];

        $selection = FetchMatchDataJob::selectFixtures($fixtures);

        $this->assertSame([1], array_column(array_column($selection['kept'], 'fixture'), 'id'));
        $this->assertSame([2], array_column(array_column($selection['inactive'], 'fixture'), 'id'));
        $this->assertSame([3], array_column(array_column($selection['untracked'], 'fixture'), 'id'));
        $this->assertSame([4], array_column(array_column($selection['out_of_hours'], 'fixture'), 'id'));

        $this->assertCount(2, FetchMatchDataJob::selectFixtures($fixtures, null, true)['kept']);
    }

    public function test_date_command_lists_every_ignored_league(): void
    {
        Http::fake([
            '*/fixtures*' => Http::response(['errors' => [], 'response' => [
                $this->fixture(1, 113),
                $this->fixture(2, 964),
                $this->fixture(3, 10),
                $this->fixture(4, 10),
            ]], 200, ['x-ratelimit-requests-remaining' => '90']),
        ]);

        $this->artisan('api-football:test', ['--date' => '2026-09-30'])
            ->expectsOutputToContain('4 match(s) renvoyé(s) par l\'API, 0 gardé(s) par le pipeline.')
            ->expectsOutputToContain('Ligues suivies mais désactivées (api-football.inactive_leagues) : 1 ligue(s), 1 match(s)')
            ->expectsOutputToContain('Ligue 113 (Pays) × 1')
            ->expectsOutputToContain('Ligues non suivies (api-football.leagues) : 2 ligue(s), 3 match(s)')
            ->expectsOutputToContain('Ligue 10 (Pays) × 2')
            ->expectsOutputToContain('Ligue 964 (Pays) × 1')
            ->assertSuccessful();
    }

    public function test_paginated_fixtures_by_date_fail_instead_of_losing_matches(): void
    {
        Http::fake([
            '*/fixtures*' => Http::response(['errors' => [], 'paging' => ['current' => 1, 'total' => 2], 'response' => [$this->fixture(1, 39)]]),
        ]);

        try {
            app(ApiFootballService::class)->getFixturesByDate('2026-09-30');
            $this->fail('Une réponse paginée doit lever une exception.');
        } catch (ApiFootballException $e) {
            $this->assertSame(ApiFootballException::API, $e->kind);
        }

        $this->assertFalse(Cache::has('api_football_fixtures_date_2026-09-30'));
    }

    public function test_single_page_fixtures_by_date_are_returned_and_cached(): void
    {
        Http::fake([
            '*/fixtures*' => Http::response(['errors' => [], 'paging' => ['current' => 1, 'total' => 1], 'response' => [$this->fixture(1, 39), $this->fixture(2, 61)]]),
        ]);

        $this->assertCount(2, app(ApiFootballService::class)->getFixturesByDate('2026-09-30'));
        $this->assertCount(2, app(ApiFootballService::class)->getFixturesByDate('2026-09-30'));
        Http::assertSentCount(1);
    }

    public function test_upcoming_fixtures_use_the_date_window_without_season(): void
    {
        Http::fake([
            '*/fixtures*' => Http::response(['errors' => [], 'response' => [$this->fixture(1, 61), $this->fixture(2, 39)]]),
        ]);

        $fixtures = app(ApiFootballService::class)->getUpcomingFixtures(61);

        $this->assertCount(2, $fixtures); // un match par jour, aujourd'hui et demain
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => !isset($request->data()['season']) && !isset($request->data()['next']) && isset($request->data()['date']));
    }
}
