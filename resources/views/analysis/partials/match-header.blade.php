{{-- En-tête du match --}}
<div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="text-3xl font-bold text-slate-700">
        {{ $footballMatch->home_team }} vs {{ $footballMatch->away_team }}
    </h2>

    <p class="text-slate-600 mt-2">
        {{ displayDate($footballMatch->match_date) }} | {{ $footballMatch->competition }}
    </p>

    <div class="text-slate-800 flex gap-6 mt-4 text-sm flex-wrap">
        <span class="font-medium">
            Cotes 1X2 :
            {{ (float) $footballMatch->odds_home > 0
                ? number_format($footballMatch->odds_home, 2) . ' / ' . number_format($footballMatch->odds_draw, 2) . ' / ' . number_format($footballMatch->odds_away, 2)
                : 'N/D' }}
        </span>
        @if($footballMatch->completed)
            <span class="font-semibold text-slate-700">Score final : {{ $footballMatch->score_home }} - {{ $footballMatch->score_away }}</span>
        @endif
    </div>
</div>
