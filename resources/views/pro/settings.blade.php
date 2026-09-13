@extends('layouts.pro')

@section('title', 'Parametres')
@section('page-title', 'Parametres')
@section('page-subtitle', 'Configuration du systeme')

@section('content')

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Statut APIs --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">APIs connectees</h3>
        </div>
        <div class="p-5 space-y-3">
            @php
                $apis = [
                    ['name' => 'API-Football', 'key' => $config['api_football_key'], 'detail' => 'Stats, H2H, blessures, predictions'],
                    ['name' => 'The Odds API', 'key' => $config['odds_api_key'], 'detail' => 'Cotes multi-bookmakers'],
                    ['name' => 'OpenWeatherMap', 'key' => $config['openweathermap_key'], 'detail' => 'Meteo des matchs'],
                    ['name' => 'Anthropic (Claude)', 'key' => $config['anthropic_key'], 'detail' => 'Analyse IA narrative (Phase 5)'],
                ];
            @endphp

            @foreach($apis as $api)
                <div class="flex items-center justify-between py-2 {{ !$loop->last ? 'border-b border-slate-50' : '' }}">
                    <div>
                        <p class="text-sm font-medium text-slate-800">{{ $api['name'] }}</p>
                        <p class="text-xs text-slate-400">{{ $api['detail'] }}</p>
                    </div>
                    @if($api['key'])
                        <span class="flex items-center gap-1.5 text-xs font-medium text-emerald-600">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            Connecte
                        </span>
                    @else
                        <span class="flex items-center gap-1.5 text-xs font-medium text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                            Non configure
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Quota Odds API --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Quota Odds API</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ $oddsQuota['month'] }}</p>
        </div>
        <div class="p-5">
            <div class="flex items-end justify-between mb-3">
                <span class="text-3xl font-bold text-slate-800">{{ $oddsQuota['used'] }}</span>
                <span class="text-sm text-slate-400">/ {{ $oddsQuota['limit'] }}</span>
            </div>
            <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                @php $pct = $oddsQuota['limit'] > 0 ? ($oddsQuota['used'] / $oddsQuota['limit']) * 100 : 0; @endphp
                <div class="h-full rounded-full {{ $pct >= 80 ? 'bg-red-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                     style="width: {{ min(100, $pct) }}%"></div>
            </div>
            <p class="text-xs text-slate-400 mt-2">{{ $oddsQuota['remaining'] }} requetes restantes ce mois</p>
            @if($pct >= 80)
                <p class="text-xs text-red-600 font-medium mt-1">Attention : quota proche de la limite</p>
            @endif
        </div>
    </div>

    {{-- Pipeline --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Pipeline</h3>
            <p class="text-xs text-slate-500 mt-0.5">Scheduler desactive — lancement manuel uniquement</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <span class="text-slate-500">Heure passage 1</span>
                    <span class="block font-medium text-slate-800">{{ $config['pipeline_time'] }}</span>
                </div>
                <div>
                    <span class="text-slate-500">Heure passage 2</span>
                    <span class="block font-medium text-slate-800">{{ $config['pipeline_update'] }}</span>
                </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
                <p class="text-xs text-slate-500 mb-3">Commandes disponibles :</p>
                <div class="space-y-2">
                    <div class="bg-slate-50 rounded-lg px-3 py-2">
                        <code class="text-xs text-slate-700">php artisan pipeline:run-sync {{ now()->format('Y-m-d') }}</code>
                        <p class="text-xs text-slate-400 mt-0.5">Importer matchs + cotes pour une date</p>
                    </div>
                    <div class="bg-slate-50 rounded-lg px-3 py-2">
                        <code class="text-xs text-slate-700">php artisan context:enrich --date={{ now()->format('Y-m-d') }}</code>
                        <p class="text-xs text-slate-400 mt-0.5">Enrichir le contexte (meteo, enjeu, fatigue)</p>
                    </div>
                    <div class="bg-slate-50 rounded-lg px-3 py-2">
                        <code class="text-xs text-slate-700">php artisan market:track snapshot</code>
                        <p class="text-xs text-slate-400 mt-0.5">Snapshot des cotes pour le CLV</p>
                    </div>
                    <div class="bg-slate-50 rounded-lg px-3 py-2">
                        <code class="text-xs text-slate-700">php artisan source-d:test --compare</code>
                        <p class="text-xs text-slate-400 mt-0.5">Comparer Source D vs cotes marche</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Config combos --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Configuration combos</h3>
            <p class="text-xs text-slate-500 mt-0.5">Seuils pour la selection automatique (Phase 6)</p>
        </div>
        <div class="p-5 space-y-3">
            @php
                $comboParams = [
                    ['label' => 'Confiance minimum', 'key' => 'combo_min_confidence', 'unit' => '%'],
                    ['label' => 'Cote totale min', 'key' => 'combo_min_odds', 'unit' => ''],
                    ['label' => 'Cote totale max', 'key' => 'combo_max_odds', 'unit' => ''],
                    ['label' => 'Matchs minimum', 'key' => 'combo_min_matches', 'unit' => ''],
                    ['label' => 'Matchs maximum', 'key' => 'combo_max_matches', 'unit' => ''],
                ];
            @endphp

            @foreach($comboParams as $param)
                <div class="flex items-center justify-between py-1.5 {{ !$loop->last ? 'border-b border-slate-50' : '' }}">
                    <span class="text-sm text-slate-600">{{ $param['label'] }}</span>
                    <span class="text-sm font-medium text-slate-800 font-mono">{{ $config[$param['key']] }}{{ $param['unit'] }}</span>
                </div>
            @endforeach

            <p class="text-xs text-slate-400 pt-2">Ces valeurs sont configurees dans le fichier .env</p>
        </div>
    </div>

    {{-- Sources --}}
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Hierarchie des sources</h3>
        </div>
        <div class="p-5">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="text-left px-3 py-2 text-xs font-semibold text-slate-500">Source</th>
                            <th class="text-left px-3 py-2 text-xs font-semibold text-slate-500">Type</th>
                            <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500">Poids</th>
                            <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500">1X2</th>
                            <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500">O/U</th>
                            <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500">BTTS</th>
                            <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500">DC</th>
                            <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500">Disponibilite</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @php
                            $sources = [
                                ['name' => 'D', 'type' => 'Poisson + xG + cotes', 'weight' => 'Principal', 'w' => '80%', 'ou' => '75%', 'btts' => '70%', 'dc' => '92%', 'avail' => 'Auto'],
                                ['name' => 'E', 'type' => 'API-Football predictions', 'weight' => 'Secondaire', 'w' => '65%', 'ou' => '55%', 'btts' => '50%', 'dc' => '82%', 'avail' => 'Auto'],
                                ['name' => 'A', 'type' => 'good-sport.co', 'weight' => 'Optionnel', 'w' => '60%', 'ou' => '61%', 'btts' => '63%', 'dc' => '86%', 'avail' => 'Manuel'],
                                ['name' => 'B', 'type' => 'mybets.today', 'weight' => 'Optionnel', 'w' => '59%', 'ou' => '68%', 'btts' => '67%', 'dc' => '90%', 'avail' => 'Manuel'],
                                ['name' => 'C', 'type' => 'probabilities', 'weight' => 'Optionnel', 'w' => '68%', 'ou' => '64%', 'btts' => '66%', 'dc' => '88%', 'avail' => 'Manuel'],
                            ];
                        @endphp
                        @foreach($sources as $src)
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 font-semibold text-slate-800">{{ $src['name'] }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ $src['type'] }}</td>
                                <td class="text-center px-3 py-2">
                                    <span class="px-2 py-0.5 text-xs font-medium rounded
                                        {{ $src['weight'] === 'Principal' ? 'bg-blue-50 text-blue-700' : ($src['weight'] === 'Secondaire' ? 'bg-slate-100 text-slate-600' : 'bg-slate-50 text-slate-400') }}">
                                        {{ $src['weight'] }}
                                    </span>
                                </td>
                                <td class="text-center px-3 py-2 text-xs font-mono text-slate-600">{{ $src['w'] }}</td>
                                <td class="text-center px-3 py-2 text-xs font-mono text-slate-600">{{ $src['ou'] }}</td>
                                <td class="text-center px-3 py-2 text-xs font-mono text-slate-600">{{ $src['btts'] }}</td>
                                <td class="text-center px-3 py-2 text-xs font-mono text-slate-600">{{ $src['dc'] }}</td>
                                <td class="text-center px-3 py-2">
                                    <span class="px-2 py-0.5 text-xs font-medium rounded {{ $src['avail'] === 'Auto' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                        {{ $src['avail'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

@endsection
