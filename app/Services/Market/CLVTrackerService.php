<?php

namespace App\Services\Market;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Services\Api\OddsApiService;
use App\Services\DataPipeline\PipelineLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * CLV (Closing Line Value) Tracker.
 *
 * Bookmaker de référence : odds-api.clv_bookmaker (Pinnacle). Le CLV mesure le
 * mouvement de la cote Pinnacle entre la prédiction et la clôture, PAS celui du
 * prix Bet365 qui sert aux prédictions (voir docs/decisions.md, 14/09/2026).
 * Cote de prédiction et cote de clôture viennent toujours du même bookmaker.
 *
 * CLV = (cote_prise / cote_clôture - 1) × 100
 *
 * Si CLV > 0 → tu as pris une meilleure cote que la clôture = tu bats le marché.
 * Si CLV < 0 → le marché a évolué contre toi.
 *
 * Un CLV moyen positif sur le long terme est le meilleur indicateur
 * de rentabilité en paris sportifs (Pinnacle, 2019).
 */
class CLVTrackerService
{
    private OddsApiService $oddsApi;

    public function __construct(OddsApiService $oddsApi)
    {
        $this->oddsApi = $oddsApi;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SNAPSHOT — Capturer les cotes à un instant T
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public const MISSING_QUOTA = 'quota_exhausted';
    public const MISSING_NO_RESPONSE = 'no_response';
    public const MISSING_EVENT_NOT_FOUND = 'event_not_found';
    public const MISSING_BOOKMAKER_ABSENT = 'bookmaker_absent';
    public const MISSING_STALE_QUOTE = 'stale_quote';

    /**
     * Relevé de prédiction : cotes du bookmaker du CLV pour les matchs à venir
     * d'une date, dans les championnats du CLV, liés à un événement The Odds API.
     *
     * Toujours sans cache (2 crédits par championnat) : une réponse recyclée sous un
     * horodatage récent n'est pas une observation. Un match attendu sans relevé est
     * compté dans `missing` avec sa raison, et journalisé dans le canal pipeline.
     *
     * @return array{expected: int, stored: int, missing: list<array>}
     */
    public function snapshotOdds(string $date): array
    {
        // Matchs à venir seulement ; championnats du CLV seulement, un relevé sans
        // clôture possible dépense des crédits pour rien.
        $matches = FootballMatch::where('data_source', 'api')
            ->measurable()
            ->whereDate('match_date', $date)
            ->where('completed', false)
            ->where('match_date', '>', now())
            ->whereIn('league_id', config('pipeline.closing.leagues'))
            ->whereNotNull('odds_api_event_id')
            ->orderBy('match_date')
            ->get();

        if ($matches->isEmpty()) {
            Log::channel('pipeline')->info("CLV : aucun match à relever pour le {$date}");

            return ['expected' => 0, 'stored' => 0, 'missing' => []];
        }

        $report = $this->snapshotLeagues($matches);

        Log::channel('pipeline')->log($report['missing'] ? 'warning' : 'info', "CLV : {$report['stored']} relevé(s) de prédiction sur {$report['expected']} attendu(s) pour le {$date}", [
            'bookmaker' => config('odds-api.clv_bookmaker'),
            'missing' => $report['missing'],
        ]);

        return $report;
    }

    /**
     * Un appel sans cache par championnat, puis un relevé par match si le bookmaker
     * du CLV est présent avec une cote récente.
     *
     * @return array{expected: int, stored: int, missing: list<array>}
     */
    private function snapshotLeagues(Collection $matches): array
    {
        $stored = 0;
        $missing = [];

        foreach ($matches->groupBy('league_id') as $leagueId => $leagueMatches) {
            if ($this->oddsApi->isQuotaExhausted()) {
                foreach ($leagueMatches as $match) {
                    $missing[] = $this->missing($match, self::MISSING_QUOTA);
                }
                continue;
            }

            $events = $this->oddsApi->getFreshOddsByLeagueId((int) $leagueId);
            if (!is_array($events)) {
                foreach ($leagueMatches as $match) {
                    $missing[] = $this->missing($match, self::MISSING_NO_RESPONSE, ['league_id' => (int) $leagueId]);
                }
                continue;
            }

            foreach ($leagueMatches as $match) {
                try {
                    $problem = $this->snapshotMatch($match, $events);
                    if ($problem === null) {
                        $stored++;
                    } else {
                        $missing[] = $problem;
                    }
                } catch (\Exception $e) {
                    PipelineLog::caught('CLV relevé', $e, ['match_id' => $match->id]);
                    $missing[] = $this->missing($match, 'exception', ['error' => $e->getMessage()]);
                }
            }
        }

        return ['expected' => $matches->count(), 'stored' => $stored, 'missing' => $missing];
    }

    /**
     * Relève un match dans une réponse fraîche. Retourne null si le relevé est
     * enregistré, sinon la raison de l'absence.
     */
    private function snapshotMatch(FootballMatch $match, array $events): ?array
    {
        // Identifiant d'événement lié d'abord, noms d'équipe en repli
        $odds = $match->odds_api_event_id
            ? $this->oddsApi->findEventOddsById($events, $match->odds_api_event_id)
            : null;
        $odds ??= $this->oddsApi->findEventOdds($events, $match->home_team, $match->away_team, $match->match_date->format('Y-m-d'));

        if ($odds === null) {
            return $this->missing($match, self::MISSING_EVENT_NOT_FOUND, [
                'event_id' => $match->odds_api_event_id,
                'events_in_response' => count($events),
            ]);
        }

        if ($odds['odds_home'] === null) {
            return $this->missing($match, self::MISSING_BOOKMAKER_ABSENT, [
                'event_id' => $odds['event_id'],
                'bookmakers_present' => $odds['bookmaker_count'],
            ]);
        }

        $maxAge = (int) config('pipeline.odds_snapshot.max_quote_age_minutes');
        $quotedAt = $odds['quoted_at'] ? Carbon::parse($odds['quoted_at']) : null;
        $age = $quotedAt ? round($quotedAt->diffInSeconds(now(), false) / 60, 1) : null;

        if ($age === null || $age > $maxAge) {
            return $this->missing($match, self::MISSING_STALE_QUOTE, [
                'event_id' => $odds['event_id'],
                'quoted_at' => $odds['quoted_at'],
                'quote_age_minutes' => $age,
                'max_quote_age_minutes' => $maxAge,
            ]);
        }

        $this->storeSnapshot($match, $odds);

        return null;
    }

    private function missing(FootballMatch $match, string $reason, array $context = []): array
    {
        return ['match_id' => $match->id, 'match' => $match->full_name, 'reason' => $reason] + $context;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CLÔTURE — Snapshot juste avant le coup d'envoi, puis marquage
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Relevé de clôture : matchs dont le coup d'envoi tombe dans les
     * `pipeline.closing.window_minutes` prochaines minutes, mêmes règles que le
     * relevé de prédiction (sans cache, cote récente, raison de chaque absence).
     *
     * Limité aux championnats `pipeline.closing.leagues` et arrêté quand le quota
     * restant passe sous `pipeline.closing.quota_reserve`.
     *
     * @return array{expected: int, stored: int, missing: list<array>}
     */
    public function snapshotClosingOdds(): array
    {
        $window = (int) config('pipeline.closing.window_minutes');
        $reserve = (int) config('pipeline.closing.quota_reserve');

        $candidates = FootballMatch::where('data_source', 'api')
            ->measurable()
            ->where('completed', false)
            ->whereIn('league_id', config('pipeline.closing.leagues'))
            ->whereNotNull('odds_api_event_id')
            ->whereNotNull('odds_at_pred_home')
            ->where('match_date', '>', now())
            ->where('match_date', '<=', now()->addMinutes($window))
            ->orderBy('match_date')
            ->get();

        // Déjà un relevé fiable dans la fenêtre de clôture : pas de second appel
        $matches = $candidates->reject(fn (FootballMatch $m) => OddsMovement::where('match_id', $m->id)
            ->where('bookmaker', config('odds-api.clv_bookmaker'))
            ->reliable()
            ->where('snapshot_at', '>=', $m->match_date->copy()->subMinutes($window))
            ->exists());

        if ($matches->isEmpty()) {
            return ['expected' => 0, 'stored' => 0, 'missing' => []];
        }

        $remaining = $this->oddsApi->getMonthlyUsage()['remaining'];
        if ($remaining !== null && $remaining < $reserve) {
            $report = [
                'expected' => $matches->count(),
                'stored' => 0,
                'missing' => $matches->map(fn ($m) => $this->missing($m, self::MISSING_QUOTA, ['remaining' => $remaining, 'reserve' => $reserve]))->values()->all(),
            ];
        } else {
            $report = $this->snapshotLeagues($matches);
        }

        Log::channel('pipeline')->log($report['missing'] ? 'warning' : 'info', "CLV clôture : {$report['stored']} relevé(s) sur {$report['expected']} attendu(s)", [
            'bookmaker' => config('odds-api.clv_bookmaker'),
            'missing' => $report['missing'],
        ]);

        return $report;
    }

    /**
     * Cote de clôture = dernier snapshot pris avant le coup d'envoi, dans la
     * fenêtre de clôture. Sans snapshot dans cette fenêtre, la clôture reste vide :
     * aucun repli sur une cote plus ancienne ni sur une autre source (les cotes
     * odds_* de matches viennent d'API-Football, relevées des heures plus tôt).
     *
     * Seul un relevé fiable (sans cache, cote récente) peut servir de clôture.
     *
     * @return array{closed: int, missing: list<int>}
     */
    public function markClosingOdds(): array
    {
        $window = (int) config('pipeline.closing.window_minutes');

        // Plus aucun snapshot n'est pris après le coup d'envoi : au-delà d'un jour,
        // un match sans clôture n'en aura jamais.
        $matches = FootballMatch::where('data_source', 'api')
            ->measurable()
            ->whereNotNull('odds_at_pred_home')
            ->whereNull('odds_closing_home')
            ->where('match_date', '<=', now())
            ->where('match_date', '>=', now()->subDay())
            ->get();

        $count = 0;
        $missing = [];

        foreach ($matches as $match) {
            // La cote de prédiction vient du premier snapshot : la clôture doit venir
            // du même bookmaker, et ce bookmaker doit être celui du CLV.
            $reference = OddsMovement::where('match_id', $match->id)
                ->whereNotNull('odds_home')
                ->orderBy('snapshot_at')
                ->value('bookmaker');

            if ($reference !== config('odds-api.clv_bookmaker')) {
                if ($match->match_date->gte(now()->subMinutes($window))) {
                    Log::channel('pipeline')->warning("CLV : match #{$match->id} non clôturé, cote de prédiction d'un autre bookmaker", [
                        'prediction_bookmaker' => $reference,
                        'clv_bookmaker' => config('odds-api.clv_bookmaker'),
                    ]);
                }
                continue;
            }

            $closing = $this->closingSnapshot($match);

            if (!$closing) {
                // Signalé une fois, au premier passage après le coup d'envoi
                if ($match->match_date->gte(now()->subMinutes($window))) {
                    $missing[] = $match->id;
                }
                continue;
            }

            $match->update([
                'odds_closing_home' => $closing->odds_home,
                'odds_closing_draw' => $closing->odds_draw,
                'odds_closing_away' => $closing->odds_away,
                'odds_closing_over' => $closing->odds_over_2_5,
            ]);

            $count++;
        }

        if ($missing) {
            Log::channel('pipeline')->warning('CLV : clôture manquante, aucun snapshot dans la fenêtre avant le coup d\'envoi', [
                'window_minutes' => $window,
                'matches' => $missing,
            ]);
        }

        Log::channel('pipeline')->info("CLV : {$count} match(s) clôturé(s)");

        return ['closed' => $count, 'missing' => $missing];
    }

    /**
     * Relevé de clôture d'un match : dernier relevé fiable du bookmaker du CLV pris
     * dans les `pipeline.closing.window_minutes` avant le coup d'envoi. Règle unique
     * pour le CLV de /market et pour le journal des sélections : aucun repli sur un
     * relevé plus ancien ni sur une autre source.
     */
    public function closingSnapshot(FootballMatch $match): ?OddsMovement
    {
        $window = (int) config('pipeline.closing.window_minutes');

        return OddsMovement::where('match_id', $match->id)
            ->where('bookmaker', config('odds-api.clv_bookmaker'))
            ->reliable()
            ->whereNotNull('odds_home')
            ->where('snapshot_at', '<=', $match->match_date)
            ->where('snapshot_at', '>=', $match->match_date->copy()->subMinutes($window))
            ->orderBy('snapshot_at', 'desc')
            ->first();
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CALCUL CLV
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer le CLV pour un match.
     *
     * CLV = (odds_at_prediction / odds_closing - 1) × 100
     *
     * @return array|null CLV par marché, ou null si pas de données
     */
    public function calculateCLV(FootballMatch $match): ?array
    {
        if (!$match->odds_at_pred_home || !$match->odds_closing_home) {
            return null;
        }

        $clv = [];

        $clv['home'] = $this->clvFormula((float) $match->odds_at_pred_home, (float) $match->odds_closing_home);
        $clv['draw'] = $this->clvFormula((float) $match->odds_at_pred_draw, (float) $match->odds_closing_draw);
        $clv['away'] = $this->clvFormula((float) $match->odds_at_pred_away, (float) $match->odds_closing_away);

        if ($match->odds_at_pred_over && $match->odds_closing_over) {
            $clv['over'] = $this->clvFormula((float) $match->odds_at_pred_over, (float) $match->odds_closing_over);
        }

        // CLV moyen (pondéré par marché pertinent)
        $values = array_filter($clv, fn($v) => $v !== null);
        $clv['average'] = count($values) > 0 ? round(array_sum($values) / count($values), 2) : 0;

        return $clv;
    }

    /**
     * Calculer le CLV summary pour tous les matchs avec données CLV.
     */
    public function getSummary(): array
    {
        // Matchs contaminés exclus (post_kickoff_data)
        $matches = FootballMatch::measurable()
            ->whereNotNull('odds_at_pred_home')
            ->whereNotNull('odds_closing_home')
            ->get();

        if ($matches->isEmpty()) {
            return [
                'total_matches' => 0,
                'clv_positive' => 0,
                'clv_negative' => 0,
                'clv_positive_pct' => 0,
                'avg_clv' => 0,
                'avg_clv_home' => 0,
                'avg_clv_draw' => 0,
                'avg_clv_away' => 0,
                'details' => [],
            ];
        }

        $details = [];
        $totalCLV = 0;
        $clvPositive = 0;
        $allHome = [];
        $allDraw = [];
        $allAway = [];

        foreach ($matches as $match) {
            $clv = $this->calculateCLV($match);
            if (!$clv) continue;

            $details[] = [
                'match' => $match->full_name,
                'date' => $match->match_date->format('Y-m-d'),
                'competition' => $match->competition,
                'clv_home' => $clv['home'],
                'clv_draw' => $clv['draw'],
                'clv_away' => $clv['away'],
                'clv_over' => $clv['over'] ?? null,
                'clv_avg' => $clv['average'],
            ];

            $totalCLV += $clv['average'];
            if ($clv['average'] > 0) $clvPositive++;

            $allHome[] = $clv['home'];
            $allDraw[] = $clv['draw'];
            $allAway[] = $clv['away'];
        }

        $count = count($details);

        return [
            'total_matches' => $count,
            'clv_positive' => $clvPositive,
            'clv_negative' => $count - $clvPositive,
            'clv_positive_pct' => $count > 0 ? round(($clvPositive / $count) * 100, 1) : 0,
            'avg_clv' => $count > 0 ? round($totalCLV / $count, 2) : 0,
            'avg_clv_home' => count($allHome) > 0 ? round(array_sum($allHome) / count($allHome), 2) : 0,
            'avg_clv_draw' => count($allDraw) > 0 ? round(array_sum($allDraw) / count($allDraw), 2) : 0,
            'avg_clv_away' => count($allAway) > 0 ? round(array_sum($allAway) / count($allAway), 2) : 0,
            'details' => $details,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MOUVEMENTS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Enregistrer un relevé fiable (appelé seulement après les contrôles de
     * snapshotMatch). Le premier relevé d'un match fixe la cote de prédiction.
     */
    private function storeSnapshot(FootballMatch $match, array $odds): OddsMovement
    {
        // Variation calculée contre le dernier relevé fiable seulement : jamais contre
        // un relevé recyclé depuis le cache
        $previousSnapshot = OddsMovement::where('match_id', $match->id)
            ->where('bookmaker', $odds['bookmaker'])
            ->reliable()
            ->whereNotNull('odds_home')
            ->orderBy('snapshot_at', 'desc')
            ->first();

        $movements = $this->calculateMovements($odds, $previousSnapshot);

        $snapshot = OddsMovement::create([
            'match_id' => $match->id,
            'bookmaker' => $odds['bookmaker'],
            'odds_home' => $odds['odds_home'],
            'odds_draw' => $odds['odds_draw'],
            'odds_away' => $odds['odds_away'],
            'odds_over_2_5' => $odds['odds_over_2_5'],
            'odds_under_2_5' => $odds['odds_under_2_5'],
            'bookmaker_count' => $odds['bookmaker_count'] ?? 0,
            'move_home_pct' => $movements['home'],
            'move_draw_pct' => $movements['draw'],
            'move_away_pct' => $movements['away'],
            'move_over_pct' => $movements['over'],
            'snapshot_at' => now(),
            'quoted_at' => Carbon::parse($odds['quoted_at']),
            'reliable' => true,
        ]);

        if (!$match->odds_at_pred_home) {
            $match->update([
                'odds_at_pred_home' => $odds['odds_home'],
                'odds_at_pred_draw' => $odds['odds_draw'],
                'odds_at_pred_away' => $odds['odds_away'],
                'odds_at_pred_over' => $odds['odds_over_2_5'],
                'predicted_at' => now(),
            ]);
        }

        return $snapshot;
    }

    /**
     * Variations brutes des cotes par rapport au relevé précédent du même
     * bookmaker, en pourcentage signé. Matière première du test de mouvement de
     * ligne (étape 4) : aucune interprétation, aucun score, aucun seuil.
     *
     * L'ancien score « sharp money » (0 à 100, pondérations écrites à la main,
     * alerte à 60) est supprimé le 14/09/2026 : un verdict jamais mesuré. Les
     * colonnes odds_movements.sharp_alert et sharp_score restent en base, orphelines.
     */
    private function calculateMovements(array $currentOdds, ?OddsMovement $previous): array
    {
        if (!$previous) {
            return ['home' => null, 'draw' => null, 'away' => null, 'over' => null];
        }

        $moveOver = null;
        if ($previous->odds_over_2_5 && $currentOdds['odds_over_2_5']) {
            $moveOver = $this->percentChange((float) $previous->odds_over_2_5, (float) $currentOdds['odds_over_2_5']);
        }

        $round = fn (?float $v) => $v !== null ? round($v, 2) : null;

        return [
            'home' => $round($this->percentChange((float) $previous->odds_home, (float) $currentOdds['odds_home'])),
            'draw' => $round($this->percentChange((float) $previous->odds_draw, (float) $currentOdds['odds_draw'])),
            'away' => $round($this->percentChange((float) $previous->odds_away, (float) $currentOdds['odds_away'])),
            'over' => $round($moveOver),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // UTILITAIRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function clvFormula(float $oddsPrediction, float $oddsClosing): ?float
    {
        if ($oddsClosing <= 0 || $oddsPrediction <= 0) {
            return null;
        }

        return round(($oddsPrediction / $oddsClosing - 1) * 100, 2);
    }

    private function percentChange(float $old, float $new): ?float
    {
        if ($old <= 0) return null;
        return (($new - $old) / $old) * 100;
    }
}
