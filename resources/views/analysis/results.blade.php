@extends('layouts.pro')

@section('title', 'Resultat')
@section('page-title', 'Resultat Analyse')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="space-y-6">

        {{-- Sélecteur de matchs récents --}}
        @if(isset($allRecentMatches) && $allRecentMatches->count() > 1)
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-bold text-slate-700 mb-3">Matchs récents analysés</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($allRecentMatches as $recentMatch)
                <a href="{{ route('analysis.results', ['match_id' => $recentMatch->id]) }}"
                   class="block p-3 rounded hover:bg-slate-50 transition border
                          {{ isset($selectedMatchId) && $recentMatch->id === $selectedMatchId
                             ? 'bg-blue-50 border-blue-300'
                             : 'border-slate-200' }}">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="font-semibold text-slate-700 text-sm">
                                {{ $recentMatch->home_team }} <span class="text-slate-500">vs</span> {{ $recentMatch->away_team }}
                            </div>
                            <div class="text-xs text-slate-400 mt-1">
                                {{ displayDate($recentMatch->match_date) }} • {{ $recentMatch->competition }}
                            </div>
                        </div>
                        <div class="ml-2">
                            <div class="text-sm font-bold text-slate-700">{{ $recentMatch->global_confidence }}%</div>
                            <div class="text-xs text-slate-400">confiance</div>
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- En-tête du match --}}
        @include('analysis.partials.match-header', ['match' => $match, 'analysis' => $analysis])
        
        {{-- Recommandations principales --}}
        @include('analysis.partials.recommendations', ['analysis' => $analysis])
        
        {{-- Combinés suggérés --}}
        @if(!empty($analysis['combos']))
            @include('analysis.partials.combos', ['combos' => $analysis['combos']])
        @endif
        
        {{-- Recommandations enrichies Layer 2 --}}
        @php
            $hasEnrichedRecs = false;
            if (is_array($analysis) && isset($analysis['advancedInsights']['enrichedRecommendations'])) {
                $hasEnrichedRecs = !empty($analysis['advancedInsights']['enrichedRecommendations']);
            }
        @endphp
        
        @if($hasEnrichedRecs)
            @include('analysis.partials.enriched-recommendations', ['advancedInsights' => $analysis['advancedInsights']])
        @endif
        
        {{-- Analyse avancée V2 --}}
        @php
            // Détecter si données avancées disponibles (session ou BDD)
            $hasAdvancedData = false;

            if (is_array($match)) {
                // Format session
                $hasAdvancedData = !empty($match['tacticalData']) 
                    || !empty($match['sofascoreData']) 
                    || !empty($match['footyStatsData']) 
                    || !empty($match['fbrefData']);
            } else {
                // Format BDD - vérifier si advancedData existe et a du contenu
                if (isset($match->advancedData)) {
                    $adv = $match->advancedData;
                    $hasAdvancedData = !empty($adv->tactical_data) 
                        || !empty($adv->sofascore_data) 
                        || !empty($adv->footystats_data) 
                        || !empty($adv->fbref_data);
                }
            }
        @endphp

        @if($hasAdvancedData)
            @include('analysis.partials.advanced-analysis', ['match' => $match])
        @endif
        
        {{-- Actions --}}
        @include('analysis.partials.actions')
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Toggle expand/collapse pour les recommandations
    document.querySelectorAll('[data-toggle-rec]').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = this.getAttribute('data-toggle-rec');
            const content = document.getElementById(target);
            const chevron = this.querySelector('[data-chevron]');
            
            content.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        });
    });
</script>
@endpush