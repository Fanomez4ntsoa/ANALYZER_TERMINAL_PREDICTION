<?php

namespace App\Services\Backtesting\FootballData;

/**
 * Détecte, dans une liste de noms d'équipes, les paires qui ne diffèrent que
 * par un caractère non-ASCII (King’s Lynn / King's Lynn, Münster / Munster).
 * Deux graphies pour la même équipe casseraient toute jointure ultérieure.
 */
class TeamNameAudit
{
    /** Repli explicite des caractères que la décomposition Unicode ne ramène pas à l'ASCII. */
    private const FOLD = [
        '’' => "'", '‘' => "'", '‛' => "'", '′' => "'",
        '“' => '"', '”' => '"', '„' => '"',
        '–' => '-', '—' => '-', ' ' => ' ',
        'ß' => 'ss', 'ẞ' => 'SS',
        'Æ' => 'AE', 'æ' => 'ae', 'Œ' => 'OE', 'œ' => 'oe',
        'Ø' => 'O', 'ø' => 'o', 'Đ' => 'D', 'đ' => 'd', 'Ł' => 'L', 'ł' => 'l',
        'İ' => 'I', 'ı' => 'i', 'Þ' => 'Th', 'þ' => 'th',
    ];

    /**
     * @param  string[]  $names
     * @return array<int, array{0: string, 1: string}>  paires triées, chaque paire triée
     */
    public function suspectPairs(array $names): array
    {
        $byKey = [];
        foreach (array_unique($names) as $name) {
            if (!$this->hasNonAscii($name)) {
                $byKey[$name][] = $name;
                continue;
            }
            $byKey[$this->fold($name)][] = $name;
        }

        $pairs = [];
        foreach ($byKey as $variants) {
            if (count($variants) < 2) {
                continue;
            }
            sort($variants);
            for ($i = 0; $i < count($variants); $i++) {
                for ($j = $i + 1; $j < count($variants); $j++) {
                    $pairs[] = [$variants[$i], $variants[$j]];
                }
            }
        }
        sort($pairs);

        return $pairs;
    }

    public function hasNonAscii(string $name): bool
    {
        return preg_match('/[^\x00-\x7F]/', $name) === 1;
    }

    /** Ramène un nom à sa forme ASCII ; les caractères ASCII ne sont pas touchés. */
    public function fold(string $name): string
    {
        $name = strtr($name, self::FOLD);
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_D) ?: $name;
            $name = preg_replace('/\p{Mn}+/u', '', $name) ?? $name;
        }

        return $name;
    }
}
