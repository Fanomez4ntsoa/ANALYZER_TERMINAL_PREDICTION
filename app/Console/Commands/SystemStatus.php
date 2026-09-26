<?php

namespace App\Console\Commands;

use App\Models\BacktestFdRun;
use App\Models\PredictionLogEntry;
use App\Services\Api\ApiFootballService;
use App\Services\Api\OddsApiService;
use App\Services\DataPipeline\DatabaseBackup;
use App\Services\DataPipeline\PipelineGaps;
use App\Services\DataPipeline\TimeZoneCheck;
use App\Support\Terminal\PipelineFreshness;
use App\Support\Terminal\SystemState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * État du système en une dizaine de lignes, pour un contrôle par SSH sur le VPS.
 *
 * Une ligne par voyant, préfixée OK, ALERTE ou CRITIQUE. Code de sortie non nul dès
 * qu'un voyant n'est pas OK : utilisable tel quel par une surveillance externe.
 * Deux appels réseau gratuits (API-Football /status, The Odds API /sports) ;
 * --offline les saute.
 */
class SystemStatus extends Command
{
    protected $signature = 'system:status
                            {--offline : Ne pas interroger les API (quotas non affichés)}';

    protected $description = 'État du système en dix lignes : passage, planificateur, jours manqués, journal, quotas, sauvegarde';

    private const HEARTBEAT_MAX_MINUTES = 10;
    private const BACKUP_MAX_HOURS = 26;
    private const RECENT_GAP_DAYS = 7;
    private const PENDING_MAX_DAYS = 2;

    /** @var list<array{int, string, string}> */
    private array $lines = [];

    public function handle(): int
    {
        $enabled = config('pipeline.enabled') === true;

        $this->collection($enabled);
        $this->heartbeat($enabled);
        $this->pipeline();
        $this->gaps();
        $this->journal();
        $this->pending();
        if ($this->option('offline')) {
            $this->add(SystemState::NOTICE, 'Quotas', 'non lus (--offline)');
        } else {
            $this->apiFootball();
            $this->oddsApi();
        }
        $this->backup($enabled);
        $this->reference();
        $this->timeZones();

        // Machine qui ne collecte pas (copie de lecture) : rien n'y est attendu,
        // aucun voyant n'y est une panne. Sauf des heures corrompues : fausses partout.
        if (!$enabled) {
            $this->lines = array_map(fn ($l) => $l[1] === 'Fuseaux' ? $l : [min($l[0], SystemState::NOTICE), $l[1], $l[2]], $this->lines);
        }

        $worst = max(array_map(fn ($l) => $l[0], $this->lines) ?: [0]);
        $verdict = match (true) {
            $worst >= SystemState::CRITICAL => 'CRITIQUE',
            $worst >= SystemState::WARNING => 'ALERTE',
            default => 'OK',
        };

        $this->line(sprintf('football-analyzer · %s · %s UTC · %s', gethostname() ?: '?', now('UTC')->format('Y-m-d H:i'), $verdict));
        foreach ($this->lines as [$severity, $label, $message]) {
            $level = match (true) {
                $severity >= SystemState::CRITICAL => 'CRITIQUE',
                $severity >= SystemState::WARNING => 'ALERTE',
                !$enabled => 'INFO',
                default => 'OK',
            };
            // Remplissage en caractères, pas en octets (accents)
            $this->line(str_pad($level, 9) . ' ' . $label . str_repeat(' ', max(1, 15 - mb_strlen($label))) . $message);
        }

        return $worst >= SystemState::WARNING ? self::FAILURE : self::SUCCESS;
    }

    private function add(int $severity, string $label, string $message): void
    {
        $this->lines[] = [$severity, $label, $message];
    }

    /** Quelle machine fait foi : un coup d'œil doit suffire. */
    private function collection(bool $enabled): void
    {
        $host = gethostname() ?: '?';
        $this->add(SystemState::NOTICE, 'Collecte', $enabled
            ? "ACTIVE sur cette machine ({$host}) : c'est la base de référence"
            : "désactivée sur cette machine ({$host}), copie de lecture ; la base de référence est " . config('pipeline.reference_host'));
    }

