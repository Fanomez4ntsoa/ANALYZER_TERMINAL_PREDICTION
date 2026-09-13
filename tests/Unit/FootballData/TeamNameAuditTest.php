<?php

namespace Tests\Unit\FootballData;

use App\Services\Backtesting\FootballData\TeamNameAudit;
use PHPUnit\Framework\TestCase;

class TeamNameAuditTest extends TestCase
{
    public function test_pairs_differing_only_by_non_ascii_character_are_flagged(): void
    {
        $pairs = (new TeamNameAudit())->suspectPairs([
            'King’s Lynn', "King's Lynn", 'Münster', 'Munster', 'Preußen', 'Preussen',
            'Alavés', 'Alaves', 'Beşiktaş', 'Besiktas', 'Guimarães', 'Guimaraes',
            'Arsenal', "Nott'm Forest", 'Kings Lynn',
        ]);

        $this->assertSame([
            ['Alaves', 'Alavés'],
            ['Besiktas', 'Beşiktaş'],
            ['Guimaraes', 'Guimarães'],
            ["King's Lynn", 'King’s Lynn'],
            ['Munster', 'Münster'],
            ['Preussen', 'Preußen'],
        ], $pairs);
    }

    public function test_pairs_differing_by_ascii_characters_are_not_flagged(): void
    {
        $pairs = (new TeamNameAudit())->suspectPairs(["King's Lynn", 'Kings Lynn', 'Man United', 'Man Utd', 'Arsenal', 'arsenal']);
        $this->assertSame([], $pairs);
    }

    public function test_two_non_ascii_spellings_of_same_name_are_flagged(): void
    {
        $pairs = (new TeamNameAudit())->suspectPairs(['Beşiktaş', 'Besiktaş']);
        $this->assertSame([['Besiktaş', 'Beşiktaş']], $pairs);
    }
}
