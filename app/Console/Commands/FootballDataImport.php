<?php

namespace App\Console\Commands;

use App\Models\HistoricalMatch;
use App\Services\Backtesting\FootballData\CsvParser;
use App\Services\Backtesting\FootballData\TeamNameAudit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FootballDataImport extends Command
{
    protected $signature = 'football-data:import
                            {--seasons= : Saisons AABB séparées par des virgules (défaut : toutes celles de la config)}
                            {--divisions= : Divisions à importer (ex. E0,D1) — défaut : toutes celles du zip}
                            {--force-download : Re-télécharger le zip même s\'il est déjà présent}';

    protected $description = 'Télécharger les CSV football-data.co.uk et les charger dans historical_matches';

    public function handle(CsvParser $parser, TeamNameAudit $audit): int
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
        $missingLog = [];

        foreach ($seasons as $season) {
            if (!preg_match('/^\d{4}$/', $season)) {
                $this->error("Saison invalide : {$season} (format attendu AABB, ex. 2324)");
                return self::FAILURE;
            }

            $files = $this->resolveCsvFiles($disk, "{$base}/{$season}", $season);
            if ($files === null) {
                return self::FAILURE;
            }

            foreach ($files as $file) {
                $div = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                if ($divisions !== null && !in_array($div, $divisions, true)) {
                    continue;
                }

                $parsed = $parser->parse($disk->path($file), $season);
                $count = $this->upsert($parsed['rows'], "{$season}/{$div}.csv");

                $summary[] = [
                    $season, $div, $parsed['total_rows'], $count, $parsed['skipped_rows'],
                    $parsed['converted_lines'], count($parsed['missing_columns']),
                ];

                if (!empty($parsed['missing_columns'])) {
                    $missingLog[] = ["{$season}/{$div}.csv", implode(', ', $parsed['missing_columns'])];
                    Log::warning("football-data: colonnes absentes dans {$season}/{$div}.csv", [
                        'missing' => $parsed['missing_columns'],
                        'rows' => $parsed['total_rows'],
                    ]);
                }
                if ($parsed['skipped_rows'] > 0) {
                    Log::warning("football-data: {$parsed['skipped_rows']} lignes ignorées dans {$season}/{$div}.csv (date/équipes invalides)");
                }
                if ($parsed['converted_lines'] > 0) {
                    Log::info("football-data: {$parsed['converted_lines']} lignes converties de " . CsvParser::SOURCE_ENCODING . " vers UTF-8 dans {$season}/{$div}.csv");
                }
            }
        }

        $this->table(
            ['Saison', 'Div', 'Lignes CSV', 'Upsert', 'Ignorées', 'Converties ' . CsvParser::SOURCE_ENCODING, 'Colonnes absentes'],
            $summary
        );
        $this->info('Import terminé. Colonnes Max/Avg jamais lues (liste blanche config/football-data.php).');

        $this->newLine();
        $this->line('<comment>Journal des colonnes absentes</comment>');
        if ($missingLog === []) {
            $this->line('Aucune colonne de la liste blanche absente.');
        } else {
            $this->table(['Fichier', 'Colonnes absentes'], $missingLog);
        }

        $this->newLine();
        $this->auditTeamNames($audit, $divisions);

        return self::SUCCESS;
    }

    /**
     * Chemins (relatifs au disque) des CSV à lire pour une saison.
     *
     * Ordre : zip présent → extrait dans {saison}/csv ; sinon CSV déjà décompressés
     * dans {saison}/csv ou {saison}/ ; sinon téléchargement. --force-download
     * retélécharge dans tous les cas.
     *
     * @return string[]|null  null en cas d'échec (déjà signalé)
     */
    private function resolveCsvFiles(Filesystem $disk, string $seasonDir, string $season): ?array
    {
        $zipRel = "{$seasonDir}/data.zip";
        $csvDirRel = "{$seasonDir}/csv";

        $existingCsv = $this->findCsvFiles($disk, [$csvDirRel, $seasonDir]);

        if ($this->option('force-download') || (!$disk->exists($zipRel) && $existingCsv === [])) {
            $url = config('football-data.base_url') . "/{$season}/data.zip";
            $this->info("Téléchargement {$url}");
            $response = Http::withUserAgent('football-analyzer-web/backtest')->timeout(120)->get($url);
            if (!$response->successful()) {
                $this->error("Échec du téléchargement ({$response->status()}) pour la saison {$season}");
                return null;
            }
            $disk->put($zipRel, $response->body());
        } elseif ($disk->exists($zipRel)) {
            $this->line("Zip déjà présent pour {$season}, réutilisé (--force-download pour rafraîchir)");
        }

        if ($disk->exists($zipRel)) {
            $zip = new \ZipArchive();
            if ($zip->open($disk->path($zipRel)) !== true) {
                $this->error("Zip illisible : {$zipRel}");
                return null;
            }
            $disk->makeDirectory($csvDirRel);
            $zip->extractTo($disk->path($csvDirRel));
            $zip->close();

            return $this->findCsvFiles($disk, [$csvDirRel]);
        }

        $dir = dirname($existingCsv[0]);
        $this->line("Zip absent pour {$season} : " . count($existingCsv) . " CSV déjà décompressés utilisés dans {$dir}");

        return $existingCsv;
    }

    /** CSV du premier répertoire de la liste qui en contient, triés par nom. */
    private function findCsvFiles(Filesystem $disk, array $dirs): array
    {
        foreach ($dirs as $dir) {
            if (!$disk->exists($dir)) {
                continue;
            }
            $files = collect($disk->files($dir))
                ->filter(fn ($f) => str_ends_with(strtolower($f), '.csv'))
                ->sort()
                ->values()
                ->all();
            if ($files !== []) {
                return $files;
            }
        }

        return [];
    }

    /**
     * Noms d'équipes distincts par division (toutes saisons en base) et paires
     * qui ne diffèrent que par un caractère non-ASCII.
     */
    private function auditTeamNames(TeamNameAudit $audit, ?array $divisions): void
    {
        $this->line('<comment>Vérification des noms d\'équipes</comment>');

        $namesByDiv = [];
        foreach (['home_team', 'away_team'] as $col) {
            HistoricalMatch::query()
                ->when($divisions !== null, fn ($q) => $q->whereIn('div', $divisions))
                ->select(['div', $col])
                ->distinct()
                ->orderBy('div')
                ->get()
                ->each(function ($r) use (&$namesByDiv, $col) {
                    $namesByDiv[$r->div][] = $r->{$col};
                });
        }
        ksort($namesByDiv);

        $rows = [];
        $suspects = [];
        foreach ($namesByDiv as $div => $names) {
            $names = array_values(array_unique($names));
            $nonAscii = array_values(array_filter($names, fn ($n) => $audit->hasNonAscii($n)));
            $pairs = $audit->suspectPairs($names);
            $rows[] = [$div, count($names), implode(', ', $nonAscii) ?: '-', count($pairs)];
            foreach ($pairs as [$a, $b]) {
                $suspects[] = [$div, $a, $b];
            }
        }
        $this->table(['Div', 'Équipes distinctes', 'Noms avec caractères non-ASCII', 'Paires suspectes'], $rows);

        if ($suspects === []) {
            $this->info('Aucune paire de noms ne diffère seulement par un caractère non-ASCII.');
            return;
        }

        $this->warn(count($suspects) . ' paire(s) de noms à réconcilier avant toute jointure :');
        $this->table(['Div', 'Graphie A', 'Graphie B'], $suspects);
        Log::warning('football-data: graphies d\'équipes en double (caractère non-ASCII)', ['pairs' => $suspects]);
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
