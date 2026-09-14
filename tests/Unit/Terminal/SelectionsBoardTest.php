<?php

namespace Tests\Unit\Terminal;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\Probability\PoissonModelService;
use App\Support\Terminal\MarketLabel;
use App\Support\Terminal\SelectionsBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Logique pure : modèles non enregistrés, relations posées à la main. */
class SelectionsBoardTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-09-14 12:00', 'UTC');
    }

    private function match(int $id, string $kickoff, int $league, ?float $oddsHome, array $predictions = []): FootballMatch
    {
        $match = new FootballMatch(['home_team' => "H{$id}", 'away_team' => "A{$id}", 'competition' => "L{$league}", 'league_id' => $league]);
        $match->id = $id;
        $match->match_date = CarbonImmutable::parse($kickoff, 'UTC');
        $match->odds_home = $oddsHome;
        $match->setRelation('predictions', new Collection($predictions));

        return $match;
    }

    private function prediction(int $id, string $market, string $outcome, ?float $odds = 2.0): Prediction
    {
        $p = new Prediction(['market' => $market, 'outcome' => $outcome, 'model_probability' => 0.5, 'odds' => $odds,
            'implied_probability' => $odds ? 1 / $odds : null, 'edge' => 0.01, 'lambda_home' => 1.4, 'lambda_away' => 1.1, 'rho' => -0.05]);
        $p->id = $id;

        return $p;
    }

    public function test_statuses_never_omit_a_match(): void
    {
        $matches = new Collection([
            $this->match(1, '2026-09-14 16:00', 94, null),        // hors Top 5, sans cotes
            $this->match(2, '2026-09-14 16:00', 39, null),        // Top 5, cotes absentes
            $this->match(3, '2026-09-14 10:00', 39, null),        // commencé, jamais calculé
            $this->match(4, '2026-09-14 16:00', 94, 1.8),         // cotes relevées quand le périmètre était large
        ]);

        $board = SelectionsBoard::build($matches, [39, 78, 135, 140, 61], $this->now);

        $this->assertSame(
            [SelectionsBoard::OUT_OF_PERIMETER, SelectionsBoard::NO_ODDS, SelectionsBoard::KICKED_OFF_UNCOMPUTED, SelectionsBoard::NOT_COMPUTED],
            array_column($board['groups'], 'status')
        );
        $this->assertSame(4, $board['counts']['matches']);
    }

    public function test_rows_follow_the_catalogue_not_the_edge(): void
    {
        $match = $this->match(5, '2026-09-14 18:00', 135, 1.5, [
            $this->prediction(10, Prediction::MARKET_BTTS, 'Yes'),
            $this->prediction(11, Prediction::MARKET_WINNER, '2'),
            $this->prediction(12, Prediction::MARKET_OVER_UNDER_25, 'Under'),
            $this->prediction(13, Prediction::MARKET_WINNER, '1'),
        ]);

        $rows = SelectionsBoard::build(new Collection([$match]), [135], $this->now)['groups'][0]['rows'];

        $this->assertSame(['1X2 · 1', '1X2 · 2', 'O/U 2.5 · Under', 'BTTS · Oui'], array_column($rows, 'tag'));
    }

    public function test_missing_odds_and_kicked_off_lines_are_not_pickable(): void
    {
        $upcoming = $this->match(6, '2026-09-14 18:00', 135, 1.5, [
            $this->prediction(20, Prediction::MARKET_WINNER, '1'),
            $this->prediction(21, Prediction::MARKET_BTTS, 'Yes', null),
        ]);
        $started = $this->match(7, '2026-09-14 11:00', 135, 1.5, [$this->prediction(22, Prediction::MARKET_WINNER, '1')]);

        $groups = SelectionsBoard::build(new Collection([$upcoming, $started]), [135], $this->now)['groups'];

        $this->assertSame([true, false], array_column($groups[0]['rows'], 'pickable'));
        $this->assertNull($groups[0]['rows'][1]['edge'], 'cote absente : aucun écart affiché');
        $this->assertFalse($groups[1]['rows'][0]['pickable']);
    }

    public function test_simulation_uses_recorded_lambdas_and_exact_matrix(): void
    {
        $match = $this->match(8, '2026-09-14 18:00', 135, 1.5, [$this->prediction(30, Prediction::MARKET_WINNER, '1')]);
        $poisson = new PoissonModelService();

        $sim = SelectionsBoard::build(new Collection([$match]), [135], $this->now, $poisson)['simulations'][8];

        $this->assertSame(1.4, $sim['lambda_home']);
        $this->assertSame("λ 1,400 / 1,100 · ρ \u{2212}0,050", $sim['params']);
        $this->assertEqualsWithDelta($poisson->scoreMatrix(1.4, 1.1, -0.05)[2][1], $sim['matrix'][2][1], 1e-7);
    }

    public function test_market_sort_key_orders_the_catalogue(): void
    {
        $this->assertLessThan(MarketLabel::sortKey(Prediction::MARKET_DOUBLE_CHANCE, '1X'), MarketLabel::sortKey(Prediction::MARKET_WINNER, '2'));
        $this->assertSame('DC · X2', MarketLabel::tag(Prediction::MARKET_DOUBLE_CHANCE, 'X2'));
    }
}
