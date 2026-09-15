<?php

namespace App\Services\Probability;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\PredictionLogEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transforme la sortie de XGModelService en lignes `predictions` :
 * une ligne par (marché, issue) avec la probabilité du modèle, la cote du
 * bookmaker configuré, la probabilité implicite brute, la probabilité
 * "fair" (marge retirée) et l'écart modèle - fair.
 */
class PredictionService
{
    public function __construct(private XGModelService $xgModel)
    {
    }

    /**
     * Calcule et persiste les prédictions d'un match (remplace les précédentes).
     *
     * Refuse tout match commencé ou marqué post_kickoff_data : aucune donnée n'est
     * écrite après le coup d'envoi, et les prédictions d'avant-match ne sont jamais
     * écrasées. Garde unique pour tous les appelants (bouton Analyser, commande).
     *
     * Chaque calcul ajoute aussi ses lignes admissibles au journal des sélections
     * (prediction_log), dans la même transaction : un calcul affiché mais absent du
     * journal serait un trou silencieux. `predictions` est remplacée, le journal
     * jamais.
     *
     * @param string $trigger PredictionLogEntry::TRIGGER_PIPELINE (predictions:compute,
     *                        tous les matchs d'une date) ou TRIGGER_MANUAL (bouton)
     * @return Collection<Prediction>
     * @throws KickoffPassedException
     */
    public function computeAndStore(FootballMatch $match, string $trigger): Collection
    {
        if ($match->hasKickedOff() || $match->post_kickoff_data) {
            throw new KickoffPassedException(
                "Match #{$match->id} commencé ou contaminé : aucune prédiction écrite après le coup d'envoi"
            );
        }

        $rows = $this->compute($match);

        DB::transaction(function () use ($match, $rows, $trigger) {
            $match->predictions()->delete();
            foreach ($rows as $row) {
                $match->predictions()->create($row);
            }

            foreach (array_filter($rows, [PredictionLogEntry::class, 'admits']) as $row) {
                PredictionLogEntry::create([
                    'match_id' => $match->id,
                    'league_id' => $match->league_id,
                    'home_team' => $match->home_team,
                    'away_team' => $match->away_team,
                    'kickoff_at' => $match->match_date,
                    'trigger' => $trigger,
                    'market' => $row['market'],
                    'outcome' => $row['outcome'],
                    'model_probability' => $row['model_probability'],
                    'full_model_probability' => $row['full_model_probability'],
                    'full_model_signals' => $row['full_model_signals'],
                    'model_mode' => $row['model_mode'],
                    'lambda_home' => $row['lambda_home'],
                    'lambda_away' => $row['lambda_away'],
                    'rho' => $row['rho'],
                    'odds' => $row['odds'],
                    'bookmaker' => $row['bookmaker'],
                    'fair_probability' => $row['fair_probability'],
                    'odds_taken_at' => $row['odds_taken_at'],
                    'computed_at' => $row['computed_at'],
                ]);
            }
        });

        return $match->predictions()->orderBy('id')->get();
    }

