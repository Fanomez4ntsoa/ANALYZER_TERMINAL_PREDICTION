{{-- Recommandations principales --}}
<div class="bg-white  rounded-xl shadow-sm p-6">
    <h3 class="text-2xl font-bold text-slate-700 mb-6">RECOMMANDATIONS</h3>
    
    <div class="space-y-4">
        @foreach($analysis['recommendations'] as $index => $rec)
            @php
                $levelColors = [
                    1 => 'bg-green-900',
                    2 => 'bg-green-800',
                    3 => 'bg-blue-600',
                    4 => 'bg-yellow-800'
                ];
                
                $levelLabels = [
                    1 => 'PRIORITÉ 1 - TRÈS SÛR',
                    2 => 'PRIORITÉ 2 - SÛR',
                    3 => 'PRIORITÉ 3 - MODÉRÉ',
                    4 => 'VALUE BET'
                ];
                
                $marketNames = [
                    'winner' => '1X2',
                    'overUnder' => 'Over/Under 2.5',
                    'btts' => 'BTTS',
                    'doubleChance' => 'Double Chance',
                    'exactScore' => 'Score Exact'
                ];
                
                $levelColor = $levelColors[$rec['level']] ?? 'bg-gray-400';
                $levelLabel = $levelLabels[$rec['level']] ?? 'AUTRE';
                $marketName = $marketNames[$rec['market']] ?? $rec['market'];
                
                $consensusType = $rec['consensus_type'] ?? 'N/A';
                $consensusAgreement = $rec['consensus_agreement'] ?? 0;
                
                $valueAnalysis = $rec['valueAnalysis'] ?? null;
                $valueVerdict = $rec['valueVerdict'] ?? 'NO_ODDS';
            @endphp
            
            <div class="border-2 border-slate-200 rounded-lg overflow-hidden">
                {{-- En-tête cliquable --}}
                <div class="{{ $levelColor }} text-slate-800 p-4 cursor-pointer flex justify-between items-center"
                     data-toggle-rec="rec-{{ $index }}">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold text-lg">{{ $levelLabel }}</span>
                            <span class="text-sm">(Score: {{ $rec['score'] }}/100)</span>
                            
                            @if($valueVerdict === 'VALUE')
                                <span class="bg-white text-green-800 px-2 py-1 rounded text-xs font-bold flex items-center gap-1">
                                    ✅ VALUE
                                    @if(isset($rec['valueBonus']))
                                        <span class="text-green-900">+{{ number_format($rec['valueBonus'], 1) }}</span>
                                    @endif
                                </span>
                            @elseif($valueVerdict === 'SUSPICIOUS')
                                <span class="bg-white text-red-600 px-2 py-1 rounded text-xs font-bold">
                                    🚨 SUSPECT
                                </span>
                            @elseif($valueVerdict === 'NO_VALUE')
                                <span class="bg-white text-orange-600 px-2 py-1 rounded text-xs font-bold">
                                    ⚠️ NO VALUE
                                </span>
                            @endif
                            
                            @if(!empty($rec['special_rule']))
                                <span class="bg-white text-purple-600 px-2 py-1 rounded text-xs font-bold">
                                    🌟 RÈGLE SPÉCIALE
                                </span>
                            @endif
                        </div>
                        
                        <div class="text-2xl font-bold mt-1">
                            {{ $marketName }}: {{ $rec['bet'] }}
                        </div>
                        
                        <div class="mt-2 flex gap-4 text-sm flex-wrap">
                            @if($valueAnalysis && ($valueAnalysis['hasOdds'] ?? false))
                                <span>Cote: {{ number_format($valueAnalysis['odds'], 2) }}</span>
                            @elseif($rec['odds'])
                                <span>Cote: ~{{ number_format($rec['odds'], 2) }}</span>
                            @else
                                <span>Cote: N/D</span>
                            @endif
                            
                            <span>Fiabilité: {{ $rec['confidence'] }}%</span>
                            
                            @if($valueAnalysis && $valueAnalysis['hasOdds'])
                                <span class="font-semibold {{ $valueAnalysis['edge'] >= 5 ? 'text-green-100' : 'text-orange-100' }}">
                                    Edge: {{ number_format($valueAnalysis['edge'], 1) }}%
                                </span>
                            @endif
                            
                            <span>
                                @if($consensusType === 'TOTAL')
                                    <svg class="inline mr-1 w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                @elseif($consensusType === 'CONFLIT')
                                    <svg class="inline mr-1 w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                                {{ $consensusType }}
                            </span>
                        </div>
                    </div>
                    
                    <div>
                        <svg data-chevron class="w-6 h-6 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                </div>
                
                {{-- Contenu détaillé (caché par défaut) --}}
                <div id="rec-{{ $index }}" class="hidden p-4 bg-white">
                    
                    @if($valueAnalysis && $valueAnalysis['hasOdds'])
                        <div class="mb-4 p-4 rounded-lg border-2 
                            {{ $valueVerdict === 'VALUE' ? 'bg-white border-green-300' : '' }}
                            {{ $valueVerdict === 'SUSPICIOUS' ? 'bg-white border-red-300' : '' }}
                            {{ $valueVerdict === 'NO_VALUE' ? 'bg-white border-orange-300' : '' }}
                        ">
                            <h4 class="font-bold mb-3
                                {{ $valueVerdict === 'VALUE' ? 'text-green-800' : '' }}
                                {{ $valueVerdict === 'SUSPICIOUS' ? 'text-red-800' : '' }}
                                {{ $valueVerdict === 'NO_VALUE' ? 'text-orange-800' : '' }}
                            ">
                                💰 Analyse de Value
                            </h4>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                                <div>
                                    <div class="text-xs text-slate-600">Cote Bookmaker</div>
                                    <div class="font-bold text-slate-700">{{ number_format($valueAnalysis['odds'], 2) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-slate-600">Proba Implicite</div>
                                    <div class="font-bold text-slate-700">{{ number_format($valueAnalysis['impliedProbability'], 1) }}%</div>
                                </div>
                                <div>
                                    <div class="text-xs text-slate-600">Notre Confiance</div>
                                    <div class="font-bold text-slate-700">{{ $rec['confidence'] }}%</div>
                                </div>
                                <div>
                                    <div class="text-xs text-slate-600">Edge Calculé</div>
                                    <div class="font-bold {{ $valueAnalysis['edge'] >= 5 ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $valueAnalysis['edge'] >= 0 ? '+' : '' }}{{ number_format($valueAnalysis['edge'], 1) }}%
                                    </div>
                                </div>
                            </div>
                            
                            {{-- Message de value --}}
                            @if(isset($rec['valueMessage']))
                                <div class="text-sm text-green-700 font-medium bg-white p-2 rounded">
                                    {{ $rec['valueMessage'] }}
                                </div>
                            @endif
                            
                            @if(isset($rec['valueWarning']))
                                <div class="text-sm {{ $valueVerdict === 'SUSPICIOUS' ? 'text-red-700 bg-white' : 'text-orange-700 bg-white' }} p-2 rounded">
                                    {{ $rec['valueWarning'] }}
                                </div>
                            @endif
                            
                            {{-- Kelly Stake si value détectée --}}
                            @if($valueVerdict === 'VALUE' && isset($valueAnalysis['kellyStake']) && $valueAnalysis['kellyStake'] > 0)
                                <div class="mt-3 pt-3 border-t border-green-200">
                                    <div class="text-xs text-green-700 mb-1">💡 Mise optimale (1/4 Kelly sur 1000€)</div>
                                    <div class="font-bold text-green-800 text-lg">{{ number_format($valueAnalysis['kellyStake'], 0) }}€</div>
                                    <div class="text-xs text-green-600 mt-1">Espérance de gain: +{{ number_format($valueAnalysis['edge'], 1) }}%</div>
                                </div>
                            @endif
                        </div>
                    @elseif($valueVerdict === 'NO_ODDS')
                        <div class="mb-4 p-3 bg-white border border-gray-300 rounded-lg">
                            <p class="text-sm text-slate-700">⚠️ Aucune cote disponible pour ce pari - Analyse de value impossible</p>
                        </div>
                    @endif
                    
                    <div class="mb-4">
                        <h4 class="font-bold text-slate-700 mb-2">Analyse détaillée:</h4>
                        <p class="text-slate-600 text-sm">{{ $rec['logic'] }}</p>
                    </div>
                    
                    @if(!empty($rec['special_rule_reason']))
                        <div class="mb-4 p-3 bg-white border border-purple-200 rounded-lg">
                            <h4 class="font-bold text-purple-700 mb-1">🌟 Règle spéciale:</h4>
                            <p class="text-sm text-purple-700">{{ $rec['special_rule_reason'] }}</p>
                        </div>
                    @endif
                    
                    <div class="mb-4">
                        <h4 class="font-bold text-slate-700 mb-2">Comparaison sources:</h4>
                        <div class="space-y-1">
                            @php
                                $predictions = is_string($rec['predictions']) 
                                    ? json_decode($rec['predictions'], true) 
                                    : $rec['predictions'];
                            @endphp
                            @if(is_array($predictions) && !empty($predictions))
                                @foreach($predictions as $pred)
                                    <div class="text-sm text-slate-600 flex items-center gap-2">
                                        <span class="font-medium">Source {{ $pred['source'] }}:</span>
                                        <span>{{ $pred['value'] }}</span>
                                        <span class="text-slate-400">({{ $pred['confidence'] }}%)</span>
                                    </div>
                                @endforeach
                            @else
                                <p class="text-sm text-slate-400">Aucune prédiction disponible</p>
                            @endif
                        </div>
                    </div>
                    
                    @if(!empty($rec['warnings']))
                        @php
                            // Décoder le JSON si c'est une string
                            $warnings = is_string($rec['warnings']) 
                                ? json_decode($rec['warnings'], true) 
                                : $rec['warnings'];
                        @endphp
                        
                        @if(is_array($warnings) && !empty($warnings))
                            <div class="mb-4 p-3 bg-white border border-yellow-200 rounded-lg">
                                <h4 class="font-bold text-yellow-200 mb-1">⚠️ Avertissements:</h4>
                                @foreach($warnings as $warning)
                                    <p class="text-sm text-yellow-300">{{ $warning }}</p>
                                @endforeach
                            </div>
                        @endif
                    @endif
                    
                    <div class="text-sm">
                        <span class="font-medium text-slate-500">Consensus:</span>
                        <span class="ml-2 text-gray-600">
                            {{ $consensusType }} ({{ $consensusAgreement }}/3)
                        </span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>