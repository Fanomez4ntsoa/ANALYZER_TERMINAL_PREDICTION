<?php

namespace App\Services\Market;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Services\Api\OddsApiService;
use Illuminate\Support\Facades\Log;

/**
 * CLV (Closing Line Value) Tracker.
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

    /**
     * Prendre un snapshot des cotes pour tous les matchs à venir d'une date.
     * Utilise les cotes déjà en cache si possible (0 crédit).
     * Sinon, 1 appel par ligue (max 5 crédits/run = ~2-3 ligues × 2 marchés).
     *
     * @return int Nombre de snapshots créés
     */
    public function snapshotOdds(string $date): int
    {
        // Seulement les matchs à venir (pas les live ni les terminés)
        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $date)
            ->where('completed', false)
            ->where('match_date', '>', now()) // Exclut les matchs déjà commencés (live)
            ->whereNotNull('odds_api_event_id')
            ->get();

        if ($matches->isEmpty()) {
            Log::info("CLV: aucun match à venir pour le {$date}");
            return 0;
        }

        $count = 0;
        $leaguesFetched = [];

        foreach ($matches as $match) {
            $leagueId = $match->league_id;

            // Récupérer les cotes de la ligue (1 appel par ligue, en cache 2h)
            if (!isset($leaguesFetched[$leagueId])) {
                if ($this->oddsApi->isQuotaExhausted()) {
                    Log::warning("CLV: quota épuisé, arrêt des snapshots");
                    break;
                }
                $leaguesFetched[$leagueId] = true;
            }

            // Trouver les cotes de ce match via le matching existant
            $odds = $this->oddsApi->findOddsForMatch(
                $match->home_team,
                $match->away_team,
                $match->match_date->format('Y-m-d'),
                $leagueId
            );

            if (!$odds) {
                continue;
            }

            // Récupérer le snapshot précédent pour calculer le mouvement
            $previousSnapshot = OddsMovement::where('match_id', $match->id)
                ->orderBy('snapshot_at', 'desc')
                ->first();

            // Calculer les mouvements
            $movements = $this->calculateMovements($odds, $previousSnapshot);

            // Créer le snapshot
            OddsMovement::create([
                'match_id' => $match->id,
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
                'sharp_alert' => $movements['sharp_alert'],
                'sharp_score' => $movements['sharp_score'],
                'snapshot_at' => now(),
            ]);

            // Premier snapshot = cote au moment de la prédiction
            if (!$match->odds_at_pred_home) {
                $match->update([
                    'odds_at_pred_home' => $odds['odds_home'],
                    'odds_at_pred_draw' => $odds['odds_draw'],
                    'odds_at_pred_away' => $odds['odds_away'],
                    'odds_at_pred_over' => $odds['odds_over_2_5'],
                    'predicted_at' => now(),
                ]);
            }

            $count++;
        }

        Log::info("CLV: {$count} snapshots créés pour le {$date}", [
            'leagues_fetched' => count($leaguesFetched),
        ]);

        return $count;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CLÔTURE — Marquer les cotes de clôture
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Marquer le dernier snapshot comme cote de clôture pour les matchs
     * qui ont déjà commencé ou sont terminés.
     *
     * @return int Nombre de matchs clôturés
     */
    public function markClosingOdds(): int
    {
        $matches = FootballMatch::where('data_source', 'api')
            ->whereNotNull('odds_at_pred_home')
            ->whereNull('odds_closing_home')
            ->where('match_date', '<=', now())
            ->get();

        $count = 0;

        foreach ($matches as $match) {
            $lastSnapshot = OddsMovement::where('match_id', $match->id)
                ->orderBy('snapshot_at', 'desc')
                ->first();

            if (!$lastSnapshot) {
                // Pas de snapshot → utiliser les cotes actuelles comme clôture
                $match->update([
                    'odds_closing_home' => $match->odds_home,
                    'odds_closing_draw' => $match->odds_draw,
                    'odds_closing_away' => $match->odds_away,
                    'odds_closing_over' => $match->odds_over_2_5,
                ]);
            } else {
                $match->update([
                    'odds_closing_home' => $lastSnapshot->odds_home,
                    'odds_closing_draw' => $lastSnapshot->odds_draw,
                    'odds_closing_away' => $lastSnapshot->odds_away,
                    'odds_closing_over' => $lastSnapshot->odds_over_2_5,
                ]);
            }

            $count++;
        }

        Log::info("CLV: {$count} matchs clôturés");
        return $count;
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
        $matches = FootballMatch::whereNotNull('odds_at_pred_home')
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
     * Calculer les mouvements de cotes par rapport au snapshot précédent.
     */
    private function calculateMovements(array $currentOdds, ?OddsMovement $previous): array
    {
        if (!$previous) {
            return [
                'home' => null, 'draw' => null, 'away' => null, 'over' => null,
                'sharp_alert' => false, 'sharp_score' => 0,
            ];
        }

        $moveHome = $this->percentChange((float) $previous->odds_home, (float) $currentOdds['odds_home']);
        $moveDraw = $this->percentChange((float) $previous->odds_draw, (float) $currentOdds['odds_draw']);
        $moveAway = $this->percentChange((float) $previous->odds_away, (float) $currentOdds['odds_away']);
        $moveOver = null;

        if ($previous->odds_over_2_5 && $currentOdds['odds_over_2_5']) {
            $moveOver = $this->percentChange((float) $previous->odds_over_2_5, (float) $currentOdds['odds_over_2_5']);
        }

        // Détection sharp money
        $sharpScore = $this->detectSharpScore($moveHome, $moveDraw, $moveAway, $moveOver, $previous);
        $sharpAlert = $sharpScore >= 60;

        return [
            'home' => $moveHome !== null ? round($moveHome, 2) : null,
            'draw' => $moveDraw !== null ? round($moveDraw, 2) : null,
            'away' => $moveAway !== null ? round($moveAway, 2) : null,
            'over' => $moveOver !== null ? round($moveOver, 2) : null,
            'sharp_alert' => $sharpAlert,
            'sharp_score' => $sharpScore,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SHARP MONEY DETECTION
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Score sharp money (0-100).
     *
     * Signaux :
     * - Mouvement > 5% en < 4h sur une issue = 30 pts
     * - Mouvement asymétrique (une issue baisse, les autres montent) = 25 pts
     * - Mouvement > 8% = 20 pts supplémentaires
     * - Mouvement sur O/U concordant = 15 pts
     * - Beaucoup de bookmakers alignés = 10 pts
     */
    private function detectSharpScore(?float $moveHome, ?float $moveDraw, ?float $moveAway, ?float $moveOver, OddsMovement $previous): int
    {
        $score = 0;

        $absHome = abs($moveHome ?? 0);
        $absDraw = abs($moveDraw ?? 0);
        $absAway = abs($moveAway ?? 0);
        $absOver = abs($moveOver ?? 0);
        $maxMove = max($absHome, $absDraw, $absAway);

        // Mouvement significatif (> 5%)
        if ($maxMove >= 5) {
            $score += 30;

            // Mouvement fort (> 8%)
            if ($maxMove >= 8) {
                $score += 20;
            }
        }

        // Mouvement asymétrique : une issue baisse fortement, les autres montent
        if ($moveHome !== null && $moveAway !== null) {
            $homeDown = $moveHome < -3;
            $awayDown = $moveAway < -3;

            if ($homeDown && $moveAway > 1) {
                $score += 25; // Sharp sur le domicile
            } elseif ($awayDown && $moveHome > 1) {
                $score += 25; // Sharp sur l'extérieur
            }
        }

        // Mouvement concordant O/U
        if ($absOver >= 4) {
            $score += 15;
        }

        // Beaucoup de bookmakers alignés (mouvement de marché, pas un seul book)
        if ($previous->bookmaker_count >= 15 && $maxMove >= 3) {
            $score += 10;
        }

        return min(100, $score);
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
