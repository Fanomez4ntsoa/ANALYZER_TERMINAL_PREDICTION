<?php

namespace App\Services\DataPipeline;

use Illuminate\Support\Facades\Process;

/**
 * Sauvegarde de la base par mysqldump, compressée, avec rotation.
 *
 * Le projet n'avait aucune sauvegarde quand la base a été vidée le 15/09/2026
 * (docs/decisions.md). Règles :
 *  - un fichier horodaté par passage (`<base>_AAAA-MM-JJ_HHMMSS.sql.gz`), jamais
 *    écrasé : une relance le même jour ne remplace pas une sauvegarde saine ;
 *  - le mot de passe passe par un fichier d'options temporaire (0600), jamais par
 *    la ligne de commande ;
 *  - un vidage non terminé (code non nul ou sans « Dump completed ») est refusé et
 *    supprimé ;
 *  - la rotation n'a lieu qu'après une sauvegarde réussie et garde les sauvegardes
 *    des `keep_days` derniers jours distincts.
 */
class DatabaseBackup
{
    /**
     * @return array{file: string, bytes: int, deleted: list<string>}
     * @throws \RuntimeException
     */
    public function run(): array
    {
        $connectionName = config('pipeline.backup.connection');
        $connection = config("database.connections.{$connectionName}");
        if (!is_array($connection) || !in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException("Sauvegarde : connexion « {$connectionName} » absente ou non MySQL/MariaDB");
        }

        $dir = config('pipeline.backup.path');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Sauvegarde : dossier {$dir} impossible à créer");
        }

        $database = $connection['database'];
        $base = $dir . '/' . $database . '_' . now()->format('Y-m-d_His');
        $sqlPath = "{$base}.sql";
        $gzPath = "{$base}.sql.gz";
        if (file_exists($gzPath)) {
            throw new \RuntimeException("Sauvegarde : {$gzPath} existe déjà, jamais écrasée");
        }

        $optionsFile = $this->writeOptionsFile($connection);
        try {
            $result = Process::timeout((int) config('pipeline.backup.timeout_seconds'))->run([
                config('pipeline.backup.dump_binary'),
                "--defaults-extra-file={$optionsFile}",
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                "--result-file={$sqlPath}",
                $database,
            ]);
        } finally {
            @unlink($optionsFile);
        }

        if (!$result->successful() || !$this->dumpCompleted($sqlPath)) {
            @unlink($sqlPath);
            throw new \RuntimeException('Sauvegarde : vidage non terminé (code ' . $result->exitCode() . ') ' . trim(mb_substr($result->errorOutput(), 0, 500)));
        }

        $this->compress($sqlPath, $gzPath);

        return [
            'file' => $gzPath,
            'bytes' => filesize($gzPath),
            'deleted' => $this->rotate($dir, $database),
        ];
    }

    private function writeOptionsFile(array $connection): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbbackup');
        chmod($path, 0600);

        $quote = fn ($value) => '"' . addcslashes((string) $value, "\\\"") . '"';
        $lines = ['[client]', 'user=' . $quote($connection['username'] ?? ''), 'password=' . $quote($connection['password'] ?? '')];
        if (!empty($connection['unix_socket'])) {
            $lines[] = 'socket=' . $quote($connection['unix_socket']);
        } else {
            $lines[] = 'host=' . $quote($connection['host'] ?? '127.0.0.1');
            $lines[] = 'port=' . (int) ($connection['port'] ?? 3306);
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /** mysqldump termine un vidage complet par « -- Dump completed on … ». */
    private function dumpCompleted(string $sqlPath): bool
    {
        if (!is_file($sqlPath) || filesize($sqlPath) === 0) {
            return false;
        }
        $handle = fopen($sqlPath, 'rb');
        fseek($handle, max(0, filesize($sqlPath) - 200));
        $tail = stream_get_contents($handle);
        fclose($handle);

        return str_contains($tail, '-- Dump completed');
    }

    private function compress(string $sqlPath, string $gzPath): void
    {
        $in = fopen($sqlPath, 'rb');
        $out = gzopen($gzPath, 'wb6');
        if ($in === false || $out === false) {
            throw new \RuntimeException("Sauvegarde : compression de {$sqlPath} impossible");
        }
        while (!feof($in)) {
            gzwrite($out, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        chmod($gzPath, 0600);
        unlink($sqlPath);
    }

    /**
     * Garde les sauvegardes des `keep_days` derniers jours distincts ; ne touche
     * qu'aux fichiers de cette base au format attendu.
     *
     * @return list<string> fichiers supprimés
     */
    private function rotate(string $dir, string $database): array
    {
        $pattern = '/^' . preg_quote($database, '/') . '_(\d{4}-\d{2}-\d{2})_\d{6}\.sql\.gz$/';
        $byDay = [];
        foreach (scandir($dir) as $file) {
            if (preg_match($pattern, $file, $m)) {
                $byDay[$m[1]][] = $file;
            }
        }
        krsort($byDay);

        $deleted = [];
        foreach (array_slice($byDay, max(1, (int) config('pipeline.backup.keep_days')), null, true) as $files) {
            foreach ($files as $file) {
                unlink("{$dir}/{$file}");
                $deleted[] = $file;
            }
        }

        return $deleted;
    }
}
