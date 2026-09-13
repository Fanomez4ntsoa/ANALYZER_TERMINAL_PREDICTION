@extends('layouts.pro')

@section('title', 'Historique')
@section('page-title', 'Historique')
@section('page-subtitle', $matches->total() . ' matchs — filtres avances')

@section('content')

{{-- Filtres --}}
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('history.index') }}" class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-6 gap-3 items-end">
        <div class="lg:col-span-2">
            <label class="block text-xs font-medium text-slate-600 mb-1">Recherche</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Equipe..."
                   class="w-full border border-slate-300 rounded-lg text-sm px-3 py-1.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Ligue</label>
            <select name="league" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-1.5 focus:ring-2 focus:ring-blue-500">
                <option value="">Toutes</option>
                @foreach($leagues as $l)
                    <option value="{{ $l->league_id }}" @selected(($filters['league'] ?? null) == $l->league_id)>{{ $l->competition }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Statut</label>
            <select name="status" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-1.5 focus:ring-2 focus:ring-blue-500">
                <option value="">Tous</option>
                <option value="analyzed" @selected(($filters['status'] ?? '') === 'analyzed')>Analyses</option>
                <option value="completed" @selected(($filters['status'] ?? '') === 'completed')>Termines</option>
                <option value="upcoming" @selected(($filters['status'] ?? '') === 'upcoming')>A venir</option>
                <option value="live" @selected(($filters['status'] ?? '') === 'live')>En cours</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="flex-1 px-3 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                Filtrer
            </button>
            <a href="{{ route('history.index') }}" class="px-3 py-1.5 border border-slate-200 text-slate-500 text-sm rounded-lg hover:bg-slate-50 transition-colors" title="Reset">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </a>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                   class="border border-slate-300 rounded-lg text-xs px-2 py-1.5 focus:ring-2 focus:ring-blue-500">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                   class="border border-slate-300 rounded-lg text-xs px-2 py-1.5 focus:ring-2 focus:ring-blue-500">
        </div>

        <div class="col-span-1 md:col-span-full flex justify-end">
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Export CSV
            </a>
        </div>
    </form>
</div>

@if($matches->isEmpty())
    <x-pro.card>
        <div class="text-center py-10">
            <p class="text-sm text-slate-500 mb-3">Aucun match ne correspond aux filtres</p>
            <a href="{{ route('history.index') }}" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Reset les filtres</a>
        </div>
    </x-pro.card>
@else
    <x-pro.card :title="$matches->total() . ' resultats'" :padding="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Match</th>
                        <th class="text-left px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Date</th>
                        <th class="text-left px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Competition</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Score</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Cotes</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Prédictions</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Statut</th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($matches as $match)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3">
                                <span class="font-medium text-slate-800">{{ $match->home_team }}</span>
                                <span class="text-slate-400 mx-1">v</span>
                                <span class="font-medium text-slate-800">{{ $match->away_team }}</span>
                            </td>
                            <td class="px-3 py-3 text-slate-500">{{ displayDate($match->match_date, 'd/m H:i') }}</td>
                            <td class="px-3 py-3">
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded bg-slate-100 text-slate-600">{{ $match->competition }}</span>
                            </td>
                            <td class="px-3 py-3 text-center font-mono text-xs">
                                @if($match->completed)
                                    <span class="text-slate-700 font-semibold">{{ $match->score_home }}-{{ $match->score_away }}</span>
                                @else
                                    <span class="text-slate-300">--</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center text-xs text-slate-500 font-mono">
                                {{ (float)$match->odds_home > 0 ? number_format($match->odds_home, 2) . '/' . number_format($match->odds_draw, 2) . '/' . number_format($match->odds_away, 2) : 'N/D' }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if($match->predictions_count > 0)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded bg-emerald-50 text-emerald-700">{{ $match->predictions_count }} issues</span>
                                @else
                                    <span class="text-slate-300">--</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if($match->completed)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded bg-emerald-50 text-emerald-700">FT</span>
                                @elseif($match->match_date < now())
                                    <span class="px-2 py-0.5 text-xs font-medium rounded bg-amber-50 text-amber-700">LIVE</span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-medium rounded bg-slate-100 text-slate-600">A venir</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right">
                                <div class="flex items-center gap-1 justify-end">
                                    <a href="{{ route('history.show', $match->id) }}" class="p-1.5 text-slate-400 hover:text-blue-600 rounded hover:bg-blue-50 transition-colors" title="Voir">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </a>
                                    <form action="{{ route('history.delete', $match->id) }}" method="POST" onsubmit="return confirm('Supprimer ce match ?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 rounded hover:bg-red-50 transition-colors" title="Supprimer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($matches->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $matches->links() }}
            </div>
        @endif
    </x-pro.card>
@endif

@endsection
