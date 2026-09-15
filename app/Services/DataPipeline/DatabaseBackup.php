<?php

namespace App\Services\DataPipeline;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *    des `keep_days` derniers jours distincts ;
 *  - une sauvegarde plus de deux fois plus grosse que la nouvelle n'est jamais
 *    supprimée (`shrink_ratio`) : une base qui rétrécit brutalement est une anomalie,
 *    pas une rotation. Sinon une semaine de sauvegardes d'une base vidée évincerait
 *    les bonnes, exactement le scénario du 15/09/2026 avec sept jours de délai.
 *
 * verify() restaure la dernière sauvegarde dans une base temporaire, compte les
 * lignes des tables principales, puis supprime cette base.
 */
class DatabaseBackup
{
    /**
     * @return array{file: string, bytes: int, deleted: list<string>, kept_shrunk: list<array>}
     * @throws \RuntimeException
     */
    public function run(): array
    {
        $connection = $this->connection();

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

        return ['file' => $gzPath, 'bytes' => filesize($gzPath)] + $this->rotate($dir, $database, $gzPath);
    }

    /**
     * Restaure la dernière sauvegarde dans une base temporaire, compte les lignes des
     * tables `verify_tables` (sauvegarde et base réelle), puis supprime la base
     * temporaire, même en cas d'échec. Aucune table de la base réelle n'est écrite.
     *
     * @return array{file: string, database: string, tables: array<string, array{backup: ?int, live: ?int}>, missing: list<string>, dropped: bool}
     * @throws \RuntimeException
     */
    public function verify(): array
    {
        $connectionName = config('pipeline.backup.connection');
        $connection = $this->connection();
        $database = $connection['database'];

        $latest = $this->backups(config('pipeline.backup.path'), $database)[0] ?? null;
        if ($latest === null) {
            throw new \RuntimeException('Vérification : aucune sauvegarde à restaurer');
        }
        $file = config('pipeline.backup.path') . '/' . $latest['file'];

        $temporary = self::temporaryDatabaseName($database, now()->format('YmdHis'));
        $live = DB::connection($connectionName);
        if ($live->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$temporary]) !== null) {
            throw new \RuntimeException("Vérification : la base temporaire {$temporary} existe déjà, rien n'est touché");
        }

        $sqlFile = tempnam(sys_get_temp_dir(), 'dbverify');
        chmod($sqlFile, 0600);
        $optionsFile = null;
        $verifyConnection = 'backup_verify';
        $created = false;

        try {
            $this->decompress($file, $sqlFile);
            $live->statement("CREATE DATABASE `{$temporary}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;

            $optionsFile = $this->writeOptionsFile($connection);
            $result = Process::timeout((int) config('pipeline.backup.timeout_seconds'))->run([
                config('pipeline.backup.client_binary'),
                "--defaults-extra-file={$optionsFile}",
                '--default-character-set=utf8mb4',
                $temporary,
                '--execute=source ' . $sqlFile,
            ]);
            if (!$result->successful()) {
                throw new \RuntimeException('Vérification : restauration échouée (code ' . $result->exitCode() . ') ' . trim(mb_substr($result->errorOutput(), 0, 500)));
            }

            config(["database.connections.{$verifyConnection}" => ['database' => $temporary] + $connection]);
            $restored = DB::connection($verifyConnection);
            $restoredTables = array_map(fn ($row) => array_values((array) $row)[0], $restored->select('SHOW TABLES'));

            $tables = [];
            $missing = [];
            foreach (config('pipeline.backup.verify_tables') as $table) {
                $present = in_array($table, $restoredTables, true);
                if (!$present) {
                    $missing[] = $table;
                }
                $tables[$table] = [
                    'backup' => $present ? $restored->table($table)->count() : null,
                    'live' => $live->getSchemaBuilder()->hasTable($table) ? $live->table($table)->count() : null,
                ];
            }
        } finally {
            DB::purge($verifyConnection);
            if ($created) {
                $live->statement("DROP DATABASE IF EXISTS `{$temporary}`");
            }
            if ($optionsFile !== null) {
                @unlink($optionsFile);
            }
            @unlink($sqlFile);
        }

        $dropped = $live->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$temporary]) === null;

        return ['file' => $file, 'database' => $temporary, 'tables' => $tables, 'missing' => $missing, 'dropped' => $dropped];
    }

    /**
     * Nom de la base temporaire : toujours dérivé de la base réelle, jamais égal à
     * elle, limité à des caractères sûrs entre accents graves.
     */
    public static function temporaryDatabaseName(string $database, string $stamp): string
    {
        $name = "{$database}_verify_{$stamp}";
        if (!preg_match('/^[A-Za-z0-9_-]+_verify_\d{14}$/', $name) || $name === $database || strlen($name) > 64) {
            throw new \RuntimeException("Vérification : nom de base temporaire refusé ({$name})");
        }

        return $name;
    }

    private function connection(): array
    {
        $connectionName = config('pipeline.backup.connection');
        $connection = config("database.connections.{$connectionName}");
        if (!is_array($connection) || !in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException("Sauvegarde : connexion « {$connectionName} » absente ou non MySQL/MariaDB");
        }

        return $connection;
    }

    private function decompress(string $gzPath, string $sqlPath): void
    {
        $in = gzopen($gzPath, 'rb');
        $out = fopen($sqlPath, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException("Vérification : décompression de {$gzPath} impossible");
        }
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);
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
     * Sauvegardes de cette base au format attendu, de la plus récente à la plus
     * ancienne.
     *
     * @return list<array{file: string, day: string, bytes: int}>
     */
    private function backups(string $dir, string $database): array
    {
        $pattern = '/^' . preg_quote($database, '/') . '_(\d{4}-\d{2}-\d{2})_\d{6}\.sql\.gz$/';
        $backups = [];
        foreach (is_dir($dir) ? scandir($dir) : [] as $file) {
            if (preg_match($pattern, $file, $m)) {
                $backups[] = ['file' => $file, 'day' => $m[1], 'bytes' => filesize("{$dir}/{$file}")];
            }
        }
        usort($backups, fn ($a, $b) => strcmp($b['file'], $a['file']));

        return $backups;
    }

    /**
     * Garde les sauvegardes des `keep_days` derniers jours distincts ; ne touche
     * qu'aux fichiers de cette base au format attendu. Ne supprime jamais une
     * sauvegarde dont la nouvelle fait moins de `shrink_ratio` de la taille :
     * refus journalisé.
     *
     * @return array{deleted: list<string>, kept_shrunk: list<array{file: string, bytes: int}>}
     */
    private function rotate(string $dir, string $database, string $newestPath): array
    {
        $newestBytes = filesize($newestPath);
        $ratio = (float) config('pipeline.backup.shrink_ratio');
        $keepDays = array_slice(array_unique(array_column($this->backups($dir, $database), 'day')), 0, max(1, (int) config('pipeline.backup.keep_days')));

        $deleted = [];
        $keptShrunk = [];
        foreach ($this->backups($dir, $database) as $backup) {
            if (in_array($backup['day'], $keepDays, true)) {
                continue;
            }
            if ($newestBytes < $ratio * $backup['bytes']) {
                $keptShrunk[] = ['file' => $backup['file'], 'bytes' => $backup['bytes']];
                continue;
            }
            unlink("{$dir}/{$backup['file']}");
            $deleted[] = $backup['file'];
        }

        if ($keptShrunk) {
            Log::channel('pipeline')->warning('db:backup : rotation refusée, la base a rétréci brutalement', [
                'newest' => basename($newestPath),
                'newest_bytes' => $newestBytes,
                'shrink_ratio' => $ratio,
                'kept' => $keptShrunk,
            ]);
        }

        return ['deleted' => $deleted, 'kept_shrunk' => $keptShrunk];
    }
}
