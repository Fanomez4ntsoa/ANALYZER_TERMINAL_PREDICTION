<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Passage quotidien du pipeline, lancé par le planificateur (routes/console.php).
 *
 * Quatre étapes dans l'ordre, chacune journalisée dans le canal `pipeline`
 * (storage/logs/pipeline-AAAA-MM-JJ.log) : début, durée, code de sortie, sortie.
 * Si l'import échoue, les étapes suivantes ne tournent pas : elles
 * travailleraient sur des données absentes ou périmées.
 */
class PipelineDaily extends Command
{
    protected $signature = 'pipeline:daily
                            {date? : Date cible (YYYY-MM-DD, défaut : aujourd\'hui)}';

    protected $description = 'Passage quotidien : import, contexte, probabilités, snapshot de cotes';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $log = Log::channel('pipeline');

        $steps = [
            ['pipeline:run-sync', ['date' => $date], true],
            ['context:enrich', ['--date' => $date], false],
            ['predictions:compute', ['date' => $date], false],
            ['market:track', ['action' => 'snapshot', '--date' => $date], false],
        ];

        $log->info("pipeline:daily démarré pour le {$date}");
        $failures = 0;

        foreach ($steps as [$command, $arguments, $blocking]) {
            $started = microtime(true);
            $log->info("Étape {$command} : début", ['arguments' => $arguments]);

            $output = new BufferedOutput();
            try {
                $exitCode = Artisan::call($command, $arguments, $output);
                $error = null;
            } catch (\Throwable $e) {
                $exitCode = self::FAILURE;
                $error = get_class($e) . ' — ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')';
            }

            $context = [
                'duration_s' => round(microtime(true) - $started, 1),
                'exit_code' => $exitCode,
                'output' => mb_substr(trim($output->fetch()), -4000),
            ];
            if ($error !== null) {
                $context['exception'] = $error;
            }

            if ($exitCode === self::SUCCESS) {
                $log->info("Étape {$command} : terminée", $context);
                $this->info("{$command} : OK ({$context['duration_s']} s)");
                continue;
            }

            $failures++;
            $log->error("Étape {$command} : échec", $context);
            $this->error("{$command} : échec (code {$exitCode})" . ($error ? " — {$error}" : ''));

            if ($blocking) {
                $log->error("pipeline:daily interrompu : {$command} a échoué, étapes suivantes non lancées");
                return self::FAILURE;
            }
        }

        $log->log($failures > 0 ? 'warning' : 'info', "pipeline:daily terminé pour le {$date}", ['failures' => $failures]);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
