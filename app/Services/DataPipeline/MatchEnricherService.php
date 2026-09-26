<?php

namespace App\Services\DataPipeline;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use Illuminate\Support\Facades\Log;

class MatchEnricherService
{
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CRÉATION / MISE À JOUR MATCH
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Créer ou mettre à jour un match à partir des données API-Football.
     * Retourne le FootballMatch créé/mis à jour.
     */
    public function upsertFromApiFootball(array $fixtureData): ?FootballMatch
    {
        $fixture = $fixtureData['fixture'] ?? null;
        $teams = $fixtureData['teams'] ?? null;
        $league = $fixtureData['league'] ?? null;

        if (!$fixture || !$teams || !$league) {
            Log::warning('MatchEnricher: données fixture incomplètes', [
                'has_fixture' => (bool) $fixture,
                'has_teams' => (bool) $teams,
                'has_league' => (bool) $league,
            ]);
            return null;
        }

        $fixtureId = $fixture['id'];

        // Champs fixes mis à jour à chaque passage (pas les cotes : enrichWithApiFootballOdds)
        $payload = [
            'home_team' => $teams['home']['name'],
            'home_team_id' => $teams['home']['id'],
            'away_team' => $teams['away']['name'],
            'away_team_id' => $teams['away']['id'],
            'match_date' => $fixture['date'],
            'competition' => $league['name'],
            'league_id' => $league['id'],
            'season' => $league['season'] ?? null,
            'data_source' => 'api',
            'score_home' => $fixture['status']['short'] === 'FT' ? ($fixtureData['goals']['home'] ?? null) : null,
            'score_away' => $fixture['status']['short'] === 'FT' ? ($fixtureData['goals']['away'] ?? null) : null,
            'completed' => in_array($fixture['status']['short'], ['FT', 'AET', 'PEN']),
            'api_status' => $fixture['status']['short'],
        ];

        $existing = FootballMatch::where('api_football_id', $fixtureId)->first();
        if ($existing && self::isRescheduled($existing, $fixtureData)) {
            $payload += $this->resetForNewKickoff($existing, $fixture['date']);
        }
        if (!$existing) {
            // Premier import : cotes à null jusqu'au relevé API-Football
            $payload['odds_home'] = null;
            $payload['odds_draw'] = null;
            $payload['odds_away'] = null;
            // Créé après son coup d'envoi (backfill, rattrapage J-1) : contaminé
            // définitivement, exclu de toute mesure. Jamais retiré ensuite.
            $payload['post_kickoff_data'] = !self::isBeforeKickoff($fixtureData);
        }

        $match = FootballMatch::updateOrCreate(
            ['api_football_id' => $fixtureId],
            $payload
        );

        Log::info("MatchEnricher: match #{$fixtureId} upserted", [
            'match_id' => $match->id,
            'match' => "{$teams['home']['name']} vs {$teams['away']['name']}",
            'date' => $fixture['date'],
            'created' => $match->wasRecentlyCreated,
        ]);

        return $match;
    }

    /**
     * Match pas encore joué dont le coup d'envoi a changé (report : Levante-Athletic
     * du 16/09/2026 reprogrammé au 21/10). Un match terminé dont l'heure est
     * corrigée après coup n'est pas concerné.
     */
    public static function isRescheduled(FootballMatch $existing, array $fixtureData): bool
    {
        $status = $fixtureData['fixture']['status']['short'] ?? null;
        $date = $fixtureData['fixture']['date'] ?? null;

        return in_array($status, ['NS', 'TBD', 'PST'], true)
            && $date !== null
            && $existing->match_date !== null
            && !$existing->match_date->equalTo(\Carbon\Carbon::parse($date));
    }

    /**
     * Colonnes liées à l'ancien coup d'envoi, remises à nul : cotes Bet365, cote de
     * prédiction et clôture du CLV, liaison The Odds API. Sinon le nouveau coup
     * d'envoi hériterait de cotes relevées des semaines plus tôt : un marché absent
     * du nouveau relevé garderait l'ancienne cote, et le CLV de /market comparerait
     * la cote de prédiction de l'ancienne date à la clôture de la nouvelle.
     * Les relevés odds_movements restent ; closingSnapshot ne retient que ceux de la
     * fenêtre précédant le nouveau coup d'envoi. Les lignes du journal gardent leurs
     * propres cotes et sont déclarées non clôturables par log:settle.
     */
    private function resetForNewKickoff(FootballMatch $existing, string $newDate): array
    {
        $columns = array_values(array_filter(
            $existing->getFillable(),
            fn (string $column) => str_starts_with($column, 'odds_') || $column === 'predicted_at',
        ));

        Log::channel('pipeline')->warning("MatchEnricher: match #{$existing->id} reprogrammé, cotes de l'ancien coup d'envoi remises à nul", [
            'match' => $existing->full_name,
            'from' => $existing->match_date->toIso8601String(),
            'to' => $newDate,
            'previous' => array_filter($existing->only($columns), fn ($value) => $value !== null),
        ]);

        return array_fill_keys($columns, null);
    }

