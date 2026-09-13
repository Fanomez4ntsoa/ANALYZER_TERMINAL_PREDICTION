<?php

namespace App\Console\Commands;

use App\Models\HistoricalMatch;
use App\Services\Backtesting\FootballData\CsvParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FootballDataImport extends Command
{
    protected $signature = 'football-data:import
                            {--seasons= : Saisons AABB séparées par des virgules (défaut : toutes celles de la config)}
                            {--divisions= : Divisions à importer (ex. E0,D1) — défaut : toutes celles du zip}
                            {--force : Re-télécharger le zip même s\'il est déjà présent}';

    protected $description = 'Télécharger les CSV football-data.co.uk et les charger dans historical_matches';

    public function handle(CsvParser $parser): int
    {
        $seasons = $this->option('seasons')
            ? array_map('trim', explode(',', $this->option('seasons')))
            : array_merge(config('football-data.work_seasons'), config('football-data.holdout_seasons'));

        $divisions = $this->option('divisions')
            ? array_map(fn ($d) => strtoupper(trim($d)), explode(',', $this->option('divisions')))
            : null;

        $disk = Storage::disk('local');
        $base = config('football-data.storage_path');
        $summary = [];

        foreach ($seasons as $season) {
            if (!preg_match('/^\d{4}$/', $season)) {
                $this->error("Saison invalide : {$season} (format attendu AABB, ex. 2324)");
                return self::FAILURE;
            }

            $zipRel = "{$base}/{$season}/data.zip";
            $dirRel = "{$base}/{$season}/csv";

            if ($this->option('force') || !$disk->exists($zipRel)) {
                $url = config('football-data.base_url') . "/{$season}/data.zip";
                $this->info("Téléchargement {$url}");
                $response = Http::withUserAgent('football-analyzer-web/backtest')->timeout(120)->get($url);
                if (!$response->successful()) {
                    $this->error("Échec du téléchargement ({$response->status()}) pour la saison {$season}");
                    return self::FAILURE;
                }
                $disk->put($zipRel, $response->body());
            } else {
                $this->line("Zip déjà présent pour {$season}, réutilisé (--force pour re-télécharger)");
            }

            $zip = new \ZipArchive();
            if ($zip->open($disk->path($zipRel)) !== true) {
                $this->error("Zip illisible : {$zipRel}");
                return self::FAILURE;
            }
            $disk->makeDirectory($dirRel);
            $zip->extractTo($disk->path($dirRel));
            $zip->close();

            $files = collect($disk->files($dirRel))
                ->filter(fn ($f) => str_ends_with(strtolower($f), '.csv'))
                ->sort()
                ->values();

            foreach ($files as $file) {
                $div = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                if ($divisions !== null && !in_array($div, $divisions, true)) {
                    continue;
                }

                $parsed = $parser->parse($disk->path($file), $season);
                $count = $this->upsert($parsed['rows'], "{$season}/{$div}.csv");

                $summary[] = [$season, $div, $parsed['total_rows'], $count, $parsed['skipped_rows'], implode(',', $parsed['missing_columns']) ?: '-'];

                if (!empty($parsed['missing_columns'])) {
                    Log::warning("football-data: colonnes absentes dans {$season}/{$div}.csv", [
                        'missing' => $parsed['missing_columns'],
                        'rows' => $parsed['total_rows'],
                    ]);
                }
                if ($parsed['skipped_rows'] > 0) {
                    Log::warning("football-data: {$parsed['skipped_rows']} lignes ignorées dans {$season}/{$div}.csv (date/équipes invalides)");
                }
            }
        }

        $this->table(['Saison', 'Div', 'Lignes CSV', 'Upsert', 'Ignorées', 'Colonnes absentes'], $summary);
        $this->info('Import terminé. Colonnes Max/Avg jamais lues (liste blanche config/football-data.php).');

        return self::SUCCESS;
    }

    private function upsert(array $rows, string $sourceFile): int
    {
        if (empty($rows)) {
            return 0;
        }

        $now = now();
        $fields = array_values(config('football-data.columns'));
        $fields = array_merge($fields, ['season', 'league_id']);

        $updateColumns = array_values(array_diff($fields, ['season', 'div', 'match_date', 'home_team', 'away_team']));
        $updateColumns[] = 'source_file';
        $updateColumns[] = 'imported_at';
        $updateColumns[] = 'updated_at';

        $chunks = array_chunk($rows, 500);
        $total = 0;
        foreach ($chunks as $chunk) {
            $payload = array_map(function ($row) use ($sourceFile, $now) {
                $row['source_file'] = $sourceFile;
                $row['imported_at'] = $now;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                return $row;
            }, $chunk);

            HistoricalMatch::upsert(
                $payload,
                ['season', 'div', 'match_date', 'home_team', 'away_team'],
                $updateColumns
            );
            $total += count($chunk);
        }

        return $total;
    }
}
