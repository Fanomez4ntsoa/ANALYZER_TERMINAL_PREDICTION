<?php

namespace Tests\Unit\DataPipeline;

use App\Services\DataPipeline\DatabaseBackup;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Sauvegarde : mysqldump simulé (Process::fake), aucune base réelle touchée. La
 * connexion par défaut des tests reste SQLite ; seule la configuration de
 * sauvegarde désigne une connexion MariaDB, jamais ouverte.
 */
class DatabaseBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/dbbackup-test-' . bin2hex(random_bytes(4));
        config([
            'pipeline.backup.connection' => 'mariadb',
            'pipeline.backup.path' => $this->dir,
            'pipeline.backup.keep_days' => 7,
            'database.connections.mariadb.database' => 'football-analyzer',
            'database.connections.mariadb.username' => 'admin',
            'database.connections.mariadb.password' => 's3cret"pw',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Simule mysqldump : écrit le fichier --result-file, complet ou non. */
    private function fakeDump(bool $completed = true, int $exitCode = 0, ?array &$commands = null): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use ($completed, $exitCode, &$commands) {
            $commands[] = $process->command;
            foreach ($process->command as $arg) {
                if (str_starts_with($arg, '--defaults-extra-file=')) {
                    $commands['options'] = file_get_contents(substr($arg, 22));
                    $commands['options_file'] = substr($arg, 22);
                }
                if (str_starts_with($arg, '--result-file=')) {
                    file_put_contents(substr($arg, 14), "CREATE TABLE matches (id int);\n" . ($completed ? "-- Dump completed on 2026-09-15 10:00:01\n" : ''));
                }
            }

            return Process::result(exitCode: $exitCode, errorOutput: $exitCode ? 'mysqldump: Got error: 1045' : '');
        });
    }

    public function test_dump_is_compressed_and_password_never_on_command_line(): void
    {
        $this->fakeDump(commands: $commands);

        $result = app(DatabaseBackup::class)->run();

        $this->assertSame("{$this->dir}/football-analyzer_2026-09-15_100000.sql.gz", $result['file']);
        $this->assertStringContainsString('-- Dump completed', gzdecode(file_get_contents($result['file'])));
        $this->assertFileDoesNotExist("{$this->dir}/football-analyzer_2026-09-15_100000.sql");
        $this->assertStringNotContainsString('s3cret', implode(' ', $commands[0]));
        $this->assertStringContainsString('password="s3cret\\"pw"', $commands['options']);
        $this->assertFileDoesNotExist($commands['options_file']);
        $this->assertSame('football-analyzer', end($commands[0]));
    }

    public function test_failed_or_incomplete_dump_is_refused_and_keeps_existing_backups(): void
    {
        mkdir($this->dir, 0700, true);
        $old = "{$this->dir}/football-analyzer_2026-09-01_100000.sql.gz";
        file_put_contents($old, 'ancienne');

        foreach ([[false, 0], [true, 2]] as [$completed, $exitCode]) {
            $this->fakeDump($completed, $exitCode);
            try {
                app(DatabaseBackup::class)->run();
                $this->fail('Vidage non terminé accepté');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('vidage non terminé', $e->getMessage());
            }
        }

        $this->assertSame([basename($old)], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function test_rotation_keeps_the_last_seven_distinct_days_only_after_success(): void
    {
        mkdir($this->dir, 0700, true);
        $existing = [
            'football-analyzer_2026-09-05_100000.sql.gz',
            'football-analyzer_2026-09-06_100000.sql.gz',
            'football-analyzer_2026-09-08_100000.sql.gz',
            'football-analyzer_2026-09-09_100000.sql.gz',
            'football-analyzer_2026-09-10_100000.sql.gz',
            'football-analyzer_2026-09-12_100000.sql.gz',
            'football-analyzer_2026-09-14_100000.sql.gz',
            'football-analyzer_2026-09-14_183000.sql.gz',
            'autre-base_2026-01-01_100000.sql.gz',
            'notes.txt',
        ];
        foreach ($existing as $file) {
            file_put_contents("{$this->dir}/{$file}", 'x');
        }
        $this->fakeDump();

        $result = app(DatabaseBackup::class)->run();

        // Jours gardés : 15, 14 (deux fichiers), 12, 10, 09, 08, 06
        $this->assertSame(['football-analyzer_2026-09-05_100000.sql.gz'], $result['deleted']);
        $this->assertFileExists("{$this->dir}/football-analyzer_2026-09-14_183000.sql.gz");
        $this->assertFileExists("{$this->dir}/autre-base_2026-01-01_100000.sql.gz");
        $this->assertFileExists("{$this->dir}/notes.txt");
    }

    public function test_rotation_never_deletes_backups_when_the_database_shrank(): void
    {
        mkdir($this->dir, 0700, true);
        // Huit jours de sauvegardes saines (100 Ko), puis une base vidée
        foreach (['05', '06', '07', '08', '09', '10', '11', '12'] as $day) {
            file_put_contents("{$this->dir}/football-analyzer_2026-09-{$day}_100000.sql.gz", random_bytes(100_000));
        }
        $this->fakeDump();

        $result = app(DatabaseBackup::class)->run();

        $this->assertSame([], $result['deleted']);
        $this->assertSame(['football-analyzer_2026-09-06_100000.sql.gz', 'football-analyzer_2026-09-05_100000.sql.gz'], array_column($result['kept_shrunk'], 'file'));
        $this->assertCount(9, glob("{$this->dir}/*.sql.gz"));
    }

    public function test_shrunk_database_keeps_old_backups_on_every_later_run(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents("{$this->dir}/football-analyzer_2026-09-01_100000.sql.gz", random_bytes(100_000));
        // Sept jours de sauvegardes d'une base vide : la saine a quitté la fenêtre
        foreach (['09', '10', '11', '12', '13', '14'] as $day) {
            file_put_contents("{$this->dir}/football-analyzer_2026-09-{$day}_100000.sql.gz", 'vide');
        }
        $this->fakeDump();

        $this->artisan('db:backup')->assertExitCode(1);

        $this->assertFileExists("{$this->dir}/football-analyzer_2026-09-01_100000.sql.gz");
    }

    public function test_normal_growth_rotates_without_warning(): void
    {
        mkdir($this->dir, 0700, true);
        foreach (['01', '02', '03', '04', '05', '06', '07', '08'] as $day) {
            file_put_contents("{$this->dir}/football-analyzer_2026-09-{$day}_100000.sql.gz", 'x');
        }
        $this->fakeDump();

        $this->artisan('db:backup')->assertExitCode(0);

        $this->assertFileDoesNotExist("{$this->dir}/football-analyzer_2026-09-01_100000.sql.gz");
        $this->assertFileDoesNotExist("{$this->dir}/football-analyzer_2026-09-02_100000.sql.gz");
        $this->assertCount(7, glob("{$this->dir}/*.sql.gz"));
    }

    public function test_temporary_database_name_is_derived_and_never_the_real_one(): void
    {
        $this->assertSame('football-analyzer_verify_20260915100000', DatabaseBackup::temporaryDatabaseName('football-analyzer', '20260915100000'));

        foreach ([['football`; DROP DATABASE x; --', '20260915100000'], ['football-analyzer', 'now'], [str_repeat('a', 60), '20260915100000']] as [$db, $stamp]) {
            try {
                DatabaseBackup::temporaryDatabaseName($db, $stamp);
                $this->fail("Nom accepté : {$db}");
            } catch (\RuntimeException) {
            }
        }
    }

    public function test_non_mysql_connection_is_refused(): void
    {
        config(['pipeline.backup.connection' => 'sqlite']);
        Process::fake();

        $this->expectException(\RuntimeException::class);
        app(DatabaseBackup::class)->run();
    }

    public function test_command_fails_visibly(): void
    {
        $this->fakeDump(exitCode: 2);

        $this->artisan('db:backup')->assertExitCode(1);
    }
}
