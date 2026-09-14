{{--
    Écart de clôture (CLV) et relevés Pinnacle. Deux mentions obligatoires, en tête :
    le CLV mesure Pinnacle et non le prix Bet365 des prédictions ; l'Over 2.5 y est
    partiel. Ordre des lignes : date, jamais le CLV (principe 1). Valeurs sans couleur :
    la couleur est réservée au signe de l'écart du modèle sur un marché dérivé.
--}}
@php
    use App\Support\Terminal\Fmt;

    $signed = fn (?float $v, int $d = 2) => $v === null ? '' : ($v > 0 ? '+' : '') . Fmt::number($v, $d);
    $total = $summary['total_matches'] ?? 0;
@endphp

<x-terminal-layout title="Marché" :states="$states">
<div class="flex flex-col gap-gap">

    <div class="measure">
        <span class="lab">Mesure</span>
        <span>Le CLV mesure les cotes <b class="font-medium">Pinnacle</b>, pas le prix <b class="font-medium">Bet365</b> des prédictions : un CLV positif dit que le premier relevé Pinnacle était meilleur que sa clôture, rien sur la cote que l'on peut jouer chez Bet365.</span>
    </div>
    <div class="measure">
        <span class="lab">O/U 2.5 partiel</span>
        <span>Pinnacle ne publie que sa ligne principale de buts, à 2.5 sur un quart des matchs environ. La colonne Over 2.5 ne couvre que ces matchs ; la moyenne générale porte sur le 1X2.</span>
    </div>

    <div class="page-grid">
        <x-terminal.panel title="Écart de clôture" :live="$total > 0">
            <x-slot:meta>{{ $bookmaker }}</x-slot:meta>
            @if ($total === 0)
                <x-terminal.note>Aucune clôture relevée pour l'instant. Les clôtures sont prises automatiquement toutes les 5 minutes avant le coup d'envoi, sur les championnats {{ implode(', ', $leagues) }}.</x-terminal.note>
            @else
                <div class="flex flex-wrap items-center gap-group">
                    <x-terminal.figure :value="$signed((float) $summary['avg_clv'], 1)" suffix="%" live glow class="text-crt-l" />
                    <span class="lab">moyenne des trois issues 1X2</span>
                </div>
                <div class="rows">
                    <x-terminal.row label="Matchs clôturés">{{ Fmt::number($total, 0) }}</x-terminal.row>
                    <x-terminal.row label="CLV moyen positif">{{ Fmt::number((float) $summary['clv_positive_pct'], 1) }} % des matchs</x-terminal.row>
                    <x-terminal.row label="Issue 1 · X · 2">{{ $signed((float) $summary['avg_clv_home']) }} · {{ $signed((float) $summary['avg_clv_draw']) }} · {{ $signed((float) $summary['avg_clv_away']) }} %</x-terminal.row>
                    <x-terminal.row label="Over 2.5">
                        @if ($over['count'] > 0)
                            {{ $signed($over['mean']) }} % sur {{ $over['count'] }} matchs
                        @else
                            aucune ligne à 2.5
                        @endif
                    </x-terminal.row>
                </div>
            @endif
            <x-terminal.note>CLV = (cote du premier relevé ÷ cote de clôture − 1) × 100, les deux chez {{ $bookmaker }}. Matchs contaminés exclus.</x-terminal.note>
        </x-terminal.panel>

        <x-terminal.panel title="Relevés">
            <div class="rows">
                <x-terminal.row label="Bookmaker du CLV">{{ $bookmaker }}</x-terminal.row>
                <x-terminal.row label="Championnats">{{ implode(', ', $leagues) }}</x-terminal.row>
                <x-terminal.row label="Quota The Odds API">
                    @if ($quota['used'] !== null)
                        {{ $quota['used'] }} / {{ $quota['limit'] }} · {{ $quota['remaining'] }} restantes
                    @else
                        non synchronisé
                    @endif
                </x-terminal.row>
            </div>
            <x-terminal.note>Les cotes des prédictions viennent d'API-Football (Bet365). The Odds API ne sert qu'à ces relevés.</x-terminal.note>
        </x-terminal.panel>
    </div>

    <x-terminal.panel title="CLV par match">
        <x-slot:meta>du plus récent au plus ancien · en %</x-slot:meta>
        @if ($details->isEmpty())
            <x-terminal.note>Aucun match clôturé.</x-terminal.note>
        @else
            <div class="t-table-wrap">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th class="l">Date</th>
                            <th class="l">Rencontre</th>
                            <th class="l">Championnat</th>
                            <th>1</th>
                            <th>X</th>
                            <th>2</th>
                            <th title="Seulement quand la ligne principale Pinnacle est à 2.5">Over 2.5</th>
                            <th>Moyenne</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($details as $d)
                        <tr>
                            <td class="l num t-dim">{{ \Carbon\Carbon::parse($d['date'])->format('d/m/Y') }}</td>
                            <td class="l">{{ str_replace(' vs ', ' · ', $d['match']) }}</td>
                            <td class="l">{{ $d['competition'] }}</td>
                            <td class="t-hi">{{ $signed($d['clv_home']) }}</td>
                            <td class="t-hi">{{ $signed($d['clv_draw']) }}</td>
                            <td class="t-hi">{{ $signed($d['clv_away']) }}</td>
                            <td class="t-hi">{{ $signed($d['clv_over']) }}</td>
                            <td class="t-hi">{{ $signed($d['clv_avg']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-terminal.panel>

    <x-terminal.panel title="Derniers relevés">
        <x-slot:meta>20 derniers · cotes brutes</x-slot:meta>
        @if ($movements->isEmpty())
            <x-terminal.note>Aucun relevé enregistré.</x-terminal.note>
        @else
            <div class="t-table-wrap">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th class="l">Relevé UTC</th>
                            <th class="l">Rencontre</th>
                            <th class="l">Bookmaker</th>
                            <th>1</th>
                            <th>X</th>
                            <th>2</th>
                            <th>Over 2.5</th>
                            <th>Under 2.5</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($movements as $mv)
                        <tr>
                            <td class="l num t-dim">{{ $mv->snapshot_at?->copy()->utc()->format('d/m H:i') }}</td>
                            <td class="l">
                                @if ($mv->match)
                                    <a class="match-btn" href="{{ route('history.show', $mv->match_id) }}">{{ $mv->match->home_team }} <span class="team-sep">·</span> {{ $mv->match->away_team }}</a>
                                @endif
                            </td>
                            <td class="l">{{ $mv->bookmaker }}</td>
                            <td class="t-hi">{{ Fmt::odds($mv->odds_home !== null ? (float) $mv->odds_home : null) }}</td>
                            <td class="t-hi">{{ Fmt::odds($mv->odds_draw !== null ? (float) $mv->odds_draw : null) }}</td>
                            <td class="t-hi">{{ Fmt::odds($mv->odds_away !== null ? (float) $mv->odds_away : null) }}</td>
                            <td class="t-hi">{{ Fmt::odds($mv->odds_over_2_5 !== null ? (float) $mv->odds_over_2_5 : null) }}</td>
                            <td class="t-hi">{{ Fmt::odds($mv->odds_under_2_5 !== null ? (float) $mv->odds_under_2_5 : null) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-terminal.note>Cotes relevées telles quelles. Aucun score de mouvement affiché.</x-terminal.note>
        @endif
    </x-terminal.panel>
</div>
</x-terminal-layout>
