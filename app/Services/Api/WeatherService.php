<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service météo via OpenWeatherMap.
 *
 * Impact sur les marchés :
 * - Vent > 40 km/h → réduit Over 2.5 (jeu long difficile)
 * - Pluie forte / neige → favorise équipes physiques, réduit technique
 * - Chaleur > 32°C → fatigue accrue, plus de buts en 2e mi-temps
 * - Froid < 2°C → jeu plus direct, moins de buts
 */
class WeatherService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('app.openweathermap_base_url', 'https://api.openweathermap.org/data/2.5');
        $this->apiKey = env('OPENWEATHERMAP_KEY', '');
    }

    /**
     * Récupérer la météo pour une ville à une date donnée.
     * Utilise /forecast pour les matchs à venir (jusqu'à 5 jours).
     */
    public function getWeatherForMatch(string $city, string $country, string $matchDate): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $cacheKey = "weather_{$city}_{$country}_{$matchDate}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($city, $country, $matchDate) {
            return $this->fetchForecast($city, $country, $matchDate);
        });
    }

    /**
     * Analyser l'impact météo sur le match.
     *
     * @return array Impact normalisé pour le ContextAnalyzer
     */
    public function analyzeImpact(?array $weather): array
    {
        if (!$weather) {
            return [
                'condition' => 'unknown',
                'temperature' => null,
                'wind_speed' => null,
                'rain' => false,
                'impact' => 'none',
                'over_modifier' => 0,
                'btts_modifier' => 0,
                'description' => 'Météo inconnue',
            ];
        }

        $temp = $weather['temperature'] ?? 20;
        $wind = $weather['wind_speed'] ?? 0; // km/h
        $rain = $weather['rain'] ?? false;
        $snow = $weather['snow'] ?? false;
        $condition = $weather['condition'] ?? 'Clear';

        $overMod = 0;
        $bttsMod = 0;
        $impact = 'none';
        $descriptions = [];

        // Vent fort
        if ($wind > 50) {
            $overMod -= 15;
            $bttsMod -= 10;
            $impact = 'significant';
            $descriptions[] = "Vent tres fort ({$wind} km/h) - jeu aerien tres difficile";
        } elseif ($wind > 40) {
            $overMod -= 10;
            $bttsMod -= 5;
            $impact = 'minor';
            $descriptions[] = "Vent fort ({$wind} km/h) - jeu long perturbe";
        } elseif ($wind > 30) {
            $overMod -= 5;
            $descriptions[] = "Vent modere ({$wind} km/h)";
        }

        // Pluie
        if ($rain || str_contains(strtolower($condition), 'rain')) {
            $overMod -= 5;
            $impact = max($impact, 'minor');
            $descriptions[] = "Pluie - terrain lourd, technique reduite";
        }

        // Neige
        if ($snow || str_contains(strtolower($condition), 'snow')) {
            $overMod -= 10;
            $bttsMod -= 8;
            $impact = 'significant';
            $descriptions[] = "Neige - conditions extremes";
        }

        // Chaleur extreme
        if ($temp > 35) {
            $overMod += 5; // Plus de buts en 2e MT (fatigue)
            $impact = max($impact, 'minor');
            $descriptions[] = "Chaleur extreme ({$temp}C) - fatigue 2e MT";
        } elseif ($temp > 32) {
            $descriptions[] = "Chaleur ({$temp}C)";
        }

        // Froid extreme
        if ($temp < 0) {
            $overMod -= 8;
            $impact = max($impact, 'minor');
            $descriptions[] = "Gel ({$temp}C) - jeu direct";
        } elseif ($temp < 2) {
            $overMod -= 3;
            $descriptions[] = "Froid ({$temp}C)";
        }

        return [
            'condition' => $condition,
            'temperature' => $temp,
            'wind_speed' => $wind,
            'rain' => $rain || str_contains(strtolower($condition), 'rain'),
            'snow' => $snow || str_contains(strtolower($condition), 'snow'),
            'impact' => $impact,
            'over_modifier' => $overMod,
            'btts_modifier' => $bttsMod,
            'description' => implode(' | ', $descriptions) ?: 'Conditions normales',
        ];
    }

    /**
     * Récupérer les prévisions météo et trouver celle la plus proche de la date du match.
     */
    private function fetchForecast(string $city, string $country, string $matchDate): ?array
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/forecast", [
                'q' => "{$city},{$country}",
                'appid' => $this->apiKey,
                'units' => 'metric',
            ]);

            if ($response->failed()) {
                Log::warning("Weather: erreur HTTP {$response->status()}", ['city' => $city]);
                return null;
            }

            $data = $response->json();
            $forecasts = $data['list'] ?? [];

            if (empty($forecasts)) {
                return null;
            }

            // Trouver la prévision la plus proche de l'heure du match
            $targetTimestamp = strtotime($matchDate);
            $closest = null;
            $closestDiff = PHP_INT_MAX;

            foreach ($forecasts as $forecast) {
                $diff = abs($forecast['dt'] - $targetTimestamp);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closest = $forecast;
                }
            }

            if (!$closest) {
                return null;
            }

            return [
                'temperature' => round($closest['main']['temp'] ?? 20),
                'feels_like' => round($closest['main']['feels_like'] ?? 20),
                'humidity' => $closest['main']['humidity'] ?? 50,
                'wind_speed' => round(($closest['wind']['speed'] ?? 0) * 3.6), // m/s → km/h
                'condition' => $closest['weather'][0]['main'] ?? 'Clear',
                'description' => $closest['weather'][0]['description'] ?? '',
                'rain' => isset($closest['rain']),
                'snow' => isset($closest['snow']),
            ];

        } catch (\Exception $e) {
            Log::warning("Weather: exception", ['city' => $city, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
