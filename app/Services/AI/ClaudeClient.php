<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP pour l'API Anthropic Claude.
 * Utilisé par tous les agents IA.
 */
class ClaudeClient
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;

    public function __construct()
    {
        $this->apiKey = env('ANTHROPIC_API_KEY', '');
        $this->model = env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514');
        $this->maxTokens = 1000;
    }

    /**
     * Envoyer un prompt à Claude et récupérer la réponse JSON.
     *
     * @param string      $systemPrompt Le rôle de l'agent
     * @param string      $userMessage  Les données du match
     * @param int         $maxTokens    Override max tokens si besoin
     * @param string|null $model        Override modèle (null = défaut env ANTHROPIC_MODEL)
     * @return array ['content' => array|null, 'input_tokens' => int, 'output_tokens' => int, 'error' => string|null]
     */
    public function ask(string $systemPrompt, string $userMessage, int $maxTokens = 0, ?string $model = null): array
    {
        if (empty($this->apiKey)) {
            return ['content' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'error' => 'ANTHROPIC_API_KEY non configuree'];
        }

        $tokens = $maxTokens > 0 ? $maxTokens : $this->maxTokens;
        $modelToUse = $model ?: $this->model;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $modelToUse,
                    'max_tokens' => $tokens,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            if ($response->failed()) {
                $error = $response->json('error.message') ?? "HTTP {$response->status()}";
                Log::error("ClaudeClient: erreur API", ['status' => $response->status(), 'error' => $error]);
                return ['content' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'error' => $error];
            }

            $data = $response->json();
            $text = $data['content'][0]['text'] ?? '';
            $inputTokens = $data['usage']['input_tokens'] ?? 0;
            $outputTokens = $data['usage']['output_tokens'] ?? 0;

            // Extraire le JSON de la réponse (Claude peut entourer de ```json...```)
            $parsed = $this->extractJson($text);

            return [
                'content' => $parsed,
                'raw' => $text,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'error' => $parsed === null ? 'JSON invalide dans la reponse' : null,
            ];

        } catch (\Exception $e) {
            Log::error("ClaudeClient: exception", ['error' => $e->getMessage()]);
            return ['content' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extraire un objet JSON d'une réponse texte Claude.
     */
    private function extractJson(string $text): ?array
    {
        // Tenter un décodage direct
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Chercher un bloc ```json ... ```
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Chercher le premier { ... } dans le texte
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}
