<?php

namespace App\Console\Commands;

use App\Jobs\FetchMatchDataJob;
use App\Services\Api\ApiFootballException;
use App\Services\Api\ApiFootballService;
use Illuminate\Console\Command;

class TestApiFootball extends Command
{
    protected $signature = 'api-football:test
                            {--fixtures : Tester les matchs à venir (Ligue 1, aujourd\'hui et demain)}
                            {--status : Vérifier le quota API}
                            {--fixture-id= : Récupérer les données facultatives d\'un match (prédictions, blessures)}
                            {--date= : Récupérer les matchs d\'une date (YYYY-MM-DD), filtrés comme le pipeline}
                            {--all : Avec --date, ne pas filtrer par créneau horaire (comme pipeline:run-sync --all)}
                            {--search-team= : Rechercher une équipe par nom}';

    protected $description = 'Tester la connexion à API-Football et vérifier les endpoints';

    public function handle(ApiFootballService $api): int
    {
        try {
            return $this->dispatchTest($api);
        } catch (ApiFootballException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function dispatchTest(ApiFootballService $api): int
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

        $account = $status;

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

        // Le corps de /status retarde : quota retenu par le pipeline, source par source
        $usage = $api->getDailyUsage();
        $this->newLine();
        $this->table(['Quota du jour', 'Requêtes consommées'], [
            ['/status (corps)', $usage['status_current']],
            ['En-tête x-ratelimit-requests-remaining', $usage['header_current'] ?? 'absent'],
            ['Compteur local de ce serveur', $usage['local_current']],
            ['Retenu (le plus pessimiste)', "{$usage['current']} / {$usage['limit']}, {$usage['remaining']} restantes"],
        ]);

        return self::SUCCESS;
    }

    private function testFixtures(ApiFootballService $api): int
    {
        $this->info('Matchs à venir de Ligue 1 (ID: 61), aujourd\'hui et demain (2 requêtes)...');

        $fixtures = $api->getUpcomingFixtures(61);

        if (empty($fixtures)) {
            $this->warn('Aucun match de Ligue 1 à venir aujourd\'hui ni demain.');
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
        $this->info("Données facultatives du match #{$fixtureId} (2 requêtes)...");

        $data = $api->getOptionalMatchData($fixtureId);

        $this->table(['Donnée', 'Disponible'], [
            ['Blessures', count($data['injuries']) . ' joueur(s)'],
            ['Prédictions', $data['predictions'] ? 'Oui' : 'Non'],
        ]);

        if ($data['predictions']) {
            $pred = $data['predictions'];
            $this->newLine();
            $this->info('Prédictions API-Football :');
            $this->line("  Conseil : " . ($pred['predictions']['advice'] ?? '-'));

            if (isset($pred['predictions']['percent'])) {
                $pct = $pred['predictions']['percent'];
                $this->line("  Probabilités : Home={$pct['home']} Draw={$pct['draw']} Away={$pct['away']}");
            }
        }

        return self::SUCCESS;
    }

    private function testByDate(ApiFootballService $api, string $date): int
    {
        $this->info("Matchs du {$date}, filtrage du pipeline" . ($this->option('all') ? ' (--all : sans créneau horaire)' : '') . '...');

        $fixtures = $api->getFixturesByDate($date);

        if (empty($fixtures)) {
            $this->warn("Aucun match trouvé pour le {$date}.");
            return self::SUCCESS;
        }

        $selection = FetchMatchDataJob::selectFixtures($fixtures, null, (bool) $this->option('all'));
        $filtered = $selection['kept'];
        $this->line(count($fixtures) . ' match(s) renvoyé(s) par l\'API, ' . count($filtered) . ' gardé(s) par le pipeline.');
        $this->reportIgnored('Ligues suivies mais désactivées (api-football.inactive_leagues)', $selection['inactive']);
        $this->reportIgnored('Ligues suivies, matchs hors créneau horaire (pipeline.match_start_hour/match_end_hour)', $selection['out_of_hours']);
        $this->reportIgnored('Ligues non suivies (api-football.leagues)', $selection['untracked']);

        if (empty($filtered)) {
            $this->warn("Aucun match gardé par le pipeline pour le {$date}.");
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
        $this->info(count($rows) . " match(s) gardé(s) par le pipeline.");

        return self::SUCCESS;
    }

    /**
     * Toutes les ligues écartées pour un motif, avec le nombre de matchs de chacune.
     */
    private function reportIgnored(string $reason, array $fixtures): void
    {
        if (empty($fixtures)) {
            return;
        }

        $leagues = [];
        foreach ($fixtures as $f) {
            $id = $f['league']['id'] ?? 0;
            $leagues[$id] ??= ['name' => ($f['league']['name'] ?? '?') . ' (' . ($f['league']['country'] ?? '?') . ')', 'count' => 0];
            $leagues[$id]['count']++;
        }
        ksort($leagues);

        $this->newLine();
        $this->line("{$reason} : " . count($leagues) . ' ligue(s), ' . count($fixtures) . ' match(s)');
        foreach ($leagues as $id => $league) {
            $this->line(sprintf('  %5d  %s × %d', $id, $league['name'], $league['count']));
        }
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
