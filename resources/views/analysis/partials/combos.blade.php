{{-- Combinés suggérés --}}
<div class="bg-white rounded-xl shadow-sm p-6">
    <h3 class="text-2xl font-bold text-slate-700 mb-6">💰 COMBINÉS SUGGÉRÉS</h3>
    
    <div class="space-y-4">
        @foreach($combos as $combo)
            <div class="bg-white from-orange-100 to-red-100 p-4 rounded-lg border-2 border-orange-300">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <div class="font-bold text-lg text-slate-700 flex items-center gap-2">
                            @if($combo['priority'] === 'MAX')
                                <svg class="w-6 h-6 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/>
                                </svg>
                            @endif
                            {{ $combo['name'] }}
                        </div>
                        <div class="text-2xl font-bold text-orange-600 mt-1">
                            Cote totale: {{ $combo['totalOdds'] }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-slate-600">Confiance</div>
                        <div class="text-xl font-bold text-green-600">{{ $combo['confidence'] }}%</div>
                    </div>
                </div>
                
                <div class="mt-3 space-y-1">
                    @foreach($combo['bets'] as $bet)
                        <div class="text-sm text-slate-700">✓ {{ $bet }}</div>
                    @endforeach
                </div>
                
                <div class="mt-3 text-sm text-slate-600 italic">
                    {{ $combo['logic'] }}
                </div>
            </div>
        @endforeach
    </div>
</div>