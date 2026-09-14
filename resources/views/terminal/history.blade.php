{{--
    Historique : tous les matchs, du plus récent au plus ancien. Filtres de
    navigation seulement (équipe, championnat, statut, dates) : aucun filtre ni
    tri sur l'écart, la probabilité ou la cote (docs/design-system.md, principe 1).
--}}
@php
    $statuses = ['' => 'Tous', 'analyzed' => 'Calculés', 'completed' => 'Terminés', 'upcoming' => 'À venir', 'live' => 'Commencés, non terminés'];
@endphp

<x-terminal-layout title="Historique">
<div class="flex flex-col gap-gap">
    <x-terminal.panel title="Filtres">
        <form method="GET" action="{{ route('history.index') }}" class="filters">
            <div class="field">
                <label class="lab" for="f-q">Équipe</label>
                <input class="input" id="f-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Nom d'équipe">
            </div>
            <div class="field">
                <label class="lab" for="f-league">Championnat</label>
                <select class="input" id="f-league" name="league">
                    <option value="">Tous</option>
                    @foreach ($leagues as $l)
                        <option value="{{ $l->league_id }}" @selected(($filters['league'] ?? null) == $l->league_id)>{{ $l->competition }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="lab" for="f-status">Statut</label>
                <select class="input" id="f-status" name="status">
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="lab" for="f-from">Du</label>
                <input class="input num" id="f-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="field">
                <label class="lab" for="f-to">Au</label>
                <input class="input num" id="f-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="flex flex-wrap items-center gap-gap">
                <button type="submit" class="toggle">Filtrer</button>
                <a class="toggle" href="{{ route('history.index') }}">Effacer</a>
                <a class="toggle" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" title="Une ligne par prédiction, matchs filtrés">CSV</a>
            </div>
        </form>
    </x-terminal.panel>

    <x-terminal.panel title="Matchs">
        <x-slot:meta><span class="num">{{ App\Support\Terminal\Fmt::number($matches->total(), 0) }} résultats · du plus récent au plus ancien</span></x-slot:meta>
        @if ($matches->isEmpty())
            <x-terminal.note>Aucun match ne correspond aux filtres.</x-terminal.note>
        @else
            <div class="t-table-wrap">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th class="l">Date UTC</th>
                            <th class="l">Rencontre</th>
                            <th class="l">Championnat</th>
                            <th>Score</th>
                            <th title="Cotes 1X2">Cotes 1X2</th>
                            <th>Lignes</th>
                            <th class="l">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($matches as $m)
                        <tr>
                            <td class="l num t-dim">{{ $m->match_date?->copy()->utc()->format('d/m/Y H:i') }}</td>
                            <td class="l">
                                <a class="match-btn" href="{{ route('history.show', $m->id) }}">{{ $m->home_team }} <span class="team-sep">·</span> {{ $m->away_team }}</a>
                                @if ($m->post_kickoff_data)<span class="scope" title="Données collectées après le coup d'envoi : exclu de toute mesure">Contaminé</span>@endif
                            </td>
                            <td class="l">{{ $m->competition }}</td>
                            <td class="num">@if ($m->completed){{ $m->score_home }}-{{ $m->score_away }}@endif</td>
                            <td class="num">
                                @if ((float) $m->odds_home > 0)
                                    {{ App\Support\Terminal\Fmt::odds((float) $m->odds_home) }} · {{ App\Support\Terminal\Fmt::odds((float) $m->odds_draw) }} · {{ App\Support\Terminal\Fmt::odds((float) $m->odds_away) }}
                                @endif
                            </td>
                            <td class="num">@if ($m->predictions_count > 0){{ $m->predictions_count }}@endif</td>
                            <td class="l t-dim">
                                @if ($m->completed) Terminé
                                @elseif ($m->hasKickedOff()) Commencé
                                @else À venir
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $matches->links('terminal.partials.pagination') }}
        @endif
    </x-terminal.panel>
</div>
</x-terminal-layout>
