{{-- En-tête du match --}}
<div class="bg-white rounded-xl shadow-sm p-6">
    @php
        // Gérer les 2 formats : array (session) et objet (BDD)
        $homeTeam = is_array($match) ? $match['teams']['home'] : $match->home_team;
        $awayTeam = is_array($match) ? $match['teams']['away'] : $match->away_team;
        $matchDate = is_array($match) ? $match['date'] : $match->match_date;
        $competition = is_array($match) ? $match['competition'] : $match->competition;
        
        $oddsHome = is_array($match) ? $match['odds']['home'] : $match->odds_home;
        $oddsDraw = is_array($match) ? $match['odds']['draw'] : $match->odds_draw;
        $oddsAway = is_array($match) ? $match['odds']['away'] : $match->odds_away;
    @endphp
    
    <h2 class="text-3xl font-bold text-slate-700">
        {{ $homeTeam }} vs {{ $awayTeam }}
    </h2>
    
    <p class="text-slate-600 mt-2">
        {{ displayDate($matchDate) }} | {{ $competition }}
    </p>
    
    <div class="text-slate-800 flex gap-6 mt-4 text-sm flex-wrap">
        <span class="font-medium">
            Cotes: {{ (float)$oddsHome > 0 ? number_format($oddsHome, 2) . ' / ' . number_format($oddsDraw, 2) . ' / ' . number_format($oddsAway, 2) : 'N/D' }}
        </span>
        
        <span class="font-bold text-blue-400">
            Confiance globale: {{ $analysis['globalConfidence'] }}%
        </span>
        
        @if(isset($analysis['context']['description']))
            <span class="text-slate-500">
                {{ $analysis['context']['description'] }}
            </span>
        @endif
    </div>
</div>