    /** Sans battement récent, la crontab ne tourne pas : ni passage, ni clôture. */
    private function heartbeat(bool $enabled): void
    {
        $path = config('pipeline.heartbeat_path');
        $last = is_file($path) ? Carbon::parse(trim((string) file_get_contents($path))) : null;

        if ($last === null) {
            $this->add($enabled ? SystemState::CRITICAL : SystemState::NOTICE, 'Planificateur', 'aucun battement enregistré : crontab absente ?');

            return;
        }

        $minutes = (int) $last->diffInMinutes(now(), true);
        $this->add(
            $enabled && $minutes > self::HEARTBEAT_MAX_MINUTES ? SystemState::CRITICAL : SystemState::NOTICE,
            'Planificateur',
            "dernier battement il y a {$minutes} min ({$last->utc()->format('Y-m-d H:i')} UTC)" . ($minutes > self::HEARTBEAT_MAX_MINUTES ? ' : crontab arrêtée ?' : ''),
        );
    }

    private function pipeline(): void
    {
        $freshness = app(PipelineFreshness::class)->current();
        $latest = $freshness['latest'];
        $lastSuccess = $freshness['lastSuccess'];

        $message = $lastSuccess === null
            ? 'aucun passage réussi'
            : 'dernier réussi : ' . $lastSuccess->run_date->format('Y-m-d') . ' (fini ' . ($lastSuccess->finished_at?->utc()->format('d/m H:i') ?? '?') . ' UTC)';
        if ($latest !== null && $latest->isNot($lastSuccess)) {
            $message .= ' · dernier : ' . $latest->run_date->format('Y-m-d') . ' ' . $latest->status;
        }

        $states = $freshness['states'];
        usort($states, fn (SystemState $a, SystemState $b) => $b->severity <=> $a->severity);
        if ($states !== []) {
            $message .= ' · ' . $states[0]->message;
        }

        $this->add($states[0]->severity ?? SystemState::NOTICE, 'Pipeline', $message);
    }

    private function gaps(): void
    {
        $gaps = app(PipelineGaps::class)->compute();
        $days = array_merge($gaps['missing'], $gaps['failed']);
        sort($days);

        if ($gaps['since'] === null) {
            $this->add(SystemState::NOTICE, 'Jours manqués', 'journal vide');

            return;
        }
        if ($days === []) {
            $this->add(SystemState::NOTICE, 'Jours manqués', "aucun depuis le {$gaps['since']}");

            return;
        }

        $recent = array_filter($days, fn (string $day) => $day >= now()->subDays(self::RECENT_GAP_DAYS)->toDateString());
        $this->add(
            $recent !== [] ? SystemState::WARNING : SystemState::NOTICE,
            'Jours manqués',
            count($days) . " depuis le {$gaps['since']} (" . implode(', ', array_slice($days, -5)) . ')'
                . ($recent !== [] ? ', dont ' . count($recent) . ' ces ' . self::RECENT_GAP_DAYS . ' derniers jours' : ''),
        );
    }

    private function journal(): void
    {
        $settled = PredictionLogEntry::settled();
        $this->add(SystemState::NOTICE, 'Journal', sprintf('%d match(s) clôturé(s), %d ligne(s) · %d non clôturable(s) · %d ligne(s) au total',
            (clone $settled)->distinct()->count('match_id'),
            $settled->count(),
            PredictionLogEntry::voided()->count(),
            PredictionLogEntry::count(),
        ));
    }

    /** Une ligne en attente depuis plus de deux jours a manqué la veille et le rattrapage. */
    private function pending(): void
    {
        $pending = PredictionLogEntry::pending()->where('kickoff_at', '<', now());
        $lines = (clone $pending)->count();
        if ($lines === 0) {
            $this->add(SystemState::NOTICE, 'En attente', 'aucune ligne passée en attente de clôture');

            return;
        }

        $oldest = Carbon::parse((clone $pending)->min('kickoff_at'));
        $this->add(
            $oldest->lt(now()->subDays(self::PENDING_MAX_DAYS)) ? SystemState::WARNING : SystemState::NOTICE,
            'En attente',
            sprintf('%d ligne(s), %d match(s), la plus ancienne du %s', $lines, (clone $pending)->distinct()->count('match_id'), $oldest->format('Y-m-d')),
        );
    }

    private function apiFootball(): void
    {
        try {
            $usage = app(ApiFootballService::class)->getDailyUsage();
        } catch (\Throwable $e) {
            $this->add(SystemState::WARNING, 'API-Football', 'quota illisible : ' . $e->getMessage());

            return;
        }

        $reserve = (int) config('api-football.budget.optional_reserve');
        $this->add(
            $usage['remaining'] <= $reserve ? SystemState::WARNING : SystemState::NOTICE,
            'API-Football',
            "{$usage['remaining']} requête(s) restante(s) sur {$usage['limit']} aujourd'hui (remise à zéro à minuit UTC)",
        );
    }

