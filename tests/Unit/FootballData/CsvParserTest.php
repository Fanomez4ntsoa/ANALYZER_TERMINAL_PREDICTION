<?php

namespace Tests\Unit\FootballData;

use App\Services\Backtesting\FootballData\CsvParser;
use Tests\TestCase;

class CsvParserTest extends TestCase
{
    private const HEADER = "Div,Date,Time,HomeTeam,AwayTeam,FTHG,FTAG,FTR,B365H,B365D,B365A\n";

    private function write(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fd');
        file_put_contents($path, $content);
        return $path;
    }

    public function test_windows_1252_line_is_converted_and_apostrophe_kept(): void
    {
        // \x92 = apostrophe typographique en Windows-1252, \xFC = ü
        $path = $this->write(self::HEADER
            . "EC,14/08/2021,15:00,King\x92s Lynn,Nott'm Forest,1,2,A,2.5,3.2,2.8\n"
            . "EC,15/08/2021,15:00,M\xFCnster,Preu\xDFen,0,0,D,2.5,3.2,2.8\n");

        $parsed = (new CsvParser())->parse($path, '2122');
        unlink($path);

        $this->assertSame(2, $parsed['total_rows']);
        $this->assertSame(2, $parsed['converted_lines']);
        $this->assertSame('King’s Lynn', $parsed['rows'][0]['home_team']);
        $this->assertSame("Nott'm Forest", $parsed['rows'][0]['away_team']);
        $this->assertSame('Münster', $parsed['rows'][1]['home_team']);
        $this->assertSame('Preußen', $parsed['rows'][1]['away_team']);
        foreach ($parsed['rows'] as $row) {
            $this->assertTrue(mb_check_encoding($row['home_team'], 'UTF-8'));
            $this->assertTrue(mb_check_encoding($row['away_team'], 'UTF-8'));
        }
    }

    public function test_valid_utf8_line_is_left_untouched(): void
    {
        $path = $this->write("\xEF\xBB\xBF" . self::HEADER
            . "D2,02/08/2024,18:30,Münster,Preußen,2,1,H,2.1,3.4,3.3\n"
            . "SP1,02/08/2024,18:30,Alavés,Cádiz,2,1,H,2.1,3.4,3.3\n"
            . "T1,02/08/2024,18:30,Beşiktaş,Gençlerbirliği,2,1,H,2.1,3.4,3.3\n"
            . "P1,02/08/2024,18:30,Guimarães,Estrela da Amadora,2,1,H,2.1,3.4,3.3\n");

        $parsed = (new CsvParser())->parse($path, '2425');
        unlink($path);

        $this->assertSame(4, $parsed['total_rows']);
        $this->assertSame(0, $parsed['converted_lines']);
        $this->assertSame(['Münster', 'Alavés', 'Beşiktaş', 'Guimarães'], array_column($parsed['rows'], 'home_team'));
        $this->assertSame(['Preußen', 'Cádiz', 'Gençlerbirliği', 'Estrela da Amadora'], array_column($parsed['rows'], 'away_team'));
        $this->assertSame('D2', $parsed['rows'][0]['div']); // BOM retiré de l'en-tête
    }

    public function test_mixed_file_converts_only_invalid_lines(): void
    {
        $path = $this->write(self::HEADER
            . "SP1,02/08/2024,18:30,Alavés,Cádiz,2,1,H,2.1,3.4,3.3\n"
            . "SP1,03/08/2024,18:30,Alav\xE9s,C\xE1diz,2,1,H,2.1,3.4,3.3\n");

        $parsed = (new CsvParser())->parse($path, '2425');
        unlink($path);

        $this->assertSame(1, $parsed['converted_lines']);
        $this->assertSame('Alavés', $parsed['rows'][0]['home_team']);
        $this->assertSame('Alavés', $parsed['rows'][1]['home_team']);
        $this->assertSame('Cádiz', $parsed['rows'][1]['away_team']);
    }

    public function test_missing_columns_are_reported(): void
    {
        $path = $this->write(self::HEADER . "E0,14/08/2021,15:00,Arsenal,Chelsea,1,2,A,2.5,3.2,2.8\n");
        $parsed = (new CsvParser())->parse($path, '2122');
        unlink($path);

        $this->assertContains('PSCH', $parsed['missing_columns']);
        $this->assertContains('B365>2.5', $parsed['missing_columns']);
        $this->assertNotContains('B365H', $parsed['missing_columns']);
        $this->assertNull($parsed['rows'][0]['ps_close_home']);
        $this->assertSame(2.5, $parsed['rows'][0]['b365_open_home']);
    }
}
