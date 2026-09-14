<?php

namespace App\Services\DataPipeline;

use Illuminate\Support\Facades\Log;

/**
 * Journal des exceptions interceptées dans le pipeline.
 *
 * Tout try/catch du pipeline qui continue après une exception passe par ici :
 * avertissement dans le canal `pipeline` avec la classe, le message et
 * l'emplacement. Un échec avalé ne doit jamais rester invisible (FormationProfiles
 * a bloqué les cotes pendant des mois sans que rien ne le signale).
 */
class PipelineLog
{
    public static function caught(string $context, \Throwable $e, array $extra = []): void
    {
        Log::channel('pipeline')->warning("{$context} : " . get_class($e) . ' — ' . $e->getMessage(), $extra + [
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}
