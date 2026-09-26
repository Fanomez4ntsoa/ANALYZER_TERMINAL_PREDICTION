<?php

namespace App\Console\Commands;

use App\Services\PredictionLog\PredictionLogReport;
use App\Support\Terminal\MarketLabel;
use Illuminate\Console\Command;

/**
 * Rapport de calibration du journal des sélections sur matchs réels.
 *
 * Affiche toujours l'effectif total. Sous le seuil (config prediction-log.min_matches,
 * en matchs clôturés par marché), chaque bloc dit explicitement que ses chiffres ne
 * permettent aucune conclusion. Au-dessus, aucun verdict non plus.
 */
class LogReport extends Command
{
    protected $signature = 'log:report
                            {--market= : Un marché (winner, doubleChance, overUnder25, btts)}
                            {--league= : Un championnat (league_id API-Football)}';

    protected $description = 'Calibration du journal des sélections : Brier et fréquence observée par tranche, modèle marché seul et modèle complet';

    public function handle(PredictionLogReport $report): int
    {
        $market = $this->option('market');
        $markets = collect(config('prediction-log.groups'))->pluck('markets')->flatten()->all();
        if ($market !== null && !in_array($market, $markets, true)) {
            $this->error("Marché inconnu : {$market} (" . implode(', ', $markets) . ')');

            return self::FAILURE;
        }
        $league = $this->option('league') !== null ? (int) $this->option('league') : null;

        $data = $report->build($market, $league);
        $t = $data['totals'];
        $threshold = $data['threshold'];

        $this->line('<options=bold>JOURNAL DES SÉLECTIONS — calibration sur matchs réels</>');
        $this->line('Lignes mesurées : premier calcul du pipeline de chaque match, clôturé, match non contaminé.');
        $this->line("Seuil : {$threshold} matchs clôturés par marché. En dessous, les chiffres ne permettent aucune conclusion.");
        if ($market !== null || $league !== null) {
            $this->line('Filtre : ' . implode(', ', array_filter([$market ? "marché {$market}" : null, $league ? "championnat {$league}" : null])));
        }
        $this->newLine();
        $this->line("<options=bold>Effectif total : {$t['settled_matches']} match(s) clôturé(s), {$t['settled_lines']} ligne(s)</>");
        $this->line("En attente de clôture, non mesurés : {$t['pending_matches']} match(s), {$t['pending_lines']} ligne(s)");
        $this->line("Écartés, non clôturables : {$t['voided_matches']} match(s), {$t['voided_lines']} ligne(s)"
            . ($t['voided_by_reason'] ? ' (' . collect($t['voided_by_reason'])->map(fn ($n, $reason) => "{$reason} {$n}")->implode(', ') . ')' : ''));
        $this->line("Écartés, lignes du bouton seulement : {$t['manual_only_matches']} match(s), {$t['manual_only_lines']} ligne(s) dont {$t['manual_only_settled_lines']} clôturée(s)");
        $this->line("Écartés, matchs contaminés : {$t['contaminated_matches']}");
        $this->renderPipelineGaps($data['pipeline_gaps']);

        if ($t['settled_matches'] < $threshold) {
            $this->newLine();
            $this->warn("  {$t['settled_matches']} match(s) clôturé(s) au total : aucun marché ne peut atteindre le seuil de {$threshold}. AUCUNE CONCLUSION POSSIBLE.");
        }

        foreach ($data['groups'] as $group) {
            $this->newLine();
            $this->line("<options=bold>══ {$group['label']} — {$group['note']} ══</>");

            foreach ($group['results'] as $groupMarket => $result) {
                $label = MarketLabel::market($groupMarket);
                $this->renderSection("{$label} · modèle marché seul", $result['model'], $threshold, 'marché seul');

                $full = $result['full'];
                $this->newLine();
                $this->line("<options=bold>── {$label} · modèle complet (marché + comparaison + blessures) ──</>");
                $this->line("Progression propre : {$full['overall']['matches']} / {$threshold} match(s) où comparaison ou blessures ont servi,"
                    . " contre {$full['market_only_matches']} pour le marché seul.");
                $this->line("Exclus : {$full['without_extra_signal_matches']} match(s) sans signal hors marché (identique au marché seul),"
                    . " {$full['not_computed_matches']} sans modèle complet calculé.");
                $this->renderSection(null, $full, $threshold, 'complet', 'marché seul');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Jours de prédictions définitivement perdus : jamais importés, donc absents de
     * tous les autres compteurs.
     */
    private function renderPipelineGaps(array $gaps): void
    {
        if ($gaps['since'] === null) {
            return;
        }

        if ($gaps['missing'] === [] && $gaps['failed'] === []) {
            $this->line("Jours sans passage du pipeline depuis le {$gaps['since']} : aucun");

            return;
        }

        if ($gaps['missing'] !== []) {
            $this->warn("Jours sans passage du pipeline depuis le {$gaps['since']} : " . count($gaps['missing']) . ' ('
                . implode(', ', $gaps['missing']) . '). Matchs jamais importés ni calculés : prédictions définitivement perdues, absentes des compteurs ci-dessus.');
        }
        if ($gaps['failed'] !== []) {
            $this->warn('Jours au passage échoué ou interrompu : ' . count($gaps['failed']) . ' (' . implode(', ', $gaps['failed']) . ').');
        }
    }

    private function renderSection(?string $title, array $section, int $threshold, string $name, ?string $baselineName = null): void
    {
        if ($title !== null) {
            $this->newLine();
            $this->line("<options=bold>── {$title} ──</>");
        }

        $this->renderMetrics('Tous championnats', $section['overall'], $threshold, $name, $baselineName);

        if (count($section['by_league']) > 0) {
            $this->line('Par championnat :');
            $headers = ['Championnat', 'Matchs', 'Lignes', "Brier {$name}"];
            if ($baselineName !== null) {
                $headers[] = "Brier {$baselineName}";
            }
            array_push($headers, 'Brier Bet365', 'Statut');

            $rows = [];
            foreach ($section['by_league'] as $m) {
                $row = [$m['league'], $m['matches'], $m['lines'], $this->num($m['brier'])];
                if ($baselineName !== null) {
                    $row[] = $this->num($m['brier_baseline']);
                }
                array_push($row, $this->num($m['brier_fair']), $this->status($m, $threshold, short: true));
                $rows[] = $row;
            }
            $this->table($headers, $rows);

            foreach ($section['by_league'] as $m) {
                $this->renderBins("{$m['league']} ({$m['matches']} match(s))", $m['bins'], $name);
            }
        }
    }

    private function renderMetrics(string $scope, array $m, int $threshold, string $name, ?string $baselineName): void
    {
        $this->line("{$scope} : {$m['matches']} / {$threshold} match(s), {$m['lines']} ligne(s) · " . $this->status($m, $threshold));

        if ($m['lines'] === 0) {
            return;
        }

        $line = "Brier {$name} " . $this->num($m['brier']);
        if ($baselineName !== null) {
            $line .= " · Brier {$baselineName} " . $this->num($m['brier_baseline']);
        }
        $line .= ' · Brier Bet365 équitable ' . $this->num($m['brier_fair']);
        $this->line($line);

        if ($baselineName !== null) {
            $this->line("Différence {$name} − {$baselineName} : " . $this->diff($m['diff_vs_baseline']));
        }
        $this->line("Différence {$name} − Bet365 équitable : " . $this->diff($m['diff_vs_fair']));

        $this->renderBins(null, $m['bins'], $name);
    }

    private function renderBins(?string $title, array $bins, string $name): void
    {
        if ($bins === []) {
            return;
        }
        if ($title !== null) {
            $this->line("Tranches · {$title} :");
        }

        $this->table(
            ['Tranche', 'Lignes', 'Matchs', "Annoncé {$name}", 'Observé', 'Bet365 équitable'],
            array_map(fn (array $b) => [
                sprintf('%d–%d %%', round($b['from'] * 100), round($b['to'] * 100)),
                $b['lines'],
                $b['matches'],
                $this->pct($b['announced']),
                $this->pct($b['observed']),
                $this->pct($b['fair']),
            ], $bins),
        );
    }

    private function status(array $m, int $threshold, bool $short = false): string
    {
        if ($m['threshold_reached']) {
            return $short ? 'seuil atteint' : 'effectif minimal atteint : lire avec l\'erreur type, aucun verdict';
        }

        return $short
            ? "< {$threshold} : aucune conclusion"
            : "{$m['matches']} < {$threshold} : CES CHIFFRES NE PERMETTENT AUCUNE CONCLUSION";
    }

    private function num(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 5, ',', '');
    }

    private function pct(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 1, ',', '') . ' %';
    }

    private function diff(?array $d): string
    {
        if ($d === null) {
            return '—';
        }

        $signed = ($d['mean'] >= 0 ? '+' : '−') . $this->num(abs($d['mean']));

        return $signed . ' (erreur type ' . ($d['se'] === null ? 'non calculable, un seul match' : $this->num($d['se'])) . ')';
    }
}
