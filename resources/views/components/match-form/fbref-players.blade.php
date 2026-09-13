<div class="bg-white rounded-lg p-5 border border-purple-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- TOP BUTEUR DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Domicile - Meilleur Buteur
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Nom du joueur</label>
                    <input
                        type="text"
                        name="home_top_scorer"
                        placeholder="Ex: Kylian Mbappé"
                        class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                    />
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Nombre de buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_ts_goals"
                            min="0"
                            placeholder="15"
                            class="fletext-slate-800 x-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-xs text-slate-400">buts</span>
                    </div>
                </div>
                
                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="home_ts_available"
                        id="home_ts_available"
                        value="1"
                        checked
                        class="wtext-slate-800 -4 h-4 text-purple-600 bg-white border-gray-300 rounded focus:ring-purple-500"
                    />
                    <label for="home_ts_available" class="text-sm text-slate-700">
                        ✅ Disponible pour le match (non blessé/suspendu)
                    </label>
                </div>
            </div>
            
            <!-- Indicateur Impact Domicile -->
            <div class="mt-3 p-3 bg-white border border-green-200 rounded-lg">
                <p class="text-xs text-green-800">
                    💡 <strong>Impact :</strong> Le meilleur buteur est souvent déterminant. Son absence peut réduire considérablement le potentiel offensif.
                </p>
            </div>
        </div>
        
        <!-- TOP BUTEUR EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Extérieur - Meilleur Buteur
            </label>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Nom du joueur</label>
                    <input
                        type="text"
                        name="away_top_scorer"
                        placeholder="Ex: Erling Haaland"
                        class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                    />
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Nombre de buts</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_ts_goals"
                            min="0"
                            placeholder="12"
                            class="fletext-slate-800 x-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                        />
                        <span class="text-xs text-slate-400">buts</span>
                    </div>
                </div>
                
                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="away_ts_available"
                        id="away_ts_available"
                        value="1"
                        checked
                        class="wtext-slate-800 -4 h-4 text-purple-600 bg-white border-gray-300 rounded focus:ring-purple-500"
                    />
                    <label for="away_ts_available" class="text-sm text-slate-700">
                        ✅ Disponible pour le match (non blessé/suspendu)
                    </label>
                </div>
            </div>
            
            <!-- Indicateur Impact Extérieur -->
            <div class="mt-3 p-3 bg-white border border-green-200 rounded-lg">
                <p class="text-xs text-green-800">
                    💡 <strong>Impact :</strong> Le meilleur buteur est souvent déterminant. Son absence peut réduire considérablement le potentiel offensif.
                </p>
            </div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-yellow-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-yellow-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-yellow-800">
                <strong>Joueurs clés :</strong> Un buteur de 20+ buts absent peut transformer un favori en outsider. Vérifiez toujours la disponibilité des stars offensives !
            </p>
        </div>
    </div>
    
</div>