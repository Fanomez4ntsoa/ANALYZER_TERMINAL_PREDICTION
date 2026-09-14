<?php

namespace App\Support\Terminal;

/**
 * Un état système affiché par le layout du terminal.
 *
 * Gravité CRITICAL : les données affichées ne sont pas celles du jour ou sont
 * incomplètes au point de tromper. Seule la plus grave de la page passe en vidéo
 * inverse (docs/design-system.md) ; les autres s'affichent en ligne.
 */
final class SystemState
{
    public const CRITICAL = 3;
    public const WARNING = 2;
    public const NOTICE = 1;

    public function __construct(
        public readonly int $severity,
        public readonly string $key,
        public readonly string $message,
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * Répartit les états : au plus un en vidéo inverse, le plus grave, et
     * seulement s'il est critique. Les autres, par gravité décroissante, en ligne.
     *
     * @param  SystemState[]  $states
     * @return array{inverse: ?SystemState, lines: SystemState[]}
     */
    public static function arrange(array $states): array
    {
        $ordered = array_values($states);
        // Tri stable : à gravité égale, l'ordre de déclaration est conservé
        usort($ordered, fn (self $a, self $b) => $b->severity <=> $a->severity);

        $inverse = null;
        if ($ordered !== [] && $ordered[0]->severity === self::CRITICAL) {
            $inverse = array_shift($ordered);
        }

        return ['inverse' => $inverse, 'lines' => $ordered];
    }
}