    /**
     * Fixture pas encore commencée : statut « à venir » et coup d'envoi futur.
     * Un match reporté, en cours ou terminé ne reçoit plus que son score.
     */
    public static function isBeforeKickoff(array $fixtureData): bool
    {
        $status = $fixtureData['fixture']['status']['short'] ?? null;
        $date = $fixtureData['fixture']['date'] ?? null;

        return in_array($status, ['NS', 'TBD'], true)
            && $date !== null
            && \Carbon\Carbon::parse($date)->isFuture();
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ENRICHISSEMENT COTES — API-Football /odds (source principale)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Enrichir un match avec les cotes API-Football : 1 call → tous marchés/lignes.
     * C'est la source PRINCIPALE des cotes (remplace The Odds API pour les snapshots de cotes).
     *
     * @param FootballMatch $match
     * @param array $parsedOdds Sortie de ApiFootballService::getFixtureOdds()
     */
    public function enrichWithApiFootballOdds(FootballMatch $match, array $parsedOdds): FootballMatch
    {
        $columns = [
            // 1X2
            'odds_home', 'odds_draw', 'odds_away',
            // Over/Under — 4 lignes principales
            'odds_over_1_5', 'odds_under_1_5',
            'odds_over_2_5', 'odds_under_2_5',
            'odds_over_3_5', 'odds_under_3_5',
            'odds_over_4_5', 'odds_under_4_5',
            // BTTS
            'odds_btts_yes', 'odds_btts_no',
            // Double Chance
            'odds_dc_1x', 'odds_dc_12', 'odds_dc_x2',
        ];

        $updates = [];
        foreach ($columns as $col) {
            if (($parsedOdds[$col] ?? null) !== null) {
                $updates[$col] = $parsedOdds[$col];
            }
        }

        if (empty($updates)) {
            Log::debug("MatchEnricher: aucune cote API-Football pour match #{$match->id}");
            return $match;
        }

        // Heure du relevé : frontière legacy_max / bookmaker identifié (cf. PredictionService)
        $updates['odds_fetched_at'] = now();
        // Bookmaker unique dont viennent toutes ces cotes, repris dans predictions.bookmaker
        $updates['odds_bookmaker'] = $parsedOdds['bookmaker'];

        $match->update($updates);

        Log::info("MatchEnricher: cotes API-Football enrichies pour match #{$match->id}", [
            'match' => $match->full_name,
            'columns_filled' => count($updates),
            'over_2_5' => $updates['odds_over_2_5'] ?? '-',
            'under_2_5' => $updates['odds_under_2_5'] ?? '-',
            'over_3_5' => $updates['odds_over_3_5'] ?? '-',
            'under_3_5' => $updates['odds_under_3_5'] ?? '-',
            'btts_no' => $updates['odds_btts_no'] ?? '-',
            'dc_1x' => $updates['odds_dc_1x'] ?? '-',
            'bookmaker' => $parsedOdds['bookmaker'],
        ]);

        return $match;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LIAISON The Odds API — CLV tracker uniquement
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Lier un match à son événement The Odds API (odds_api_event_id) pour le CLV tracker.
     *
     * Depuis la simplification (2026-09-13), The Odds API n'écrit plus JAMAIS dans
     * les colonnes odds_* de `matches` : la seule source de cotes est API-Football /odds
     * (enrichWithApiFootballOdds). Les cotes The Odds API vont dans `odds_movements`.
     */
    public function linkOddsApiEvent(FootballMatch $match, array $normalizedOdds): FootballMatch
    {
        if (empty($normalizedOdds['event_id'])) {
            return $match;
        }

        $match->update(['odds_api_event_id' => $normalizedOdds['event_id']]);

        Log::info("MatchEnricher (TheOddsApi/CLV): event lié pour match #{$match->id}", [
            'match' => $match->full_name,
            'event_id' => $normalizedOdds['event_id'],
        ]);

        return $match;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // DONNÉES FACULTATIVES — Blessures et prédictions API-Football
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Enrichir un match avec les données facultatives d'API-Football (blessures,
     * prédictions). Stockées pour un test futur, n'entrent dans aucun calcul.
     *
     * @param array $optionalData Sortie de ApiFootballService::getOptionalMatchData()
     */
    public function enrichWithAdvancedData(FootballMatch $match, array $optionalData): FootballMatch
    {
        $advancedData = [];

        // Blessures → format sofascore_data.injuries. Une liste vide venue d'un appel
        // réussi est stockée : zéro blessé n'est pas une donnée absente.
        if (is_array($optionalData['injuries'] ?? null)) {
            $advancedData['sofascore_data'] = [
                'injuries' => $this->normalizeInjuries($optionalData['injuries'], $match->home_team_id),
            ];
        }

        // Prédictions API-Football → données de contexte (bloc comparison)
        if (!empty($optionalData['predictions'])) {
            $advancedData['context_data'] = $this->normalizeContextFromPredictions($optionalData['predictions']);
        }

        if (!empty($advancedData)) {
            AdvancedData::updateOrCreate(
                ['match_id' => $match->id],
                $advancedData
            );

            $match->update(['enriched_at' => now()]);

            Log::info("MatchEnricher: données facultatives enrichies pour match #{$match->id}", [
                'match' => $match->full_name,
                'dimensions' => array_keys($advancedData),
            ]);
        }

        return $match;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // NORMALISATEURS — API-Football → format Layer 2
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Normaliser les données de blessures.
     */
    private function normalizeInjuries(array $injuries, ?int $homeTeamId): array
    {
        $home = [];
        $away = [];

        foreach ($injuries as $injury) {
            $entry = [
                'player' => $injury['player']['name'] ?? 'Inconnu',
                'type' => $injury['player']['type'] ?? 'Missing',
                'reason' => $injury['player']['reason'] ?? null,
            ];

            if (($injury['team']['id'] ?? null) === $homeTeamId) {
                $home[] = $entry;
            } else {
                $away[] = $entry;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /**
     * Normaliser le contexte depuis les prédictions API-Football.
     */
    private function normalizeContextFromPredictions(array $predictions): array
    {
        $advice = $predictions['predictions']['advice'] ?? '';
        $comparison = $predictions['comparison'] ?? [];

        // Plus d'« importance » déduite du texte du conseil (« draw » → high) :
        // une valeur inventée. L'importance vient des seuls enjeux (ContextEnricherService).
        return [
            'apiFootballAdvice' => $advice,
            'comparison' => $comparison,
        ];
    }

}
