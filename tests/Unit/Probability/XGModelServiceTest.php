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
    private const LEAGUE = 78; // Bundesliga

    /** Moyennes injectées : 2122 = 3,0 buts, 2223 = 3,2 buts (306 matchs chacune). */
    private function goals(): LeagueGoalAverages
    {
        return new LeagueGoalAverages([
            '2122' => [self::LEAGUE => ['goals' => 918.0, 'matches' => 306]],
            '2223' => [self::LEAGUE => ['goals' => 979.2, 'matches' => 306]],
        ]);
    }

    private function model(?LeagueGoalAverages $goals = null): XGModelService
    {
        return new XGModelService(new PoissonModelService(), $goals ?? $this->goals());
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

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'xg-model.legacy_home_advantage_after_fusion' => false,
            'xg-model.legacy_constant_share_rescaling' => false,
            'xg-model.anchor_total_on_league_average' => false,
            'football-data.work_seasons' => ['2122', '2223', '2324'],
        ]);
    }

    private function l1(array $analysis1x2, array $implied): float
    {
        return abs($analysis1x2['home'] - $implied['home']) + abs($analysis1x2['draw'] - $implied['draw']) + abs($analysis1x2['away'] - $implied['away']);
    }

    // ── avantage domicile

    public function test_market_only_applies_no_home_advantage(): void
    {
        $r = $this->model()->predict($this->match(2.70, 3.30, 2.70, 1.85, 1.95), true);

        $this->assertEqualsWithDelta($r['lambdas']['home'], $r['lambdas']['away'], 0.01);
        $this->assertEqualsWithDelta($r['analysis']['1x2']['home'], $r['analysis']['1x2']['away'], 0.3);
    }

    public function test_legacy_flag_restores_post_fusion_home_advantage(): void
    {
        config(['xg-model.legacy_home_advantage_after_fusion' => true]);
        $r = $this->model()->predict($this->match(2.70, 3.30, 2.70, 1.85, 1.95), true);

        $this->assertGreaterThan($r['lambdas']['away'] * 1.10, $r['lambdas']['home']);
        $this->assertGreaterThan($r['analysis']['1x2']['away'] + 3, $r['analysis']['1x2']['home']);
    }

    // ── recalage conjoint

    public function test_joint_fit_reproduces_ou_exactly(): void
    {
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50, 1.80, 2.00), true);

        $this->assertSame('over_under', $r['signals']['market']['total_source']);
        $this->assertEqualsWithDelta($r['signals']['market']['implied']['under_2_5'], $r['analysis']['overUnder25']['under'], 0.15);
    }

    public function test_joint_fit_is_closer_to_input_1x2_than_constant_share(): void
    {
        $match = $this->match(1.80, 3.60, 4.50, 1.80, 2.00);
        $joint = $this->model()->predict($match, true);
        config(['xg-model.legacy_constant_share_rescaling' => true]);
        $legacy = $this->model()->predict($match, true);
        $implied = $joint['signals']['market']['implied'];

        $this->assertSame('over_under_constant_share', $legacy['signals']['market']['total_source']);
        $this->assertLessThan($this->l1($legacy['analysis']['1x2'], $implied) - 0.5, $this->l1($joint['analysis']['1x2'], $implied));
        // Totaux identiques à la tolérance de l'ancienne dichotomie près
        $this->assertEqualsWithDelta($legacy['lambdas']['home'] + $legacy['lambdas']['away'], $joint['lambdas']['home'] + $joint['lambdas']['away'], 0.03);
    }

    public function test_joint_fit_cannot_restore_market_draw_under_independent_poisson(): void
    {
        // Limite connue : à total fixé, deux Poisson indépendantes sous-estiment le nul.
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50, 1.80, 2.00), true);
        $this->assertLessThan($r['signals']['market']['implied']['draw'] - 1.5, $r['analysis']['1x2']['draw']);
    }

    public function test_grid_reproduces_input_1x2_without_ou_odds(): void
    {
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50), true);
        $implied = $r['signals']['market']['implied'];

        $this->assertSame('grid', $r['signals']['market']['total_source']);
        $this->assertLessThan(1.0, $this->l1($r['analysis']['1x2'], $implied));
    }

    // ── ancrage (désactivé par défaut)

    public function test_anchor_is_disabled_by_default(): void
    {
        $defaults = require base_path('config/xg-model.php');
        $this->assertFalse($defaults['anchor_total_on_league_average']);
        $this->assertFalse($defaults['legacy_constant_share_rescaling']);
        $this->assertFalse($defaults['legacy_home_advantage_after_fusion']);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50), true);
        $this->assertNull($r['signals']['market']['total_anchor']);
    }

    public function test_anchor_unscoped_uses_all_work_seasons(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50), true);

        $this->assertSame(3.1, $r['signals']['market']['total_anchor']);
        $this->assertSame('league_anchor', $r['signals']['market']['total_source']);
        $this->assertEqualsWithDelta(3.1, $r['lambdas']['home'] + $r['lambdas']['away'], 0.002);
    }

    public function test_anchor_scoped_uses_only_prior_seasons(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $goals = $this->goals();
        $model = $this->model($goals);

        $goals->scopeToSeasonsBefore('2223');
        $this->assertSame(3.0, $model->predict($this->match(1.80, 3.60, 4.50), true)['signals']['market']['total_anchor']);

        $goals->scopeToSeasonsBefore('2324');
        $this->assertSame(3.1, $model->predict($this->match(1.80, 3.60, 4.50), true)['signals']['market']['total_anchor']);
    }

    public function test_anchor_scoped_without_prior_season_leaves_total_free(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $goals = $this->goals();
        $goals->scopeToSeasonsBefore('2122');
        $r = $this->model($goals)->predict($this->match(1.80, 3.60, 4.50), true);

        $this->assertNull($r['signals']['market']['total_anchor']);
        $this->assertSame('grid', $r['signals']['market']['total_source']);
    }

    public function test_anchor_falls_back_to_constant_only_in_production(): void
    {
        config(['xg-model.anchor_total_on_league_average' => true]);
        $r = $this->model()->predict($this->match(1.80, 3.60, 4.50, null, null, 999), true);
        $this->assertSame(2 * 1.40, $r['signals']['market']['total_anchor']);

        $goals = $this->goals();
        $goals->scopeToSeasonsBefore('2324');
        $r = $this->model($goals)->predict($this->match(1.80, 3.60, 4.50, null, null, 999), true);
        $this->assertNull($r['signals']['market']['total_anchor']);
    }
}
