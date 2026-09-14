{{--
    Page principale du terminal (docs/design-system.md, maquette v3 corrigée).
    Aucun tri ni aucune mise en forme ne hiérarchise les sélections : ordre par
    heure du match, puis catalogue des marchés.
--}}
@php
    use App\Support\Terminal\Fmt;
    use App\Support\Terminal\MarketLabel;
    use App\Support\Terminal\MarketNature;
    use App\Support\Terminal\SelectionsBoard;

    $groups = $selections['groups'];
    $simulations = $selections['simulations'];
    $counts = $selections['counts'];

    // Match du Monte-Carlo par défaut : le prochain coup d'envoi calculé, sinon le premier
    $upcoming = collect($groups)->first(fn ($g) => !$g['kicked_off'] && isset($simulations[$g['match_id']]));
    $defaultMatch = $upcoming['match_id'] ?? array_key_first($simulations);

    // Marchés de la calibration : la DC est un miroir exact du 1X2, elle n'apporte rien
    $calibrationMarkets = collect($calibration['markets'] ?? [])->keyBy('market')->only(['winner', 'overUnder25', 'btts']);
    $dc = collect($calibration['markets'] ?? [])->firstWhere('market', 'doubleChance');

    $hasClosing = ($closing['total_matches'] ?? 0) > 0;
    $statusLabel = [
        SelectionsBoard::NO_ODDS => 'Cotes absentes',
        SelectionsBoard::KICKED_OFF_UNCOMPUTED => 'Commencé, non calculé',
        SelectionsBoard::NOT_COMPUTED => 'Cotes relevées, probabilités pas encore calculées',
    ];
@endphp

