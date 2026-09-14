{{--
    Matchs d'une date : données disponibles et calcul à la demande. Aucun calcul
    sur un match commencé ou contaminé (PredictionService refuse aussi côté serveur).
--}}
@php
    $computable = $matches->where('can_compute', true)->values();
    $jsMatches = fn ($list) => $list->map(fn ($m) => ['id' => $m->id, 'name' => "{$m->home_team} · {$m->away_team}", 'computed' => $m->predictions->isNotEmpty()])->values();
@endphp

<x-terminal-layout :title="'Matchs du ' . $date->format('d/m/Y')">
<div x-data="matchCompute({{ Js::from(['matches' => $jsMatches($computable), 'computeUrl' => url('/analysis/analyze-match')]) }})" class="flex flex-col gap-gap">

    <x-terminal.panel :title="'Matchs du ' . $date->locale('fr')->translatedFormat('j F Y')">
        <x-slot:meta>
            <span class="num">{{ $matches->count() }} matchs · {{ $computable->count() }} calculables</span>
            <span class="team-sep">│</span>
            <a class="panel-link" href="{{ route('analysis.index', ['date' => $date->subDay()->toDateString()]) }}">‹ {{ $date->subDay()->format('d/m') }}</a>
            <form method="GET" action="{{ route('analysis.index') }}" class="flex items-center gap-gap">
                <label for="match-date" class="sr-only">Date</label>
                <input id="match-date" class="input num" type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()">
            </form>
            <a class="panel-link" href="{{ route('analysis.index', ['date' => $date->addDay()->toDateString()]) }}">{{ $date->addDay()->format('d/m') }} ›</a>
        </x-slot:meta>

        <div class="flex flex-wrap items-center gap-group">
            <button type="button" class="toggle" id="compute-pending" @click="computeAll(false)" :disabled="running || pending.length === 0">
                <i>Calculer</i><span x-text="'les ' + pending.length + ' non calculés'">les matchs non calculés</span>
            </button>
            <button type="button" class="toggle" id="compute-all" @click="computeAll(true)" :disabled="running || matches.length === 0">
                <i>Recalculer</i><span x-text="'les ' + matches.length + ' matchs à venir'">les matchs à venir</span>
            </button>
            <span class="lab num" x-show="running || done" x-cloak x-text="(running ? 'Calcul ' : 'Terminé ') + progress + ' / ' + total + (current ? ' · ' + current : '')"></span>
        </div>
        <template x-for="err in errors" :key="err">
            <div class="state-line" role="status"><span class="state-key">Erreur</span><span class="state-msg" x-text="err" :title="err"></span></div>
        </template>

        @if ($matches->isEmpty())
            <x-terminal.note>Aucun match importé pour cette date. Import : <span class="text-p-mid">php artisan pipeline:run-sync {{ $date->toDateString() }}</span></x-terminal.note>
        @else
            <div class="t-table-wrap">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th class="l">Heure UTC</th>
                            <th class="l">Rencontre</th>
                            <th class="l">Championnat</th>
                            <th title="Cotes 1X2 du bookmaker unique">Cotes 1X2</th>
                            <th class="l">Données</th>
                            <th class="l">Probabilités</th>
                            <th class="l"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($matches as $m)
                        <tr @class(['is-out' => $m->hasKickedOff()])>
                            <td class="l num">{{ $m->match_date?->copy()->utc()->format('H:i') }}</td>
                            <td class="l">
                                {{ $m->home_team }} <span class="team-sep">·</span> {{ $m->away_team }}
                                @if ($m->post_kickoff_data)<span class="scope" title="Données collectées après le coup d'envoi : exclu de toute mesure">Contaminé</span>@endif
                            </td>
                            <td class="l">{{ $m->competition }}</td>
                            <td class="num">
                                @if ((float) $m->odds_home > 0)
                                    {{ App\Support\Terminal\Fmt::odds((float) $m->odds_home) }} · {{ App\Support\Terminal\Fmt::odds((float) $m->odds_draw) }} · {{ App\Support\Terminal\Fmt::odds((float) $m->odds_away) }}
                                    <span class="nature">{{ $m->odds_bookmaker ?? 'legacy_max' }}</span>
                                @endif
                            </td>
                            <td class="l t-dim">{{ $m->available_data === [] ? '—' : implode(', ', $m->available_data) }}</td>
                            <td class="l" id="status-{{ $m->id }}">
                                @if ($m->predictions->isNotEmpty())
                                    <span class="num">{{ $m->predictions->count() }} lignes</span>
                                    <span class="nature num">{{ $m->computed_at?->copy()->utc()->format('d/m H:i') }} UTC</span>
                                @elseif ($m->hasKickedOff())
                                    <span class="t-dim">Commencé, non calculé</span>
                                @elseif ((float) $m->odds_home <= 0)
                                    <span class="t-dim">Cotes absentes</span>
                                @else
                                    <span class="t-dim">Non calculé</span>
                                @endif
                            </td>
                            <td class="l">
                                <div class="flex items-center gap-gap">
                                    @if ($m->can_compute)
                                        <button type="button" class="toggle" id="compute-{{ $m->id }}" @click="computeOne({{ $m->id }})" :disabled="running">{{ $m->predictions->isNotEmpty() ? 'Recalculer' : 'Calculer' }}</button>
                                    @endif
                                    @if ($m->predictions->isNotEmpty())
                                        <a class="toggle" href="{{ route('history.show', $m->id) }}">Détail</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-terminal.note class="max-w-none">Calcul possible avant le coup d'envoi seulement, sur un match non contaminé avec cotes 1X2 complètes. Recalculer remplace les probabilités du match. Le passage quotidien calcule déjà les matchs à venir.</x-terminal.note>
        @endif
    </x-terminal.panel>
</div>
</x-terminal-layout>
