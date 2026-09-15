<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AppReset extends Command
{
    protected $signature = 'app:reset {--force : Skip confirmation}';
    protected $description = 'Vider toutes les tables de donnees (matchs, recommendations, backtest, etc.) sans toucher aux users';

    public function handle(): int
    {
        $tables = [
            'match_validations',
            'backtest_predictions',
            'backtest_runs',
            'odds_movements',
            'combos',
            'recommendations',
            'sources',
            'advanced_data',
            'matches',
            'referees',
        ];

        $counts = [];
        foreach ($tables as $table) {
            try {
                $counts[$table] = DB::table($table)->count();
            } catch (\Exception $e) {
                $counts[$table] = '-';
            }
        }

        $this->table(['Table', 'Lignes'], collect($counts)->map(fn($c, $t) => [$t, $c])->values()->toArray());

        $total = collect($counts)->filter(fn($c) => is_int($c))->sum();
        $this->newLine();
        $this->warn("Cela va supprimer {$total} lignes au total.");
        $this->line('Tables preservees : users, password_reset_tokens, sessions, cache, jobs, migrations, prediction_log (journal des selections, jamais vide)');

        if (!$this->option('force') && !$this->confirm('Confirmer la suppression ?')) {
            $this->info('Annule.');
            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        foreach ($tables as $table) {
            try {
                DB::table($table)->truncate();
                $this->line("  Truncated: {$table}");
            } catch (\Exception $e) {
                $this->warn("  Skip: {$table} ({$e->getMessage()})");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->newLine();
        $this->info('Reset termine. Base de donnees videe.');

        return self::SUCCESS;
    }
}
