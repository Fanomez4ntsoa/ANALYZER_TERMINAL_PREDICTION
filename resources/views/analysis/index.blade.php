@extends('layouts.pro')

@section('title', 'Matchs du jour')
@section('page-title', 'Matchs du jour')
@section('page-subtitle', $date . ' — ' . $matches->count() . ' matchs')

@section('content')

<div x-data="matchAnalyzer()" x-cloak>

    {{-- Navigation date + actions --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
            <a href="{{ route('analysis.index', ['date' => $prevDate]) }}"
               class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-500 hover:text-slate-700 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <input type="date" value="{{ $date }}"
                   onchange="window.location.href='{{ route('analysis.index') }}?date='+this.value"
                   class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm text-slate-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <a href="{{ route('analysis.index', ['date' => $nextDate]) }}"
               class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-500 hover:text-slate-700 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
            <a href="{{ route('analysis.index', ['date' => now()->format('Y-m-d')]) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 transition-colors">
                Aujourd'hui
            </a>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('analysis.manual') }}"
               class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 transition-colors">
                Saisie manuelle
            </a>

            @if($matches->count() > 0)
                <button @click="runAll()"
                        :disabled="analyzing"
                        class="px-4 py-2 text-white text-sm font-medium rounded-lg disabled:bg-blue-300 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                        :class="pendingMatches.length === 0 ? 'bg-slate-600 hover:bg-slate-700' : 'bg-blue-600 hover:bg-blue-700'">
                    <svg x-show="!analyzing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path x-show="pendingMatches.length > 0" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        <path x-show="pendingMatches.length === 0" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <svg x-show="analyzing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span x-text="analyzing
                        ? (reanalyzing ? 'Re-analyse en cours...' : 'Analyse en cours...') + ' (' + analyzed + '/' + total + ')'
                        : (pendingMatches.length === 0 ? 'Re-analyser tous les matchs' : 'Analyser tous les matchs')"></span>
                </button>
            @endif
        </div>
    </div>

    {{-- Progress bar --}}
    <div x-show="analyzing" x-transition class="mb-6">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-slate-700" x-text="currentMatch || 'Preparation...'"></span>
                <span class="text-sm text-slate-500" x-text="analyzed + '/' + total"></span>
            </div>
            <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-blue-600 rounded-full transition-all duration-300"
                     :style="'width: ' + (total > 0 ? (analyzed / total) * 100 : 0) + '%'"></div>
            </div>
            <div x-show="errors.length > 0" class="mt-2">
                <template x-for="err in errors" :key="err">
                    <p class="text-xs text-red-600" x-text="err"></p>
                </template>
            </div>
        </div>
    </div>

    {{-- Liste des matchs --}}
    @if($matches->isEmpty())
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-10 text-center">
            <p class="text-sm text-slate-500 mb-2">Aucun match pour le {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</p>
            <p class="text-xs text-slate-400">Lancez le pipeline : <code class="bg-slate-100 px-1.5 py-0.5 rounded">php artisan pipeline:run-sync {{ $date }}</code></p>
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Match</th>
                        <th class="text-left px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Competition</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Heure</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Cotes 1X2</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Sources</th>
                        <th class="text-center px-3 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Confiance</th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($matches as $match)
                        <tr class="hover:bg-slate-50 transition-colors" id="match-row-{{ $match->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-slate-800">{{ $match->home_team }}</span>
                                    <span class="text-slate-400">v</span>
                                    <span class="font-medium text-slate-800">{{ $match->away_team }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded bg-slate-100 text-slate-600">{{ $match->competition }}</span>
                            </td>
                            <td class="px-3 py-3 text-center text-slate-500">
                                {{ displayTime($match->match_date) }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if((float) $match->odds_home > 0)
                                    <span class="text-xs font-mono text-slate-600">
                                        {{ number_format($match->odds_home, 2) }} / {{ number_format($match->odds_draw, 2) }} / {{ number_format($match->odds_away, 2) }}
                                    </span>
                                @else
                                    <span class="text-slate-300 text-xs">--</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    @foreach($match->available_sources as $src => $available)
                                        <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded {{ $available ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-50 text-slate-300' }}"
                                              title="{{ $src }}">
                                            {{ strtoupper(substr($src, 0, 1)) }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-3 py-3 text-center" id="confidence-{{ $match->id }}">
                                @if($match->is_analyzed)
                                    <span class="font-semibold {{ $match->global_confidence >= 70 ? 'text-emerald-600' : ($match->global_confidence >= 50 ? 'text-amber-600' : 'text-slate-400') }}">
                                        {{ $match->global_confidence }}%
                                    </span>
                                @else
                                    <span class="text-slate-300 text-xs">Non analyse</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right">
                                @if($match->is_analyzed)
                                    <a href="{{ route('history.show', $match->id) }}"
                                       class="px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition-colors">
                                        Voir
                                    </a>
                                @else
                                    <button @click="analyzeSingle({{ $match->id }}, '{{ $match->home_team }} v {{ $match->away_team }}')"
                                            :disabled="analyzing"
                                            class="px-3 py-1.5 text-xs font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:bg-blue-300 disabled:cursor-not-allowed transition-colors">
                                        Analyser
                                    </button>
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

@push('scripts')
<script>
function matchAnalyzer() {
    return {
        analyzing: false,
        reanalyzing: false,
        analyzed: 0,
        total: 0,
        currentMatch: '',
        errors: [],

        // Matchs non analysés (pour analyze)
        pendingMatches: @json($matches->where('is_analyzed', false)->map(fn($m) => ['id' => $m->id, 'name' => $m->home_team . ' v ' . $m->away_team])->values()),

        // Tous les matchs (pour re-analyze)
        allMatches: @json($matches->map(fn($m) => ['id' => $m->id, 'name' => $m->home_team . ' v ' . $m->away_team])->values()),

        async analyzeSingle(matchId, matchName) {
            this.analyzing = true;
            this.reanalyzing = false;
            this.currentMatch = matchName;
            this.total = 1;
            this.analyzed = 0;

            await this.runAnalysis(matchId, matchName);

            this.analyzing = false;

            if (this.errors.length === 0) {
                window.location.reload();
            }
        },

        /**
         * Route vers analyzeAll() si pending matches > 0, sinon reanalyzeAll().
         */
        async runAll() {
            if (this.pendingMatches.length > 0) {
                await this.analyzeAll();
            } else {
                await this.reanalyzeAll();
            }
        },

        async analyzeAll() {
            if (this.pendingMatches.length === 0) return;

            this.analyzing = true;
            this.reanalyzing = false;
            this.analyzed = 0;
            this.total = this.pendingMatches.length;
            this.errors = [];

            for (const match of this.pendingMatches) {
                this.currentMatch = match.name;
                await this.runAnalysis(match.id, match.name);
                this.analyzed++;
            }

            this.currentMatch = 'Termine !';
            this.analyzing = false;

            setTimeout(() => {
                window.location.href = '{{ route("analysis.results") }}';
            }, 800);
        },

        async reanalyzeAll() {
            if (this.allMatches.length === 0) return;

            if (!confirm(`Re-analyser les ${this.allMatches.length} matchs ? Les recommandations actuelles seront ecrasees.`)) {
                return;
            }

            this.analyzing = true;
            this.reanalyzing = true;
            this.analyzed = 0;
            this.total = this.allMatches.length;
            this.errors = [];

            for (const match of this.allMatches) {
                this.currentMatch = match.name;
                await this.runAnalysis(match.id, match.name);
                this.analyzed++;
            }

            this.currentMatch = 'Re-analyse terminee !';
            this.analyzing = false;
            this.reanalyzing = false;

            setTimeout(() => {
                window.location.reload();
            }, 800);
        },

        async runAnalysis(matchId, matchName) {
            try {
                const response = await fetch(`/analysis/analyze-match/${matchId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const data = await response.json();

                if (data.success) {
                    // Mettre à jour la confiance dans le tableau
                    const cell = document.getElementById(`confidence-${matchId}`);
                    if (cell) {
                        const conf = data.global_confidence;
                        const color = conf >= 70 ? 'text-emerald-600' : (conf >= 50 ? 'text-amber-600' : 'text-slate-400');
                        cell.innerHTML = `<span class="font-semibold ${color}">${conf}%</span>`;
                    }
                } else {
                    this.errors.push(`${matchName}: ${data.error || 'Erreur inconnue'}`);
                }
            } catch (e) {
                this.errors.push(`${matchName}: ${e.message}`);
            }
        },
    };
}
</script>
@endpush
