<?php

namespace App\Console\Commands;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use App\Services\Context\ContextEnricherService;
use Illuminate\Console\Command;

class EnrichContext extends Command
{
    protected $signature = 'context:enrich
                            {--date= : Date cible (YYYY-MM-DD)}
                            {--match-id= : Match spécifique}
                            {--show : Afficher le contexte enrichi sans sauvegarder}';

    protected $description = 'Enrichir le contexte situationnel des matchs (fatigue, enjeu, meteo, pression)';

    public function handle(ContextEnricherService $enricher): int
    {
        if ($matchId = $this->option('match-id')) {
            return $this->enrichSingle($enricher, (int) $matchId);
        }

        return $this->enrichByDate($enricher);
    }

    private function enrichSingle(ContextEnricherService $enricher, int $matchId): int
    {
        $match = FootballMatch::with('advancedData')->find($matchId);
        if (!$match) {
            $this->error("Match #{$matchId} introuvable.");
            return self::FAILURE;
        }

        $this->info("{$match->full_name} ({$match->competition})");

        if ($match->hasKickedOff() && !$this->option('show')) {
            $this->error("Coup d'envoi passé : contexte non enregistré (donnée post coup d'envoi). Utilisez --show pour afficher sans enregistrer.");
            return self::FAILURE;
        }

        $enriched = $enricher->enrich($match);

        if ($this->option('show')) {
            $this->displayContext($match, $enriched);
            return self::SUCCESS;
        }

        $this->saveContext($match, $enriched);
        $this->displayContext($match, $enriched);

        return self::SUCCESS;
    }

    private function enrichByDate(ContextEnricherService $enricher): int
    {
        $date = $this->option('date') ?? now()->format('Y-m-d');

        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $date)
            ->with('advancedData')
            ->get();

        // Coup d'envoi passé : aucun contexte enregistré (donnée post coup d'envoi)
        $kickedOff = $matches->filter(fn (FootballMatch $m) => $m->hasKickedOff());
        if ($kickedOff->isNotEmpty() && !$this->option('show')) {
            $this->warn("{$kickedOff->count()} match(s) déjà commencé(s), ignoré(s).");
            $matches = $matches->reject(fn (FootballMatch $m) => $m->hasKickedOff());
        }

        if ($matches->isEmpty()) {
            $this->warn("Aucun match à enrichir pour le {$date}.");
            return self::SUCCESS;
        }

        $this->info("Enrichissement contexte — {$matches->count()} matchs ({$date})");
        $this->newLine();

        $rows = [];
        foreach ($matches as $match) {
            $enriched = $enricher->enrich($match);

            if (!$this->option('show')) {
                $this->saveContext($match, $enriched);
            }

            $fatigue = $enriched['fatigue'] ?? [];
            $stakes = $enriched['stakes'] ?? [];
            $weather = $enriched['weather'] ?? [];
            $pressure = $enriched['coachPressure'] ?? [];

            $rows[] = [
                substr($match->home_team, 0, 12) . ' v ' . substr($match->away_team, 0, 12),
                $enriched['importance'],
                ($fatigue['available'] ?? false) ? $fatigue['home']['fatigue_score'] . '/' . $fatigue['away']['fatigue_score'] : '-',
                ($stakes['available'] ?? false) ? substr($stakes['home']['stake'] ?? '-', 0, 8) . '/' . substr($stakes['away']['stake'] ?? '-', 0, 8) : '-',
                ($weather['condition'] ?? 'unknown') !== 'unknown' ? "{$weather['temperature']}C {$weather['condition']}" : '-',
                ($pressure['available'] ?? false) ? ($pressure['home']['score'] ?? 0) . '/' . ($pressure['away']['score'] ?? 0) : '-',
            ];
        }

        $this->table(
            ['Match', 'Enjeu', 'Fatigue H/A', 'Stakes H/A', 'Meteo', 'Pression H/A'],
            $rows
        );

        $saved = $this->option('show') ? 'affiché' : 'sauvegardé';
        $this->info("{$matches->count()} matchs {$saved}.");

        return self::SUCCESS;
    }

    private function saveContext(FootballMatch $match, array $enriched): void
    {
        AdvancedData::updateOrCreate(
            ['match_id' => $match->id],
            ['context_data' => $enriched]
        );
    }

    private function displayContext(FootballMatch $match, array $enriched): void
    {
        $this->newLine();

        // Fatigue
        $fatigue = $enriched['fatigue'] ?? [];
        if ($fatigue['available'] ?? false) {
            $this->info("Fatigue calendaire :");
            $this->table(['', 'Matchs 7j', 'Matchs 14j', 'Matchs 21j', 'Europe', 'Score'], [
                [
                    $match->home_team,
                    $fatigue['home']['matches_7d'],
                    $fatigue['home']['matches_14d'],
                    $fatigue['home']['matches_21d'],
                    $fatigue['home']['european'] ? 'Oui' : 'Non',
                    $fatigue['home']['fatigue_score'] . '/100',
                ],
                [
                    $match->away_team,
                    $fatigue['away']['matches_7d'],
                    $fatigue['away']['matches_14d'],
                    $fatigue['away']['matches_21d'],
                    $fatigue['away']['european'] ? 'Oui' : 'Non',
                    $fatigue['away']['fatigue_score'] . '/100',
                ],
            ]);
        }

        // Stakes
        $stakes = $enriched['stakes'] ?? [];
        if ($stakes['available'] ?? false) {
            $this->info("Enjeux :");
            $this->table(['Equipe', 'Rang', 'Enjeu', 'Motivation'], [
                [$match->home_team, $stakes['home']['rank'] ?? '-', $stakes['home']['description'] ?? '-', $stakes['home']['motivation'] ?? '-'],
                [$match->away_team, $stakes['away']['rank'] ?? '-', $stakes['away']['description'] ?? '-', $stakes['away']['motivation'] ?? '-'],
            ]);
        }

        // Météo
        $weather = $enriched['weather'] ?? [];
        if (($weather['condition'] ?? 'unknown') !== 'unknown') {
            $this->info("Meteo : {$weather['temperature']}C, {$weather['condition']}, vent {$weather['wind_speed']} km/h");
            if (($weather['impact'] ?? 'none') !== 'none') {
                $this->warn("  Impact: {$weather['description']}");
                $this->line("  Over modifier: {$weather['over_modifier']}% | BTTS modifier: {$weather['btts_modifier']}%");
            }
        }

        // Pression
        $pressure = $enriched['coachPressure'] ?? [];
        if ($pressure['available'] ?? false) {
            $this->info("Pression :");
            $this->line("  {$match->home_team}: {$pressure['home']['description']}");
            $this->line("  {$match->away_team}: {$pressure['away']['description']}");
        }

        $this->newLine();
        $this->info("Importance resolue : {$enriched['importance']}");
    }
}
