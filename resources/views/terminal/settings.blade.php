{{--
    Réglages en lecture seule : tout se configure dans .env. Aucune lueur, aucune
    valeur vivante mise en avant.
--}}
@php
    use App\Models\PipelineRun;

    $statusLabel = [
        PipelineRun::SUCCESS => 'réussi',
        PipelineRun::INCOMPLETE => 'incomplet',
        PipelineRun::FAILED => 'en échec',
        PipelineRun::RUNNING => 'en cours ou interrompu',
    ];
    $commands = [
        'pipeline:daily [date]' => 'Passage quotidien : import, contexte, probabilités, relevé (planifié)',
        'pipeline:run-sync {date}' => 'Import des matchs et des cotes Bet365',
        'predictions:compute [date]' => 'Probabilités des matchs à venir, non contaminés, avec cotes',
        'context:enrich --date=' => 'Fatigue, enjeux, météo',
        'market:track snapshot|closing|close|clv' => 'Relevés et clôtures Pinnacle',
        'pipeline:backfill --from= --to=' => 'Import sur une période',
    ];
@endphp

<x-terminal-layout title="Réglages" :states="$states">
<div class="flex flex-col gap-gap">
    <x-terminal.note class="max-w-none">Lecture seule. Toute valeur se change dans le fichier .env, puis <span class="text-p-mid">php artisan config:cache</span>.</x-terminal.note>

    <div class="page-grid">
        <x-terminal.panel title="Clés d'API">
            <div class="rows">
                @foreach ($keys as $name => $key)
                    <div class="row">
                        <span class="flex flex-col gap-px">
                            <span class="lab">{{ $name }}</span>
                            <span class="note">{{ $key['role'] }}</span>
                        </span>
                        <span class="row-value">{{ $key['configured'] ? 'configurée' : 'absente' }}</span>
                    </div>
                @endforeach
            </div>
        </x-terminal.panel>

        <x-terminal.panel title="Quotas">
            <div class="rows">
                <x-terminal.row label="API-Football, aujourd'hui">
                    @if ($apiFootballUsage)
                        {{ $apiFootballUsage['current'] }} / {{ $apiFootballUsage['limit'] }} · {{ $apiFootballUsage['remaining'] }} restantes
                    @else
                        illisible
                    @endif
                </x-terminal.row>
                <x-terminal.row label="The Odds API, {{ $oddsQuota['month'] }}">{{ $oddsQuota['used'] }} / {{ $oddsQuota['limit'] }} · {{ $oddsQuota['remaining'] }} restantes</x-terminal.row>
            </div>
            <x-terminal.note>API-Football : le plus pessimiste de /status et du compteur local, les compteurs de l'API étant en retard.</x-terminal.note>
        </x-terminal.panel>

        <x-terminal.panel title="Bookmakers et périmètre">
            <div class="rows">
                <x-terminal.row label="Cotes des prédictions">API-Football id {{ $config['bookmaker_api_football'] }} (Bet365)</x-terminal.row>
                <x-terminal.row label="CLV">The Odds API · {{ $config['bookmaker_clv'] }}</x-terminal.row>
                <x-terminal.row label="Championnats avec cotes">{{ $config['odds_leagues'] }}</x-terminal.row>
                <x-terminal.row label="Championnats clôturés">{{ $config['closing_leagues'] }}</x-terminal.row>
            </div>
            <x-terminal.note>Un bookmaker unique par usage, jamais un maximum ni une moyenne. Le CLV mesure Pinnacle, pas le prix Bet365.</x-terminal.note>
        </x-terminal.panel>

        <x-terminal.panel title="Planification">
            <div class="rows">
                <x-terminal.row label="Passage quotidien">{{ $config['schedule'] }}</x-terminal.row>
                <x-terminal.row label="Créneau des matchs">{{ $config['match_window'] }}</x-terminal.row>
                <x-terminal.row label="Run de référence">#{{ $config['reference_run']['id'] ?? '—' }} · {{ $config['reference_run']['population'] ?? '' }} · {{ implode('-', $config['reference_run']['seasons'] ?? []) }}</x-terminal.row>
            </div>
            <x-terminal.note>Cron requis : <span class="text-p-mid">* * * * * php artisan schedule:run</span></x-terminal.note>
        </x-terminal.panel>
    </div>

    <x-terminal.panel title="Derniers passages du pipeline">
        <x-slot:meta>10 derniers · pipeline_runs</x-slot:meta>
        @if ($runs->isEmpty())
            <x-terminal.note>Aucun passage enregistré.</x-terminal.note>
        @else
            <div class="t-table-wrap">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th class="l">Date traitée</th>
                            <th class="l">Statut</th>
                            <th class="l">Démarré UTC</th>
                            <th>Durée</th>
                            <th class="l">Étapes en échec</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($runs as $run)
                        @php
                            $failed = array_keys(array_filter($run->steps ?? [], fn ($s) => ($s['exit_code'] ?? 0) !== 0));
                            $duration = $run->finished_at && $run->started_at ? $run->started_at->diffInSeconds($run->finished_at) : null;
                        @endphp
                        <tr>
                            <td class="l num">{{ $run->run_date?->format('d/m/Y') }}</td>
                            <td class="l">{{ $statusLabel[$run->status] ?? $run->status }}</td>
                            <td class="l num t-dim">{{ $run->started_at?->copy()->utc()->format('d/m H:i') }}</td>
                            <td class="num">@if ($duration !== null){{ (int) round($duration) }} s @endif</td>
                            <td class="l">{{ implode(', ', $failed) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-terminal.panel>

    <x-terminal.panel title="Commandes">
        <div class="rows">
            @foreach ($commands as $command => $role)
                <div class="row">
                    <span class="text-p-mid">php artisan {{ $command }}</span>
                    <span class="note text-right">{{ $role }}</span>
                </div>
            @endforeach
        </div>
    </x-terminal.panel>
</div>
</x-terminal-layout>
