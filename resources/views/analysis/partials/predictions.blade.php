{{-- Tableau des prédictions par marché : probabilité modèle vs cote --}}
@php
    $marketLabels = [
        'winner' => '1X2',
        'doubleChance' => 'Double Chance',
        'overUnder25' => 'Over/Under 2.5',
        'btts' => 'BTTS',
    ];
    $pct = fn ($v) => $v === null ? '--' : number_format($v * 100, 1) . '%';
    $edgeClass = fn ($e) => $e === null ? 'text-slate-300' : ($e > 0 ? 'text-emerald-600' : 'text-red-500');
    $firstPrediction = collect($predictionsByMarket)->flatten(1)->first();
@endphp

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
        <h3 class="text-sm font-semibold text-slate-800">Prédictions du modèle</h3>
        @if($firstPrediction)
            <span class="text-xs text-slate-400">
                Calculé le {{ displayDate($firstPrediction->computed_at) }}
                @if($firstPrediction->bookmaker)
                    · cotes {{ $firstPrediction->bookmaker }}
                    @if($firstPrediction->odds_taken_at)
                        relevées le {{ displayDate($firstPrediction->odds_taken_at) }}
                    @endif
                @endif
            </span>
        @endif
    </div>

    @if(empty($predictionsByMarket))
        <div class="px-5 py-10 text-center text-sm text-slate-400">Aucune prédiction calculée pour ce match.</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Marché</th>
                        <th class="text-left px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Issue</th>
                        <th class="text-right px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Modèle</th>
                        <th class="text-right px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">Cote</th>
                        <th class="text-right px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide" title="1 / cote, marge incluse">Implicite</th>
                        <th class="text-right px-3 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide" title="Marge retirée, ensemble normalisé à 100%">Fair</th>
                        <th class="text-right px-5 py-2.5 text-xs font-semibold text-slate-500 uppercase tracking-wide" title="Modèle − Fair">Écart</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach($predictionsByMarket as $market => $rows)
                        @foreach($rows as $p)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-2 text-slate-600 {{ $loop->first ? 'font-medium' : 'text-slate-300' }}">
                                    {{ $loop->first ? ($marketLabels[$market] ?? $market) : '' }}
                                </td>
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $p->outcome }}</td>
                                <td class="px-3 py-2 text-right font-mono text-slate-800">{{ $pct($p->model_probability) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-slate-600">{{ $p->odds !== null ? number_format($p->odds, 2) : '--' }}</td>
                                <td class="px-3 py-2 text-right font-mono text-slate-500">{{ $pct($p->implied_probability) }}</td>
                                <td class="px-3 py-2 text-right font-mono text-slate-500">{{ $pct($p->fair_probability) }}</td>
                                <td class="px-5 py-2 text-right font-mono font-semibold {{ $edgeClass($p->edge) }}">
                                    {{ $p->edge === null ? '--' : (($p->edge > 0 ? '+' : '') . number_format($p->edge * 100, 1) . ' pt') }}
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
