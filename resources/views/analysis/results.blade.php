@extends('layouts.pro')

@section('title', 'Prédictions')
@section('page-title', 'Prédictions')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="space-y-6">

        {{-- Sélecteur de matchs récents --}}
        @if(isset($allRecentMatches) && $allRecentMatches->count() > 1)
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-bold text-slate-700 mb-3">Matchs récents avec prédictions</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($allRecentMatches as $recentMatch)
                <a href="{{ route('analysis.results', ['match_id' => $recentMatch->id]) }}"
                   class="block p-3 rounded hover:bg-slate-50 transition border
                          {{ $recentMatch->id === $selectedMatchId ? 'bg-blue-50 border-blue-300' : 'border-slate-200' }}">
                    <div class="font-semibold text-slate-700 text-sm">
                        {{ $recentMatch->home_team }} <span class="text-slate-500">vs</span> {{ $recentMatch->away_team }}
                    </div>
                    <div class="text-xs text-slate-400 mt-1">
                        {{ displayDate($recentMatch->match_date) }} • {{ $recentMatch->competition }}
                    </div>
                </a>
                @endforeach
            </div>
        </div>
        @endif

        @include('analysis.partials.match-header', ['footballMatch' => $footballMatch])

        @include('analysis.partials.predictions', ['predictionsByMarket' => $predictionsByMarket])

        @include('analysis.partials.actions')
    </div>
</div>
@endsection
