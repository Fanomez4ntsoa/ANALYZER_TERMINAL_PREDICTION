<?php

namespace App\Services\Api;

/**
 * Échec d'un appel API-Football. Jamais confondu avec une absence de donnée :
 * une limite de débit, un quota épuisé ou un refus de l'offre est un échec.
 */
class ApiFootballException extends \RuntimeException
{
    public const RATE_LIMIT = 'rate_limit';     // 10 requêtes/minute (offre gratuite)
    public const DAILY_QUOTA = 'daily_quota';   // 100 requêtes/jour (offre gratuite)
    public const PLAN = 'plan';                 // endpoint ou saison refusés par l'offre
    public const HTTP = 'http';
    public const NETWORK = 'network';
    public const CONFIG = 'config';
    public const API = 'api';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly string $endpoint = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct("API-Football {$kind} sur {$endpoint} : {$message}", 0, $previous);
    }
}
