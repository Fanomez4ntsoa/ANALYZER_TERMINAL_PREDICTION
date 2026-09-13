<?php

namespace App\Console\Commands;

use App\Models\BacktestFdRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rapport de calibration d'un run football-data, lu par population
 * (config football-data.populations), jamais sur les 22 divisions confondues.
 *
 * Par population et par marché : lignes, Brier du modèle, Brier de la cote
 * d'entrée démarginalisée et de la clôture Pinnacle sur les mêmes lignes,
 * probabilité moyenne annoncée et fréquence observée par issue. Puis biais
 * domicile (1X2) et décalage Over 2.5 (transfert), et segmentation par division.
 * Aucune métrique financière.
 */
class BacktestReport extends Command
{
    protected $signature = 'backtest:report
                            {run : Id du run backtest_fd_runs}
                            {--compare= : Id d\'un run de référence : ajoute un tableau des écarts par population}
                            {--no-write : Ne pas écrire le rapport Markdown à côté de l\'export JSON}';

    protected $description = 'Rapport de calibration par population (Top 5, deuxièmes divisions, autres) d\'un run football-data';

    private const MARKETS = [
        'adjustment|winner' => ['Ajustement — 1X2', ['1', 'X', '2']],
        'adjustment|overUnder25' => ['Ajustement — O/U 2.5', ['Over', 'Under']],
        'derived|btts' => ['Dérivé — BTTS', ['Yes', 'No']],
        'derived|overUnder15' => ['Dérivé — O/U 1.5', ['Over', 'Under']],
        'derived|overUnder35' => ['Dérivé — O/U 3.5', ['Over', 'Under']],
        'derived|doubleChance' => ['Dérivé — Double chance', ['1X', 'X2', '12']],
        'transfer|overUnder25' => ['Transfert — O/U 2.5', ['Over', 'Under']],
    ];

    private array $out = [];

    public function handle(): int
    {
        $run = BacktestFdRun::find((int) $this->argument('run'));
        if (!$run) {
            $this->error('Run introuvable');
            return self::FAILURE;
        }
        $compare = $this->option('compare') ? BacktestFdRun::find((int) $this->option('compare')) : null;
        if ($this->option('compare') && !$compare) {
            $this->error('Run de comparaison introuvable');
            return self::FAILURE;
        }

        $pops = $this->populations();
        $stats = $this->stats($run, $pops);

        $model = $run->config['model'] ?? null;
        $modelDesc = $model === null ? 'modèle : non renseigné (run antérieur aux corrections = ancien facteur domicile, sans ancrage)'
            : 'modèle : facteur domicile ' . ($model['legacy_home_advantage_after_fusion'] ? 'ANCIEN' : 'corrigé') . ', total sans O/U ' . ($model['anchor_total_on_league_average'] ? 'ancré' : 'non ancré');
        $this->p("# Run #{$run->id} ({$run->label}) — {$run->status} — saisons " . implode(',', $run->seasons) . " — entrée {$run->input_bookmaker} ouverture — {$modelDesc}\n");

        $this->sectionMatches($run, $pops, $stats);
        $this->sectionMarkets($pops, $stats);
        $this->sectionBiases($pops, $stats);
        $this->sectionDivisions($pops, $stats);

        if ($compare) {
            $this->sectionCompare($run, $compare, $pops, $stats, $this->stats($compare, $pops));
        }

        $markdown = implode("\n", $this->out) . "\n";
        $this->line($markdown);

        if (!$this->option('no-write')) {
            $label = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($run->label ?? $run->sample));
            $path = "backtest/run_{$run->id}_{$label}_populations.md";
            Storage::disk('local')->put($path, $markdown);
            $this->info("Rapport écrit : storage/app/private/{$path}");
        }

        return self::SUCCESS;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ populations et agrégats

    /** @return array<string, string[]>  label → divisions */
    private function populations(): array
    {
        $all = DB::table('historical_matches')->distinct()->orderBy('div')->pluck('div')->all();
        $pops = [];
        $used = [];
        foreach (config('football-data.populations') as $p) {
            if ($p['divisions'] !== null) {
                $pops[$p['label']] = $p['divisions'];
                $used = array_merge($used, $p['divisions']);
            }
        }
        foreach (config('football-data.populations') as $p) {
            if ($p['divisions'] === null) {
                $pops[$p['label']] = array_values(array_diff($all, $used));
            }
        }
        return $pops;
    }

    private static function cell(): array
    {
        return ['n' => 0, 'sse_m' => 0.0, 'n_ref' => 0, 'sse_m_ref' => 0.0, 'sse_ref' => 0.0, 'n_in' => 0, 'sse_m_in' => 0.0, 'sse_in' => 0.0, 'sum_p' => 0.0, 'sum_y' => 0.0, 'sum_ref' => 0.0, 'sum_in' => 0.0];
    }

    /**
     * Agrégats par population et par division : famille|marché (toutes issues)
     * et famille|marché|issue.
     */
    private function stats(BacktestFdRun $run, array $pops): array
    {
        $popOf = [];
        foreach ($pops as $label => $divs) {
            foreach ($divs as $d) {
                $popOf[$d] = $label;
            }
        }

        $agg = DB::table('backtest_fd_predictions')->where('run_id', $run->id)
            ->selectRaw("`div`, family, market, outcome, count(*) n,
                sum(pow(model_probability - observed, 2)) sse_m,
                sum(pinnacle_close_fair is not null) n_ref,
                sum(if(pinnacle_close_fair is not null, pow(model_probability - observed, 2), 0)) sse_m_ref,
                sum(if(pinnacle_close_fair is not null, pow(pinnacle_close_fair - observed, 2), 0)) sse_ref,
                sum(input_open_fair is not null) n_in,
                sum(if(input_open_fair is not null, pow(model_probability - observed, 2), 0)) sse_m_in,
                sum(if(input_open_fair is not null, pow(input_open_fair - observed, 2), 0)) sse_in,
                sum(model_probability) sum_p, sum(observed) sum_y,
                sum(coalesce(pinnacle_close_fair, 0)) sum_ref, sum(coalesce(input_open_fair, 0)) sum_in")
            ->groupBy('div', 'family', 'market', 'outcome')->get();

        $byPop = [];
        $byDiv = [];
        foreach ($agg as $r) {
            $pop = $popOf[$r->div] ?? null;
            if ($pop === null) {
                continue;
            }
            foreach (['market' => "{$r->family}|{$r->market}", 'outcome' => "{$r->family}|{$r->market}|{$r->outcome}"] as $lvl => $k) {
                $byPop[$pop][$lvl][$k] ??= self::cell();
                $byDiv[$r->div][$lvl][$k] ??= self::cell();
                foreach (array_keys(self::cell()) as $f) {
                    $byPop[$pop][$lvl][$k][$f] += $r->$f;
                    $byDiv[$r->div][$lvl][$k][$f] += $r->$f;
                }
            }
        }

        $evaluated = DB::table('backtest_fd_predictions')->where('run_id', $run->id)
            ->selectRaw('`div`, count(distinct historical_match_id) n')->groupBy('div')->pluck('n', 'div')->all();

        return ['pop' => $byPop, 'div' => $byDiv, 'evaluated' => $evaluated];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ métriques

    private function brierModel(array $c): ?float { return $c['n'] ? $c['sse_m'] / $c['n'] : null; }
    private function brierModelOnRef(array $c): ?float { return $c['n_ref'] ? $c['sse_m_ref'] / $c['n_ref'] : null; }
    private function brierRef(array $c): ?float { return $c['n_ref'] ? $c['sse_ref'] / $c['n_ref'] : null; }
    private function brierModelOnInput(array $c): ?float { return $c['n_in'] ? $c['sse_m_in'] / $c['n_in'] : null; }
    private function brierInput(array $c): ?float { return $c['n_in'] ? $c['sse_in'] / $c['n_in'] : null; }
    private function meanModel(array $c): ?float { return $c['n'] ? $c['sum_p'] / $c['n'] : null; }
    private function observed(array $c): ?float { return $c['n'] ? $c['sum_y'] / $c['n'] : null; }
    private function meanRef(array $c): ?float { return $c['n_ref'] ? $c['sum_ref'] / $c['n_ref'] : null; }
    private function meanInput(array $c): ?float { return $c['n_in'] ? $c['sum_in'] / $c['n_in'] : null; }

    private function pct(?float $x): string { return $x === null ? '-' : number_format($x * 100, 1, ',', ' '); }
    private function pts(?float $x): string { return $x === null ? '-' : sprintf('%+.1f', $x * 100); }
    private function br(?float $x): string { return $x === null ? '-' : number_format($x, 4, ',', ''); }
    private function dbr(?float $x): string { return $x === null ? '-' : sprintf('%+.4f', $x); }
    private function n(int|float $n): string { return number_format($n, 0, ',', ' '); }
    private function p(string $line): void { $this->out[] = $line; }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ sections

    private function sectionMatches(BacktestFdRun $run, array $pops, array $stats): void
    {
        $this->p("## A. Matchs et exclusions par population\n");
        $this->p('| Population | Divisions | Chargés | Évalués | Sans score | Sans ' . strtoupper($run->input_bookmaker) . ' ouv. 1X2 | Sans ' . strtoupper($run->input_bookmaker) . ' ouv. O/U | Sans Pinnacle clôt. 1X2 | Sans Pinnacle clôt. O/U |');
        $this->p('|---|---|---|---|---|---|---|---|---|');
        $prefix = $run->input_bookmaker === 'ps' ? 'ps_open' : 'b365_open';
        $hm = DB::table('historical_matches')->whereIn('season', $run->seasons)
            ->selectRaw("`div`, count(*) n,
                sum(fthg is null or ftag is null) no_score,
                sum({$prefix}_home is null or {$prefix}_draw is null or {$prefix}_away is null) no_in_1x2,
                sum({$prefix}_over25 is null or {$prefix}_under25 is null) no_in_ou,
                sum(ps_close_home is null or ps_close_draw is null or ps_close_away is null) no_ref_1x2,
                sum(ps_close_over25 is null or ps_close_under25 is null) no_ref_ou")
            ->groupBy('div')->get()->keyBy('div');
        foreach ($pops as $label => $divs) {
            $t = array_fill_keys(['n', 'ev', 'no_score', 'no_in_1x2', 'no_in_ou', 'no_ref_1x2', 'no_ref_ou'], 0);
            foreach ($divs as $d) {
                if (!isset($hm[$d])) {
                    continue;
                }
                foreach (['n', 'no_score', 'no_in_1x2', 'no_in_ou', 'no_ref_1x2', 'no_ref_ou'] as $k) {
                    $t[$k] += $hm[$d]->$k;
                }
                $t['ev'] += $stats['evaluated'][$d] ?? 0;
            }
            $this->p(sprintf('| %s | %s | %s | %s | %d | %d | %d | %d | %d |', $label, implode(' ', $divs), $this->n($t['n']), $this->n($t['ev']), $t['no_score'], $t['no_in_1x2'], $t['no_in_ou'], $t['no_ref_1x2'], $t['no_ref_ou']));
        }
        $this->p('');
    }

    private function sectionMarkets(array $pops, array $stats): void
    {
        $this->p("## B. Calibration par population et par marché\n");
        $this->p("Lignes = une par issue. Brier modèle sur toutes les lignes ; Brier de la cote d'entrée démarginalisée et de la clôture Pinnacle sur les lignes qui en disposent (le Brier modèle restreint à ces lignes s'en écarte de moins de 0,0003). Moyenne annoncée et fréquence observée par issue, écart en points.\n");
        foreach ($pops as $label => $divs) {
            $s = $stats['pop'][$label] ?? null;
            if (!$s) {
                continue;
            }
            $this->p("### {$label}\n");
            $this->p('| Marché | Lignes | Brier modèle | Brier entrée ouv. | Brier Pinnacle clôt. | Issue | Annoncée | Observée | Entrée ouv. | Pinnacle clôt. | Écart |');
            $this->p('|---|---|---|---|---|---|---|---|---|---|---|');
            foreach (self::MARKETS as $mk => [$name, $outcomes]) {
                $c = $s['market'][$mk] ?? null;
                if (!$c) {
                    continue;
                }
                $first = true;
                foreach ($outcomes as $o) {
                    $oc = $s['outcome']["{$mk}|{$o}"] ?? self::cell();
                    $this->p(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                        $first ? $name : '', $first ? $this->n($c['n']) : '', $first ? $this->br($this->brierModel($c)) : '',
                        $first ? $this->br($this->brierInput($c)) : '', $first ? $this->br($this->brierRef($c)) : '',
                        $o, $this->pct($this->meanModel($oc)), $this->pct($this->observed($oc)), $this->pct($this->meanInput($oc)), $this->pct($this->meanRef($oc)),
                        $this->pts($this->meanModel($oc) - $this->observed($oc))));
                    $first = false;
                }
            }
            $this->p('');
        }
    }

    private function sectionBiases(array $pops, array $stats): void
    {
        $this->p("## C. Biais domicile (1X2) et décalage Over 2.5 (transfert) par population\n");
        $this->p('| Population | Matchs 1X2 | Domicile annoncée | Observée | Entrée ouv. | Pinnacle clôt. | Biais modèle | Biais entrée | Biais Pinnacle | Nul | Extérieur | Brier 1X2 modèle | entrée | Pinnacle |');
        $this->p('|---|---|---|---|---|---|---|---|---|---|---|---|---|---|');
        foreach ($pops as $label => $divs) {
            $s = $stats['pop'][$label] ?? null;
            $h = $s['outcome']['adjustment|winner|1'] ?? null;
            if (!$h) {
                continue;
            }
            $x = $s['outcome']['adjustment|winner|X'];
            $a = $s['outcome']['adjustment|winner|2'];
            $w = $s['market']['adjustment|winner'];
            $this->p(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |', $label, $this->n($h['n']),
                $this->pct($this->meanModel($h)), $this->pct($this->observed($h)), $this->pct($this->meanInput($h)), $this->pct($this->meanRef($h)),
                $this->pts($this->meanModel($h) - $this->observed($h)), $this->pts($this->meanInput($h) - $this->observed($h)), $this->pts($this->meanRef($h) - $this->observed($h)),
                $this->pts($this->meanModel($x) - $this->observed($x)), $this->pts($this->meanModel($a) - $this->observed($a)),
                $this->br($this->brierModel($w)), $this->br($this->brierInput($w)), $this->br($this->brierRef($w))));
        }
        $this->p('');
        $this->p('| Population | Matchs O/U | Over transfert | Over observée | Over Pinnacle clôt. | Over entrée ouv. | Décalage transfert | Décalage ajustement | Brier transfert | Brier ajustement | Brier entrée | Brier Pinnacle |');
        $this->p('|---|---|---|---|---|---|---|---|---|---|---|---|');
        foreach ($pops as $label => $divs) {
            $s = $stats['pop'][$label] ?? null;
            $t = $s['outcome']['transfer|overUnder25|Over'] ?? null;
            if (!$t) {
                continue;
            }
            $ad = $s['outcome']['adjustment|overUnder25|Over'];
            $tm = $s['market']['transfer|overUnder25'];
            $am = $s['market']['adjustment|overUnder25'];
            $this->p(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |', $label, $this->n($t['n']),
                $this->pct($this->meanModel($t)), $this->pct($this->observed($t)), $this->pct($this->meanRef($t)), $this->pct($this->meanInput($ad)),
                $this->pts($this->meanModel($t) - $this->observed($t)), $this->pts($this->meanModel($ad) - $this->observed($ad)),
                $this->br($this->brierModel($tm)), $this->br($this->brierModel($am)), $this->br($this->brierInput($am)), $this->br($this->brierRef($am))));
        }
        $this->p('');
    }

    private function sectionDivisions(array $pops, array $stats): void
    {
        $this->p("## D. Segmentation par division\n");
        foreach ($pops as $label => $divs) {
            $this->p("### {$label}\n");
            $this->p('| Div | Matchs | 1X2 Brier modèle | entrée | Pinnacle | Biais domicile modèle | entrée | Pinnacle | O/U 2.5 Brier modèle | Pinnacle | BTTS Brier | BTTS Oui écart | O/U 1.5 Brier | O/U 3.5 Brier | Over transfert | observée | Décalage transfert | Transfert Brier |');
            $this->p('|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|');
            foreach ($divs as $d) {
                $s = $stats['div'][$d] ?? null;
                $h = $s['outcome']['adjustment|winner|1'] ?? null;
                if (!$h) {
                    continue;
                }
                $w = $s['market']['adjustment|winner'];
                $ou = $s['market']['adjustment|overUnder25'] ?? self::cell();
                $btts = $s['outcome']['derived|btts|Yes'] ?? self::cell();
                $t = $s['outcome']['transfer|overUnder25|Over'] ?? self::cell();
                $tm = $s['market']['transfer|overUnder25'] ?? self::cell();
                $this->p(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |', $d, $this->n($h['n']),
                    $this->br($this->brierModel($w)), $this->br($this->brierInput($w)), $this->br($this->brierRef($w)),
                    $this->pts($this->meanModel($h) - $this->observed($h)), $this->pts($this->meanInput($h) - $this->observed($h)), $this->pts($this->meanRef($h) - $this->observed($h)),
                    $this->br($this->brierModel($ou)), $this->br($this->brierRef($ou)),
                    $this->br($this->brierModel($s['market']['derived|btts'] ?? self::cell())), $this->pts($this->meanModel($btts) - $this->observed($btts)),
                    $this->br($this->brierModel($s['market']['derived|overUnder15'] ?? self::cell())), $this->br($this->brierModel($s['market']['derived|overUnder35'] ?? self::cell())),
                    $this->pct($this->meanModel($t)), $this->pct($this->observed($t)), $this->pts($this->meanModel($t) - $this->observed($t)), $this->br($this->brierModel($tm))));
            }
            $this->p('');
        }
    }

    private function sectionCompare(BacktestFdRun $run, BacktestFdRun $ref, array $pops, array $stats, array $refStats): void
    {
        $this->p("## E. Écarts par rapport au run #{$ref->id} ({$ref->label})\n");
        $this->p('Δ = run courant − run de référence. Brier : négatif = mieux. Biais et décalages en points.' . "\n");
        $this->p('| Population | Δ Brier 1X2 | Brier 1X2 − entrée | Brier 1X2 − Pinnacle | Δ biais domicile | Biais domicile | Δ Brier transfert | Δ décalage Over transfert | Décalage Over transfert | Δ BTTS Oui écart | BTTS Oui écart | Δ Brier BTTS |');
        $this->p('|---|---|---|---|---|---|---|---|---|---|---|---|');
        foreach ($pops as $label => $divs) {
            $s = $stats['pop'][$label] ?? null;
            $r = $refStats['pop'][$label] ?? null;
            if (!$s || !$r) {
                continue;
            }
            $w = $s['market']['adjustment|winner']; $rw = $r['market']['adjustment|winner'];
            $h = $s['outcome']['adjustment|winner|1']; $rh = $r['outcome']['adjustment|winner|1'];
            $tm = $s['market']['transfer|overUnder25']; $rtm = $r['market']['transfer|overUnder25'];
            $t = $s['outcome']['transfer|overUnder25|Over']; $rt = $r['outcome']['transfer|overUnder25|Over'];
            $b = $s['outcome']['derived|btts|Yes']; $rb = $r['outcome']['derived|btts|Yes'];
            $bm = $s['market']['derived|btts']; $rbm = $r['market']['derived|btts'];
            $bias = $this->meanModel($h) - $this->observed($h); $rbias = $this->meanModel($rh) - $this->observed($rh);
            $shift = $this->meanModel($t) - $this->observed($t); $rshift = $this->meanModel($rt) - $this->observed($rt);
            $bttsGap = $this->meanModel($b) - $this->observed($b); $rbttsGap = $this->meanModel($rb) - $this->observed($rb);
            $this->p(sprintf('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |', $label,
                $this->dbr($this->brierModel($w) - $this->brierModel($rw)),
                $this->dbr($this->brierModelOnInput($w) - $this->brierInput($w)), $this->dbr($this->brierModelOnRef($w) - $this->brierRef($w)),
                $this->pts($bias - $rbias), $this->pts($bias),
                $this->dbr($this->brierModel($tm) - $this->brierModel($rtm)), $this->pts($shift - $rshift), $this->pts($shift),
                $this->pts($bttsGap - $rbttsGap), $this->pts($bttsGap), $this->dbr($this->brierModel($bm) - $this->brierModel($rbm))));
        }
        $this->p('');
    }
}
