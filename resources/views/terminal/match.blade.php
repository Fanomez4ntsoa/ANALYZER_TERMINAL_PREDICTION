{{--
    Détail d'un match : cotes, paramètres enregistrés avec la prédiction et
    probabilités dans l'ordre du catalogue des marchés. Aucune lueur : rien de
    vivant ici, sauf si le match est à venir, et même alors aucune valeur unique.
--}}
@php
    use App\Support\Terminal\Fmt;

    $first = $predictions->first();
    $status = match (true) {
        (bool) $match->completed => 'Terminé',
        $match->hasKickedOff() => 'Commencé',
        default => 'À venir',
    };
@endphp

<x-terminal-layout :title="$match->home_team . ' · ' . $match->away_team">
<div class="flex flex-col gap-gap">
    <div class="flex flex-wrap items-center gap-group">
        <a class="panel-link lab" href="{{ route('history.index') }}">‹ Historique</a>
        <a class="panel-link lab" href="{{ route('analysis.index', ['date' => $match->match_date?->copy()->utc()->toDateString()]) }}">Matchs du {{ $match->match_date?->copy()->utc()->format('d/m') }}</a>
    </div>

    <div class="page-grid">
        <x-terminal.panel title="Match">
            <x-slot:meta>{{ $status }}</x-slot:meta>
            <div class="flex flex-wrap items-baseline gap-group">
                <span class="match-title">{{ $match->home_team }} <span class="team-sep">·</span> {{ $match->away_team }}</span>
                @if ($match->completed)
                    <span class="readout num">{{ $match->score_home }}<small class="text-p-dim">-</small>{{ $match->score_away }}</span>
                @endif
                @if ($match->post_kickoff_data)
                    <span class="scope" title="Données collectées après le coup d'envoi : exclu de toute mesure">Contaminé</span>
                @endif
            </div>
            <div class="rows">
                <x-terminal.row label="Coup d'envoi">{{ $match->match_date?->copy()->utc()->format('d/m/Y H:i') }} UTC</x-terminal.row>
                <x-terminal.row label="Championnat">{{ $match->competition }}</x-terminal.row>
                <x-terminal.row label="Cotes 1X2">
                    @if ((float) $match->odds_home > 0)
                        {{ Fmt::odds((float) $match->odds_home) }} · {{ Fmt::odds((float) $match->odds_draw) }} · {{ Fmt::odds((float) $match->odds_away) }}
                    @endif
                </x-terminal.row>
                <x-terminal.row label="Bookmaker">{{ $first?->bookmaker ?? $match->odds_bookmaker }}</x-terminal.row>
            </div>
            @if (($first?->bookmaker ?? null) === 'legacy_max')
                <x-terminal.note>legacy_max : maximum entre bookmakers, cote injouable. L'écart de ce match n'a pas de sens.</x-terminal.note>
            @endif
        </x-terminal.panel>

        <x-terminal.panel title="Calcul enregistré">
            @if ($first === null)
                <x-terminal.note>Aucune probabilité calculée pour ce match.</x-terminal.note>
            @else
                <div class="rows">
                    <x-terminal.row label="Mode">{{ $first->model_mode === 'market_only' ? 'marché seul' : ($first->model_mode ?? 'non renseigné') }}</x-terminal.row>
                    <x-terminal.row :label="new Illuminate\Support\HtmlString('<span class=normal-case>λ</span> domicile · extérieur')">
                        <span class="normal-case">@if ($first->lambda_home !== null){{ Fmt::number($first->lambda_home, 3) }} · {{ Fmt::number($first->lambda_away, 3) }}@endif</span>
                    </x-terminal.row>
                    <x-terminal.row :label="new Illuminate\Support\HtmlString('<span class=normal-case>ρ</span> Dixon-Coles')">@if ($first->rho !== null){{ Fmt::number($first->rho, 3) }}@endif</x-terminal.row>
                    <x-terminal.row label="Cotes relevées">{{ $first->odds_taken_at?->copy()->utc()->format('d/m/Y H:i') }}@if ($first->odds_taken_at) UTC @endif</x-terminal.row>
                    <x-terminal.row label="Calculé">{{ $first->computed_at?->copy()->utc()->format('d/m/Y H:i') }} UTC</x-terminal.row>
                </div>
            @endif
        </x-terminal.panel>
    </div>

    <x-terminal.panel title="Probabilités">
        <x-slot:meta>Ordre du catalogue des marchés</x-slot:meta>
        @if ($predictions->isEmpty())
            <x-terminal.note>Aucune ligne.</x-terminal.note>
        @else
            <div class="t-table-wrap">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th class="l">Marché</th>
                            <th class="l">Nature</th>
                            <th title="Probabilité du modèle">Modèle %</th>
                            <th title="Cote du bookmaker unique">Cote</th>
                            <th title="1/cote, marge incluse">Implicite %</th>
                            <th title="Marge retirée, ensemble du marché normalisé à 100 %">Démarginalisée %</th>
                            <th title="Modèle moins probabilité démarginalisée, en points">Écart pts</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($predictions as $i => $p)
                        <tr @class(['group-start' => $i > 0 && $predictions[$i - 1]->market !== $p->market])>
                            <td class="l"><x-terminal.tag>{{ App\Support\Terminal\MarketLabel::tag($p->market, $p->outcome) }}</x-terminal.tag></td>
                            <td class="l"><x-terminal.nature :market="$p->market" /></td>
                            <td class="t-hi">{{ Fmt::percent($p->model_probability) }}</td>
                            <td class="t-hi">{{ Fmt::odds($p->odds) }}</td>
                            <td class="t-hi">{{ Fmt::percent($p->implied_probability) }}</td>
                            <td class="t-hi">{{ Fmt::percent($p->fair_probability) }}</td>
                            <td><x-terminal.edge :edge="$p->odds === null ? null : $p->edge" :market="$p->market" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-terminal.nature-note class="max-w-none" />
        @endif
    </x-terminal.panel>
</div>
</x-terminal-layout>
