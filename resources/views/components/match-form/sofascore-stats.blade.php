<div class="bg-white rounded-lg p-5 border border-blue-400">
    
    <!-- POSSESSION -->
    <div class="mb-6">
        <h5 class="font-semibold text-slate-700 mb-3">Possession Moyenne (%)</h5>
        
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-slate-700 mb-1">Domicile</label>
                <input
                    type="number"
                    name="home_possession"
                    min="0"
                    max="100"
                    step="0.1"
                    placeholder="55.0"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Extérieur</label>
                <input
                    type="number"
                    name="away_possession"
                    min="0"
                    max="100"
                    step="0.1"
                    placeholder="45.0"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
        </div>
    </div>
    
    <!-- TIRS -->
    <div class="mb-6">
        <h5 class="font-semibold text-slate-700 mb-3">Tirs Moyens par Match</h5>
        
        <div class="grid grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-slate-700 mb-1">Tirs (Domicile)</label>
                <input
                    type="number"
                    name="home_shots"
                    min="0"
                    step="0.1"
                    placeholder="15"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Cadrés (Domicile)</label>
                <input
                    type="number"
                    name="home_shots_on_target"
                    min="0"
                    step="0.1"
                    placeholder="6"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Tirs (Exterieur)</label>
                <input
                    type="number"
                    name="away_shots"
                    min="0"
                    step="0.1"
                    placeholder="10"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Cadrés (Exterieur)</label>
                <input
                    type="number"
                    name="away_shots_on_target"
                    min="0"
                    step="0.1"
                    placeholder="4"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
        </div>
    </div>
    
    <!-- BUTS SAISON -->
    <div>
        <h5 class="font-semibold text-slate-700 mb-3">Buts de la Saison en cours</h5>
        
        <div class="grid grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Marqués (Domicile)</label>
                <input
                    type="number"
                    name="home_goals_scored"
                    min="0"
                    placeholder="25"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-500 mb-1">Encaissés (Domicile)</label>
                <input
                    type="number"
                    name="home_goals_conceded"
                    min="0"
                    placeholder="10"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-500 mb-1">Marqués (Exterieur)</label>
                <input
                    type="number"
                    name="away_goals_scored"
                    min="0"
                    placeholder="18"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-500 mb-1">Encaissés (Exterieur)</label>
                <input
                    type="number"
                    name="away_goals_conceded"
                    min="0"
                    placeholder="15"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
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
                <strong>Indicateurs de performance :</strong> Les statistiques montrent le style de jeu. Une équipe avec beaucoup de possession mais peu de tirs cadrés peut manquer d'efficacité.
            </p>
        </div>
    </div>
    
</div>