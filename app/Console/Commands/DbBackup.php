<?php

namespace App\Console\Commands;

use App\Services\DataPipeline\DatabaseBackup;
use App\Services\DataPipeline\PipelineLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sauvegarde de la base (mysqldump compressé, rotation sur sept jours), première
 * étape de pipeline:daily. Échec visible si le vidage ne se termine pas.
 */
class DbBackup extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Sauvegarder la base (mysqldump compressé dans storage/app/private/backups, rotation sur sept jours)';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $result = $backup->run();
        } catch (\RuntimeException $e) {
            PipelineLog::caught('db:backup', $e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $size = number_format($result['bytes'] / 1024, 0, ',', ' ');
        $this->info("Sauvegarde écrite : {$result['file']} ({$size} Ko)");
        if ($result['deleted']) {
            $this->line('Rotation : ' . implode(', ', $result['deleted']));
        }
        Log::channel('pipeline')->info('db:backup terminé', $result);

        return self::SUCCESS;
    }
}
