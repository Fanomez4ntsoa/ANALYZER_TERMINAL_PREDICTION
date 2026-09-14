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
                    ['name' => 'The Odds API', 'key' => $config['odds_api_key'], 'detail' => 'Cotes (bookmaker unique) + CLV'],
                    ['name' => 'OpenWeatherMap', 'key' => $config['openweathermap_key'], 'detail' => 'Meteo des matchs'],
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
            <p class="text-xs text-slate-500 mt-0.5">pipeline:daily chaque jour (cron schedule:run requis)</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <span class="text-slate-500">Passage quotidien</span>
                    <span class="block font-medium text-slate-800">{{ $config['schedule_time'] }}</span>
                </div>
                <div>
                    <span class="text-slate-500">Créneau des matchs (UTC)</span>
                    <span class="block font-medium text-slate-800">{{ $config['match_start_hour'] }}h – {{ $config['match_end_hour'] }}h</span>
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
                        <code class="text-xs text-slate-700">php artisan predictions:compute {{ now()->format('Y-m-d') }}</code>
                        <p class="text-xs text-slate-400 mt-0.5">Calculer les probabilités des matchs à venir</p>
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
            <h3 class="text-sm font-semibold text-slate-800">Bookmaker de référence</h3>
            <p class="text-xs text-slate-500 mt-0.5">Un seul bookmaker, aucune cote stockée s'il est absent</p>
        </div>
        <div class="p-5 space-y-3">
            @php
                $comboParams = [
                    ['label' => 'The Odds API (ODDS_API_BOOKMAKER)', 'key' => 'bookmaker_odds_api', 'unit' => ''],
                    ['label' => 'API-Football id (API_FOOTBALL_PREFERRED_BOOKMAKER)', 'key' => 'bookmaker_api_football', 'unit' => ''],
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

</div>

@endsection
