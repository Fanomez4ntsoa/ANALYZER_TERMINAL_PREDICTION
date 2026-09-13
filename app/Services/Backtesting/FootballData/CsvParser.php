<?php

namespace App\Services\Backtesting\FootballData;

use Carbon\Carbon;

/**
 * Lecture d'un CSV football-data.co.uk vers des lignes normalisées.
 *
 * Seules les colonnes de la liste blanche config('football-data.columns') sont lues.
 * Une colonne absente donne null et est journalisée dans le rapport ; les colonnes
 * Max et Avg (multi-bookmakers) ne sont jamais lues.
 */
class CsvParser
{
    /**
     * @return array{rows: array<int, array>, missing_columns: string[], skipped_rows: int, total_rows: int}
     */
    public function parse(string $path, string $season): array
    {
        $map = config('football-data.columns');
        $required = config('football-data.required_columns');
        $leagueIds = config('football-data.league_ids');

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d'ouvrir {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return ['rows' => [], 'missing_columns' => array_keys($map), 'skipped_rows' => 0, 'total_rows' => 0];
        }

        // BOM UTF-8 + espaces
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header);
        $index = array_flip($header);

        $missing = array_values(array_filter(array_keys($map), fn ($col) => !isset($index[$col])));
        foreach ($required as $col) {
            if (!isset($index[$col])) {
                fclose($handle);
                throw new \RuntimeException("Colonne obligatoire '{$col}' absente dans {$path}");
            }
        }

        $rows = [];
        $skipped = 0;
        $total = 0;

        while (($line = fgetcsv($handle)) !== false) {
            // Lignes vides en fin de fichier
            if (count($line) < 4 || trim((string) ($line[$index['Div']] ?? '')) === '') {
                continue;
            }
            $total++;

            $row = [];
            foreach ($map as $csvCol => $field) {
                $raw = isset($index[$csvCol]) ? trim((string) ($line[$index[$csvCol]] ?? '')) : '';
                $row[$field] = $raw === '' ? null : $raw;
            }

            $date = $this->parseDate($row['match_date']);
            if ($date === null || $row['home_team'] === null || $row['away_team'] === null) {
                $skipped++;
                continue;
            }

            $row['match_date'] = $date->format('Y-m-d');
            $row['season'] = $season;
            $row['league_id'] = $leagueIds[$row['div']] ?? null;
            $row['fthg'] = $this->toInt($row['fthg']);
            $row['ftag'] = $this->toInt($row['ftag']);
            $row['ftr'] = $row['ftr'] !== null ? strtoupper(substr($row['ftr'], 0, 1)) : null;

            foreach ($map as $field) {
                if (str_starts_with($field, 'b365_') || str_starts_with($field, 'ps_')) {
                    $row[$field] = $this->toOdd($row[$field]);
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'missing_columns' => $missing,
            'skipped_rows' => $skipped,
            'total_rows' => $total,
        ];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        foreach (['d/m/Y', 'd/m/y'] as $format) {
            try {
                $d = Carbon::createFromFormat($format, $value);
                if ($d !== false) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    private function toInt(?string $value): ?int
    {
        return ($value === null || !is_numeric($value)) ? null : (int) $value;
    }

    /** Cote décimale valide (> 1.0), sinon null. */
    private function toOdd(?string $value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $odd = (float) $value;
        return $odd > 1.0 ? $odd : null;
    }
}
