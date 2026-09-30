<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Support\Terminal\Fmt;
use App\Support\Terminal\MarketNature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Export Markdown des matchs du jour (heure d'affichage, EAT) ayant des
 * probabilités calculées, pour une analyse externe.
 *
 * Lecture seule : aucun calcul, aucune écriture en base. Probabilité équitable et
 * écart sont ceux stockés dans `predictions`, jamais recalculés. Ordre des matchs :
 * coup d'envoi croissant, jamais l'écart (docs/decisions.md, 14/09/2026).
 */
class MatchesExportToday extends Command
{
    protected $signature = 'matches:export-today';

    protected $description = 'Exporter en Markdown les matchs du jour ayant des probabilités (storage/app/private/exports)';

    /** Ordre des lignes et libellés : (marché, issue) → libellé. */
    private const ROWS = [
        [Prediction::MARKET_WINNER, '1', '1'],
        [Prediction::MARKET_WINNER, 'X', 'X'],
        [Prediction::MARKET_WINNER, '2', '2'],
        [Prediction::MARKET_DOUBLE_CHANCE, '1X', '1X'],
        [Prediction::MARKET_DOUBLE_CHANCE, 'X2', 'X2'],
        [Prediction::MARKET_DOUBLE_CHANCE, '12', '12'],
        [Prediction::MARKET_OVER_UNDER_25, 'Over', 'Over 2.5'],
        [Prediction::MARKET_OVER_UNDER_25, 'Under', 'Under 2.5'],
        [Prediction::MARKET_BTTS, 'Yes', 'BTTS Oui'],
        [Prediction::MARKET_BTTS, 'No', 'BTTS Non'],
    ];

    private const NOT_COLLECTED = 'non collecté';

    private string $tz;

    public function handle(): int
    {
        $this->tz = config('app.display_timezone');
        $now = now()->setTimezone($this->tz);
        $dayStart = $now->copy()->startOfDay();

        $matches = FootballMatch::query()
            ->measurable()
            ->whereHas('predictions')
            ->where('match_date', '>=', $dayStart->copy()->utc())
            ->where('match_date', '<', $dayStart->copy()->addDay()->utc())
            ->with(['predictions', 'advancedData'])
            ->orderBy('match_date')
            ->orderBy('id')
            ->get();

        $markdown = $this->render($now, $matches);

        $path = 'exports/matches-' . $now->format('Y-m-d-H\hi') . '.md';
        Storage::disk('local')->put($path, $markdown);

        $this->info(count($matches) . ' match(s) exporté(s).');
        $this->line(Storage::disk('local')->path($path));

        return self::SUCCESS;
    }

    private function render(Carbon $now, Collection $matches): string
    {
        $schedule = Carbon::parse(config('pipeline.schedule_time'), config('pipeline.schedule_timezone'));

        $lines = [
            "# Matchs du {$now->format('d/m/Y')} — Export pour analyse externe",
            '',
            "Généré le : {$now->format('d/m/Y à H\hi')} (EAT)",
            "Pipeline : {$schedule->format('H:i')} {$schedule->format('T')} ({$schedule->copy()->setTimezone($this->tz)->format('H:i')} EAT)",
            'Nombre de matchs : ' . count($matches),
            'Modèle : marché seul, run de référence #' . config('football-data.reference_run.id'),
            '',
        ];

        if ($matches->isEmpty()) {
            $lines[] = "Aucun match du jour avec des probabilités calculées (pipeline:daily non passé, trêve ou aucun match coté).";
            $lines[] = '';
        }

        foreach ($matches as $match) {
            array_push($lines, ...$this->matchBlock($match));
        }

        array_push($lines, '---', '', ...$this->footer());

        return implode("\n", $lines) . "\n";
    }

    private function matchBlock(FootballMatch $match): array
    {
        $predictions = $match->predictions->keyBy(fn (Prediction $p) => "{$p->market}|{$p->outcome}");
        $sample = $match->predictions->first(fn (Prediction $p) => $p->odds !== null);

        $lines = [
            "### {$match->home_team} vs {$match->away_team}",
            '',
            "- Compétition : {$match->competition}",
            "- Coup d'envoi : {$this->local($match->match_date)} (EAT)",
            "- ID match : {$match->id}",
            '- Cotes : ' . ($sample
                ? "{$sample->bookmaker}, relevées le {$this->local($sample->odds_taken_at)} (EAT)"
                : 'aucune cote enregistrée'),
            '- Probabilités calculées le : ' . $this->local($match->predictions->max('computed_at')) . ' (EAT)',
            '',
            "**Marchés d'ajustement** (les λ sont estimés sur ces cotes : l'écart y est mécanique, jamais une opportunité)",
            '',
            ...$this->marketTable($predictions, MarketNature::ADJUSTMENT),
            '',
            '**Marchés dérivés** (non utilisés pour l\'ajustement : seul leur écart porte une information propre au modèle)',
            '',
            ...$this->marketTable($predictions, MarketNature::DERIVED),
            '',
            '**Contexte collecté**',
            '',
            ...$this->contextLines($match),
            '',
        ];

        return $lines;
    }

    private function marketTable(Collection $predictions, string $nature): array
    {
        $lines = [
            '| Marché | Modèle | Cote Bet365 | Prob. équitable | Écart |',
            '|---|---|---|---|---|',
        ];

        foreach (self::ROWS as [$market, $outcome, $label]) {
            if (MarketNature::of($market) !== $nature) {
                continue;
            }

            $p = $predictions->get("{$market}|{$outcome}");
            if ($p === null) {
                $lines[] = "| {$label} | non calculé | non calculé | non calculé | non calculé |";
                continue;
            }

            $odds = $p->odds !== null ? Fmt::odds($p->odds) : 'cote absente';
            $fair = $p->fair_probability !== null ? Fmt::percent($p->fair_probability) . ' %' : 'non calculable (cote absente)';
            $edge = $p->edge !== null ? Fmt::points($p->edge) : 'non calculable';
            $lines[] = "| {$label} | " . Fmt::percent($p->model_probability) . " % | {$odds} | {$fair} | {$edge} |";
        }

        return $lines;
    }

    private function contextLines(FootballMatch $match): array
    {
        $context = $match->advancedData?->context_data ?? [];
        $collected = isset($context['collected_at']);
        $suffix = $collected ? '' : ' (context:enrich non passé pour ce match)';

        return [
            '- Fatigue : ' . ($collected ? $this->fatigue($context['fatigue'] ?? null) : self::NOT_COLLECTED . $suffix),
            '- Blessures : ' . $this->injuries($match->advancedData?->sofascore_data['injuries'] ?? null, $match),
            '- Météo : ' . ($collected ? $this->weather($context['weather'] ?? null) : self::NOT_COLLECTED . $suffix),
            '- Enjeux : ' . ($collected ? $this->stakes($context['stakes'] ?? null) : self::NOT_COLLECTED . $suffix),
        ];
    }

    /** Absence expliquée : jamais un tiret, jamais confondue avec une valeur nulle. */
    private function unavailable(?array $dimension): string
    {
        if ($dimension === null) {
            return self::NOT_COLLECTED;
        }

        $reason = $dimension['reason'] ?? null;
        if ($reason === null) {
            return 'aucune donnée';
        }

        return (str_contains($reason, 'offre gratuite') ? 'non disponible (offre gratuite) : ' : 'non disponible : ') . $reason;
    }

    private function fatigue(?array $fatigue): string
    {
        if (!($fatigue['available'] ?? false)) {
            return $this->unavailable($fatigue);
        }

        $side = fn (array $t) => sprintf(
            '%d match(s) sur 7 j, %d sur 14 j, %d sur 21 j%s',
            $t['matches_7d'], $t['matches_14d'], $t['matches_21d'], $t['european'] ? ', coupe d\'Europe récente' : ''
        );

        return "domicile {$side($fatigue['home'])} ; extérieur {$side($fatigue['away'])} (périmètre : {$fatigue['scope']})";
    }

    private function injuries(?array $injuries, FootballMatch $match): string
    {
        if ($injuries === null) {
            return self::NOT_COLLECTED . ' (données facultatives : hors périmètre des cotes ou budget du jour épuisé)';
        }

        // L'API renvoie parfois chaque absent deux fois : dédoublonné à l'affichage
        $side = function (array $list): string {
            $names = collect($list)
                ->map(fn ($i) => $i['player'] . (!empty($i['reason']) ? " ({$i['reason']})" : ''))
                ->unique()
                ->values();

            return $names->isEmpty() ? 'aucun absent signalé' : $names->implode(', ');
        };

        return "{$match->home_team} : {$side($injuries['home'] ?? [])} ; {$match->away_team} : {$side($injuries['away'] ?? [])}";
    }

    private function weather(?array $weather): string
    {
        if (!($weather['available'] ?? false)) {
            return $this->unavailable($weather);
        }

        $parts = [$weather['description'] ?? $weather['condition'] ?? 'condition inconnue'];
        if (($weather['temperature'] ?? null) !== null) {
            $parts[] = Fmt::number((float) $weather['temperature'], 0) . ' °C';
        }
        if (($weather['wind_speed'] ?? null) !== null) {
            $parts[] = 'vent ' . Fmt::number((float) $weather['wind_speed'], 0) . ' km/h';
        }
        $parts[] = ($weather['rain'] ?? false) ? 'pluie' : 'sans pluie';

        return implode(', ', $parts);
    }

    private function stakes(?array $stakes): string
    {
        if (!($stakes['available'] ?? false)) {
            return $this->unavailable($stakes);
        }

        $side = fn (array $s) => ($s['description'] ?? $s['stake'] ?? 'inconnu') . (isset($s['rank']) ? " ({$s['rank']}e)" : '');

        return "domicile {$side($stakes['home'])} ; extérieur {$side($stakes['away'])}";
    }

    private function footer(): array
    {
        return [
            'Ces probabilités viennent d\'un modèle de Poisson dont les paramètres sont estimés sur les cotes (mode marché seul) : il est calibré sur le marché, il ne bat pas la clôture Pinnacle.',
            '',
            'La probabilité équitable est celle du bookmaker, marge retirée, telle que stockée par le système ; l\'écart est modèle moins probabilité équitable, en points.',
            '',
            'Cet export ne contient aucune recommandation.',
        ];
    }

    private function local(mixed $date): string
    {
        return $date === null ? 'inconnu' : Carbon::parse($date)->setTimezone($this->tz)->format('d/m/Y à H\hi');
    }
}
