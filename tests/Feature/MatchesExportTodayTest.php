<?php

namespace Tests\Feature;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use App\Models\Prediction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Export Markdown des matchs du jour : journée EAT, valeurs stockées jamais
 * recalculées, marchés d'ajustement et dérivés séparés, absences expliquées.
 */
class MatchesExportTodayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 30/09/2026 13h15 EAT
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:15:00', 'UTC'));
        config([
            'app.display_timezone' => 'Indian/Antananarivo',
            'pipeline.schedule_time' => '10:00',
            'pipeline.schedule_timezone' => 'UTC',
            'football-data.reference_run.id' => 1,
        ]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function match(string $home, string $away, string $kickoffUtc, bool $withPredictions = true, array $attributes = []): FootballMatch
    {
        $match = FootballMatch::create([
            'home_team' => $home, 'away_team' => $away, 'match_date' => $kickoffUtc,
            'competition' => 'Serie A', 'league_id' => 135, 'data_source' => 'api',
        ] + $attributes);

        if ($withPredictions) {
            $rows = [
                ['winner', '1', 0.473, 2.00, 0.473, 0.0],
                ['winner', 'X', 0.303, 3.20, 0.296, 0.007],
                ['winner', '2', 0.224, 4.10, 0.231, -0.007],
                ['doubleChance', '1X', 0.776, 1.22, 0.769, 0.007],
                ['doubleChance', 'X2', 0.527, 1.73, 0.527, 0.0],
                ['doubleChance', '12', 0.697, 1.36, 0.704, -0.007],
                ['overUnder25', 'Over', 0.344, 2.75, 0.344, 0.0],
                ['overUnder25', 'Under', 0.656, 1.44, 0.656, 0.0],
                // Valeurs volontairement incohérentes avec la cote : l'export doit
                // reprendre le stocké, jamais recalculer 1 / cote
                ['btts', 'Yes', 0.402, 2.25, 0.4111, -0.0091],
                ['btts', 'No', 0.598, null, null, null],
            ];
            foreach ($rows as [$market, $outcome, $model, $odds, $fair, $edge]) {
                Prediction::create([
                    'match_id' => $match->id, 'market' => $market, 'outcome' => $outcome,
                    'model_probability' => $model, 'model_mode' => 'market_only',
                    'lambda_home' => 1.4, 'lambda_away' => 1.1, 'rho' => -0.05,
                    'odds' => $odds, 'implied_probability' => $odds ? 1 / $odds : null,
                    'fair_probability' => $fair, 'edge' => $edge,
                    'bookmaker' => $odds ? 'bet365' : null, 'odds_taken_at' => '2026-09-30 10:05:00',
                    'computed_at' => '2026-09-30 10:10:00',
                ]);
            }
        }

        return $match;
    }

    private function export(): string
    {
        $this->artisan('matches:export-today')
            ->expectsOutputToContain(Storage::disk('local')->path('exports/matches-2026-09-30-13h15.md'))
            ->assertSuccessful();

        return Storage::disk('local')->get('exports/matches-2026-09-30-13h15.md');
    }

    public function test_exports_the_eat_day_in_kickoff_order(): void
    {
        $this->match('Late', 'Match', '2026-09-30 18:45:00');
        $this->match('Early', 'Match', '2026-09-29 21:30:00');          // 00h30 EAT le 30 : inclus
        $this->match('Tomorrow', 'Eat', '2026-09-30 21:30:00');         // 00h30 EAT le 01/10 : exclu
        $this->match('No', 'Predictions', '2026-09-30 15:00:00', false); // exclu
        $this->match('Contaminated', 'Match', '2026-09-30 16:00:00', true, ['post_kickoff_data' => true]); // exclu

        $md = $this->export();

        $this->assertStringContainsString('# Matchs du 30/09/2026 — Export pour analyse externe', $md);
        $this->assertStringContainsString('Généré le : 30/09/2026 à 13h15 (EAT)', $md);
        $this->assertStringContainsString('Pipeline : 10:00 UTC (13:00 EAT)', $md);
        $this->assertStringContainsString('Nombre de matchs : 2', $md);
        $this->assertStringContainsString('Modèle : marché seul, run de référence #1', $md);
        $this->assertLessThan(strpos($md, '### Late vs Match'), strpos($md, '### Early vs Match'));
        $this->assertStringContainsString("Coup d'envoi : 30/09/2026 à 00h30 (EAT)", $md);
        $this->assertStringNotContainsString('Tomorrow', $md);
        $this->assertStringNotContainsString('Predictions', $md);
        $this->assertStringNotContainsString('Contaminated', $md);
        $this->assertStringContainsString('aucune recommandation', $md);
        $this->assertStringContainsString('ne bat pas la clôture Pinnacle', $md);
    }

    public function test_stored_values_are_reused_and_markets_split_by_nature(): void
    {
        $this->match('Como', 'Parma', '2026-09-30 16:00:00');

        $md = $this->export();

        $adjustment = strpos($md, "**Marchés d'ajustement**");
        $derived = strpos($md, '**Marchés dérivés**');
        $this->assertNotFalse($adjustment);
        $this->assertNotFalse($derived);
        $this->assertLessThan($derived, strpos($md, '| Under 2.5 | 65,6 % | 1,44 | 65,6 % | 0,0 |'));
        $this->assertGreaterThan($adjustment, strpos($md, '| X | 30,3 % | 3,20 | 29,6 % | +0,7 |'));
        $this->assertGreaterThan($derived, strpos($md, '| BTTS Oui | 40,2 % | 2,25 | 41,1 % | ' . "\u{2212}" . '0,9 |'));
        $this->assertStringContainsString('| BTTS Non | 59,8 % | cote absente | non calculable (cote absente) | non calculable |', $md);
    }

    public function test_missing_context_says_why(): void
    {
        $withContext = $this->match('Como', 'Parma', '2026-09-30 16:00:00');
        AdvancedData::create([
            'match_id' => $withContext->id,
            'context_data' => [
                'fatigue' => ['available' => false, 'reason' => 'Couverture insuffisante : filtre horaire'],
                'stakes' => ['available' => false, 'reason' => "Classement non collecté : standings refusé par l'offre gratuite API-Football"],
                'weather' => ['available' => true, 'description' => 'Conditions normales', 'temperature' => 18.4, 'wind_speed' => 12, 'rain' => false],
                'collected_at' => '2026-09-30T10:12:00+00:00',
            ],
            'sofascore_data' => ['injuries' => [
                'home' => [['player' => 'A. Un', 'reason' => 'Knee Injury'], ['player' => 'A. Un', 'reason' => 'Knee Injury']],
                'away' => [],
            ]],
        ]);
        $this->match('Lecce', 'Pisa', '2026-09-30 18:45:00');

        $md = $this->export();
        [$como, $lecce] = explode('### Lecce vs Pisa', $md);

        $this->assertStringContainsString('- Fatigue : non disponible : Couverture insuffisante : filtre horaire', $como);
        $this->assertStringContainsString('- Enjeux : non disponible (offre gratuite) : Classement non collecté', $como);
        $this->assertStringContainsString('- Météo : Conditions normales, 18 °C, vent 12 km/h, sans pluie', $como);
        $this->assertStringContainsString('- Blessures : Como : A. Un (Knee Injury) ; Parma : aucun absent signalé', $como);

        $this->assertStringContainsString('- Fatigue : non collecté (context:enrich non passé pour ce match)', $lecce);
        $this->assertStringContainsString('- Blessures : non collecté (données facultatives', $lecce);
    }

    public function test_empty_day_still_writes_a_file(): void
    {
        $md = $this->export();

        $this->assertStringContainsString('Nombre de matchs : 0', $md);
        $this->assertStringContainsString('Aucun match du jour avec des probabilités calculées', $md);
    }
}
