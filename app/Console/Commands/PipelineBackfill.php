<?php

namespace App\Console\Commands;

use App\Jobs\FetchMatchDataJob;
use App\Models\FootballMatch;
use App\Services\DataPipeline\PipelineLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PipelineBackfill extends Command
{
    protected $signature = 'pipeline:backfill
                            {--from= : Date de debut (YYYY-MM-DD)}
                            {--to= : Date de fin (YYYY-MM-DD)}
                            {--delay=2 : Delai en secondes entre chaque jour}
                            {--no-odds : Ne pas recuperer les cotes (economise le quota Odds API)}';

    protected $description = 'Importer les matchs historiques en masse sur une periode donnee';

    public function handle(): int
    {
        if (\App\Support\CollectionGuard::refuses($this)) {
            return self::FAILURE;
        }

        $from = Carbon::parse($this->option('from') ?? now()->subMonth()->format('Y-m-d'));
        $to = Carbon::parse($this->option('to') ?? now()->subDay()->format('Y-m-d'));
        $delay = (int) $this->option('delay');
        $skipOdds = $this->option('no-odds');

        if ($from->gt($to)) {
            $this->error('La date de debut doit etre avant la date de fin.');
            return self::FAILURE;
        }

        $totalDays = $from->diffInDays($to) + 1;
        $this->info("Backfill: {$from->format('Y-m-d')} -> {$to->format('Y-m-d')} ({$totalDays} jours)");
        $this->newLine();

        if (!$this->confirm("Lancer l'import de {$totalDays} jours ?")) {
            $this->info('Annule.');
            return self::SUCCESS;
        }

        $matchesBefore = FootballMatch::count();
        $daysDone = 0;
        $daysWithMatches = 0;
        $errors = [];

        $bar = $this->output->createProgressBar($totalDays);
        $bar->setFormat(" %current%/%max% [%bar%] %message%");
        $bar->setMessage('Demarrage...');
        $bar->start();

        $current = $from->copy();

        while ($current->lte($to)) {
            $date = $current->format('Y-m-d');
            $countBefore = FootballMatch::where('data_source', 'api')->whereDate('match_date', $date)->count();

            try {
                FetchMatchDataJob::dispatchSync($date, null, !$skipOdds, true);

                $countAfter = FootballMatch::where('data_source', 'api')->whereDate('match_date', $date)->count();
                $imported = $countAfter - $countBefore;

                if ($imported > 0) {
                    $daysWithMatches++;
                }

                $bar->setMessage("{$date} : {$countAfter} matchs" . ($imported > 0 ? " (+{$imported})" : ''));

            } catch (\Exception $e) {
                PipelineLog::caught('pipeline:backfill', $e, ['date' => $date]);
                $errors[] = "{$date}: {$e->getMessage()}";
                $bar->setMessage("{$date} : ERREUR");
            }

            $daysDone++;
            $bar->advance();
            $current->addDay();

            if ($current->lte($to) && $delay > 0) {
                sleep($delay);
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Resume
        $matchesAfter = FootballMatch::count();
        $totalImported = $matchesAfter - $matchesBefore;
        $completedCount = FootballMatch::where('completed', true)->count();

        $this->table(['Metrique', 'Valeur'], [
            ['Jours traites', $daysDone],
            ['Jours avec matchs', $daysWithMatches],
            ['Matchs importes', $totalImported],
            ['Total matchs en DB', $matchesAfter],
            ['Matchs termines (FT)', $completedCount],
            ['Erreurs', count($errors)],
        ]);

        if (!empty($errors)) {
            $this->newLine();
            $this->warn('Erreurs :');
            foreach (array_slice($errors, 0, 10) as $err) {
                $this->line("  {$err}");
            }
            if (count($errors) > 10) {
                $this->line("  ... et " . (count($errors) - 10) . " autres");
            }
        }

        return self::SUCCESS;
    }
}
