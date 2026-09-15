<?php

namespace App\Console\Commands;

use App\Services\DataPipeline\DatabaseBackup;
use App\Services\DataPipeline\PipelineLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sauvegarde de la base (mysqldump compressé, rotation sur sept jours), première
 * étape de pipeline:daily. Échec visible si le vidage ne se termine pas, ou si la
 * rotation est refusée parce que la base a rétréci brutalement.
 *
 * --verify : restaure la dernière sauvegarde dans une base temporaire, compte les
 * lignes des tables principales, puis supprime la base temporaire. Une sauvegarde
 * jamais restaurée n'est pas une sauvegarde.
 */
class DbBackup extends Command
{
    protected $signature = 'db:backup
                            {--verify : Restaurer la dernière sauvegarde dans une base temporaire et compter ses lignes, sans nouvelle sauvegarde}';

    protected $description = 'Sauvegarder la base (mysqldump compressé dans storage/app/private/backups, rotation sur sept jours) ou vérifier la dernière sauvegarde';

    public function handle(DatabaseBackup $backup): int
    {
        return $this->option('verify') ? $this->verify($backup) : $this->backup($backup);
    }

    private function backup(DatabaseBackup $backup): int
    {
        try {
            $result = $backup->run();
        } catch (\RuntimeException $e) {
            PipelineLog::caught('db:backup', $e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sauvegarde écrite : {$result['file']} ({$this->kb($result['bytes'])})");
        if ($result['deleted']) {
            $this->line('Rotation : ' . implode(', ', $result['deleted']));
        }
        Log::channel('pipeline')->info('db:backup terminé', $result);

        if ($result['kept_shrunk']) {
            $this->error('Rotation refusée : la nouvelle sauvegarde fait moins de '
                . (config('pipeline.backup.shrink_ratio') * 100) . ' % de sauvegardes plus anciennes, gardées :');
            foreach ($result['kept_shrunk'] as $kept) {
                $this->line("  {$kept['file']} ({$this->kb($kept['bytes'])})");
            }
            $this->line('La base a rétréci brutalement : anomalie à examiner avant toute suppression manuelle.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function verify(DatabaseBackup $backup): int
    {
        try {
            $result = $backup->verify();
        } catch (\Throwable $e) {
            PipelineLog::caught('db:backup --verify', $e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restauration de {$result['file']} dans {$result['database']}");
        $this->table(['Table', 'Lignes restaurées', 'Lignes en base réelle'], array_map(
            fn (string $table, array $counts) => [$table, $counts['backup'] ?? 'ABSENTE', $counts['live'] ?? 'absente'],
            array_keys($result['tables']),
            $result['tables'],
        ));
        $this->line('Base réelle comptée maintenant : elle a pu évoluer depuis la sauvegarde.');

        $failed = $result['missing'] !== [] || !$result['dropped'];
        if ($result['missing']) {
            $this->error('Tables absentes de la sauvegarde : ' . implode(', ', $result['missing']));
        }
        $this->{$result['dropped'] ? 'info' : 'error'}($result['dropped']
            ? "Base temporaire {$result['database']} supprimée"
            : "Base temporaire {$result['database']} NON supprimée : à supprimer à la main");

        Log::channel('pipeline')->log($failed ? 'error' : 'info', 'db:backup --verify terminé', $result);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
    }
}
