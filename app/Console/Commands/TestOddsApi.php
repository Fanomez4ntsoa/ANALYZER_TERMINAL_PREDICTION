<?php

namespace App\Console\Commands;

use App\Services\Api\OddsApiService;
use Illuminate\Console\Command;

class TestOddsApi extends Command
{
    protected $signature = 'odds-api:test
                            {--sports : Lister les sports soccer disponibles}
                            {--events= : Lister les événements d\'une ligue (sport key)}
                            {--odds= : Récupérer les cotes d\'une ligue (sport key)}
                            {--match= : Trouver les cotes d\'un match (format: "Home vs Away|YYYY-MM-DD|leagueId")}
                            {--quota : Afficher le quota mensuel}';

    protected $description = 'Tester la connexion à The Odds API et vérifier les endpoints';

    public function handle(OddsApiService $api): int
    {
        if ($this->option('quota')) {
            return $this->showQuota($api);
        }

        if ($this->option('sports')) {
            return $this->testSports($api);
        }

        if ($sportKey = $this->option('events')) {
            return $this->testEvents($api, $sportKey);
        }

        if ($sportKey = $this->option('odds')) {
            return $this->testOdds($api, $sportKey);
        }

        if ($matchStr = $this->option('match')) {
            return $this->testMatchOdds($api, $matchStr);
        }

        // Par défaut : quota + sports
        $this->showQuota($api);
        $this->newLine();
        return $this->testSports($api);
    }

    private function showQuota(OddsApiService $api): int
    {
        $this->info('Quota The Odds API :');

        $usage = $api->getMonthlyUsage();

        $this->table(['Propriété', 'Valeur'], [
            ['Mois', $usage['month']],
            ['Utilisé', $usage['used']],
            ['Limite', $usage['limit']],
            ['Restant', $usage['remaining']],
            ['Seuil alerte', $usage['alert_threshold']],
        ]);

        if ($usage['used'] >= $usage['alert_threshold']) {
            $this->warn("ATTENTION : quota proche de la limite !");
        }

        return self::SUCCESS;
    }

    private function testSports(OddsApiService $api): int
    {
        $this->info('Sports soccer disponibles (endpoint gratuit) :');

        $sports = $api->getSoccerSports();

        if (!$sports || empty($sports)) {
            $this->error('Impossible de récupérer les sports. Vérifiez ODDS_API_KEY.');
            return self::FAILURE;
        }

        $mapping = $api->getLeagueMapping();
        $mappedKeys = array_values($mapping);

        $rows = [];
        foreach ($sports as $sport) {
            $key = $sport['key'];
            $tracked = in_array($key, $mappedKeys) ? 'OUI' : '';
            $active = ($sport['active'] ?? false) ? 'Actif' : 'Inactif';

            $rows[] = [$key, $sport['title'] ?? '-', $active, $tracked];
        }

        $this->table(['Sport Key', 'Titre', 'Statut', 'Suivi'], $rows);
        $this->info(count($sports) . ' sports soccer trouvés.');

        return self::SUCCESS;
    }

    private function testEvents(OddsApiService $api, string $sportKey): int
    {
        $this->info("Événements à venir pour {$sportKey} (endpoint gratuit) :");

        $events = $api->getEvents($sportKey);

        if (!$events || empty($events)) {
            $this->warn("Aucun événement trouvé pour {$sportKey}.");
            return self::SUCCESS;
        }

        $rows = [];
        foreach (array_slice($events, 0, 15) as $event) {
            $rows[] = [
                substr($event['id'], 0, 12) . '...',
                $event['commence_time'] ?? '-',
                $event['home_team'] ?? '-',
                $event['away_team'] ?? '-',
            ];
        }

        $this->table(['Event ID', 'Date', 'Domicile', 'Extérieur'], $rows);
        $this->info(count($events) . ' événement(s) total.');

        return self::SUCCESS;
    }

    private function testOdds(OddsApiService $api, string $sportKey): int
    {
        if ($api->isQuotaExhausted()) {
            $this->error('Quota mensuel épuisé ! Impossible de récupérer les cotes.');
            return self::FAILURE;
        }

        $this->info("Cotes pour {$sportKey} (consomme du quota) :");
        $this->warn("Cette requête coûte des crédits. Continuer ?");

        if (!$this->confirm('Confirmer ?')) {
            return self::SUCCESS;
        }

        $odds = $api->getOddsBySport($sportKey);

        if (!$odds || empty($odds)) {
            $this->warn("Aucune cote trouvée pour {$sportKey}.");
            return self::SUCCESS;
        }

        $rows = [];
        foreach (array_slice($odds, 0, 10) as $event) {
            $homeOdds = '-';
            $drawOdds = '-';
            $awayOdds = '-';
            $bookCount = count($event['bookmakers'] ?? []);

            // Prendre le premier bookmaker pour l'aperçu
            $firstBook = $event['bookmakers'][0] ?? null;
            if ($firstBook) {
                $h2h = collect($firstBook['markets'] ?? [])->firstWhere('key', 'h2h');
                if ($h2h) {
                    foreach ($h2h['outcomes'] as $o) {
                        if ($o['name'] === $event['home_team']) $homeOdds = $o['price'];
                        elseif ($o['name'] === 'Draw') $drawOdds = $o['price'];
                        else $awayOdds = $o['price'];
                    }
                }
            }

            $rows[] = [
                $event['home_team'] ?? '-',
                $event['away_team'] ?? '-',
                $homeOdds,
                $drawOdds,
                $awayOdds,
                $bookCount,
            ];
        }

        $this->table(['Domicile', 'Extérieur', '1', 'X', '2', 'Books'], $rows);
        $this->info(count($odds) . ' match(s) avec cotes.');

        $this->newLine();
        $this->showQuota($api);

        return self::SUCCESS;
    }

    private function testMatchOdds(OddsApiService $api, string $matchStr): int
    {
        // Format: "Home vs Away|YYYY-MM-DD|leagueId"
        $parts = explode('|', $matchStr);

        if (count($parts) !== 3) {
            $this->error('Format attendu : "Home vs Away|YYYY-MM-DD|leagueId"');
            $this->line('Exemple : "Monaco vs Marseille|2026-04-05|61"');
            return self::FAILURE;
        }

        [$teams, $date, $leagueId] = $parts;
        $teamsParts = explode(' vs ', $teams);

        if (count($teamsParts) !== 2) {
            $this->error('Format des équipes : "Home vs Away"');
            return self::FAILURE;
        }

        [$home, $away] = $teamsParts;
        $leagueId = (int) trim($leagueId);

        $this->info("Recherche des cotes : {$home} vs {$away} ({$date}, ligue #{$leagueId})");

        $odds = $api->findOddsForMatch(trim($home), trim($away), trim($date), $leagueId);

        if (!$odds) {
            $this->warn('Aucune cote trouvée pour ce match.');
            return self::SUCCESS;
        }

        $this->info("Match trouvé : {$odds['home_team']} vs {$odds['away_team']}");
        $this->newLine();

        $this->table(['Cote', 'Meilleure', 'Moyenne'], [
            ['1 (Home)', $odds['odds_home'], $odds['odds_avg']['home'] ?? '-'],
            ['X (Draw)', $odds['odds_draw'], $odds['odds_avg']['draw'] ?? '-'],
            ['2 (Away)', $odds['odds_away'], $odds['odds_avg']['away'] ?? '-'],
            ['Over 2.5', $odds['odds_over_2_5'] ?? '-', $odds['odds_avg']['over_2_5'] ?? '-'],
            ['Under 2.5', $odds['odds_under_2_5'] ?? '-', $odds['odds_avg']['under_2_5'] ?? '-'],
        ]);

        $this->info("{$odds['bookmaker_count']} bookmaker(s) comparés.");

        if (!empty($odds['bookmakers_detail'])) {
            $this->newLine();
            $this->info('Détail par bookmaker :');

            $rows = [];
            foreach ($odds['bookmakers_detail'] as $key => $detail) {
                $rows[] = [
                    $key,
                    $detail['home'] ?? '-',
                    $detail['draw'] ?? '-',
                    $detail['away'] ?? '-',
                    $detail['over_2_5'] ?? '-',
                    $detail['under_2_5'] ?? '-',
                ];
            }

            $this->table(['Bookmaker', '1', 'X', '2', 'O2.5', 'U2.5'], $rows);
        }

        return self::SUCCESS;
    }
}
