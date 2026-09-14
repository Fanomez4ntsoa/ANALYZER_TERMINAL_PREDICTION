@extends('layouts.pro')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Etat du pipeline')

@section('content')

{{-- Metriques principales --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <x-pro.metric label="Matchs aujourd'hui" :value="$matchesToday" sublabel="importés par le pipeline" color="slate" />
    <x-pro.metric label="Matchs en DB" :value="$totalMatches" :sublabel="$completedMatches . ' terminés'" color="slate" />
    <x-pro.metric label="Avec prédictions" :value="$analyzedMatches" sublabel="probabilités calculées" color="brand" />
    <x-pro.metric label="Quota Odds API" :value="($oddsQuota['used'] ?? 0) . '/' . ($oddsQuota['limit'] ?? 500)" sublabel="ce mois" color="amber" />
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <x-pro.card title="Systeme" subtitle="Statut pipeline">
        <div class="space-y-3 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-slate-500">API-Football</span>
                <span class="flex items-center gap-1.5 text-emerald-600 font-medium"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>OK</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">The Odds API</span>
                <span class="flex items-center gap-1.5 text-emerald-600 font-medium"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>OK</span>
            </div>
            <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                <span class="text-slate-500">Quota Odds API</span>
                <span class="text-slate-700 font-medium">{{ $oddsQuota['used'] ?? 0 }}/{{ $oddsQuota['limit'] ?? 500 }}</span>
            </div>
            <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                @php $pct = ($oddsQuota['limit'] ?? 500) > 0 ? ($oddsQuota['used'] / $oddsQuota['limit']) * 100 : 0; @endphp
                <div class="h-full {{ $pct >= 80 ? 'bg-red-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min(100, $pct) }}%"></div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-slate-100">
                <span class="text-slate-500">Matchs en DB</span>
                <span class="text-slate-700 font-medium">{{ $totalMatches }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-slate-500">Matchs avec prédictions</span>
                <span class="text-slate-700 font-medium">{{ $analyzedMatches }}</span>
            </div>
        </div>
    </x-pro.card>
</div>

{{-- Matchs recents --}}
<x-pro.card title="Activite recente" :padding="false">
    <x-slot:actions>
        <a href="{{ route('history.index') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Historique complet</a>
    </x-slot:actions>
    <div class="divide-y divide-slate-100">
        @forelse($recentMatches as $match)
            <a href="{{ route('history.show', $match->id) }}" class="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-medium text-slate-800 truncate">{{ $match->home_team }}</span>
                        <span class="text-slate-400">v</span>
                        <span class="font-medium text-slate-800 truncate">{{ $match->away_team }}</span>
                    </div>
                    <div class="flex items-center gap-2 mt-0.5 text-xs text-slate-400">
                        <span>{{ displayDate($match->match_date, 'd/m H:i') }}</span>
                        <span>{{ $match->competition }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    @if($match->predictions_count > 0)
                        <span class="px-2 py-0.5 text-xs font-medium rounded bg-blue-50 text-blue-700">{{ $match->predictions_count }} issues</span>
                    @endif
                    @if($match->completed)
                        <span class="px-2 py-0.5 text-xs font-medium rounded bg-emerald-50 text-emerald-700">FT</span>
                    @elseif($match->match_date < now())
                        <span class="px-2 py-0.5 text-xs font-medium rounded bg-amber-50 text-amber-700">LIVE</span>
                    @else
                        <span class="px-2 py-0.5 text-xs font-medium rounded bg-slate-100 text-slate-600">A venir</span>
                    @endif
                    <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>
        @empty
            <div class="px-5 py-10 text-center text-sm text-slate-400">
                Aucun match. Lancez <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs">php artisan pipeline:run-sync</code>
            </div>
        @endforelse
    </div>
</x-pro.card>

@endsection

