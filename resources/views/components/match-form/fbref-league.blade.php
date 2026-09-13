<div class="bg-white rounded-lg p-5 border border-purple-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- CLASSEMENT DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Domicile - Position au Classement
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Position</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_position"
                            min="1"
                            max="30"
                            placeholder="3"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-xs text-slate-400">ème</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Points</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_points"
                            min="0"
                            placeholder="45"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-xs text-slate-400">pts</span>
                    </div>
                </div>
            </div>
            
            <!-- Indicateur Position Domicile -->
            <div class="mt-3 p-3 bg-white rounded-lg">
                <p class="text-xs text-slate-700">
                    💡 <strong>Zone :</strong>
                    <span class= "text-purple-3"0 0">1-4 = Top 4 | 5-10 = Haut tableau | 11-15 = Milieu | 16-20 = Bas tableau</span>
                </p>
            </div>
        </div>
        
        <!-- CLASSEMENT EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Extérieur - Position au Classement
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Position</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_position"
                            min="1"
                            max="30"
                            placeholder="8"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-xs text-slate-400">ème</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Points</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_points"
                            min="0"
                            placeholder="32"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-xs text-slate-400">pts</span>
                    </div>
                </div>
            </div>
            
            <!-- Indicateur Position Extérieur -->
            <div class="mt-3 p-3 bg-white rounded-lg">
                <p class="text-xs text-slate-700">
                    💡 <strong>Zone :</strong>
                    <span class="text-purple-3= 03"> 1-4 = Top 4 | 5-10 = Haut tableau | 11-15 = Milieu | 16-20 = Bas tableau</span>
                </p>
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
                <strong>Position au classement :</strong> Indique la force globale de l'équipe sur la saison. Un grand écart (3ème vs 18ème) suggère un favori net, mais attention aux surprises !
            </p>
        </div>
    </div>
    
</div>