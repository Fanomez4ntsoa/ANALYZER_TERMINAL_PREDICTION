<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Models\PredictionLogEntry;
use App\Services\PredictionLog\PredictionLogSettler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Journal des sélections, clôture : issue sur le score final, jamais résolue par
 * défaut ; closing_edge seulement contre une clôture Pinnacle fiable, nul sinon.
 */
class PredictionLogSettleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        config(['odds-api.clv_bookmaker' => 'pinnacle', 'pipeline.closing.window_minutes' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function match(array $overrides = []): FootballMatch
    {
        return FootballMatch::create($overrides + [
            'home_team' => 'Como',
            'away_team' => 'Parma',
            'match_date' => '2026-09-15 16:30:00',
            'competition' => 'Serie A',
            'league_id' => 135,
            'data_source' => 'api',
            'completed' => true,
            'score_home' => 2,
            'score_away' => 1,
        ]);
    }

    private function entry(FootballMatch $match, string $market, string $outcome, float $odds = 2.0, array $overrides = []): PredictionLogEntry
    {
        return PredictionLogEntry::create($overrides + [
            'match_id' => $match->id,
            'league_id' => 135,
            'home_team' => 'Como',
            'away_team' => 'Parma',
            'kickoff_at' => '2026-09-15 16:30:00',
            'trigger' => PredictionLogEntry::TRIGGER_PIPELINE,
            'market' => $market,
            'outcome' => $outcome,
            'model_probability' => 0.5,
            'model_mode' => 'market_only',
            'lambda_home' => 1.5,
            'lambda_away' => 1.1,
            'rho' => -0.06,
            'odds' => $odds,
            'bookmaker' => 'Bet365',
            'fair_probability' => 0.48,
            'odds_taken_at' => '2026-09-15 09:58:00',
            'computed_at' => '2026-09-15 10:00:00',
        ]);
    }

    private function closing(FootballMatch $match, array $overrides = []): OddsMovement
    {
        return OddsMovement::create($overrides + [
            'match_id' => $match->id,
            'bookmaker' => 'pinnacle',
            'odds_home' => 2.00, 'odds_draw' => 4.00, 'odds_away' => 4.00,
            'odds_over_2_5' => null, 'odds_under_2_5' => null,
            'snapshot_at' => '2026-09-15 16:25:00',
            'quoted_at' => '2026-09-15 16:24:30',
            'reliable' => true,
        ]);
    }

    public function test_outcomes_follow_the_final_score(): void
    {
        $cases = [
            [2, 1, ['winner|1' => true, 'winner|X' => false, 'winner|2' => false, 'doubleChance|1X' => true, 'doubleChance|X2' => false, 'doubleChance|12' => true, 'overUnder25|Over' => true, 'overUnder25|Under' => false, 'btts|Yes' => true, 'btts|No' => false]],
            [0, 0, ['winner|X' => true, 'doubleChance|1X' => true, 'doubleChance|X2' => true, 'doubleChance|12' => false, 'overUnder25|Under' => true, 'btts|No' => true]],
            [1, 1, ['overUnder25|Over' => false, 'btts|Yes' => true]],
            [0, 3, ['winner|2' => true, 'overUnder25|Over' => true, 'btts|No' => true]],
        ];
        foreach ($cases as [$home, $away, $expected]) {
            foreach ($expected as $key => $occurred) {
                [$market, $outcome] = explode('|', $key);
                $this->assertSame($occurred, PredictionLogSettler::occurred($market, $outcome, $home, $away), "{$home}-{$away} {$key}");
            }
        }
    }

    public function test_settles_with_closing_edge_against_pinnacle_fair_close(): void
    {
        $match = $this->match();
        $home = $this->entry($match, 'winner', '1', 2.10);
        $dc = $this->entry($match, 'doubleChance', 'X2', 1.90);
        $over = $this->entry($match, 'overUnder25', 'Over', 1.85);
        $btts = $this->entry($match, 'btts', 'Yes', 1.70);
        $snapshot = $this->closing($match);
        // Relevé non fiable plus récent et relevé fiable hors fenêtre : ignorés
        $this->closing($match, ['odds_home' => 1.5, 'snapshot_at' => '2026-09-15 16:28:00', 'reliable' => false]);
        $this->closing($match, ['odds_home' => 3.0, 'snapshot_at' => '2026-09-15 16:10:00']);

        $report = app(PredictionLogSettler::class)->settle('2026-09-15');

        $this->assertSame(4, $report['settled']);
        $this->assertSame(0, $report['failures']);
        $this->assertSame(['market_not_quoted' => 2], $report['closing_edge_missing']);

        // Pinnacle 2.00 / 4.00 / 4.00 : équitable 0,5 / 0,25 / 0,25
        $home->refresh();
        $this->assertTrue($home->outcome_occurred);
        $this->assertSame(2, $home->score_home);
        $this->assertSame('pinnacle', $home->closing_bookmaker);
        $this->assertSame(2.0, $home->closing_odds);
        $this->assertEqualsWithDelta(0.5, $home->closing_fair_probability, 1e-9);
        $this->assertEqualsWithDelta(0.05, $home->closing_edge, 1e-9);
        $this->assertSame($snapshot->id, $home->closing_odds_movement_id);
        $this->assertSame('2026-09-15 16:24:30', $home->closing_quoted_at->format('Y-m-d H:i:s'));

        // Double chance : équitable dérivée du 1X2, pas de cote Pinnacle brute
        $dc->refresh();
        $this->assertFalse($dc->outcome_occurred);
        $this->assertNull($dc->closing_odds);
        $this->assertEqualsWithDelta(0.5, $dc->closing_fair_probability, 1e-9);
        $this->assertEqualsWithDelta(-0.05, $dc->closing_edge, 1e-9);

        // O/U 2.5 non coté par Pinnacle et BTTS jamais relevé : nul, pas zéro
        foreach ([$over->refresh(), $btts->refresh()] as $entry) {
            $this->assertTrue($entry->outcome_occurred);
            $this->assertNull($entry->closing_edge);
            $this->assertNull($entry->closing_bookmaker);
            $this->assertNotNull($entry->settled_at);
        }
    }

    public function test_market_clv_and_journal_share_the_same_closing_rule(): void
    {
        $match = $this->match(['odds_at_pred_home' => 2.2, 'odds_at_pred_draw' => 3.8, 'odds_at_pred_away' => 3.6]);
        $entry = $this->entry($match, 'winner', '1');
        $this->closing($match, ['odds_home' => 2.3, 'snapshot_at' => '2026-09-15 10:01:00']);
        $closing = $this->closing($match);
        $this->closing($match, ['odds_home' => 1.5, 'snapshot_at' => '2026-09-15 16:28:00', 'reliable' => false]);

        app(\App\Services\Market\CLVTrackerService::class)->markClosingOdds();
        app(PredictionLogSettler::class)->settle('2026-09-15');

        $this->assertEqualsWithDelta(2.0, (float) $match->fresh()->odds_closing_home, 1e-9);
        $this->assertSame($closing->id, $entry->fresh()->closing_odds_movement_id);
    }

    public function test_without_reliable_closing_the_edge_stays_null(): void
    {
        $match = $this->match();
        $entry = $this->entry($match, 'winner', '1');
        $this->closing($match, ['reliable' => false]);

        $report = app(PredictionLogSettler::class)->settle('2026-09-15');

        $this->assertSame(['no_closing_snapshot' => 1], $report['closing_edge_missing']);
        $this->assertTrue($entry->fresh()->outcome_occurred);
        $this->assertNull($entry->fresh()->closing_edge);
    }

    public function test_finished_match_without_score_stays_pending_and_fails(): void
    {
        $match = $this->match(['score_home' => null, 'score_away' => null]);
        $entry = $this->entry($match, 'winner', '1');

        $this->artisan('log:settle', ['--date' => '2026-09-15'])->assertExitCode(1);

        $this->assertNull($entry->fresh()->outcome_occurred);
        $this->assertNull($entry->fresh()->settled_at);
    }

    public function test_unfinished_match_stays_pending_without_failing(): void
    {
        $match = $this->match(['completed' => false, 'score_home' => null, 'score_away' => null]);
        $entry = $this->entry($match, 'winner', '1');

        $this->artisan('log:settle', ['--date' => '2026-09-15'])->assertExitCode(0);

        $this->assertNull($entry->fresh()->outcome_occurred);
    }

    public function test_rescheduled_match_stays_pending_and_fails(): void
    {
        $match = $this->match();
        $entry = $this->entry($match, 'winner', '1');
        $match->update(['match_date' => '2026-09-15 20:45:00']);

        $report = app(PredictionLogSettler::class)->settle('2026-09-15');

        $this->assertSame(['match_changed' => 1], $report['pending']);
        $this->assertSame(1, $report['failures']);
        $this->assertNull($entry->fresh()->outcome_occurred);
    }

    public function test_settled_entries_are_not_touched_again_and_older_pending_is_reported(): void
    {
        $match = $this->match();
        $entry = $this->entry($match, 'winner', '1');
        app(PredictionLogSettler::class)->settle('2026-09-15');
        $settledAt = $entry->fresh()->settled_at;

        $old = $this->match(['home_team' => 'Lecce', 'away_team' => 'Genoa', 'match_date' => '2026-09-13 18:00:00', 'completed' => false, 'score_home' => null, 'score_away' => null]);
        $this->entry($old, 'winner', 'X', 3.2, ['home_team' => 'Lecce', 'away_team' => 'Genoa', 'kickoff_at' => '2026-09-13 18:00:00', 'computed_at' => '2026-09-13 10:00:00']);

        Carbon::setTestNow(Carbon::parse('2026-09-16 11:00:00', 'UTC'));
        $report = app(PredictionLogSettler::class)->settle('2026-09-15');

        $this->assertSame(0, $report['entries']);
        $this->assertSame(['2026-09-13' => 1], $report['older_pending']);
        $this->assertTrue($entry->fresh()->settled_at->equalTo($settledAt));
    }
}
