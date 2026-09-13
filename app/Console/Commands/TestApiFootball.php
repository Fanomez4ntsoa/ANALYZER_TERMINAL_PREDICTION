<?php

namespace App\Console\Commands;

use App\Services\Api\ApiFootballService;
use Illuminate\Console\Command;

class TestApiFootball extends Command
{
    protected $signature = 'api-football:test
                            {--fixtures : Tester les matchs à venir (Ligue 1)}
                            {--status : Vérifier le quota API}
                            {--fixture-id= : Récupérer les données complètes d\'un match}
                            {--date= : Récupérer les matchs d\'une date (YYYY-MM-DD)}
                            {--search-team= : Rechercher une équipe par nom}';

    protected $description = 'Tester la connexion à API-Football et vérifier les endpoints';

    public function handle(ApiFootballService $api): int
    {
        // Par défaut : vérifier le statut
        if (!$this->option('fixtures')
            && !$this->option('fixture-id')
            && !$this->option('date')
            && !$this->option('search-team')
        ) {
            return $this->testStatus($api);
        }

        if ($this->option('status')) {
            return $this->testStatus($api);
        }

        if ($this->option('fixtures')) {
            return $this->testFixtures($api);
        }

        if ($fixtureId = $this->option('fixture-id')) {
            return $this->testFullMatch($api, (int) $fixtureId);
        }

        if ($date = $this->option('date')) {
            return $this->testByDate($api, $date);
        }

        if ($teamName = $this->option('search-team')) {
            return $this->testSearchTeam($api, $teamName);
        }

        return self::SUCCESS;
    }

    private function testStatus(ApiFootballService $api): int
    {
        $this->info('Vérification du statut API-Football...');

        $status = $api->getAccountStatus();

        if (!$status) {
            $this->error('Impossible de contacter l\'API. Vérifiez API_FOOTBALL_KEY dans .env');
            return self::FAILURE;
        }

        $account = $status[0] ?? $status;

        $this->info('Connexion OK !');
        $this->table(
            ['Propriété', 'Valeur'],
            collect($account)->filter(fn($v) => !is_array($v))->map(fn($v, $k) => [$k, $v])->values()->toArray()
        );

        if (isset($account['requests'])) {
            $this->newLine();
            $this->table(
                ['Requêtes', 'Valeur'],
                collect($account['requests'])->map(fn($v, $k) => [$k, $v])->toArray()
            );
        }

        return self::SUCCESS;
    }

    private function testFixtures(ApiFootballService $api): int
    {
        $this->info('Récupération des 5 prochains matchs de Ligue 1 (ID: 61)...');

        $fixtures = $api->getUpcomingFixtures(61, next: 5);

        if (!$fixtures || empty($fixtures)) {
            $this->warn('Aucun match trouvé. La saison est peut-être terminée.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($fixtures as $f) {
            $rows[] = [
                $f['fixture']['id'],
                $f['fixture']['date'],
                $f['teams']['home']['name'],
                $f['teams']['away']['name'],
                $f['league']['round'] ?? '-',
            ];
        }

        $this->table(['ID', 'Date', 'Domicile', 'Extérieur', 'Journée'], $rows);

        return self::SUCCESS;
    }

    private function testFullMatch(ApiFootballService $api, int $fixtureId): int
    {
        $this->info("Récupération des données complètes pour le match #{$fixtureId}...");

        $data = $api->getFullMatchData($fixtureId);

        if (!$data) {
            $this->error("Match #{$fixtureId} introuvable.");
            return self::FAILURE;
        }

        $fixture = $data['fixture'];
        $this->info("Match : {$fixture['teams']['home']['name']} vs {$fixture['teams']['away']['name']}");
        $this->info("Date  : {$fixture['fixture']['date']}");
        $this->info("Ligue : {$fixture['league']['name']}");

        $this->newLine();
        $this->table(['Donnée', 'Disponible'], [
            ['H2H', $data['h2h'] ? count($data['h2h']) . ' matchs' : 'Non'],
            ['Stats domicile', $data['homeStats'] ? 'Oui' : 'Non'],
            ['Stats extérieur', $data['awayStats'] ? 'Oui' : 'Non'],
            ['Blessures', $data['injuries'] ? count($data['injuries']) . ' joueurs' : 'Non'],
            ['Prédictions', $data['predictions'] ? 'Oui' : 'Non'],
            ['Compositions', $data['lineups'] ? 'Oui' : 'Non'],
            ['Classement', $data['standings'] ? 'Oui' : 'Non'],
        ]);

        if ($data['predictions']) {
            $pred = $data['predictions'];
            $this->newLine();
            $this->info('Prédictions API-Football :');
            $this->line("  Conseil : " . ($pred['predictions']['advice'] ?? '-'));
            $this->line("  Gagnant : " . ($pred['predictions']['winner']['name'] ?? '-'));

            if (isset($pred['predictions']['percent'])) {
                $pct = $pred['predictions']['percent'];
                $this->line("  Probabilités : Home={$pct['home']} Draw={$pct['draw']} Away={$pct['away']}");
            }
        }

        return self::SUCCESS;
    }

    private function testByDate(ApiFootballService $api, string $date): int
    {
        $this->info("Matchs du {$date} (toutes ligues suivies)...");

        $fixtures = $api->getFixturesByDate($date);

        if (!$fixtures || empty($fixtures)) {
            $this->warn("Aucun match trouvé pour le {$date}.");
            return self::SUCCESS;
        }

        $trackedLeagues = config('api-football.leagues');
        $filtered = collect($fixtures)->filter(
            fn($f) => in_array($f['league']['id'], $trackedLeagues)
        );

        if ($filtered->isEmpty()) {
            $this->warn("Aucun match des ligues suivies pour le {$date}. ({$fixtures[0]['league']['name']} etc. ignorées)");
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($filtered as $f) {
            $rows[] = [
                $f['fixture']['id'],
                $f['league']['name'],
                $f['teams']['home']['name'],
                $f['teams']['away']['name'],
                $f['fixture']['status']['short'] ?? '-',
            ];
        }

        $this->table(['ID', 'Ligue', 'Domicile', 'Extérieur', 'Statut'], $rows);
        $this->info(count($rows) . " match(s) trouvé(s) dans les ligues suivies.");

        return self::SUCCESS;
    }

    private function testSearchTeam(ApiFootballService $api, string $name): int
    {
        $this->info("Recherche de l'équipe \"{$name}\"...");

        $results = $api->searchTeam($name);

        if (!$results || empty($results)) {
            $this->warn("Aucune équipe trouvée pour \"{$name}\".");
            return self::SUCCESS;
        }

        $rows = [];
        foreach (array_slice($results, 0, 10) as $team) {
            $rows[] = [
                $team['team']['id'],
                $team['team']['name'],
                $team['team']['country'],
                $team['team']['founded'] ?? '-',
            ];
        }

        $this->table(['ID', 'Nom', 'Pays', 'Fondé'], $rows);

        return self::SUCCESS;
    }
}
