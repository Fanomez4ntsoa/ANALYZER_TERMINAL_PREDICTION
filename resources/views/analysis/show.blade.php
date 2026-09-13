@extends('layouts.pro')

@section('title', 'Détail du Match')
@section('page-title', '👁️ Détail du Match')

@section('content')
<div class="container mx-auto px-4 py-8">
    
    {{-- Bouton retour --}}
    <div class="mb-6">
        <a href="{{ route('history.index') }}" 
           class="inline-flex items-center gap-2 text-blue-800 hover:text-blue-700 font-medium">
            ← Retour à l'historique
        </a>
    </div>

    {{-- En-tête du match --}}
    <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
        <h2 class="text-3xl font-bold text-slate-700">
            {{ $match['teams']['home'] }} vs {{ $match['teams']['away'] }}
        </h2>
        <p class="text-slate-600 mt-2">
            {{ displayDate($match['date']) }} | {{ $match['competition'] }}
        </p>
        <div class="flex gap-6 mt-4 text-sm flex-wrap">
            <span class="text-slate-800 font-medium">
                Cotes: {{ (float)$match['odds']['home'] > 0 ? number_format($match['odds']['home'], 2) . ' / ' . number_format($match['odds']['draw'], 2) . ' / ' . number_format($match['odds']['away'], 2) : 'N/D' }}
            </span>
            <span class="font-bold text-blue-600">
                Confiance globale: {{ $analysis['globalConfidence'] }}%
            </span>
            @if(isset($analysis['context']['description']))
                <span class="text-slate-600">
                    {{ $analysis['context']['description'] }}
                </span>
            @endif
        </div>

    </div>

    {{-- Recommandations principales --}}
    @if(!empty($analysis['recommendations']))
        @include('analysis.partials.recommendations', ['analysis' => $analysis])
    @endif

    {{-- Combinés suggérés --}}
    @if(!empty($analysis['combos']))
        @include('analysis.partials.combos', ['combos' => $analysis['combos']])
    @endif

    {{-- Recommandations enrichies Layer 2 --}}
    @if(!empty($analysis['advancedInsights']['enrichedRecommendations']))
        @include('analysis.partials.enriched-recommendations', ['advancedInsights' => $analysis['advancedInsights']])
    @endif

    {{-- Section Layer 2 (si disponible) --}}
    @if(isset($analysis['advancedInsights']))
        <div class="bg-white from-purple-50 to-indigo-50 rounded-xl p-6 border-2 border-purple-300 mb-6">
            <h3 class="text-xl font-bold text-purple-700 mb-4 flex items-center gap-2">
                ANALYSE LAYER 2
                <span class="text-sm font-normal bg-white px-2 py-1 rounded">
                    Score: {{ $analysis['advancedInsights']['globalScore'] }}/100
                </span>
            </h3>
            
            {{-- Convergence --}}
            <div class="mb-3 flex items-center gap-2 text-sm">
                <span class="text-slate-800 font-medium">Convergence:</span>
                @if($analysis['advancedInsights']['convergence'] === 'high')
                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs font-bold">
                        ✅ HAUTE
                    </span>
                @elseif($analysis['advancedInsights']['convergence'] === 'medium')
                    <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full text-xs font-bold">
                        ⚖️ MOYENNE
                    </span>
                @else
                    <span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs font-bold">
                        ⚠️ FAIBLE
                    </span>
                @endif
                <span class="text-slate-400">
                    (L1: {{ $analysis['advancedInsights']['layer1Score'] }} | L2: {{ $analysis['advancedInsights']['layer2Score'] }})
                </span>
            </div>
        </div>
    @endif

    {{-- Analyse avancée V2 (si données disponibles) --}}
    @php
        $hasAdvancedData = !empty($match['tacticalData']) 
            || !empty($match['sofascoreData']) 
            || !empty($match['footyStatsData']) 
            || !empty($match['fbrefData']);
    @endphp
    
    @if($hasAdvancedData)
        @include('analysis.partials.advanced-analysis', ['match' => $match])
    @endif

    {{-- Actions --}}
    @include('analysis.partials.actions')

</div>
@endsection