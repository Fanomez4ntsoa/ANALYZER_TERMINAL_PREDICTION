<?php

namespace App\Services\Probability;

/**
 * Tentative d'écrire des prédictions sur un match dont le coup d'envoi est passé
 * (ou marqué post_kickoff_data). Toujours refusée : règle 5 de CLAUDE.md.
 */
class KickoffPassedException extends \RuntimeException
{
}
