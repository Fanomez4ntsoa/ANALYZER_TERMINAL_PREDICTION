<?php

namespace App\Services\Context;

use App\Models\FootballMatch;
use App\Models\Referee;
use App\Services\Api\ApiFootballService;
use App\Services\Api\WeatherService;
use Illuminate\Support\Facades\Log;

/**
 * Enrichit le context_data avec 5 dimensions supplémentaires :
 *   1. Fatigue calendaire (matchs joués sur 7/14/21 jours)
 *   2. Enjeu précis (titre, relégation, qualification, rien à jouer)
 *   3. Météo (vent, pluie, température)
 *   4. Historique arbitre (cartons, penalties, style)
 *   5. Pression entraîneur (série de défaites)
 */
class ContextEnricherService
{
    private ApiFootballService $apiFootball;
    private WeatherService $weather;

    public function __construct(ApiFootballService $apiFootball, WeatherService $weather)
    {
        $this->apiFootball = $apiFootball;
        $this->weather = $weather;
    }

    /**
     * Enrichir le context_data d'un match avec toutes les dimensions.
     * Retourne le context_data enrichi (à merger dans advanced_data).
     */
    public function enrich(FootballMatch $match, ?array $fullMatchData = null): array
    {
        $existingContext = $match->advancedData?->context_data ?? [];
        $enriched = $existingContext;

        // 1. Fatigue calendaire
        $enriched['fatigue'] = $this->analyzeFatigue($match, $fullMatchData);

        // 2. Enjeu précis du match
        $enriched['stakes'] = $this->analyzeStakes($match, $fullMatchData);

        // 3. Météo
        $enriched['weather'] = $this->analyzeWeather($match);

        // 4. Historique arbitre
        $enriched['referee'] = $this->analyzeReferee($match, $fullMatchData);

        // 5. Pression entraîneur
        $enriched['coachPressure'] = $this->analyzeCoachPressure($match, $fullMatchData);

        // Mettre à jour l'importance si les enjeux l'exigent
        $enriched['importance'] = $this->resolveImportance($enriched);

        // Horodatage de la collecte : permet de distinguer une donnée collectée
        // avant le coup d'envoi d'une donnée collectée rétroactivement.
        $enriched['collected_at'] = now()->toIso8601String();

        Log::info("ContextEnricher: enrichi pour {$match->full_name}", [
            'dimensions' => array_keys(array_filter([
                'fatigue' => $enriched['fatigue']['available'] ?? false,
                'stakes' => $enriched['stakes']['available'] ?? false,
                'weather' => ($enriched['weather']['condition'] ?? 'unknown') !== 'unknown',
                'referee' => $enriched['referee']['available'] ?? false,
                'coachPressure' => $enriched['coachPressure']['available'] ?? false,
            ])),
        ]);

        return $enriched;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 1. FATIGUE CALENDAIRE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculer la charge calendaire depuis les fixtures passées.
     * Utilise les matchs joués sur les 7/14/21 derniers jours.
     */
    private function analyzeFatigue(FootballMatch $match, ?array $fullMatchData): array
    {
        $homeTeamId = $match->home_team_id;
        $awayTeamId = $match->away_team_id;

        if (!$homeTeamId || !$awayTeamId) {
            return ['available' => false, 'reason' => 'Pas de team IDs'];
        }

        $matchDate = $match->match_date;

        // Compter les matchs récents depuis la DB (matchs API déjà importés)
        $homeFatigue = $this->countRecentMatches($homeTeamId, $matchDate);
        $awayFatigue = $this->countRecentMatches($awayTeamId, $matchDate);

        // Score de fatigue (0 = frais, 100 = épuisé)
        $homeScore = $this->fatigueScore($homeFatigue);
        $awayScore = $this->fatigueScore($awayFatigue);

        // Détecter les matchs européens (compétitions ID 2, 3, 848)
        $euroCompIds = [2, 3, 848];
        $homeEuropean = FootballMatch::where('data_source', 'api')
            ->where(function ($q) use ($homeTeamId) {
                $q->where('home_team_id', $homeTeamId)->orWhere('away_team_id', $homeTeamId);
            })
            ->whereIn('league_id', $euroCompIds)
            ->where('match_date', '>=', $matchDate->copy()->subDays(14))
            ->where('match_date', '<', $matchDate)
            ->exists();

        $awayEuropean = FootballMatch::where('data_source', 'api')
            ->where(function ($q) use ($awayTeamId) {
                $q->where('home_team_id', $awayTeamId)->orWhere('away_team_id', $awayTeamId);
            })
            ->whereIn('league_id', $euroCompIds)
            ->where('match_date', '>=', $matchDate->copy()->subDays(14))
            ->where('match_date', '<', $matchDate)
            ->exists();

        // Surcharge européenne
        if ($homeEuropean) $homeScore += 15;
        if ($awayEuropean) $awayScore += 15;

        return [
            'available' => true,
            'home' => [
                'matches_7d' => $homeFatigue['7d'],
                'matches_14d' => $homeFatigue['14d'],
                'matches_21d' => $homeFatigue['21d'],
                'european' => $homeEuropean,
                'fatigue_score' => min(100, $homeScore),
            ],
            'away' => [
                'matches_7d' => $awayFatigue['7d'],
                'matches_14d' => $awayFatigue['14d'],
                'matches_21d' => $awayFatigue['21d'],
                'european' => $awayEuropean,
                'fatigue_score' => min(100, $awayScore),
            ],
            'advantage' => $awayScore - $homeScore, // Positif = domicile plus frais
        ];
    }

    private function countRecentMatches(int $teamId, $beforeDate): array
    {
        $query = fn($days) => FootballMatch::where('data_source', 'api')
            ->where(function ($q) use ($teamId) {
                $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->where('match_date', '>=', $beforeDate->copy()->subDays($days))
            ->where('match_date', '<', $beforeDate)
            ->count();

        return [
            '7d' => $query(7),
            '14d' => $query(14),
            '21d' => $query(21),
        ];
    }

    private function fatigueScore(array $counts): int
    {
        $score = 0;

        // 7 jours : 2+ matchs = charge haute
        if ($counts['7d'] >= 3) $score += 40;
        elseif ($counts['7d'] >= 2) $score += 25;
        elseif ($counts['7d'] >= 1) $score += 10;

        // 14 jours : 4+ matchs = surcharge
        if ($counts['14d'] >= 5) $score += 30;
        elseif ($counts['14d'] >= 4) $score += 20;
        elseif ($counts['14d'] >= 3) $score += 10;

        // 21 jours : 7+ matchs = calendrier saturé
        if ($counts['21d'] >= 7) $score += 20;
        elseif ($counts['21d'] >= 6) $score += 10;

        return $score;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 2. ENJEU PRÉCIS DU MATCH
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Détecter l'enjeu précis depuis le classement.
     */
    private function analyzeStakes(FootballMatch $match, ?array $fullMatchData): array
    {
        $standings = $fullMatchData['standings'] ?? null;

        if (!$standings) {
            // Essayer depuis le fbref_data en DB
            $fbref = $match->advancedData?->fbref_data ?? null;
            if ($fbref) {
                $homeStanding = $fbref['league']['home'] ?? null;
                $awayStanding = $fbref['league']['away'] ?? null;
            } else {
                return ['available' => false, 'reason' => 'Pas de classement'];
            }
        } else {
            $homeStanding = null;
            $awayStanding = null;
            foreach ($standings as $entry) {
                $teamId = $entry['team']['id'] ?? null;
                $data = [
                    'rank' => $entry['rank'] ?? null,
                    'points' => $entry['points'] ?? null,
                    'played' => $entry['all']['played'] ?? null,
                ];
                if ($teamId === $match->home_team_id) $homeStanding = $data;
                elseif ($teamId === $match->away_team_id) $awayStanding = $data;
            }
        }

        $totalTeams = is_array($standings) ? count($standings) : 20;
        $homeStake = $this->classifyStake($homeStanding, $totalTeams);
        $awayStake = $this->classifyStake($awayStanding, $totalTeams);

        return [
            'available' => true,
            'home' => $homeStake,
            'away' => $awayStake,
            'motivation_delta' => $homeStake['motivation'] - $awayStake['motivation'],
        ];
    }

    private function classifyStake(?array $standing, int $totalTeams): array
    {
        if (!$standing || !isset($standing['rank'])) {
            return ['stake' => 'unknown', 'motivation' => 50, 'rank' => null];
        }

        $rank = (int) $standing['rank'];
        $relegationZone = $totalTeams - 3; // 3 derniers
        $euroZone = min(6, (int) ($totalTeams * 0.3)); // Top ~30%

        if ($rank <= 1) {
            return ['stake' => 'title', 'motivation' => 95, 'rank' => $rank,
                'description' => 'Course au titre'];
        }
        if ($rank <= 3) {
            return ['stake' => 'champions_league', 'motivation' => 85, 'rank' => $rank,
                'description' => 'Place en Champions League'];
        }
        if ($rank <= $euroZone) {
            return ['stake' => 'european', 'motivation' => 75, 'rank' => $rank,
                'description' => 'Qualification europeenne'];
        }
        if ($rank >= $relegationZone) {
            return ['stake' => 'relegation', 'motivation' => 90, 'rank' => $rank,
                'description' => 'Lutte pour le maintien'];
        }
        if ($rank >= $relegationZone - 2) {
            return ['stake' => 'relegation_danger', 'motivation' => 80, 'rank' => $rank,
                'description' => 'Barragiste / danger relegation'];
        }

        // Milieu de tableau
        return ['stake' => 'mid_table', 'motivation' => 55, 'rank' => $rank,
            'description' => 'Milieu de tableau - enjeu limite'];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 3. MÉTÉO
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function analyzeWeather(FootballMatch $match): array
    {
        // Mapping ville depuis le nom d'équipe domicile (simplifié)
        $city = $this->guessCity($match->home_team);
        $country = $this->guessCountry($match->competition);

        if (!$city || !$country) {
            return $this->weather->analyzeImpact(null);
        }

        $raw = $this->weather->getWeatherForMatch($city, $country, $match->match_date->toIso8601String());

        return $this->weather->analyzeImpact($raw);
    }

    /**
     * Deviner la ville depuis le nom de l'équipe domicile.
     */
    private function guessCity(string $teamName): ?string
    {
        // Mapping direct pour les noms courants
        $mapping = [
            'Paris Saint Germain' => 'Paris', 'PSG' => 'Paris',
            'Olympique de Marseille' => 'Marseille', 'Marseille' => 'Marseille',
            'Olympique Lyonnais' => 'Lyon', 'Lyon' => 'Lyon',
            'AS Monaco' => 'Monaco', 'Monaco' => 'Monaco',
            'LOSC Lille' => 'Lille', 'Lille' => 'Lille',
            'Stade Rennais' => 'Rennes', 'Rennes' => 'Rennes',
            'RC Lens' => 'Lens', 'Lens' => 'Lens',
            'OGC Nice' => 'Nice', 'Nice' => 'Nice',
            'RC Strasbourg' => 'Strasbourg', 'Strasbourg' => 'Strasbourg',
            'Stade Brestois' => 'Brest', 'Brest' => 'Brest',
            'FC Nantes' => 'Nantes', 'Nantes' => 'Nantes',
            'Toulouse' => 'Toulouse', 'Montpellier' => 'Montpellier',
            'Angers' => 'Angers', 'Le Havre' => 'Le Havre',
            'Metz' => 'Metz', 'Lorient' => 'Lorient', 'Auxerre' => 'Auxerre',
            'Inter' => 'Milan', 'AC Milan' => 'Milan', 'AS Roma' => 'Rome',
            'Juventus' => 'Turin', 'Torino' => 'Turin', 'Napoli' => 'Naples',
            'Lazio' => 'Rome', 'Fiorentina' => 'Florence', 'Bologna' => 'Bologna',
            'Atalanta' => 'Bergamo', 'Genoa' => 'Genoa', 'Cremonese' => 'Cremona',
            'Pisa' => 'Pisa',
            'Real Madrid' => 'Madrid', 'Atletico Madrid' => 'Madrid',
            'Barcelona' => 'Barcelona', 'Valencia' => 'Valencia',
            'Sevilla' => 'Seville', 'Real Betis' => 'Seville',
            'Real Sociedad' => 'San Sebastian', 'Athletic Club' => 'Bilbao',
            'Villarreal' => 'Villarreal', 'Celta Vigo' => 'Vigo',
            'Getafe' => 'Madrid', 'Alaves' => 'Vitoria', 'Osasuna' => 'Pamplona',
            'Oviedo' => 'Oviedo',
            'Bayern Munich' => 'Munich', 'Borussia Dortmund' => 'Dortmund',
            'RB Leipzig' => 'Leipzig', 'Bayer Leverkusen' => 'Leverkusen',
            'Eintracht Frankfurt' => 'Frankfurt', 'Union Berlin' => 'Berlin',
            'Wolfsburg' => 'Wolfsburg', 'Freiburg' => 'Freiburg',
            'FC St. Pauli' => 'Hamburg', '1. FC Köln' => 'Cologne',
            'Manchester City' => 'Manchester', 'Manchester United' => 'Manchester',
            'Liverpool' => 'Liverpool', 'Arsenal' => 'London',
            'Chelsea' => 'London', 'Tottenham' => 'London',
            'Newcastle' => 'Newcastle', 'Aston Villa' => 'Birmingham',
            'West Ham' => 'London', 'Brighton' => 'Brighton',
        ];

        // Match exact
        if (isset($mapping[$teamName])) {
            return $mapping[$teamName];
        }

        // Match partiel
        foreach ($mapping as $key => $city) {
            if (str_contains($teamName, $key) || str_contains($key, $teamName)) {
                return $city;
            }
        }

        // Fallback : utiliser le nom de l'équipe comme ville
        return preg_replace('/\b(FC|CF|SC|AC|AS|US|RC|OG|SSC|AFC|BSC)\b/i', '', $teamName)
            ? trim(preg_replace('/\b(FC|CF|SC|AC|AS|US|RC|OG|SSC|AFC|BSC)\b/i', '', $teamName))
            : null;
    }

    private function guessCountry(string $competition): ?string
    {
        $mapping = [
            'Ligue 1' => 'FR', 'Ligue 2' => 'FR',
            'Premier League' => 'GB', 'Championship' => 'GB',
            'La Liga' => 'ES', 'Segunda' => 'ES',
            'Serie A' => 'IT', 'Serie B' => 'IT',
            'Bundesliga' => 'DE', '2. Bundesliga' => 'DE',
        ];

        foreach ($mapping as $key => $code) {
            if (str_contains($competition, $key)) {
                return $code;
            }
        }

        return null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 4. HISTORIQUE ARBITRE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function analyzeReferee(FootballMatch $match, ?array $fullMatchData): array
    {
        // Chercher l'arbitre dans la fixture API-Football
        $refereeName = null;

        $fixture = $fullMatchData['fixture'] ?? null;
        if ($fixture) {
            $refereeName = $fixture['fixture']['referee'] ?? null;
        }

        if (!$refereeName) {
            return ['available' => false, 'reason' => 'Arbitre non assigne'];
        }

        // Nettoyer le nom (API-Football retourne "J. Smith, England")
        $refereeName = explode(',', $refereeName)[0];
        $refereeName = trim($refereeName);

        // Chercher ou créer dans la DB
        $referee = Referee::where('name', 'like', "%{$refereeName}%")->first();

        if (!$referee) {
            // Créer avec des stats par défaut (sera enrichi via commande dédiée)
            $referee = Referee::create([
                'name' => $refereeName,
                'games_officiated' => 0,
                'yellow_per_game' => 4.0, // Moyenne européenne
                'red_per_game' => 0.15,
                'penalties_per_game' => 0.25,
                'fouls_per_game' => 24.0,
                'style' => 'moderate',
            ]);
        }

        return [
            'available' => true,
            'name' => $referee->name,
            'style' => $referee->style,
            'yellow_per_game' => (float) $referee->yellow_per_game,
            'red_per_game' => (float) $referee->red_per_game,
            'penalties_per_game' => (float) $referee->penalties_per_game,
            'games_officiated' => $referee->games_officiated,
            'impact' => $this->refereeImpact($referee),
        ];
    }

    private function refereeImpact(Referee $referee): array
    {
        $cards = (float) $referee->yellow_per_game;
        $pens = (float) $referee->penalties_per_game;

        return [
            'cards_modifier' => $cards > 5 ? 'high' : ($cards < 3 ? 'low' : 'normal'),
            'penalty_modifier' => $pens > 0.35 ? 'high' : ($pens < 0.15 ? 'low' : 'normal'),
            'over_modifier' => $pens > 0.35 ? 3 : 0, // +3% Over si arbitre donne beaucoup de pens
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // 5. PRESSION ENTRAÎNEUR
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Détecter la pression entraîneur via la série de résultats récents.
     */
    private function analyzeCoachPressure(FootballMatch $match, ?array $fullMatchData): array
    {
        // Utiliser la forme récente (sofascore_data.recentForm)
        $form = $match->advancedData?->sofascore_data['recentForm'] ?? null;

        if (!$form) {
            return ['available' => false, 'reason' => 'Pas de forme recente'];
        }

        $homeForm = $form['home'] ?? [];
        $awayForm = $form['away'] ?? [];

        $homePressure = $this->calculatePressure($homeForm);
        $awayPressure = $this->calculatePressure($awayForm);

        return [
            'available' => true,
            'home' => $homePressure,
            'away' => $awayPressure,
        ];
    }

    private function calculatePressure(array $results): array
    {
        if (empty($results)) {
            return ['score' => 0, 'style_impact' => 'neutral', 'description' => 'Pas de donnees'];
        }

        // Compter les défaites consécutives depuis le dernier match
        $consecutiveLosses = 0;
        foreach (array_reverse($results) as $r) {
            if ($r === 'L') $consecutiveLosses++;
            else break;
        }

        // Compter les résultats récents
        $last5 = array_slice($results, -5);
        $losses = count(array_filter($last5, fn($r) => $r === 'L'));
        $wins = count(array_filter($last5, fn($r) => $r === 'W'));

        // Score de pression (0 = serein, 100 = siège éjectable)
        $score = 0;

        if ($consecutiveLosses >= 5) $score += 60;
        elseif ($consecutiveLosses >= 4) $score += 45;
        elseif ($consecutiveLosses >= 3) $score += 30;
        elseif ($consecutiveLosses >= 2) $score += 15;

        if ($losses >= 4) $score += 25;
        elseif ($losses >= 3) $score += 15;

        if ($wins === 0 && count($last5) >= 5) $score += 15;

        $score = min(100, $score);

        // Impact sur le style de jeu
        if ($score >= 60) {
            $styleImpact = 'desperate'; // All-in ou ultra-défensif
            $description = "Pression extreme ({$consecutiveLosses} defaites consecutives)";
        } elseif ($score >= 30) {
            $styleImpact = 'cautious'; // Plus prudent
            $description = "Sous pression ({$losses}/5 defaites)";
        } else {
            $styleImpact = 'neutral';
            $description = "Serein ({$wins}/5 victoires)";
        }

        return [
            'score' => $score,
            'consecutive_losses' => $consecutiveLosses,
            'last5_losses' => $losses,
            'last5_wins' => $wins,
            'style_impact' => $styleImpact,
            'description' => $description,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RÉSOLUTION D'IMPORTANCE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Résoudre le niveau d'importance final du match en combinant enjeux + pression.
     */
    private function resolveImportance(array $enriched): string
    {
        $stakes = $enriched['stakes'] ?? [];
        $pressure = $enriched['coachPressure'] ?? [];

        $homeStake = $stakes['home']['stake'] ?? 'unknown';
        $awayStake = $stakes['away']['stake'] ?? 'unknown';

        // Enjeu critique
        if (in_array('title', [$homeStake, $awayStake]) ||
            in_array('relegation', [$homeStake, $awayStake])) {
            return 'critical';
        }

        // Enjeu haut
        if (in_array('champions_league', [$homeStake, $awayStake]) ||
            in_array('relegation_danger', [$homeStake, $awayStake])) {
            return 'high';
        }

        // Pression entraîneur haute
        $homePressure = $pressure['home']['score'] ?? 0;
        $awayPressure = $pressure['away']['score'] ?? 0;
        if ($homePressure >= 50 || $awayPressure >= 50) {
            return 'high';
        }

        // Qualification européenne
        if (in_array('european', [$homeStake, $awayStake])) {
            return 'medium';
        }

        // Milieu de tableau / rien à jouer
        if ($homeStake === 'mid_table' && $awayStake === 'mid_table') {
            return 'low';
        }

        return $enriched['importance'] ?? 'medium';
    }
}
