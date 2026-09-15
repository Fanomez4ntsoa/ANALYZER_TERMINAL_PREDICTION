<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\PredictionLogEntry;
use App\Services\DataPipeline\PipelineLog;
use App\Services\Probability\PredictionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PredictionsCompute extends Command
{
    protected $signature = 'predictions:compute
                            {date? : Date cible (YYYY-MM-DD, défaut : aujourd\'hui)}';

    protected $description = 'Calculer les probabilités des matchs à venir d\'une date (même calcul que le bouton Analyser)';

    public function handle(PredictionService $predictions): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        $matches = FootballMatch::where('data_source', 'api')
            ->measurable()
            ->whereDate('match_date', $date)
            ->with('advancedData')
            ->orderBy('match_date')
            ->get();

        // Coup d'envoi passé : aucune prédiction calculée après coup
        $upcoming = $matches->reject(fn (FootballMatch $m) => $m->hasKickedOff());
        $withOdds = $upcoming->filter(fn (FootballMatch $m) => (float) $m->odds_home > 0 && (float) $m->odds_draw > 0 && (float) $m->odds_away > 0);

        $computed = 0;
        $failed = 0;
        $logged = 0;

        foreach ($withOdds as $match) {
            try {
                // Déclencheur pipeline : tous les matchs de la date, indépendamment de ce
                // que l'utilisateur consulte. log:report ne lit que le premier de ces calculs.
                $rows = $predictions->computeAndStore($match, PredictionLogEntry::TRIGGER_PIPELINE);
                $matchLogged = $rows->filter(fn ($row) => PredictionLogEntry::admits($row->toArray()))->count();
                $computed++;
                $logged += $matchLogged;
                $this->line("  {$match->full_name} : {$rows->count()} lignes, {$matchLogged} journalisées");
            } catch (\Exception $e) {
                $failed++;
                PipelineLog::caught('predictions:compute', $e, ['match_id' => $match->id, 'match' => $match->full_name]);
                $this->warn("  {$match->full_name} : échec, " . $e->getMessage());
            }
        }

        $summary = [
            'date' => $date,
            'matches' => $matches->count(),
            'kicked_off' => $matches->count() - $upcoming->count(),
            'without_1x2_odds' => $upcoming->count() - $withOdds->count(),
            'computed' => $computed,
            'failed' => $failed,
            'logged_lines' => $logged,
        ];

        Log::channel('pipeline')->log($failed > 0 ? 'warning' : 'info', 'predictions:compute terminé', $summary);

        $this->table(['Métrique', 'Valeur'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
