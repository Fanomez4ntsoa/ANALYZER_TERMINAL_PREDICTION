@extends('layouts.pro')

@section('title', 'Statistiques de Performance')
@section('page-title', 'Statistiques')

@section('content')
<div class="container mx-auto px-4 py-8">
    
    @if(!$hasData)
        {{-- Aucune validation --}}
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <div class="text-6xl mb-4"></div>
            <h3 class="text-2xl font-bold text-gray-800 mb-2">Aucune validation</h3>
            <p class="text-gray-600 mb-6">Commencez par valider vos premiers matchs dans l'historique !</p>
            <a href="{{ route('history.index') }}" 
               class="inline-block bg-blue-600 text-slate-800 px-8 py-3 rounded-lg font-bold hover:bg-blue-700 transition">
                Aller à l'historique
            </a>
        </div>
    @else
        <div class="space-y-6">
            
            {{-- Stats globales --}}
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h3 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <svg class="w-6 h-6 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>
                    </svg>
                    Statistiques de Performance
                </h3>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {{-- Matchs validés --}}
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-lg border-2 border-blue-200 text-center">
                        <div class="flex justify-center mb-2">
                            <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold text-gray-800">{{ $stats['totalValidated'] }}</div>
                        <div class="text-sm text-gray-600 mt-1">Matchs validés</div>
                    </div>
                    
                    {{-- Picks réussis --}}
                    <div class="bg-gradient-to-r from-green-50 to-emerald-50 p-4 rounded-lg border-2 border-green-200 text-center">
                        <div class="flex justify-center mb-2">
                            <svg class="w-8 h-8 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold text-gray-800">{{ $stats['successfulPicks'] }}</div>
                        <div class="text-sm text-gray-600 mt-1">Picks réussis</div>
                        <div class="text-xs text-slate-400 mt-1">{{ $stats['successRate'] }}% de réussite</div>
                    </div>
                    
                    {{-- Picks ratés --}}
                    <div class="bg-gradient-to-r from-red-50 to-rose-50 p-4 rounded-lg border-2 border-red-200 text-center">
                        <div class="flex justify-center mb-2">
                            <svg class="w-8 h-8 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold text-gray-800">{{ $stats['failedPicks'] }}</div>
                        <div class="text-sm text-gray-600 mt-1">Picks ratés</div>
                        <div class="text-xs text-slate-400 mt-1">{{ 100 - $stats['successRate'] }}% d'échec</div>
                    </div>
                    
                    {{-- Total picks --}}
                    <div class="bg-gradient-to-r from-purple-50 to-pink-50 p-4 rounded-lg border-2 border-purple-200 text-center">
                        <div class="flex justify-center mb-2">
                            <svg class="w-8 h-8 text-purple-600" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold text-gray-800">{{ $stats['totalPicks'] }}</div>
                        <div class="text-sm text-gray-600 mt-1">Total picks</div>
                    </div>
                </div>
            </div>
            
            {{-- Import / Export de données --}}
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h3 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                    <svg class="w-6 h-6 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                    </svg>
                    Import / Export de Données
                </h3>
                
                <div class="grid md:grid-cols-2 gap-6">
                    
                    {{-- Import --}}
                    <div class="border-2 border-blue-200 rounded-lg p-4 bg-blue-50">
                        <h4 class="font-bold text-lg text-blue-800 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                            </svg>
                            Importer des données
                        </h4>
                        
                        <form action="{{ route('statistics.import') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                            @csrf
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-500 mb-2">
                                    Format du fichier
                                </label>
                                <select name="format" required 
                                        class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:border-blue-500 focus:outline-none">
                                    <option value="">-- Choisir --</option>
                                    <option value="json_complete">JSON Complet (export React)</option>
                                    <option value="json_analysis">JSON Analyse (condensé)</option>
                                    <option value="csv">CSV (Excel)</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-500 mb-2">
                                    Fichier à importer
                                </label>
                                <input type="file" 
                                    name="file" 
                                    accept=".json,.csv,.txt"
                                    required
                                    class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:border-blue-500 focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-100 file:text-blue-700 hover:file:bg-blue-200">
                            </div>
                            
                            <button type="submit" 
                                    class="w-full bg-blue-600 text-slate-800 px-4 py-3 rounded-lg font-bold hover:bg-blue-700 transition flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                                </svg>
                                Importer
                            </button>
                        </form>
                        
                        <div class="mt-4 text-xs text-gray-600 bg-white p-3 rounded border">
                            <strong>ℹ️ Info :</strong>
                            <ul class="mt-2 space-y-1 ml-4 list-disc">
                                <li><strong>JSON Complet</strong> : Import tous les matchs avec détails (recommandé)</li>
                                <li><strong>JSON Analyse</strong> : Import seulement les insights (pas de matchs)</li>
                                <li><strong>CSV</strong> : Import depuis un export Excel</li>
                            </ul>
                        </div>
                    </div>
                    
                    {{-- Export --}}
                    <div class="border-2 border-green-200 rounded-lg p-4 bg-green-50">
                        <h4 class="font-bold text-lg text-green-800 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                            Exporter les données
                        </h4>
                        
                        <div class="space-y-3">
                            <a href="{{ route('statistics.export.json_complete') }}" 
                            class="block w-full bg-blue-600 text-slate-800 px-4 py-3 rounded-lg font-medium hover:bg-blue-700 transition text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    JSON Complet
                                </div>
                                <span class="text-xs opacity-90">Export avec tous les détails</span>
                            </a>
                            
                            <a href="{{ route('statistics.export.json_analysis') }}" 
                            class="block w-full bg-purple-600 text-slate-800 px-4 py-3 rounded-lg font-medium hover:bg-purple-700 transition text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    JSON Pour Analyse
                                </div>
                                <span class="text-xs opacity-90">Export condensé avec insights</span>
                            </a>
                            
                            <a href="{{ route('statistics.export.csv') }}" 
                            class="block w-full bg-green-600 text-slate-800 px-4 py-3 rounded-lg font-medium hover:bg-green-700 transition text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    CSV (Excel)
                                </div>
                                <span class="text-xs opacity-90">Export pour tableur</span>
                            </a>
                        </div>
                        
                        <div class="mt-4 text-xs text-gray-600 bg-white p-3 rounded border">
                            <strong>💡 Utilisation :</strong>
                            <ul class="mt-2 space-y-1 ml-4 list-disc">
                                <li>Exporte les {{ $stats['totalValidated'] }} matchs validés</li>
                                <li>Garde une copie de tes analyses</li>
                                <li>Analyse les patterns dans Excel ou avec moi</li>
                            </ul>
                        </div>
                    </div>
                    
                </div>
                
                {{-- Messages de succès/erreur --}}
                @if(session('success'))
                    <div class="mt-6 bg-green-100 border-2 border-green-400 text-green-800 px-4 py-3 rounded-lg">
                        <div class="font-bold mb-2">✅ {{ session('success')['message'] }}</div>
                        @if(isset(session('success')['report']))
                            @php $report = session('success')['report']; @endphp
                            <div class="text-sm space-y-1">
                                <div>📊 Total: {{ $report['total'] }}</div>
                                <div>✅ Importés: {{ $report['imported'] }}</div>
                                <div>🔄 Mis à jour: {{ $report['updated'] }}</div>
                                @if($report['skipped'] > 0)
                                    <div>⏭️ Ignorés: {{ $report['skipped'] }}</div>
                                @endif
                                @if(count($report['errors']) > 0)
                                    <div class="mt-2 text-red-600">
                                        <strong>⚠️ Erreurs ({{ count($report['errors']) }}):</strong>
                                        <ul class="ml-4 list-disc">
                                            @foreach(array_slice($report['errors'], 0, 5) as $error)
                                                <li>{{ $error['match'] }}: {{ $error['error'] }}</li>
                                            @endforeach
                                            @if(count($report['errors']) > 5)
                                                <li>... et {{ count($report['errors']) - 5 }} autres</li>
                                            @endif
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
                
                @if(session('error'))
                    <div class="mt-6 bg-red-100 border-2 border-red-400 text-red-800 px-4 py-3 rounded-lg">
                        <div class="font-bold">❌ {{ session('error') }}</div>
                    </div>
                @endif
                
                @if($errors->any())
                    <div class="mt-6 bg-red-100 border-2 border-red-400 text-red-800 px-4 py-3 rounded-lg">
                        <div class="font-bold mb-2">❌ Erreurs de validation :</div>
                        <ul class="text-sm ml-4 list-disc">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Comparaison Layer 1 vs Layer 2 --}}
            @if($layer2Comparison)
                <div class="bg-gradient-to-r from-purple-50 to-indigo-50 rounded-xl shadow-sm p-6 border-2 border-purple-300">
                    <h3 class="text-2xl font-bold text-purple-700 mb-4 flex items-center gap-2">
                        Comparaison Layer 1 vs Layer 2
                        <span class="text-sm font-normal bg-purple-200 px-2 py-1 rounded">
                            {{ $layer2Comparison['matchCount'] }} matchs avec données avancées
                        </span>
                    </h3>
                    
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div class="bg-white p-4 rounded-lg border text-center">
                            <div class="text-3xl font-bold text-blue-600">{{ $layer2Comparison['layer1Total'] }}</div>
                            <div class="text-sm text-gray-600">Picks analysés</div>
                        </div>
                        
                        <div class="bg-white p-4 rounded-lg border text-center">
                            <div class="text-3xl font-bold text-green-600">{{ $layer2Comparison['layer2BetterPredictions'] }}</div>
                            <div class="text-sm text-gray-600">Layer 2 meilleur</div>
                        </div>
                        
                        <div class="bg-white p-4 rounded-lg border text-center">
                            <div class="text-3xl font-bold text-orange-600">{{ $layer2Comparison['layer1BetterPredictions'] }}</div>
                            <div class="text-sm text-gray-600">Layer 1 meilleur</div>
                        </div>
                    </div>
                    
                    {{-- Verdict --}}
                    @php
                        $layer2Better = $layer2Comparison['layer2BetterPredictions'];
                        $layer1Better = $layer2Comparison['layer1BetterPredictions'];
                    @endphp
                    
                    <div class="p-4 rounded-lg {{ $layer2Better > $layer1Better ? 'bg-green-100 border-2 border-green-400' : ($layer2Better < $layer1Better ? 'bg-orange-100 border-2 border-orange-400' : 'bg-gray-100 border-2 border-gray-400') }}">
                        <div class="font-bold text-lg">
                            @if($layer2Better > $layer1Better)
                                ✅ Layer 2 est plus précis ({{ $layer2Comparison['layer2AccuracyRate'] }}% des ajustements étaient corrects)
                            @elseif($layer2Better < $layer1Better)
                                ⚠️ Layer 1 performe mieux - Revoir les coefficients Layer 2
                            @else
                                ⚖️ Performances égales - Plus de données nécessaires
                            @endif
                        </div>
                        <div class="text-sm text-gray-600 mt-2">
                            Layer 2 a ajusté correctement {{ $layer2Better }}/{{ $layer2Comparison['layer1Total'] }} prédictions
                        </div>
                    </div>
                    
                    @if($layer2Comparison['matchCount'] < 10)
                        <div class="mt-4 text-sm text-purple-600 bg-purple-100 p-3 rounded">
                            ⚠️ {{ $layer2Comparison['matchCount'] }} matchs analysés avec Layer 2. 
                            Recommandé: 10+ matchs pour des conclusions fiables.
                        </div>
                    @endif
                </div>
            @endif
            
            {{-- INSIGHTS & PATTERNS --}}
            <div class="bg-gradient-to-r from-orange-50 to-yellow-50 rounded-xl shadow-sm border-2 border-orange-300">
                <div class="p-6 cursor-pointer flex justify-between items-center" 
                     onclick="document.getElementById('insights-content').classList.toggle('hidden')">
                    <h3 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                        <svg class="w-6 h-6 text-orange-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/>
                        </svg>
                        📈 INSIGHTS & PATTERNS
                    </h3>
                    <span class="text-gray-600 font-bold text-xl">▼</span>
                </div>
                
                <div id="insights-content" class="p-6 pt-0 space-y-6">
                    
                    {{-- Avertissement si données insuffisantes --}}
                    @if($totalMatches < 30)
                        <div class="bg-yellow-100 border-2 border-yellow-400 rounded-lg p-4 flex items-start gap-3">
                            <svg class="w-6 h-6 text-yellow-600 flex-shrink-0 mt-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <div>
                                <div class="font-bold text-yellow-800">⚠️ Données insuffisantes pour patterns fiables</div>
                                <div class="text-sm text-yellow-700 mt-1">
                                    {{ $totalMatches }} matchs validés. Recommandé : 30+ matchs pour une confiance statistique optimale.
                                </div>
                            </div>
                        </div>
                    @endif
                    
                    {{-- Analyse par marché --}}
                    <div>
                        <h4 class="font-bold text-lg text-gray-800 mb-3">📊 ANALYSE PAR MARCHÉ</h4>
                        <div class="space-y-2">
                            @foreach(collect($insights['byMarket'])->sortByDesc('rate') as $market => $data)
                                <div class="bg-white p-3 rounded-lg border border-slate-200">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="font-bold text-gray-800">{{ $market }}</span>
                                            <span class="text-sm text-gray-600 ml-2">
                                                {{ $data['success'] }}/{{ $data['total'] }} réussis
                                            </span>
                                        </div>
                                        <div class="text-xl font-bold {{ $data['rate'] >= 80 ? 'text-green-600' : ($data['rate'] >= 70 ? 'text-blue-600' : ($data['rate'] >= 60 ? 'text-orange-600' : 'text-red-600')) }}">
                                            {{ $data['rate'] }}%
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-600">
                                        @if($data['rate'] >= 80 && $data['total'] >= 5)
                                            🔥 Très fiable
                                        @elseif($data['rate'] >= 70 && $data['rate'] < 80)
                                            💡 Bon indicateur
                                        @elseif($data['rate'] >= 60 && $data['rate'] < 70)
                                            ⚠️ Prudence recommandée
                                        @else
                                            ❌ Peu fiable
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    
                    {{-- Analyse par consensus --}}
                    <div>
                        <h4 class="font-bold text-lg text-gray-800 mb-3">🤝 ANALYSE PAR CONSENSUS</h4>
                        <div class="space-y-2">
                            @foreach(collect($insights['byConsensus'])->sortByDesc('rate') as $consensus => $data)
                                <div class="bg-white p-3 rounded-lg border border-slate-200">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="font-bold text-gray-800">{{ $consensus }}</span>
                                            <span class="text-sm text-gray-600 ml-2">
                                                {{ $data['success'] }}/{{ $data['total'] }} réussis
                                            </span>
                                        </div>
                                        <div class="text-xl font-bold {{ $data['rate'] >= 85 ? 'text-green-600' : ($data['rate'] >= 70 ? 'text-blue-600' : 'text-orange-600') }}">
                                            {{ $data['rate'] }}%
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    
                    {{-- Analyse par niveau --}}
                    <div>
                        <h4 class="font-bold text-lg text-gray-800 mb-3">🎯 ANALYSE PAR PRIORITÉ</h4>
                        <div class="space-y-2">
                            @foreach(collect($insights['byLevel'])->sortKeys() as $level => $data)
                                <div class="bg-white p-3 rounded-lg border border-slate-200">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="font-bold text-gray-800">Priorité {{ $level }}</span>
                                            <span class="text-sm text-gray-600 ml-2">
                                                {{ $data['success'] }}/{{ $data['total'] }} réussis
                                            </span>
                                        </div>
                                        <div class="text-xl font-bold {{ $data['rate'] >= 80 ? 'text-green-600' : ($data['rate'] >= 70 ? 'text-blue-600' : 'text-orange-600') }}">
                                            {{ $data['rate'] }}%
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    
                    {{-- Règles d'or --}}
                    @if(count($insights['goldenRules']) > 0)
                        <div class="bg-gradient-to-r from-green-100 to-emerald-100 border-2 border-green-300 rounded-lg p-4">
                            <h4 class="font-bold text-lg text-green-800 mb-3 flex items-center gap-2">
                                <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/>
                                </svg>
                                🎯 RÈGLES D'OR DÉTECTÉES
                            </h4>
                            <div class="space-y-2">
                                @foreach($insights['goldenRules'] as $rule)
                                    <div class="text-sm text-green-800 font-medium">{{ $rule }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    
                    {{-- Warnings --}}
                    @if(count($insights['warnings']) > 0)
                        <div class="bg-gradient-to-r from-orange-100 to-red-100 border-2 border-orange-300 rounded-lg p-4">
                            <h4 class="font-bold text-lg text-orange-800 mb-3">⚠️ POINTS D'ATTENTION</h4>
                            <div class="space-y-2">
                                @foreach($insights['warnings'] as $warning)
                                    <div class="text-sm text-orange-800 font-medium">{{ $warning }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    
                </div>
            </div>
            
            {{-- Tableau détaillé --}}
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h3 class="text-2xl font-bold text-gray-800 mb-6">
                    📊 Détail de toutes les validations ({{ count($validations) }})
                </h3>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 border-b-2 border-gray-300">
                            <tr>
                                <th class="p-3 text-left font-bold">Date</th>
                                <th class="p-3 text-left font-bold">Match</th>
                                <th class="p-3 text-left font-bold">Marché</th>
                                <th class="p-3 text-left font-bold">Pick</th>
                                <th class="p-3 text-center font-bold">Résultat</th>
                                <th class="p-3 text-center font-bold">Conf.</th>
                                <th class="p-3 text-center font-bold">Consensus</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($validations as $validation)
                                <tr class="border-b hover:bg-gray-50 {{ $validation['result'] ? 'bg-green-50' : 'bg-red-50' }}">
                                    <td class="p-3 text-xs text-gray-600">
                                        {{ displayDate($validation['date'], 'd/m/Y') }}
                                    </td>
                                    <td class="p-3">
                                        <div class="font-medium text-gray-800">{{ $validation['match'] }}</div>
                                        <div class="text-xs text-slate-400">{{ $validation['competition'] }}</div>
                                    </td>
                                    <td class="p-3 font-medium text-slate-500">{{ $validation['market'] }}</td>
                                    <td class="p-3 font-bold text-blue-600">{{ $validation['pick'] }}</td>
                                    <td class="p-3 text-center">
                                        @if($validation['result'])
                                            <svg class="inline w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                            </svg>
                                        @else
                                            <svg class="inline w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                            </svg>
                                        @endif
                                    </td>
                                    <td class="p-3 text-center font-medium">{{ $validation['confidence'] }}%</td>
                                    <td class="p-3 text-center">
                                        <span class="text-xs font-bold px-2 py-1 rounded {{ 
                                            $validation['consensus'] === 'TOTAL' ? 'bg-green-200 text-green-800' : 
                                            ($validation['consensus'] === 'MAJORITÉ' ? 'bg-blue-200 text-blue-800' : 
                                            ($validation['consensus'] === 'CONFLIT' ? 'bg-orange-200 text-orange-800' : 'bg-gray-200 text-gray-800'))
                                        }}">
                                            {{ $validation['consensus'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    @endif
    
</div>
@endsection