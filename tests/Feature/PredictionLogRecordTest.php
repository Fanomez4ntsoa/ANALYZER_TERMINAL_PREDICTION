<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\PredictionLogEntry;
use App\Services\Probability\DixonColesRho;
use App\Services\Probability\LeagueGoalAverages;
use App\Services\Probability\PoissonModelService;
use App\Services\Probability\PredictionService;
use App\Services\Probability\XGModelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Journal des sélections, enregistrement : chaque calcul ajoute ses lignes jouables,
 * dans la même transaction que `predictions`, et une ligne ne se modifie jamais
 * hors de sa clôture.
 */
class PredictionLogRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'UTC'));
        $this->app->instance(XGModelService::class, new XGModelService(
            new PoissonModelService(),
            new LeagueGoalAverages([]),
            new class extends DixonColesRho {
                public function __construct() { parent::__construct([]); }
                public function rhoFor(?int $leagueId): ?float { return -0.06; }
            },
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function match(array $overrides = []): FootballMatch
    {
        return FootballMatch::create($overrides + [
            'home_team' => 'Brentford',
            'away_team' => 'Chelsea',
            'match_date' => '2026-09-15 19:00:00',
            'competition' => 'Premier League',
            'league_id' => 39,
            'data_source' => 'api',
            'odds_fetched_at' => '2026-09-15 09:58:00',
            'odds_bookmaker' => 'Bet365',
            'odds_home' => 2.90, 'odds_draw' => 3.60, 'odds_away' => 2.40,
            'odds_dc_1x' => 1.62, 'odds_dc_x2' => 1.44, 'odds_dc_12' => 1.30,
            'odds_over_2_5' => 1.80, 'odds_under_2_5' => 2.00,
            'odds_btts_yes' => 1.70, 'odds_btts_no' => null,
        ]);
    }

    private function service(): PredictionService
    {
        return $this->app->make(PredictionService::class);
    }

    public function test_playable_lines_are_logged_with_the_calculation(): void
    {
        $match = $this->match();
        $this->service()->computeAndStore($match, PredictionLogEntry::TRIGGER_PIPELINE);

        // BTTS sans cote « Non » : pas de probabilité équitable, hors journal
        $entries = PredictionLogEntry::orderBy('id')->get();
        $this->assertCount(8, $entries);
        $this->assertSame(0, $entries->where('market', 'btts')->count());

        $home = $entries->firstWhere('outcome', '1');
        $prediction = $match->predictions()->where('market', 'winner')->where('outcome', '1')->first();
        $this->assertSame('pipeline', $home->trigger);
        $this->assertSame('Bet365', $home->bookmaker);
        $this->assertSame(2.9, $home->odds);
        $this->assertEqualsWithDelta($prediction->model_probability, $home->model_probability, 1e-9);
        $this->assertEqualsWithDelta($prediction->fair_probability, $home->fair_probability, 1e-9);
        $this->assertSame('market_only', $home->model_mode);
        $this->assertSame(-0.06, $home->rho);
        $this->assertSame('2026-09-15 19:00:00', $home->kickoff_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 09:58:00', $home->odds_taken_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15 10:00:00', $home->computed_at->format('Y-m-d H:i:s'));
        $this->assertSame(39, $home->league_id);
        $this->assertNull($home->outcome_occurred);
    }

    public function test_recalculation_appends_and_never_replaces(): void
    {
        $match = $this->match();
        $this->service()->computeAndStore($match, PredictionLogEntry::TRIGGER_PIPELINE);
        Carbon::setTestNow(Carbon::parse('2026-09-15 15:00:00', 'UTC'));
        $this->service()->computeAndStore($match->fresh(), PredictionLogEntry::TRIGGER_MANUAL);

        $this->assertSame(10, $match->predictions()->count());
        $this->assertSame(8, PredictionLogEntry::where('trigger', 'pipeline')->count());
        $this->assertSame(8, PredictionLogEntry::where('trigger', 'manual')->count());
    }

    public function test_legacy_max_odds_are_not_logged(): void
    {
        $match = $this->match(['odds_fetched_at' => null, 'odds_bookmaker' => null]);
        $this->service()->computeAndStore($match, PredictionLogEntry::TRIGGER_PIPELINE);

        $this->assertSame(10, $match->predictions()->count());
        $this->assertSame(0, PredictionLogEntry::count());
    }

    public function test_kicked_off_match_writes_nothing(): void
    {
        $match = $this->match(['match_date' => '2026-09-15 09:30:00']);

        try {
            $this->service()->computeAndStore($match, PredictionLogEntry::TRIGGER_MANUAL);
            $this->fail('Calcul accepté après le coup d\'envoi');
        } catch (\App\Services\Probability\KickoffPassedException) {
        }

        $this->assertSame(0, PredictionLogEntry::count());
    }

    public function test_journal_failure_rolls_back_the_displayed_calculation(): void
    {
        $match = $this->match();

        try {
            $this->service()->computeAndStore($match, 'inconnu');
            $this->fail('Déclencheur inconnu accepté');
        } catch (\LogicException) {
        }

        $this->assertSame(0, $match->predictions()->count());
        $this->assertSame(0, PredictionLogEntry::count());
    }

    public function test_entry_is_frozen_except_its_settlement_written_once(): void
    {
        $this->service()->computeAndStore($this->match(), PredictionLogEntry::TRIGGER_PIPELINE);
        $entry = PredictionLogEntry::first();

        foreach ([['model_probability' => 0.5], ['odds' => 3.1], ['trigger' => 'manual']] as $change) {
            try {
                $entry->fresh()->update($change);
                $this->fail('Modification acceptée : ' . array_key_first($change));
            } catch (\LogicException) {
            }
        }

        $entry->update(['score_home' => 2, 'score_away' => 1, 'outcome_occurred' => true, 'settled_at' => now()]);
        $this->assertTrue($entry->fresh()->outcome_occurred);

        try {
            $entry->fresh()->update(['outcome_occurred' => false]);
            $this->fail('Clôture réécrite');
        } catch (\LogicException) {
        }

        try {
            $entry->fresh()->delete();
            $this->fail('Suppression acceptée');
        } catch (\LogicException) {
        }

        $this->assertSame(8, PredictionLogEntry::count());
        $this->assertTrue($entry->fresh()->outcome_occurred);
    }
}
