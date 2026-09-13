<?php

namespace Tests\Unit\Probability;

use App\Models\FootballMatch;
use App\Services\Probability\LeagueGoalAverages;
use App\Services\Probability\PoissonModelService;
use App\Services\Probability\XGModelService;
use Tests\TestCase;

/**
 * Mode marché seul : aucun accès base, moyennes de ligue injectées.
 */
class XGModelServiceTest extends TestCase
{
    private const LEAGUE = 78; // Bundesliga, ancre injectée ci-dessous
    private const ANCHOR = 3.17;

    private function model(): XGModelService
    {
        return new XGModelService(new PoissonModelService(), new LeagueGoalAverages([self::LEAGUE => self::ANCHOR]));
    }

    private function match(float $h, float $d, float $a, ?float $over = null, ?float $under = null, ?int $league = self::LEAGUE): FootballMatch
    {
        $m = new FootballMatch([
            'home_team' => 'A', 'away_team' => 'B', 'match_date' => '2024-01-01', 'competition' => 'D1', 'league_id' => $league,
            'odds_home' => $h, 'odds_draw' => $d, 'odds_away' => $a, 'odds_over_2_5' => $over, 'odds_under_2_5' => $under,
        ]);
        $m->setRelation('advancedData', null);
        return $m;
    }

    public function test_market_only_applies_no_home_advantage(): void
    {
        config(['xg-model.legacy_home_advantage_after_fusion' => false]);
        $r = $this->model()->predict($this->match(2.70, 3.30, 2.70, 1.85, 1.95), true);

        // Cotes symétriques : le marché ne donne aucun avantage, le modèle non plus
        $this->assertEqualsWithDelta($r['lambdas']['home'], $r['lambdas']['away'], 0.001);
        $this->assertEqualsWithDelta($r['analysis']['1x2']['home'], $r['analysis']['1x2']['away'], 0.15);
    }

    public function test_legacy_flag_restores_post_fusion_home_advantage(): void
    {
        config(['xg-model.legacy_home_advantage_after_fusion' => true]);
        $r = $this->model()->predict($this->match(2.70, 3.30, 2.70, 1.85, 1.95), true);
        config(['xg-model.legacy_home_advantage_after_fusion' => false]);

        $this->assertGreaterThan($r['lambdas']['away'] * 1.10, $r['lambdas']['home']);
        $this->assertGreaterThan($r['analysis']['1x2']['away'] + 3, $r['analysis']['1x2']['home']);
    }

    public function test_grid_reproduces_input_1x2_before_any_total_rescaling(): void
    {
        config(['xg-model.legacy_home_advantage_after_fusion' => false, 'xg-model.anchor_total_on_league_average' => false]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50), true);
        config(['xg-model.anchor_total_on_league_average' => true]);
        $fair = $r['signals']['market']['implied'];

        $this->assertEqualsWithDelta($fair['home'], $r['analysis']['1x2']['home'], 1.0);
        $this->assertEqualsWithDelta($fair['draw'], $r['analysis']['1x2']['draw'], 1.0);
        $this->assertEqualsWithDelta($fair['away'], $r['analysis']['1x2']['away'], 1.0);
    }

    public function test_ou_odds_are_reproduced_when_present(): void
    {
        config(['xg-model.legacy_home_advantage_after_fusion' => false]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50, 1.80, 2.00), true);

        $this->assertEqualsWithDelta($r['signals']['market']['implied']['under_2_5'], $r['analysis']['overUnder25']['under'], 1.0);
        // Connu, non corrigé ici : le rééchelonnage du total à partage λh/λa constant
        // déplace le 1X2 (ici +2,7 points sur le domicile). Mesuré au run A.
    }

    public function test_total_is_anchored_on_league_average_without_ou_odds(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50), true);

        $this->assertSame(self::ANCHOR, $r['signals']['market']['total_anchor']);
        $this->assertEqualsWithDelta(self::ANCHOR, $r['lambdas']['home'] + $r['lambdas']['away'], 0.002);
        $this->assertGreaterThan($r['lambdas']['away'], $r['lambdas']['home']);
    }

    public function test_anchor_falls_back_to_constant_for_unknown_league(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50, null, null, 999), true);

        $this->assertSame(2 * 1.40, $r['signals']['market']['total_anchor']);
        $this->assertEqualsWithDelta(2.80, $r['lambdas']['home'] + $r['lambdas']['away'], 0.002);
    }

    public function test_no_anchor_flag_leaves_grid_total_free(): void
    {
        config(['xg-model.anchor_total_on_league_average' => false]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50), true);
        config(['xg-model.anchor_total_on_league_average' => true]);

        $this->assertNull($r['signals']['market']['total_anchor']);
        $this->assertNotEqualsWithDelta(self::ANCHOR, $r['lambdas']['home'] + $r['lambdas']['away'], 0.05);
    }

    public function test_anchor_is_not_applied_when_ou_odds_present(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50, 1.80, 2.00), true);

        $this->assertNull($r['signals']['market']['total_anchor']);
    }
}