    private function oddsApi(): void
    {
        try {
            $usage = app(OddsApiService::class)->syncQuota();
        } catch (\Throwable $e) {
            $this->add(SystemState::WARNING, 'The Odds API', 'quota illisible : ' . $e->getMessage());

            return;
        }

        if ($usage['remaining'] === null) {
            $this->add(SystemState::WARNING, 'The Odds API', 'quota absent de la réponse');

            return;
        }

        $reserve = (int) config('pipeline.closing.quota_reserve');
        $this->add(
            $usage['remaining'] < $reserve ? SystemState::WARNING : SystemState::NOTICE,
            'The Odds API',
            "{$usage['remaining']} crédit(s) restant(s) sur {$usage['limit']} ce mois" . ($usage['remaining'] < $reserve ? " : clôture arrêtée sous {$reserve}" : ''),
        );
    }

    private function backup(bool $enabled): void
    {
        try {
            $latest = app(DatabaseBackup::class)->latest();
        } catch (\Throwable $e) {
            $this->add(SystemState::WARNING, 'Sauvegarde', 'illisible : ' . $e->getMessage());

            return;
        }

        if ($latest === null) {
            $this->add($enabled ? SystemState::CRITICAL : SystemState::NOTICE, 'Sauvegarde', 'aucune dans ' . config('pipeline.backup.path'));

            return;
        }

        $hours = (int) Carbon::createFromTimestamp($latest['modified_at'])->diffInHours(now(), true);
        $this->add(
            $enabled && $hours > self::BACKUP_MAX_HOURS ? SystemState::WARNING : SystemState::NOTICE,
            'Sauvegarde',
            sprintf('%s, il y a %d h, %s Ko', $latest['file'], $hours, number_format($latest['bytes'] / 1024, 0, ',', ' ')),
        );
    }

    /**
     * Heures du journal : session MariaDB en UTC, et données cohérentes. Des lignes
     * clôturées dont le coup d'envoi ne vaut plus celui du match signalent des heures
     * décalées, quelle qu'en soit la cause : critique partout, même sur une copie.
     */
    private function timeZones(): void
    {
        try {
            $tz = app(TimeZoneCheck::class)->inspect();
        } catch (\Throwable $e) {
            $this->add(SystemState::WARNING, 'Fuseaux', 'illisibles : ' . $e->getMessage());

            return;
        }

        if ($tz['settled_mismatches'] > 0) {
            $this->add(SystemState::CRITICAL, 'Fuseaux', "{$tz['settled_mismatches']} ligne(s) clôturée(s) dont kickoff_at ne vaut plus match_date : heures décalées (docs/deploiement.md, « Heures et fuseaux »)");

            return;
        }
        if (!$tz['measurable']) {
            $this->add(SystemState::NOTICE, 'Fuseaux', "journal cohérent ; session non mesurable (pilote {$tz['driver']})");

            return;
        }

        $detail = "session {$tz['session']}, global {$tz['global']}, système {$tz['system']}";
        if ($tz['offset_minutes'] === 0) {
            $this->add(SystemState::NOTICE, 'Fuseaux', "session MariaDB en UTC ({$detail}), journal cohérent");

            return;
        }

        $offset = sprintf('%+d min', $tz['offset_minutes']);
        $this->add(
            // DB_TIMEZONE absent : la base du portable d'avant la bascule, cohérente dans son fuseau
            $tz['configured'] === null ? SystemState::WARNING : SystemState::CRITICAL,
            'Fuseaux',
            "session MariaDB décalée de {$offset} sur UTC ({$detail}) : les TIMESTAMP s'y stockent décalés ; "
                . ($tz['configured'] === null ? 'DB_TIMEZONE absent (attendu seulement sur l\'ancienne base du portable)' : "DB_TIMEZONE={$tz['configured']} ne donne pas UTC"),
        );
    }

    /** Sans run de référence terminé, le panneau de calibration est vide. */
    private function reference(): void
    {
        $id = config('football-data.reference_run.id');
        $run = BacktestFdRun::find($id);
        $ok = $run !== null && $run->status === 'completed';

        $this->add(
            $ok ? SystemState::NOTICE : SystemState::WARNING,
            'Backtest réf.',
            $ok ? "run #{$id} terminé le " . $run->finished_at?->format('Y-m-d') : "run #{$id} introuvable ou non terminé : lancer backtest:reference",
        );
    }
}
