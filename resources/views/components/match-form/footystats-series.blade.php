<div class="bg-white rounded-lg p-5 border border-green-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- SÉRIES DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Domicile - Séries en Cours
            </label>
            
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Victoires</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_win_streak"
                            min="0"
                            placeholder="3"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Invaincu</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_unbeaten_streak"
                            min="0"
                            placeholder="5"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Buts marqués</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_scoring_streak"
                            min="0"
                            placeholder="8"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Clean Sheet</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="home_clean_sheet_streak"
                            min="0"
                            placeholder="2"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SÉRIES EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-3">
                Équipe Extérieur - Séries en Cours
            </label>
            
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Victoires</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_win_streak"
                            min="0"
                            placeholder="2"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Invaincu</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_unbeaten_streak"
                            min="0"
                            placeholder="4"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Buts marqués</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_scoring_streak"
                            min="0"
                            placeholder="6"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs text-slate-700 mb-1">Clean Sheet</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="away_clean_sheet_streak"
                            min="0"
                            placeholder="1"
                            value="0"
                            class="text-slate-800 flex-1 px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"
                        />
                        <span class="text-xs text-slate-400">matchs</span>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-orange-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-orange-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-orange-500">
                <strong>Séries & Momentum :</strong> Les séries indiquent la dynamique actuelle. Une équipe sur 5 victoires consécutives aura plus de confiance qu'une équipe sur 3 défaites. Le momentum psychologique compte !
            </p>
        </div>
    </div>
    
</div>