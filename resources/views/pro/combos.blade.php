@extends('layouts.pro')

@section('title', 'Combos')
@section('page-title', 'Combos du jour')
@section('page-subtitle', \Carbon\Carbon::parse($date)->format('d/m/Y') . ' — ' . $combos->count() . ' combo(s)')

@section('content')

{{-- Navigation date --}}
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-2">
        <a href="{{ route('combos.index', ['date' => $prevDate]) }}" class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-500 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <input type="date" value="{{ $date }}" onchange="window.location.href='{{ route('combos.index') }}?date='+this.value"
               class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-slate-700 focus:ring-2 focus:ring-blue-500">
        <a href="{{ route('combos.index', ['date' => $nextDate]) }}" class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-500 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
        <a href="{{ route('combos.index') }}" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors">Aujourd'hui</a>
    </div>
    <div class="text-xs text-slate-400">
        Seuils : confiance > {{ env('COMBO_MIN_CONFIDENCE', 65) }}% | cote {{ env('COMBO_MIN_ODDS', 1.90) }}-{{ env('COMBO_MAX_ODDS', 2.10) }} | {{ env('COMBO_MIN_MATCHES', 3) }}-{{ env('COMBO_MAX_MATCHES', 4) }} matchs
    </div>
</div>

@if($combos->isEmpty())
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-10 text-center">
        <p class="text-sm text-slate-500 mb-2">Aucun combo pour le {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</p>
        <p class="text-xs text-slate-400">
            Lancez : <code class="bg-slate-100 px-1.5 py-0.5 rounded">php artisan combos:generate {{ $date }}</code>
        </p>
    </div>
@else
    <div class="space-y-6">
        @foreach($combos as $combo)
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                {{-- Header combo --}}
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold
                            {{ $combo->rank === 1 ? 'bg-blue-600 text-white' : ($combo->rank === 2 ? 'bg-slate-200 text-slate-700' : 'bg-slate-100 text-slate-500') }}">
                            #{{ $combo->rank }}
                        </span>
                        <div>
                            <span class="text-sm font-semibold text-slate-800">Combo {{ $combo->match_count }} matchs</span>
                            <span class="text-xs text-slate-400 ml-2">Score: {{ $combo->combo_score }}/100</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-lg font-bold text-slate-800">{{ number_format($combo->total_odds, 2) }}</div>
                            <div class="text-xs text-slate-400">cote totale</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold {{ $combo->avg_confidence >= 70 ? 'text-emerald-600' : 'text-amber-600' }}">{{ number_format($combo->avg_confidence, 0) }}%</div>
                            <div class="text-xs text-slate-400">confiance moy.</div>
                        </div>
                        @if($combo->won !== null)
                            <span class="px-2.5 py-1 text-xs font-medium rounded {{ $combo->won ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                                {{ $combo->won ? 'WON' : 'LOST' }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Picks --}}
                <div class="divide-y divide-slate-50">
                    @foreach($combo->picks as $pick)
                        <div class="px-5 py-3 flex items-center justify-between">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium text-slate-800">{{ $pick['home'] }}</span>
                                    <span class="text-slate-400">v</span>
                                    <span class="font-medium text-slate-800">{{ $pick['away'] }}</span>
                                </div>
                                <div class="text-xs text-slate-400 mt-0.5">
                                    {{ $pick['competition'] }}
                                </div>
                            </div>
                            <div class="flex items-center gap-4 flex-shrink-0">
                                <div class="text-right">
                                    @php
                                        $marketNames = ['winner' => '1X2', 'overUnder' => 'O/U', 'btts' => 'BTTS', 'doubleChance' => 'DC'];
                                    @endphp
                                    <span class="text-sm font-semibold text-slate-700">{{ $marketNames[$pick['market']] ?? $pick['market'] }}: {{ $pick['pick'] }}</span>
                                </div>
                                <span class="text-sm font-mono text-slate-600">{{ number_format($pick['odds'], 2) }}</span>
                                <span class="text-xs font-medium {{ $pick['confidence'] >= 70 ? 'text-emerald-600' : 'text-amber-600' }}">{{ $pick['confidence'] }}%</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif

@endsection
