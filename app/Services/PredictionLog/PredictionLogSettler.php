<?php

namespace App\Services\PredictionLog;

use App\Models\FootballMatch;
use App\Models\OddsMovement;
use App\Models\Prediction;
use App\Models\PredictionLogEntry;
use App\Services\DataPipeline\PipelineLog;
use App\Services\Market\CLVTrackerService;
use App\Services\Probability\FairProbabilities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clôture du journal des sélections pour les matchs d'une date.
 *
 * - Issue : déterminée par le score final. Sans score, la ligne reste en attente,
 *   jamais résolue par défaut. Le score n'est écrit que pour un statut FT : un
 *   match AET/PEN reste en attente (problème connu, docs/roadmap.md).
 * - Le match doit être celui du calcul (équipes et coup d'envoi) : un match
 *   reprogrammé ou une base remise à zéro laisse la ligne en attente.
 * - closing_edge = cote Bet365 du calcul × probabilité équitable Pinnacle à la
 *   clôture − 1, seulement si une clôture fiable existe (CLVTrackerService::
 *   closingSnapshot) et que Pinnacle y cote le marché. Sinon il reste nul, jamais 0.
 *   Ce n'est pas le CLV de /market (docs/decisions.md).
 */
class PredictionLogSettler
{
    // Lignes en attente : une anomalie sur un match terminé est un échec
    public const PENDING_MATCH_MISSING = 'match_absent';
    public const PENDING_MATCH_CHANGED = 'match_changed';
    public const PENDING_NO_SCORE = 'no_score';
    public const PENDING_EXCEPTION = 'exception';
    // Match pas encore terminé (reporté, en cours) : avertissement seulement
    public const PENDING_NOT_COMPLETED = 'not_completed';

    public const FAILURE_REASONS = [
        self::PENDING_MATCH_MISSING,
        self::PENDING_MATCH_CHANGED,
        self::PENDING_NO_SCORE,
        self::PENDING_EXCEPTION,
    ];

    // closing_edge absent : jamais un échec
    public const EDGE_NO_CLOSING = 'no_closing_snapshot';
    public const EDGE_MARKET_NOT_QUOTED = 'market_not_quoted';

    public function __construct(private CLVTrackerService $clv)
    {
    }

    /**
     * @return array{date: string, entries: int, settled: int, pending: array<string, int>, pending_matches: list<array>, closing_edge_missing: array<string, int>, failures: int, older_pending: array<string, int>}
     */
    public function settle(string $date): array
    {
        $entries = PredictionLogEntry::pending()
            ->whereDate('kickoff_at', $date)
            ->orderBy('id')
            ->get();

        $report = [
            'date' => $date,
            'entries' => $entries->count(),
            'settled' => 0,
            'pending' => [],
            'pending_matches' => [],
            'closing_edge_missing' => [],
            'failures' => 0,
            'older_pending' => [],
        ];

        foreach ($entries->groupBy('match_id') as $matchId => $matchEntries) {
            try {
                $this->settleMatch(FootballMatch::find($matchId), $matchEntries, $report);
            } catch (\Exception $e) {
                PipelineLog::caught('log:settle', $e, ['match_id' => $matchId]);
                $this->pending($report, $matchEntries, self::PENDING_EXCEPTION, (int) $matchId, $e->getMessage());
            }
        }

        $report['older_pending'] = PredictionLogEntry::pending()
            ->whereDate('kickoff_at', '<', $date)
            ->pluck('kickoff_at')
            ->countBy(fn (Carbon $kickoff) => $kickoff->format('Y-m-d'))
            ->sortKeys()
            ->all();

        return $report;
    }

    private function settleMatch(?FootballMatch $match, Collection $entries, array &$report): void
    {
        if ($match === null) {
            $this->pending($report, $entries, self::PENDING_MATCH_MISSING, (int) $entries->first()->match_id);

            return;
        }

        // Match différent de celui du calcul : lignes laissées en attente
        $changed = $entries->reject(fn (PredictionLogEntry $e) => $e->home_team === $match->home_team
            && $e->away_team === $match->away_team
            && $match->match_date !== null
            && $e->kickoff_at->equalTo($match->match_date));
        if ($changed->isNotEmpty()) {
            $this->pending($report, $changed, self::PENDING_MATCH_CHANGED, $match->id, sprintf(
                'journal : %s vs %s au %s ; match : %s au %s',
                $changed->first()->home_team, $changed->first()->away_team, $changed->first()->kickoff_at?->format('Y-m-d H:i'),
                $match->full_name, $match->match_date?->format('Y-m-d H:i'),
            ));
            $entries = $entries->diff($changed);
            if ($entries->isEmpty()) {
                return;
            }
        }

        if (!$match->completed) {
            $this->pending($report, $entries, self::PENDING_NOT_COMPLETED, $match->id);

            return;
        }

        if ($match->score_home === null || $match->score_away === null) {
            $this->pending($report, $entries, self::PENDING_NO_SCORE, $match->id, 'match terminé sans score (AET/PEN ?)');

            return;
        }

        $home = (int) $match->score_home;
        $away = (int) $match->score_away;
        $snapshot = $this->clv->closingSnapshot($match);
        $closing = $snapshot ? $this->closingProbabilities($snapshot) : null;
        $settledAt = now();

        // Compteurs reportés seulement après validation de la transaction
        $edgeMissing = [];
        DB::transaction(function () use ($entries, $home, $away, $snapshot, $closing, $settledAt, &$edgeMissing) {
            $edgeMissing = [];
            foreach ($entries as $entry) {
                $fields = [
                    'score_home' => $home,
                    'score_away' => $away,
                    'outcome_occurred' => self::occurred($entry->market, (string) $entry->outcome, $home, $away),
                    'settled_at' => $settledAt,
                ];

                $closingFair = $closing['fair'][$entry->market][$entry->outcome] ?? null;
                if ($closingFair !== null) {
                    $fields += [
                        'closing_bookmaker' => $snapshot->bookmaker,
                        'closing_odds' => $closing['odds'][$entry->market][$entry->outcome] ?? null,
                        'closing_fair_probability' => round($closingFair, 5),
                        'closing_quoted_at' => $snapshot->quoted_at,
                        'closing_odds_movement_id' => $snapshot->id,
                        'closing_edge' => round($entry->odds * $closingFair - 1, 5),
                    ];
                } else {
                    $reason = $snapshot === null ? self::EDGE_NO_CLOSING : self::EDGE_MARKET_NOT_QUOTED;
                    $edgeMissing[$reason] = ($edgeMissing[$reason] ?? 0) + 1;
                }

                $entry->update($fields);
            }
        });

        $report['settled'] += $entries->count();
        foreach ($edgeMissing as $reason => $count) {
            $report['closing_edge_missing'][$reason] = ($report['closing_edge_missing'][$reason] ?? 0) + $count;
        }
    }

    /**
     * Cotes brutes et probabilités équitables Pinnacle du relevé de clôture, par
     * marché du journal. Double chance dérivée du 1X2 ; BTTS non relevé.
     */
    private function closingProbabilities(OddsMovement $snapshot): array
    {
        $odds = [
            Prediction::MARKET_WINNER => ['1' => $snapshot->odds_home, 'X' => $snapshot->odds_draw, '2' => $snapshot->odds_away],
            Prediction::MARKET_OVER_UNDER_25 => ['Over' => $snapshot->odds_over_2_5, 'Under' => $snapshot->odds_under_2_5],
        ];
        $odds = array_map(fn (array $set) => array_map(fn ($odd) => $odd === null ? null : (float) $odd, $set), $odds);

        $fair1x2 = FairProbabilities::fromOdds($odds[Prediction::MARKET_WINNER]);

        return [
            'odds' => $odds,
            'fair' => [
                Prediction::MARKET_WINNER => $fair1x2,
                Prediction::MARKET_DOUBLE_CHANCE => FairProbabilities::doubleChance($fair1x2),
                Prediction::MARKET_OVER_UNDER_25 => FairProbabilities::fromOdds($odds[Prediction::MARKET_OVER_UNDER_25]),
            ],
        ];
    }

    /**
     * L'issue s'est-elle réalisée, sur le score final (temps réglementaire).
     */
    public static function occurred(string $market, string $outcome, int $home, int $away): bool
    {
        return match ("{$market}|{$outcome}") {
            'winner|1' => $home > $away,
            'winner|X' => $home === $away,
            'winner|2' => $home < $away,
            'doubleChance|1X' => $home >= $away,
            'doubleChance|X2' => $home <= $away,
            'doubleChance|12' => $home !== $away,
            'overUnder25|Over' => $home + $away >= 3,
            'overUnder25|Under' => $home + $away <= 2,
            'btts|Yes' => $home > 0 && $away > 0,
            'btts|No' => $home === 0 || $away === 0,
            default => throw new \LogicException("Journal : issue inconnue {$market} {$outcome}"),
        };
    }

    private function pending(array &$report, Collection $entries, string $reason, int $matchId, ?string $detail = null): void
    {
        $report['pending'][$reason] = ($report['pending'][$reason] ?? 0) + $entries->count();
        $report['pending_matches'][] = array_filter([
            'match_id' => $matchId,
            'match' => $entries->first()->home_team . ' vs ' . $entries->first()->away_team,
            'lines' => $entries->count(),
            'reason' => $reason,
            'detail' => $detail,
        ], fn ($v) => $v !== null);

        if (in_array($reason, self::FAILURE_REASONS, true)) {
            $report['failures'] += $entries->count();
        }
    }
}