    /**
     * Calcule les lignes sans les persister.
     */
    public function compute(FootballMatch $match): array
    {
        // Mode marché seul : la seule configuration mesurée par le backtest. Une
        // probabilité issue d'autres signaux, affichée à côté d'une calibration
        // mesurée sur ce seul signal, serait trompeuse. Lève une exception si les
        // cotes 1X2 manquent.
        $result = $this->xgModel->predict($match, marketOnly: true);
        $model = $this->probabilities($result['analysis']);

        // Configuration complète (marché + comparaison + blessures), stockée à part
        // pour une comparaison future sur matchs réels. N'entre jamais dans
        // model_probability ; son échec n'empêche pas le calcul affiché.
        $full = null;
        $fullSignals = null;
        try {
            $fullResult = $this->xgModel->predict($match);
            $full = $this->probabilities($fullResult['analysis']);
            $fullSignals = [
                'used' => array_values(array_filter(
                    ['market', 'comparison', 'injuries'],
                    fn (string $signal) => !empty($fullResult['signals'][$signal])
                )),
                'lambda_home' => $fullResult['lambdas']['home'],
                'lambda_away' => $fullResult['lambdas']['away'],
            ];
        } catch (\Exception $e) {
            Log::warning("Prédiction #{$match->id} : configuration complète non calculée", ['error' => $e->getMessage()]);
        }

        $computedAt = now();

        // Frontière de provenance des cotes :
        //  - odds_fetched_at renseigné → relevé API-Football d'un bookmaker unique,
        //    identifié par odds_bookmaker (depuis le commit c6f76e2 du 2026-09-13) ;
        //  - odds_fetched_at null → cotes héritées = maximum multi-bookmakers,
        //    étiquetées `legacy_max` pour que le backtest puisse les exclure.
        // Jamais la config The Odds API : c'est le bookmaker du CLV (Pinnacle).
        $oddsTakenAt = $match->odds_fetched_at;
        if ($oddsTakenAt !== null && empty($match->odds_bookmaker)) {
            throw new \RuntimeException("Match #{$match->id} : cotes relevées sans bookmaker identifié (odds_bookmaker vide)");
        }
        $bookmaker = $oddsTakenAt !== null ? $match->odds_bookmaker : 'legacy_max';

        // Cotes du bookmaker unique
        $odds = [
            Prediction::MARKET_WINNER => [
                '1' => $this->odd($match->odds_home),
                'X' => $this->odd($match->odds_draw),
                '2' => $this->odd($match->odds_away),
            ],
            Prediction::MARKET_DOUBLE_CHANCE => [
                '1X' => $this->odd($match->odds_dc_1x),
                'X2' => $this->odd($match->odds_dc_x2),
                '12' => $this->odd($match->odds_dc_12),
            ],
            Prediction::MARKET_OVER_UNDER_25 => [
                'Over' => $this->odd($match->odds_over_2_5),
                'Under' => $this->odd($match->odds_under_2_5),
            ],
            Prediction::MARKET_BTTS => [
                'Yes' => $this->odd($match->odds_btts_yes),
                'No' => $this->odd($match->odds_btts_no),
            ],
        ];

        // Probabilités fair : chaque ensemble complet normalisé à 1.
        // La double chance se dérive du 1X2 fair, pas de ses propres cotes.
        $fair = [
            Prediction::MARKET_WINNER => FairProbabilities::fromOdds($odds[Prediction::MARKET_WINNER]),
            Prediction::MARKET_OVER_UNDER_25 => FairProbabilities::fromOdds($odds[Prediction::MARKET_OVER_UNDER_25]),
            Prediction::MARKET_BTTS => FairProbabilities::fromOdds($odds[Prediction::MARKET_BTTS]),
        ];
        $fair[Prediction::MARKET_DOUBLE_CHANCE] = FairProbabilities::doubleChance($fair[Prediction::MARKET_WINNER]);

        $rows = [];
        foreach ($model as $market => $outcomes) {
            foreach ($outcomes as $outcome => $probability) {
                $odd = $odds[$market][$outcome];
                $fairProb = $fair[$market][$outcome] ?? null;

                $rows[] = [
                    'market' => $market,
                    'outcome' => $outcome,
                    'model_probability' => round($probability, 5),
                    'model_mode' => 'market_only',
                    'lambda_home' => $result['lambdas']['home'],
                    'lambda_away' => $result['lambdas']['away'],
                    'rho' => $result['rho'],
                    'full_model_probability' => isset($full[$market][$outcome]) ? round($full[$market][$outcome], 5) : null,
                    'full_model_signals' => $fullSignals,
                    'odds' => $odd,
                    'implied_probability' => $odd !== null ? round(1 / $odd, 5) : null,
                    'fair_probability' => $fairProb !== null ? round($fairProb, 5) : null,
                    'edge' => $fairProb !== null ? round($probability - $fairProb, 5) : null,
                    'bookmaker' => $odd !== null ? $bookmaker : null,
                    'odds_taken_at' => $odd !== null ? $oddsTakenAt : null,
                    'computed_at' => $computedAt,
                ];
            }
        }

        return $rows;
    }

    /**
     * Probabilités par marché et issue, en décimal 0..1, depuis la sortie Poisson.
     */
    private function probabilities(array $analysis): array
    {
        return [
            Prediction::MARKET_WINNER => [
                '1' => $analysis['1x2']['home'] / 100,
                'X' => $analysis['1x2']['draw'] / 100,
                '2' => $analysis['1x2']['away'] / 100,
            ],
            Prediction::MARKET_DOUBLE_CHANCE => [
                '1X' => $analysis['doubleChance']['1X'] / 100,
                'X2' => $analysis['doubleChance']['X2'] / 100,
                '12' => $analysis['doubleChance']['12'] / 100,
            ],
            Prediction::MARKET_OVER_UNDER_25 => [
                'Over' => $analysis['overUnder25']['over'] / 100,
                'Under' => $analysis['overUnder25']['under'] / 100,
            ],
            Prediction::MARKET_BTTS => [
                'Yes' => $analysis['btts']['yes'] / 100,
                'No' => $analysis['btts']['no'] / 100,
            ],
        ];
    }

    private function odd(mixed $value): ?float
    {
        $odd = (float) $value;

        return $odd > 1.0 ? $odd : null;
    }
}
