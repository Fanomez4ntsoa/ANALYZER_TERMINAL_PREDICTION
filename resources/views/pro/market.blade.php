@extends('layouts.pro')

@section('title', 'Marche')
@section('page-title', 'Intelligence de marche')
@section('page-subtitle', 'CLV tracker, mouvements de cotes, sharp money')

@section('content')

{{-- KPI --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">CLV moyen</p>
        <p class="mt-1 text-2xl font-bold {{ $summary['avg_clv'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
            {{ $summary['avg_clv'] >= 0 ? '+' : '' }}{{ $summary['avg_clv'] }}%
        </p>
        <p class="text-xs text-slate-400 mt-0.5">{{ $summary['total_matches'] }} matchs</p>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">CLV positif</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ $summary['clv_positive_pct'] }}%</p>
        <p class="text-xs text-slate-400 mt-0.5">{{ $summary['clv_positive'] }}/{{ $summary['total_matches'] }}</p>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">CLV Home</p>
        <p class="mt-1 text-2xl font-bold {{ $summary['avg_clv_home'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
            {{ $summary['avg_clv_home'] >= 0 ? '+' : '' }}{{ $summary['avg_clv_home'] }}%
        </p>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Sharp alerts</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ $sharpAlerts->count() }}</p>
        <p class="text-xs text-slate-400 mt-0.5">recentes</p>
    </div>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Quota API</p>
        <p class="mt-1 text-2xl font-bold text-slate-800">{{ $quota['remaining'] }}</p>
        <p class="text-xs text-slate-400 mt-0.5">/ {{ $quota['limit'] }} restants</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    {{-- CLV par match --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">CLV par match</h3>
            <p class="text-xs text-slate-500 mt-0.5">CLV = (cote prise / cote cloture - 1) x 100</p>
        </div>
        @if(empty($summary['details']))
            <div class="p-5 text-center text-sm text-slate-400 py-10">
                Pas de donnees CLV.<br>
                <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs mt-2 inline-block">php artisan market:track snapshot</code>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Match</th>
                            <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">1</th>
                            <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">X</th>
                            <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">2</th>
                            <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">Moy</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($summary['details'] as $d)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2">
                                    <span class="text-slate-700 font-medium text-xs">{{ $d['match'] }}</span>
                                    <span class="text-slate-400 text-xs block">{{ $d['date'] }}</span>
                                </td>
                                <td class="text-center px-2 py-2 text-xs font-mono {{ $d['clv_home'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $d['clv_home'] >= 0 ? '+' : '' }}{{ $d['clv_home'] }}
                                </td>
                                <td class="text-center px-2 py-2 text-xs font-mono {{ $d['clv_draw'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $d['clv_draw'] >= 0 ? '+' : '' }}{{ $d['clv_draw'] }}
                                </td>
                                <td class="text-center px-2 py-2 text-xs font-mono {{ $d['clv_away'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $d['clv_away'] >= 0 ? '+' : '' }}{{ $d['clv_away'] }}
                                </td>
                                <td class="text-center px-2 py-2 text-xs font-semibold {{ $d['clv_avg'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $d['clv_avg'] >= 0 ? '+' : '' }}{{ $d['clv_avg'] }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Sharp money alerts --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Alertes sharp money</h3>
            <p class="text-xs text-slate-500 mt-0.5">Mouvements de cotes significatifs</p>
        </div>
        @if($sharpAlerts->isEmpty())
            <div class="p-5 text-center text-sm text-slate-400 py-10">
                Aucune alerte sharp money detectee
            </div>
        @else
            <div class="divide-y divide-slate-50">
                @foreach($sharpAlerts as $alert)
                    <div class="px-5 py-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-medium text-slate-800">{{ $alert->match->full_name }}</span>
                                <span class="text-xs text-slate-400 block mt-0.5">{{ displayDate($alert->snapshot_at, 'd/m H:i') }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded {{ $alert->sharp_score >= 80 ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' }}">
                                    Score: {{ $alert->sharp_score }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-2 flex gap-4 text-xs font-mono">
                            <span class="{{ ($alert->move_home_pct ?? 0) < -3 ? 'text-red-600 font-semibold' : 'text-slate-500' }}">
                                1: {{ $alert->move_home_pct !== null ? ($alert->move_home_pct >= 0 ? '+' : '') . $alert->move_home_pct . '%' : '-' }}
                            </span>
                            <span class="{{ ($alert->move_draw_pct ?? 0) < -3 ? 'text-red-600 font-semibold' : 'text-slate-500' }}">
                                X: {{ $alert->move_draw_pct !== null ? ($alert->move_draw_pct >= 0 ? '+' : '') . $alert->move_draw_pct . '%' : '-' }}
                            </span>
                            <span class="{{ ($alert->move_away_pct ?? 0) < -3 ? 'text-red-600 font-semibold' : 'text-slate-500' }}">
                                2: {{ $alert->move_away_pct !== null ? ($alert->move_away_pct >= 0 ? '+' : '') . $alert->move_away_pct . '%' : '-' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- Mouvements recents --}}
<div class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-800">Mouvements de cotes recents</h3>
            <p class="text-xs text-slate-500 mt-0.5">20 derniers snapshots</p>
        </div>
    </div>
    @if($recentMovements->isEmpty())
        <div class="p-5 text-center text-sm text-slate-400 py-10">
            Aucun mouvement enregistre.<br>
            <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs mt-2 inline-block">php artisan market:track snapshot --date={{ now()->format('Y-m-d') }}</code>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Match</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">Heure</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">1</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">X</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">2</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">O 2.5</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">Books</th>
                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">Sharp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($recentMovements as $mov)
                        <tr class="hover:bg-slate-50 {{ $mov->sharp_alert ? 'bg-amber-50/50' : '' }}">
                            <td class="px-4 py-2 text-xs text-slate-700 font-medium">{{ $mov->match->home_team }} v {{ $mov->match->away_team }}</td>
                            <td class="text-center px-2 py-2 text-xs text-slate-500">{{ displayDate($mov->snapshot_at, 'd/m H:i') }}</td>
                            <td class="text-center px-2 py-2 text-xs font-mono text-slate-600">{{ number_format($mov->odds_home, 2) }}</td>
                            <td class="text-center px-2 py-2 text-xs font-mono text-slate-600">{{ number_format($mov->odds_draw, 2) }}</td>
                            <td class="text-center px-2 py-2 text-xs font-mono text-slate-600">{{ number_format($mov->odds_away, 2) }}</td>
                            <td class="text-center px-2 py-2 text-xs font-mono text-slate-600">{{ $mov->odds_over_2_5 ? number_format($mov->odds_over_2_5, 2) : '-' }}</td>
                            <td class="text-center px-2 py-2 text-xs text-slate-500">{{ $mov->bookmaker_count }}</td>
                            <td class="text-center px-2 py-2">
                                @if($mov->sharp_alert)
                                    <span class="px-1.5 py-0.5 text-xs font-semibold rounded bg-red-50 text-red-700">{{ $mov->sharp_score }}</span>
                                @else
                                    <span class="text-slate-300">--</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection
