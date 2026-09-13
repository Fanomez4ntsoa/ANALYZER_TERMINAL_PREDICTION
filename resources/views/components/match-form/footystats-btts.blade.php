<div class="bg-white rounded-lg p-5 border border-green-400">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- BTTS DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Domicile - BTTS (%)
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">✅ BTTS Oui (Both Teams Score)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_btts_yes"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="55.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">❌ BTTS Non (Clean Sheet ou ne marque pas)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_btts_no"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="45.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- BTTS EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Extérieur - BTTS (%)
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">✅ BTTS Oui (Both Teams Score)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_btts_yes"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="60.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">❌ BTTS Non (Clean Sheet ou ne marque pas)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_btts_no"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="40.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-blue-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-blue-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-blue-500">
                <strong>BTTS (Both Teams To Score) :</strong> Indique la fréquence où les deux équipes marquent dans un match. Important pour les paris "Les deux équipes marquent".
            </p>
        </div>
    </div>
    
</div>