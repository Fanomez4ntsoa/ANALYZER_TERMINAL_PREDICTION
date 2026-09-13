<?php

namespace Tests\Unit\Probability;

use App\Services\Probability\DixonColesRho;
use App\Services\Probability\PoissonModelService;
use Tests\TestCase;

class DixonColesRhoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'football-data.work_seasons' => ['2122', '2223', '2324'],
            'football-data.holdout_seasons' => ['2425', '2526'],
        ]);
    }

    /** Scores simulés selon Dixon-Coles, forces d'équipe tirées au hasard, graine fixe. */
    private function simulate(float $rho, string $season, string $div, int $leagueSeasons, int $seed): array
    {
        mt_srand($seed);
        $poisson = new PoissonModelService();
        $rows = [];
        for ($g = 0; $g < $leagueSeasons; $g++) {
            $A = $D = [];
            for ($t = 0; $t < 20; $t++) {
                $A[$t] = exp((mt_rand() / mt_getrandmax() - 0.5) * 0.8);
                $D[$t] = exp((mt_rand() / mt_getrandmax() - 0.5) * 0.6);
            }
            for ($i = 0; $i < 20; $i++) {
                for ($j = 0; $j < 20; $j++) {
                    if ($i === $j) continue;
                    $matrix = $poisson->scoreMatrix(1.15 * $A[$i] * $D[$j] * 1.3, 1.15 * $A[$j] * $D[$i], $rho);
                    $u = mt_rand() / mt_getrandmax();
                    $c = 0.0; $hg = 6; $ag = 6;
                    foreach ($matrix as $h => $row) {
                        foreach ($row as $a => $p) {
                            $c += $p;
                            if ($u <= $c) { $hg = $h; $ag = $a; break 2; }
                        }
                    }
                    $rows[] = ['season' => $season, 'div' => $div, 'home_team' => "{$div}{$g}t{$i}", 'away_team' => "{$div}{$g}t{$j}", 'fthg' => $hg, 'ftag' => $ag];
                }
            }
        }
        return $rows;
    }

    public function test_tau_corrects_only_the_four_low_scores_and_preserves_under_2_5(): void
    {
        $p = new PoissonModelService();
        $this->assertSame(1.0, PoissonModelService::tau(2, 0, 1.5, 1.1, -0.1));
        $this->assertEqualsWithDelta(1 + 1.5 * 1.1 * 0.1, PoissonModelService::tau(0, 0, 1.5, 1.1, -0.1), 1e-12);

        $dc = $p->scoreMatrix(1.5, 1.1, -0.1);
        $normalized = $p->scoreMatrix(1.5, 1.1, 0.0);
        $this->assertEqualsWithDelta(1.0, array_sum(array_map('array_sum', $dc)), 1e-12);
        $under = fn ($m, $line) => array_sum(array_map(fn ($h) => array_sum(array_filter($m[$h], fn ($a) => $h + $a <= $line, ARRAY_FILTER_USE_KEY)), array_keys($m)));
        $this->assertEqualsWithDelta($under($normalized, 2), $under($dc, 2), 1e-12);
        $this->assertEqualsWithDelta($under($normalized, 3), $under($dc, 3), 1e-12);
        $this->assertGreaterThan($under($dc, 1), $under($normalized, 1)); // ρ < 0 : moins d'Under 1.5
        $this->assertGreaterThan($p->predict1X2(1.5, 1.1)['draw'] + 2, $p->predict1X2(1.5, 1.1, -0.1)['draw']);
    }

    public function test_independent_path_is_unchanged_when_rho_is_null(): void
    {
        $p = new PoissonModelService();
        $raw = $p->scoreMatrix(1.5, 1.1);
        $this->assertLessThan(1.0, array_sum(array_map('array_sum', $raw))); // matrice tronquée non renormalisée
        $expected = round((1 - exp(-1.5)) * (1 - exp(-1.1)) * 100, 1); // formule indépendante historique
        $this->assertSame($expected, $p->predictBTTS(1.5, 1.1)['yes']);
    }

    public function test_maximum_likelihood_recovers_known_rho(): void
    {
        $rows = $this->simulate(-0.12, '2122', 'E0', 10, 7);
        $fit = (new DixonColesRho($rows))->estimate($rows);

        $this->assertSame(3800, $fit['matches']);
        $this->assertLessThanOrEqual($fit['ci95'][1], -0.12);
        $this->assertGreaterThanOrEqual($fit['ci95'][0], -0.12);
        $this->assertGreaterThan(3.84, $fit['lr_statistic']);
    }

    public function test_too_few_matches_gives_no_estimate(): void
    {
        $rows = array_slice($this->simulate(-0.1, '2122', 'E0', 1, 3), 0, DixonColesRho::MIN_MATCHES - 1);
        $this->assertNull((new DixonColesRho($rows))->estimate($rows)['rho']);
    }

    public function test_estimation_uses_only_strictly_prior_work_seasons_per_population(): void
    {
        $rows = array_merge(
            $this->simulate(-0.10, '2122', 'E0', 2, 11),
            $this->simulate(-0.10, '2223', 'E0', 2, 12),
            $this->simulate(0.30, '2324', 'E0', 2, 13),   // ne doit jamais servir à estimer 2324
            $this->simulate(0.30, '2425', 'E0', 2, 14),   // réservée
            $this->simulate(-0.10, '2122', 'E1', 2, 15),  // autre population
        );
        $estimator = new DixonColesRho($rows);

        $estimator->scopeToSeasonsBefore('2122');
        $this->assertNull($estimator->rhoForDivision('E0'));

        $estimator->scopeToSeasonsBefore('2324');
        $fit = $estimator->fit('top5');
        $this->assertSame(['2122', '2223'], $fit['seasons']);
        $this->assertSame(1520, $fit['matches']);
        $this->assertLessThan(0.0, $fit['rho']);

        $second = $estimator->fit('second');
        $this->assertSame(760, $second['matches']);

        $estimator->scopeToSeasonsBefore('2526');
        $this->assertSame(['2122', '2223', '2324'], $estimator->fit('top5')['seasons']);
        $this->assertSame(2280, $estimator->fit('top5')['matches']);
    }

    public function test_unscoped_access_throws_during_backtest(): void
    {
        $estimator = new DixonColesRho([]);
        $estimator->requireScope(true);
        $this->expectException(\LogicException::class);
        $estimator->rhoForDivision('E0');
    }

    public function test_any_training_row_from_a_non_prior_season_throws(): void
    {
        $rows = $this->simulate(-0.1, '2324', 'E0', 2, 21);
        // Chargeur défaillant : renvoie des lignes de la saison évaluée malgré le bornage
        $leaky = new class($rows) extends DixonColesRho {
            public function __construct(private array $leak) { parent::__construct([]); }
            protected function trainingRows(array $seasons, array $divisions): array { return $this->leak; }
        };
        $leaky->scopeToSeasonsBefore('2324');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('saison 2324');
        $leaky->fit('top5');
    }

    public function test_unknown_league_gets_no_correction(): void
    {
        $this->assertNull((new DixonColesRho([]))->rhoFor(99999));
        $this->assertNull((new DixonColesRho([]))->rhoFor(null));
    }
}
