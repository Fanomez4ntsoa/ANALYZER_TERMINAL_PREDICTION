<div class="bg-white rounded-lg p-5 border border-green-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- OVER/UNDER DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Domicile - Pourcentages Over (%)
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-2s text-gray-600 mb-1">Over 1.5 buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_over_15"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="85.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Over 2.5 buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_over_25"
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
                    <label class="block text-xs text-slate-700 mb-1">Over 3.5 buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_over_35"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="30.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- OVER/UNDER EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Extérieur - Pourcentages Over (%)
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Over 1.5 buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_over_15"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="75.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Over 2.5 buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_over_25"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="50.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Over 3.5 buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_over_35"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="20.0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">%</span>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-green-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-green-800 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-green-800">
                <strong>Statistiques Over/Under :</strong> Ces pourcentages montrent la fréquence de matchs avec plus de X buts. Utile pour parier sur le nombre total de buts du match.
            </p>
        </div>
    </div>
    
</div>