{{-- Recommandations enrichies Layer 2 --}}
<div class="bg-white from-purple-50 to-indigo-50 rounded-xl p-6 border-2 border-purple-300 mt-6">
    <h3 class="text-xl font-bold text-purple-700 mb-4 flex items-center gap-2">
        RECOMMANDATIONS ENRICHIES (Layer 2)
        <span class="text-sm font-normal bg-white px-2 py-1 rounded">
            Score global: {{ $advancedInsights['globalScore'] }}/100
        </span>
    </h3>
    
    {{-- Convergence indicator --}}
    <div class="mb-4 p-3 rounded-lg bg-white">
        <div class="flex items-center gap-2">
            <span class="font-medium">Convergence Layer1/Layer2:</span>
            
            @if($advancedInsights['convergence'] === 'high')
                <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-bold">
                    ✅ HAUTE - Très fiable
                </span>
            @elseif($advancedInsights['convergence'] === 'medium')
                <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-bold">
                    ⚖️ MOYENNE - Fiable
                </span>
            @else
                <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-bold">
                    ⚠️ FAIBLE - Prudence
                </span>
            @endif
        </div>
        <div class="text-sm text-gray-600 mt-1">
            Layer 1 (Sources): {{ $advancedInsights['layer1Score'] }}/100 | 
            Layer 2 (Contexte): {{ $advancedInsights['layer2Score'] }}/100
        </div>
    </div>
    
    {{-- Recommandations enrichies --}}
    <div class="space-y-3">
        @php
            // Décodage sécurisé
            $enrichedArray = is_string($advancedInsights['enrichedRecommendations'] ?? null)
                ? json_decode($advancedInsights['enrichedRecommendations'], true)
                : ($advancedInsights['enrichedRecommendations'] ?? []);
            
            $enrichedRecs = collect($enrichedArray)
                ->filter(fn($rec) => ($rec['level'] ?? 4) <= 3)
                ->sortByDesc('score');
        @endphp
        
        @foreach($enrichedRecs as $rec)
            @php
                $bgColors = [
                    1 => 'bg-green-50 border-green-400',
                    2 => 'bg-blue-50 border-blue-400',
                    3 => 'bg-gray-50 border-gray-300'
                ];
                $bgColor = $bgColors[$rec['level']] ?? 'bg-gray-50 border-gray-300';
                
                $marketIcons = [
                    'doubleChance' => '🎯 Double Chance',
                    'btts' => '⚽ BTTS',
                    'overUnder' => '📊 Over/Under 2.5',
                    'winner' => '🏆 1X2'
                ];
                $marketLabel = $marketIcons[$rec['market']] ?? $rec['market'];
            @endphp
            
            <div class="p-4 rounded-lg border-2 {{ $bgColor }}">
                <div class="flex justify-between items-start">
                    <div>
                        <div class="font-bold text-lg">
                            {{ $marketLabel }}: {{ $rec['bet'] }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            {{ $rec['logic'] }}
                        </div>
                        
                        {{-- Warnings enrichis --}}
                        @if(!empty($rec['warnings']) && is_array($rec['warnings']))
                            <div class="mt-2 space-y-1">
                                @foreach($rec['warnings'] as $warning)
                                    <div class="text-sm text-orange-600 font-medium">
                                        {{ $warning }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    
                    <div class="text-right">
                        <div class="text-2xl font-bold text-purple-600">
                            {{ $rec['confidence'] }}%
                        </div>
                        <div class="text-sm text-slate-400">
                            Score: {{ $rec['score'] }}/100
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    
    {{-- Risk factors --}}
    @if(!empty($advancedInsights['tacticalInsights']['riskFactors']))
        <div class="mt-4 p-3 bg-orange-50 rounded-lg border border-orange-200">
            <div class="font-bold text-orange-800 mb-2">⚠️ Facteurs de risque</div>
            @foreach($advancedInsights['tacticalInsights']['riskFactors'] as $risk)
                <div class="text-sm text-orange-700">{{ $risk }}</div>
            @endforeach
        </div>
    @endif
    
    {{-- Tableau comparatif Layer1 vs Layer2 --}}
    @if(!empty($advancedInsights['originalRecommendations']))
        <div class="mt-4 p-4 bg-white rounded-lg border">
            <h4 class="font-bold text-slate-500 mb-3">📊 Comparaison des confiances</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2">Marché</th>
                            <th class="text-center py-2">Layer 1</th>
                            <th class="text-center py-2">Layer 2</th>
                            <th class="text-center py-2">Δ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $originalRecs = is_string($advancedInsights['originalRecommendations'] ?? null) 
                                ? json_decode($advancedInsights['originalRecommendations'], true) 
                                : ($advancedInsights['originalRecommendations'] ?? []);

                            $enrichedRecs = is_string($advancedInsights['enrichedRecommendations'] ?? null) 
                                ? json_decode($advancedInsights['enrichedRecommendations'], true) 
                                : ($advancedInsights['enrichedRecommendations'] ?? []);
                        @endphp
                        @foreach($originalRecs as $idx => $recL1)
                            @php
                                $recL2 = $enrichedRecs[$idx] ?? null;
                                if (!$recL2) continue;
                                            
                                $confL1 = $recL1['confidence'] ?? 0;
                                $confL2 = $recL2['confidence'] ?? 0;
                                $diff = $confL2 - $confL1;
                                
                                $marketAbbrev = [
                                    'doubleChance' => 'DC',
                                    'btts' => 'BTTS',
                                    'overUnder' => 'O/U',
                                    'winner' => '1X2',
                                    'exactScore' => 'SE'
                                ];
                                $marketLabel = $marketAbbrev[$recL1['market']] ?? $recL1['market'];
                            @endphp
                            
                            <tr class="border-b last:border-0">
                                <td class="py-2 font-medium">
                                    {{ $marketLabel }} {{ $recL1['bet'] }}
                                </td>
                                <td class="text-center py-2 text-gray-600">
                                    {{ $confL1 }}%
                                </td>
                                <td class="text-center py-2 font-bold text-purple-600">
                                    {{ $confL2 }}%
                                </td>
                                <td class="text-center py-2 font-bold {{ $diff > 0 ? 'text-green-600' : ($diff < 0 ? 'text-red-600' : 'text-slate-500') }}">
                                    {{ $diff > 0 ? '+' : '' }}{{ $diff }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>