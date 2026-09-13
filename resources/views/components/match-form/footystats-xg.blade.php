<div class="bg-white rounded-lg p-5 border border-green-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- XG DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Domicile - Expected Goals (xG)
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">xG Pour (Moyenne par match)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_xg_for"
                            min="0"
                            step="0.01"
                            placeholder="1.85"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">buts</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">xG Contre (Moyenne par match)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_xg_against"
                            min="0"
                            step="0.01"
                            placeholder="1.20"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">buts</span>
                    </div>
                </div>
            </div>
            
            <!-- Indicateur xG Domicile -->
            <div class="mt-3 p-3 bg-white rounded-lg">
                <p class="text-xs text-slate-700">
                    💡 <strong>xG Net :</strong> <span class="font-semibold text-green-700">xG Pour - xG Contre</span> = Performance offensive/défensive
                </p>
            </div>
        </div>
        
        <!-- XG EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Extérieur - Expected Goals (xG)
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">xG Pour (Moyenne par match)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_xg_for"
                            min="0"
                            step="0.01"
                            placeholder="1.50"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">buts</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">xG Contre (Moyenne par match)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_xg_against"
                            min="0"
                            step="0.01"
                            placeholder="1.40"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">buts</span>
                    </div>
                </div>
            </div>
            
            <!-- Indicateur xG Extérieur -->
            <div class="mt-3 p-3 bg-white rounded-lg">
                <p class="text-xs text-slate-700">
                    💡 <strong>xG Net :</strong> <span class="font-semibold text-green-700">xG Pour - xG Contre</span> = Performance offensive/défensive
                </p>
            </div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-purple-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-purple-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-purple-500">
                <strong>Expected Goals (xG) :</strong> Mesure la qualité des occasions créées. Un xG élevé indique une équipe qui crée beaucoup d'occasions, même si elle ne marque pas toujours. Un xG bas contre montre une défense solide.
            </p>
        </div>
    </div>
    
</div>