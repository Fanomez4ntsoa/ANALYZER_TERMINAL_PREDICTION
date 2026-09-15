<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\PredictionLogEntry;
use App\Services\PredictionLog\PredictionLogReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Journal des sélections, mesure : premier calcul du pipeline seul, effectif en
 * matchs, Brier et tranches calculés à la main, modèle complet sur ses seules
 * lignes à signal hors marché.
 */
class PredictionLogReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prediction-log.min_matches' => 2]);
    }

    private function match(string $home, int $leagueId, string $competition, bool $contaminated = false): FootballMatch
    {
        return FootballMatch::create([
            'home_team' => $home,
            'away_team' => 'Visiteur',
            'match_date' => '2026-09-20 15:00:00',
            'competition' => $competition,
            'league_id' => $leagueId,
            'data_source' => 'api',
            'post_kickoff_data' => $contaminated,
        ]);
    }

    /**
     * Un calcul 1X2 : probabilités [1, X, 2] du modèle et équitables ; issue
     * réalisée ('1', 'X', '2') ou null pour une ligne en attente.
     */
    private function winner(FootballMatch $match, string $trigger, string $computedAt, array $model, array $fair, ?string $result, ?array $full = null, array $signals = ['market']): void
    {
        foreach (['1', 'X', '2'] as $i => $outcome) {
            $entry = PredictionLogEntry::create([
                'match_id' => $match->id,
                'league_id' => $match->league_id,
                'home_team' => $match->home_team,
                'away_team' => $match->away_team,
                'kickoff_at' => $match->match_date,
                'trigger' => $trigger,
                'market' => 'winner',
                'outcome' => $outcome,
                'model_probability' => $model[$i],
                'full_model_probability' => $full[$i] ?? null,
                'full_model_signals' => $full ? ['used' => $signals] : null,
                'model_mode' => 'market_only',
                'lambda_home' => 1.4,
                'lambda_away' => 1.1,
                'odds' => round(1 / $fair[$i] * 0.95, 3),
                'bookmaker' => 'Bet365',
                'fair_probability' => $fair[$i],
                'computed_at' => $computedAt,
            ]);
            if ($result !== null) {
                $entry->update(['score_home' => 1, 'score_away' => 0, 'outcome_occurred' => $outcome === $result, 'settled_at' => '2026-09-21 10:00:00']);
            }
        }
    }

    private function fixture(): void
    {
        $a = $this->match('Arsenal', 39, 'Premier League');
        $this->winner($a, 'pipeline', '2026-09-20 10:00:00', [0.5, 0.3, 0.2], [0.45, 0.3, 0.25], '1', full: [0.55, 0.25, 0.2], signals: ['market', 'comparison']);
        // Recalcul du bouton et second passage du pipeline : jamais mesurés
        $this->winner($a, 'manual', '2026-09-20 11:00:00', [0.9, 0.05, 0.05], [0.45, 0.3, 0.25], '1');
        $this->winner($a, 'pipeline', '2026-09-20 12:00:00', [0.9, 0.05, 0.05], [0.45, 0.3, 0.25], '1');

        $b = $this->match('Bologna', 135, 'Serie A');
        $this->winner($b, 'pipeline', '2026-09-20 10:00:00', [0.4, 0.3, 0.3], [0.4, 0.25, 0.35], 'X', full: [0.4, 0.3, 0.3], signals: ['market']);

        $c = $this->match('Chelsea', 39, 'Premier League');
        $this->winner($c, 'manual', '2026-09-20 11:00:00', [0.6, 0.2, 0.2], [0.5, 0.25, 0.25], '2');

        $d = $this->match('Derby', 39, 'Premier League');
        $this->winner($d, 'pipeline', '2026-09-20 10:00:00', [0.6, 0.2, 0.2], [0.5, 0.25, 0.25], null);

        $e = $this->match('Everton', 39, 'Premier League', contaminated: true);
        $this->winner($e, 'pipeline', '2026-09-20 10:00:00', [0.6, 0.2, 0.2], [0.5, 0.25, 0.25], '1');
    }

    public function test_only_the_first_pipeline_calculation_is_measured_and_exclusions_are_counted(): void
    {
        $this->fixture();
        $report = app(PredictionLogReport::class)->build();

        $this->assertSame([
            'settled_matches' => 2,
            'settled_lines' => 6,
            'pending_matches' => 1,
            'pending_lines' => 3,
            'manual_only_matches' => 1,
            'manual_only_lines' => 3,
            'manual_only_settled_lines' => 3,
            'contaminated_matches' => 1,
        ], $report['totals']);
    }

    public function test_brier_paired_difference_and_bins_by_hand(): void
    {
        $this->fixture();
        $winner = app(PredictionLogReport::class)->build()['groups']['adjustment']['results']['winner']['model'];
        $m = $winner['overall'];

        // Modèle : A 0,25 + 0,09 + 0,04 ; B 0,16 + 0,49 + 0,09 → 1,12 / 6
        $this->assertSame(2, $m['matches']);
        $this->assertSame(6, $m['lines']);
        $this->assertTrue($m['threshold_reached']);
        $this->assertEqualsWithDelta(1.12 / 6, $m['brier'], 1e-9);
        // Bet365 équitable : A 0,455 ; B 0,845 → 1,3 / 6
        $this->assertEqualsWithDelta(1.3 / 6, $m['brier_fair'], 1e-9);
        // Écart groupé par match : D_A = −0,075, D_B = −0,105, erreur type 0,005
        $this->assertEqualsWithDelta(-0.03, $m['diff_vs_fair']['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.005, $m['diff_vs_fair']['se'], 1e-9);

        $bins = collect($m['bins'])->keyBy(fn ($b) => (string) $b['from']);
        $this->assertSame(['0.2', '0.3', '0.4', '0.5'], $bins->keys()->all());
        // 0,30 : X d'Arsenal (non), X et 2 de Bologna (oui, non)
        $this->assertSame(3, $bins['0.3']['lines']);
        $this->assertSame(2, $bins['0.3']['matches']);
        $this->assertEqualsWithDelta(1 / 3, $bins['0.3']['observed'], 1e-9);
        $this->assertEqualsWithDelta((0.3 + 0.25 + 0.35) / 3, $bins['0.3']['fair'], 1e-9);

        // Par championnat : un match chacun, seuil non atteint
        $this->assertSame(['Premier League', 'Serie A'], array_column($winner['by_league'], 'league'));
        $this->assertFalse($winner['by_league'][39]['threshold_reached']);
        $this->assertNull($winner['by_league'][39]['diff_vs_fair']['se']);
    }

    public function test_threshold_counts_matches_not_lines(): void
    {
        config(['prediction-log.min_matches' => 3]);
        $this->fixture();
        $m = app(PredictionLogReport::class)->build()['groups']['adjustment']['results']['winner']['model']['overall'];

        $this->assertSame(6, $m['lines']);
        $this->assertFalse($m['threshold_reached']);
    }

    public function test_full_model_is_measured_only_where_an_extra_signal_was_used(): void
    {
        $this->fixture();
        $full = app(PredictionLogReport::class)->build()['groups']['adjustment']['results']['winner']['full'];

        $this->assertSame(1, $full['overall']['matches']);
        $this->assertFalse($full['overall']['threshold_reached']);
        $this->assertSame(2, $full['market_only_matches']);
        $this->assertSame(1, $full['without_extra_signal_matches']);
        $this->assertSame(0, $full['not_computed_matches']);
        // Arsenal : complet 0,2025 + 0,0625 + 0,04 ; marché seul 0,38 sur les mêmes lignes
        $this->assertEqualsWithDelta(0.305 / 3, $full['overall']['brier'], 1e-9);
        $this->assertEqualsWithDelta(0.38 / 3, $full['overall']['brier_baseline'], 1e-9);
        $this->assertEqualsWithDelta((0.305 - 0.38) / 3, $full['overall']['diff_vs_baseline']['mean'], 1e-9);
    }

    public function test_command_always_shows_total_and_refuses_conclusions_below_threshold(): void
    {
        config(['prediction-log.min_matches' => 200]);
        $this->fixture();

        $this->artisan('log:report')
            ->expectsOutputToContain('Effectif total : 2 match(s) clôturé(s), 6 ligne(s)')
            ->expectsOutputToContain('Écartés, lignes du bouton seulement : 1 match(s), 3 ligne(s) dont 3 clôturée(s)')
            ->expectsOutputToContain('AUCUNE CONCLUSION POSSIBLE')
            ->expectsOutputToContain('2 < 200 : CES CHIFFRES NE PERMETTENT AUCUNE CONCLUSION')
            ->expectsOutputToContain('Progression propre : 1 / 200 match(s) où comparaison ou blessures ont servi, contre 2 pour le marché seul.')
            ->assertExitCode(0);
    }

    public function test_empty_journal_still_reports_its_total(): void
    {
        $this->artisan('log:report', ['--market' => 'btts'])
            ->expectsOutputToContain('Effectif total : 0 match(s) clôturé(s), 0 ligne(s)')
            ->expectsOutputToContain('AUCUNE CONCLUSION POSSIBLE')
            ->assertExitCode(0);

        $this->artisan('log:report', ['--market' => 'edge'])->assertExitCode(1);
    }
}