<x-terminal-layout :title="$isToday ? 'Aujourd\'hui' : 'Journée du ' . $date->format('d/m/Y')" :states="$states" fit>
<div class="home-stage"
     x-data="terminalHome({{ Js::from(['simulations' => (object) $simulations, 'defaultMatch' => $defaultMatch, 'marketOrder' => array_keys(MarketLabel::ORDER)]) }})">

    {{-- ── Score de Brier : mesure historique du marché choisi dans la calibration ── --}}
    <x-terminal.panel title="Score de Brier" class="area-brier">
        <x-slot:meta>run #{{ $calibration['run_id'] ?? '—' }}</x-slot:meta>
        @if ($calibrationMarkets->isEmpty())
            <x-terminal.note>Calibration de référence indisponible.</x-terminal.note>
        @else
            @foreach ($calibrationMarkets as $key => $m)
                <div class="flex flex-col gap-bd" x-show="market === '{{ $key }}'" @if ($key !== 'winner') x-cloak @endif>
                    <div class="flex items-center gap-gap">
                        <x-terminal.tag>{{ MarketLabel::market($key) }}</x-terminal.tag>
                        <x-terminal.nature :market="$key" />
                    </div>
                    <x-terminal.figure :value="Fmt::number($m['brier_model'], 3)" />
                    <span class="lab">0,250 = 50 % annoncé partout</span>
                    <div class="rows">
                        <x-terminal.row label="Pinnacle clôture">
                            @if ($m['brier_pinnacle_close'] !== null)
                                {{ Fmt::number($m['brier_pinnacle_close'], 3) }}
                            @else
                                <span class="t-dim" title="football-data ne publie pas de cote Pinnacle sur ce marché">aucune cote</span>
                            @endif
                        </x-terminal.row>
                        <x-terminal.row label="Lignes">{{ Fmt::number($m['n'], 0) }}</x-terminal.row>
                        <x-terminal.row label="Population">{{ $calibration['population'] }}</x-terminal.row>
                        <x-terminal.row label="Saisons">{{ implode(' · ', $calibration['seasons']) }}</x-terminal.row>
                    </div>
                </div>
            @endforeach
        @endif
    </x-terminal.panel>

    {{-- ── Monte-Carlo du match choisi ── --}}
    <x-terminal.panel title="Monte-Carlo" class="area-mc">
        <x-slot:dot><span class="dot" :class="{ 'is-on': mcRunning }" aria-hidden="true"></span></x-slot:dot>
        <x-slot:meta>
            <span x-text="sim ? sim.label : ''"></span>
            {{-- normal-case : en capitales, ρ devient Ρ et se lit « P » --}}
            <span class="num normal-case" x-text="sim ? sim.params : ''"></span>
        </x-slot:meta>
        @if ($simulations === [])
            <x-terminal.note>Aucun match calculé pour cette date : rien à simuler.</x-terminal.note>
        @else
            <div class="mc">
                <div class="mc-grid"><canvas x-ref="mcCanvas" data-glow aria-label="Matrice des scores, domicile en lignes, extérieur en colonnes"></canvas></div>
                <div class="mc-side">
                    <span class="lab">Tirages</span>
                    <x-terminal.readout live x-text="mcDraws">—</x-terminal.readout>
                    <span class="lab mt-group" x-text="mcAtRest ? 'Over 2.5 exact' : 'Over 2.5 simulé'">Over 2.5 simulé</span>
                    <x-terminal.readout live x-text="mcOver">—</x-terminal.readout>
                    <span class="lab">Modèle enregistré <span class="num text-p-mid font-normal" x-text="recorded('recorded_over25')"></span></span>
                    <span class="lab mt-group" x-text="sim ? 'Victoire ' + sim.home : ''"></span>
                    <x-terminal.readout live x-text="mcHome">—</x-terminal.readout>
                    <span class="lab">Modèle enregistré <span class="num text-p-mid font-normal" x-text="recorded('recorded_home')"></span></span>
                    <x-terminal.note class="mt-auto" title="L'Over enregistré compte aussi la masse au-delà de 6 buts ; les tirages se font dans la matrice 0 à 6 renormalisée.">Matrice 0 à 6 buts. Mouvement coupé : valeurs exactes.</x-terminal.note>
                </div>
            </div>
        @endif
    </x-terminal.panel>

    {{-- ── Calibration du run de référence ── --}}
    <x-terminal.panel title="Calibration" class="area-cal">
        <x-slot:meta>
            @if ($calibrationMarkets->isNotEmpty())
                <div class="seg" role="group" aria-label="Marché de la calibration">
                    @foreach ($calibrationMarkets as $key => $m)
                        <button type="button" id="cal-{{ $key }}" @click="market = '{{ $key }}'" :aria-pressed="market === '{{ $key }}' ? 'true' : 'false'" aria-pressed="{{ $key === 'winner' ? 'true' : 'false' }}">{{ MarketLabel::market($key) }}</button>
                    @endforeach
                </div>
            @endif
        </x-slot:meta>
        @if ($calibration === null)
            <x-terminal.note>Run de référence introuvable : aucune courbe à afficher.</x-terminal.note>
        @else
            <x-terminal.measure title="Run #{{ $calibration['run_id'] }} · {{ $calibration['input'] }} · marché seul · {{ $calibration['population'] }} · saisons {{ implode(', ', $calibration['seasons']) }}">Backtest run #{{ $calibration['run_id'] }} · {{ $calibration['population'] }} · {{ implode('-', $calibration['seasons']) }}. Ne décrit pas les prédictions du jour.</x-terminal.measure>
            @foreach ($calibrationMarkets as $key => $m)
                <div class="plot-wrap" x-show="market === '{{ $key }}'" @if ($key !== 'winner') x-cloak @endif>
                    <x-terminal.calibration-plot :market="$m" />
                </div>
            @endforeach
            <x-terminal.note class="max-w-none" :title="$dc ? 'DC non affichée : miroir exact du 1X2, Brier identique (' . Fmt::number($dc['brier_model'], 3) . ').' : null">1X2, O/U 2.5 : contrôles de cohérence. BTTS : test indépendant.</x-terminal.note>
        @endif
    </x-terminal.panel>

    {{-- ── Sélections ── tri de navigation seulement : heure, championnat, marché. Jamais l'écart. --}}
    <x-terminal.panel :title="'Sélections du ' . $date->locale('fr')->translatedFormat('j F')" live class="area-sel">
        <x-slot:meta>
            <span class="num">{{ $counts['matches'] }} matchs · {{ $counts['lines'] }} lignes</span>
            <div class="seg" role="group" aria-label="Trier par">
                @foreach (['time' => 'Heure', 'competition' => 'Championnat', 'market' => 'Marché'] as $key => $label)
                    <button type="button" id="sort-{{ $key }}" @click="sortBy = '{{ $key }}'" :aria-pressed="sortBy === '{{ $key }}' ? 'true' : 'false'" aria-pressed="{{ $key === 'time' ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <div class="seg" role="group" aria-label="Filtrer par marché">
                <button type="button" id="filter-all" @click="marketFilter = 'all'" :aria-pressed="marketFilter === 'all' ? 'true' : 'false'" aria-pressed="true">Tous</button>
                @foreach (array_keys(MarketLabel::ORDER) as $key)
                    <button type="button" id="filter-{{ $key }}" @click="marketFilter = '{{ $key }}'" :aria-pressed="marketFilter === '{{ $key }}' ? 'true' : 'false'" aria-pressed="false">{{ MarketLabel::market($key) }}</button>
                @endforeach
            </div>
            <span class="team-sep">│</span>
            <a class="panel-link" href="{{ route('dashboard', ['date' => $date->subDay()->toDateString()]) }}">‹ {{ $date->subDay()->format('d/m') }}</a>
            <a class="panel-link" href="{{ route('dashboard', ['date' => $date->addDay()->toDateString()]) }}">{{ $date->addDay()->format('d/m') }} ›</a>
        </x-slot:meta>

        <div class="t-table-wrap">
            <table class="t-table">
                <thead>
                    <tr>
                        <th class="l"><span class="sr-only">Combiné</span></th>
                        <th class="l">Heure</th>
                        <th class="l">Rencontre</th>
                        <th class="l">Marché</th>
                        <th class="l">Nature</th>
                        <th title="Probabilité du modèle en mode marché seul">Modèle %</th>
                        <th title="Cote Bet365">Cote</th>
                        <th title="1/cote, marge du bookmaker incluse">Implicite %</th>
                        <th title="Modèle moins probabilité démarginalisée, en points">Écart pts</th>
                    </tr>
                </thead>
                <tbody x-ref="selBody">
                @php $index = 0; @endphp
                @forelse ($groups as $g)
                    @php $hasSim = isset($simulations[$g['match_id']]); @endphp
                    @if ($g['status'] !== SelectionsBoard::PRICED)
                        <tr class="group-start is-out" data-row data-index="{{ $index++ }}" data-match="{{ $g['match_id'] }}" data-competition="{{ $g['competition'] }}" data-market="">
                            <td class="l"><input type="checkbox" class="pick" id="pick-m{{ $g['match_id'] }}" disabled aria-label="{{ $g['home'] }} · {{ $g['away'] }} : aucune ligne sélectionnable"></td>
                            <td class="l num">{{ $g['kickoff'] }}</td>
                            <td class="l">{{ $g['home'] }} <span class="team-sep">·</span> {{ $g['away'] }}</td>
                            <td class="l" colspan="6">
                                @if ($g['status'] === SelectionsBoard::OUT_OF_PERIMETER)
                                    <x-terminal.scope :league="$g['competition']" />
                                @else
                                    <span class="scope">{{ $statusLabel[$g['status']] }} · {{ $g['competition'] }}</span>
                                @endif
                            </td>
                        </tr>
                        @continue
                    @endif
                    @foreach ($g['rows'] as $i => $row)
                        <tr @class(['group-start' => $i === 0, 'is-repeat' => $i > 0, 'is-out' => $g['kicked_off']])
                            data-row data-index="{{ $index++ }}" data-match="{{ $g['match_id'] }}" data-competition="{{ $g['competition'] }}" data-market="{{ $row['market'] }}"
                            :class="{ 'is-picked': isPicked({{ $row['id'] }}) }">
                            <td class="l">
                                @if ($row['pickable'])
                                    <input type="checkbox" class="pick" id="pick-{{ $row['id'] }}"
                                           data-id="{{ $row['id'] }}" data-match="{{ $g['match_id'] }}"
                                           data-label="{{ $g['home'] }} · {{ $g['away'] }} · {{ $row['tag'] }}"
                                           data-probability="{{ $row['probability'] }}" data-odds="{{ $row['odds'] }}"
                                           :checked="isPicked({{ $row['id'] }})"
                                           :disabled="!canPick({{ $row['id'] }}, {{ $g['match_id'] }})"
                                           @change="toggle($el)"
                                           aria-label="Ajouter {{ $g['home'] }} · {{ $g['away'] }} · {{ $row['tag'] }} au combiné">
                                @else
                                    <input type="checkbox" class="pick" id="pick-{{ $row['id'] }}" disabled
                                           aria-label="{{ $row['tag'] }} : {{ $g['kicked_off'] ? 'match commencé' : 'cote absente' }}">
                                @endif
                            </td>
                            {{-- Heure et rencontre sur chaque ligne ; masquées quand la ligne précédente est du même match --}}
                            <td class="l t-dim num match-cell"><span>{{ $g['kickoff'] }}</span></td>
                            <td class="l match-cell">
                                <span>
                                    @if ($hasSim)
                                        <button type="button" class="match-btn" @click="mcMatch = {{ $g['match_id'] }}" :aria-pressed="mcMatch === {{ $g['match_id'] }} ? 'true' : 'false'" title="Simuler ce match">{{ $g['home'] }} <span class="team-sep">·</span> {{ $g['away'] }}</button>
                                    @else
                                        {{ $g['home'] }} <span class="team-sep">·</span> {{ $g['away'] }}
                                    @endif
                                    <span class="nature">{{ $g['competition'] }}</span>
                                    @if ($g['kicked_off'])<span class="scope">Commencé</span>@endif
                                    <span class="tag" x-show="mcMatch === {{ $g['match_id'] }}" x-cloak>MC</span>
                                </span>
                            </td>
                            <td class="l"><x-terminal.tag>{{ $row['tag'] }}</x-terminal.tag></td>
                            <td class="l"><x-terminal.nature :market="$row['market']" /></td>
                            <td class="t-hi">{{ Fmt::percent($row['probability']) }}</td>
                            <td class="t-hi">{{ Fmt::odds($row['odds']) }}</td>
                            <td class="t-hi">{{ Fmt::percent($row['implied']) }}</td>
                            <td><x-terminal.edge :edge="$row['edge']" :market="$row['market']" /></td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td class="l t-dim" colspan="9">Aucun match importé pour cette date.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-terminal.note class="max-w-none" x-show="hiddenUnpriced > 0" x-cloak>
            <span x-text="hiddenUnpriced"></span> match(s) sans ligne calculée masqué(s) par le filtre de marché.
        </x-terminal.note>
        <x-terminal.nature-note class="max-w-none" />
    </x-terminal.panel>

    <div class="home-side area-side">
        {{-- ── Combiné : l'arithmétique, rien d'autre ── --}}
        <x-terminal.panel title="Combiné" class="is-grow">
            <x-slot:meta><span class="num" x-text="picks.length + ' / 3'">0 / 3</span></x-slot:meta>
            <div class="flex flex-wrap items-end gap-group">
                <div class="kv"><span class="lab">Produit des probabilités</span><x-terminal.readout live x-text="productProbability">—</x-terminal.readout></div>
                <div class="kv"><span class="lab">Produit des cotes</span><x-terminal.readout live x-text="productOdds">—</x-terminal.readout></div>
            </div>
            <div class="rows" x-show="picks.length" x-cloak>
                <template x-for="p in picks" :key="p.id">
                    <div class="pick-line">
                        <span x-text="p.label"></span>
                        <span class="num" x-text="lineValues(p)"></span>
                    </div>
                </template>
            </div>
            <div class="flex items-baseline gap-gap">
                <x-terminal.note class="flex-1" title="Le produit suppose des issues indépendantes ; deux issues d'un même match ne le sont pas.">Une ligne par match, trois au plus.</x-terminal.note>
                <button type="button" class="toggle" x-show="picks.length" x-cloak @click="clearPicks()">Vider</button>
            </div>
        </x-terminal.panel>

        {{-- ── Écart de clôture ── --}}
        <x-terminal.panel title="Écart de clôture" :live="$hasClosing">
            <x-slot:meta>Pinnacle</x-slot:meta>
            @if ($hasClosing)
                @php $avg = (float) $closing['avg_clv']; @endphp
                <div class="flex flex-wrap items-center gap-group">
                    <x-terminal.figure :value="($avg > 0 ? '+' : '') . Fmt::number($avg, 1)" suffix="%" live glow class="text-crt-l" />
                    <div class="rows flex-1">
                        <x-terminal.row label="Matchs clôturés">{{ Fmt::number($closing['total_matches'], 0) }}</x-terminal.row>
                        <x-terminal.row label="Écart positif">{{ Fmt::number($closing['clv_positive_pct'], 1) }} %</x-terminal.row>
                    </div>
                </div>
            @else
                <x-terminal.note>Aucune clôture relevée pour l'instant.</x-terminal.note>
            @endif
            <x-terminal.note title="Moyenne des trois issues 1X2. O/U 2.5 partiel : la ligne principale Pinnacle n'est à 2.5 que sur un quart des matchs.">Mesure Pinnacle, pas Bet365. O/U 2.5 partiel.</x-terminal.note>
        </x-terminal.panel>
    </div>
</div>
</x-terminal-layout>
