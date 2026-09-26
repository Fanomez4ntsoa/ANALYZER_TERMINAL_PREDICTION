<?php

namespace Tests\Feature;

use App\Console\Commands\BacktestReference;
use App\Models\FootballMatch;
use App\Models\PipelineRun;
use App\Models\PredictionLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * Déploiement sur le VPS : une seule machine collecte, compte créé sans écho,
 * référence du backtest écrite dans .env, état en dix lignes, manifeste du transfert.
 */
class DeploymentCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:00:00', 'UTC'));
        $this->dir = sys_get_temp_dir() . '/fa-deploy-test-' . uniqid();
        mkdir($this->dir);
        config([
            'logging.channels.pipeline' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'pipeline.heartbeat_path' => "{$this->dir}/heartbeat",
            'pipeline.backup.path' => "{$this->dir}/backups",
            'pipeline.backup.connection' => 'mariadb',
            'database.connections.mariadb.database' => 'football_analyzer',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_collection_commands_refuse_to_run_where_collection_is_disabled_and_say_why(): void
    {
        config(['pipeline.enabled' => false, 'pipeline.reference_host' => 'le VPS de test']);

        $this->artisan('pipeline:daily')
            ->expectsOutputToContain('pipeline:daily refusé : la collecte est désactivée sur cette machine')
            ->expectsOutputToContain('La base qui fait foi est celle de le VPS de test')
            ->expectsOutputToContain('quota API-Football (100 requêtes par jour)')
            ->assertExitCode(1);
        $this->assertSame(0, PipelineRun::count());

        foreach (['snapshot', 'closing', 'close'] as $action) {
            $this->artisan('market:track', ['action' => $action])->assertExitCode(1);
        }
        $this->artisan('pipeline:run-sync')->assertExitCode(1);
        $this->artisan('context:enrich', ['--date' => '2026-09-26'])->assertExitCode(1);

        // Lectures et clôture du journal restent libres
        $this->artisan('log:settle')->assertExitCode(0);
        $this->artisan('log:report')->assertExitCode(0);
    }

    public function test_user_is_created_with_a_confirmed_password_and_an_existing_one_is_kept(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('E-mail du compte', 'Moi@Example.org')
            ->expectsQuestion('Mot de passe (12 caractères au moins, rien ne s\'affiche)', 'court')
            ->expectsQuestion('Mot de passe (12 caractères au moins, rien ne s\'affiche)', 'un-long-secret-1')
            ->expectsQuestion('Le même, une seconde fois', 'un-long-secret-1')
            ->expectsQuestion('Nom affiché', 'moi')
            ->expectsOutputToContain('Compte moi@example.org créé.')
            ->assertExitCode(0);

        $user = User::sole();
        $this->assertTrue(Hash::check('un-long-secret-1', $user->password));

        $this->artisan('user:create')
            ->expectsQuestion('E-mail du compte', 'moi@example.org')
            ->expectsConfirmation('Le compte moi@example.org existe déjà. Changer son mot de passe ?', 'no')
            ->assertExitCode(0);
        $this->assertTrue(Hash::check('un-long-secret-1', $user->fresh()->password));
    }

    public function test_reference_run_is_written_to_env_without_touching_other_lines(): void
    {
        $env = "{$this->dir}/.env";
        file_put_contents($env, "APP_KEY=base64:abc\nBACKTEST_REFERENCE_RUN=1\nDB_PASSWORD=\"x=y\"\n");

        BacktestReference::writeEnv($env, 'BACKTEST_REFERENCE_RUN', '4');
        $this->assertSame("APP_KEY=base64:abc\nBACKTEST_REFERENCE_RUN=4\nDB_PASSWORD=\"x=y\"\n", file_get_contents($env));

        file_put_contents($env, "APP_KEY=base64:abc");
        BacktestReference::writeEnv($env, 'BACKTEST_REFERENCE_RUN', '2');
        $this->assertSame("APP_KEY=base64:abc\nBACKTEST_REFERENCE_RUN=2\n", file_get_contents($env));
    }

    public function test_status_fits_in_ten_lines_and_fails_when_the_scheduler_is_silent(): void
    {
        config(['pipeline.enabled' => true]);
        mkdir("{$this->dir}/backups");
        file_put_contents("{$this->dir}/backups/football_analyzer_2026-09-26_100001.sql.gz", str_repeat('x', 2048));
        PipelineRun::create(['run_date' => '2026-09-26', 'status' => PipelineRun::SUCCESS, 'started_at' => '2026-09-26 10:00:00', 'finished_at' => '2026-09-26 10:05:00']);
        file_put_contents(config('pipeline.heartbeat_path'), '2026-09-26T11:59:00+00:00');

        $this->artisan('system:status', ['--offline' => true])
            ->expectsOutputToContain('Collecte       ACTIVE sur cette machine')
            ->expectsOutputToContain('dernier battement il y a 1 min')
            ->expectsOutputToContain('dernier réussi : 2026-09-26')
            ->expectsOutputToContain('football_analyzer_2026-09-26_100001.sql.gz')
            ->expectsOutputToContain('run #1 introuvable ou non terminé : lancer backtest:reference')
            ->assertExitCode(1);

        // Planificateur muet depuis une heure : critique
        file_put_contents(config('pipeline.heartbeat_path'), '2026-09-26T11:00:00+00:00');
        $this->artisan('system:status', ['--offline' => true])
            ->expectsOutputToContain('CRITIQUE  Planificateur  dernier battement il y a 60 min')
            ->assertExitCode(1);

        // Machine de lecture : rien n'y est une panne
        config(['pipeline.enabled' => false, 'pipeline.reference_host' => 'le VPS de test']);
        $this->artisan('system:status', ['--offline' => true])
            ->expectsOutputToContain('copie de lecture ; la base de référence est le VPS de test')
            ->assertExitCode(0);
        $this->artisan('log:report')
            ->expectsOutputToContain('Base : copie de lecture')
            ->assertExitCode(0);
    }

    public function test_shifted_journal_times_are_critical_even_on_a_read_copy(): void
    {
        config(['pipeline.enabled' => false]);
        $match = FootballMatch::create([
            'home_team' => 'Como', 'away_team' => 'Parma', 'match_date' => '2026-09-15 16:30:00',
            'competition' => 'Serie A', 'league_id' => 135, 'data_source' => 'api',
        ]);
        $entry = PredictionLogEntry::create([
            'match_id' => $match->id, 'league_id' => 135, 'home_team' => 'Como', 'away_team' => 'Parma',
            'kickoff_at' => '2026-09-15 16:30:00', 'trigger' => 'pipeline', 'market' => 'winner', 'outcome' => '1',
            'model_probability' => 0.5, 'model_mode' => 'market_only', 'lambda_home' => 1.4, 'lambda_away' => 1.1,
            'odds' => 2.0, 'bookmaker' => 'Bet365', 'fair_probability' => 0.48, 'computed_at' => '2026-09-15 10:00:00',
        ]);
        $entry->update(['score_home' => 1, 'score_away' => 0, 'outcome_occurred' => true, 'settled_at' => '2026-09-16 10:00:00']);

        $this->artisan('system:status', ['--offline' => true])
            ->expectsOutputToContain('Fuseaux        journal cohérent')
            ->assertExitCode(0);

        // Heures TIMESTAMP relues avec un autre fuseau : même effet qu'un décalage de 3 h
        \Illuminate\Support\Facades\DB::table('prediction_log')->where('id', $entry->id)->update(['kickoff_at' => '2026-09-15 13:30:00']);

        $this->artisan('system:status', ['--offline' => true])
            ->expectsOutputToContain('CRITIQUE  Fuseaux        1 ligne(s) clôturée(s) dont kickoff_at ne vaut plus match_date')
            ->assertExitCode(1);
    }

    public function test_manifest_detects_count_and_time_differences(): void
    {
        $match = FootballMatch::create([
            'home_team' => 'Como', 'away_team' => 'Parma', 'match_date' => '2026-09-15 16:30:00',
            'competition' => 'Serie A', 'league_id' => 135, 'data_source' => 'api',
        ]);
        PredictionLogEntry::create([
            'match_id' => $match->id, 'league_id' => 135, 'home_team' => 'Como', 'away_team' => 'Parma',
            'kickoff_at' => '2026-09-15 16:30:00', 'trigger' => 'pipeline', 'market' => 'winner', 'outcome' => '1',
            'model_probability' => 0.5, 'model_mode' => 'market_only', 'lambda_home' => 1.4, 'lambda_away' => 1.1,
            'odds' => 2.0, 'bookmaker' => 'Bet365', 'fair_probability' => 0.48, 'computed_at' => '2026-09-15 10:00:00',
        ]);
        $file = "{$this->dir}/manifest.json";
        $this->artisan('data:manifest')->assertExitCode(0);
        file_put_contents($file, json_encode(\App\Console\Commands\DataManifest::build()));

        $this->artisan('data:manifest', ['--compare' => $file])
            ->expectsOutputToContain('Identique : 7 tables et le journal.')
            ->assertExitCode(0);

        // Heures décalées au transfert : même effectif, repères différents
        $manifest = json_decode(file_get_contents($file), true);
        $manifest['times']['prediction_log.kickoff_at.min'] = '2026-09-15 13:30:00';
        $manifest['journal']['lines'] = 2;
        file_put_contents($file, json_encode($manifest));

        $this->artisan('data:manifest', ['--compare' => $file])
            ->expectsOutputToContain('ÉCART journal.lines : attendu 2, trouvé 1')
            ->expectsOutputToContain('ÉCART times.prediction_log.kickoff_at.min')
            ->assertExitCode(1);
    }
}
