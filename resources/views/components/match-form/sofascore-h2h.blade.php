<div class="bg-white rounded-lg p-5 border border-blue-400">
    
    <!-- RÉSUMÉ H2H -->
    <div class="mb-6">
        <h5 class="font-semibold text-slate-700 mb-3">Résumé Global</h5>
        
        <div class="grid grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-slate-700 mb-1">Total matchs</label>
                <input
                    type="number"
                    name="h2h_total_games"
                    min="0"
                    placeholder="10"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Victoires 1</label>
                <input
                    type="number"
                    name="h2h_home_wins"
                    min="0"
                    placeholder="5"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Nuls</label>
                <input
                    type="number"
                    name="h2h_draws"
                    min="0"
                    placeholder="3"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-700 mb-1">Victoires 2</label>
                <input
                    type="number"
                    name="h2h_away_wins"
                    min="0"
                    placeholder="2"
                    class="text-slate-800 w-full px-3 py-2 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                />
            </div>
        </div>
    </div>
    
    <!-- DÉTAIL DERNIERS H2H -->
    <div x-data="h2hManager()">
        <div class="flex justify-between items-center mb-3">
            <h5 class="font-semibold text-slate-700">Derniers H2H détaillés (optionnel)</h5>
            <button
                type="button"
                @click="addH2H()"
                class="px-3 py-1.5 bg-blue-600 text-slate-800 text-sm rounded-lg hover:bg-blue-700 transition flex items-center gap-1"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Ajouter match
            </button>
        </div>
        
        <div class="space-y-2">
            <template x-for="(h2h, index) in h2hMatches" :key="index">
                <div class="grid grid-cols-12 gap-2 items-center bg-white p-3 rounded-lg border border-blue-200">
                    <input
                        type="date"
                        :name="'h2h_matches[' + index + '][date]'"
                        class="text-slate-800 col-span-3 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    />
                    
                    <input
                        type="text"
                        :name="'h2h_matches[' + index + '][score]'"
                        placeholder="2-1"
                        class="text-slate-800 col-span-2 px-2 py-2 border bg-white border-gray-300 rounded text-sm text-center font-semibold focus:ring-2 focus:ring-blue-500"
                    />
                    
                    <select
                        :name="'h2h_matches[' + index + '][location]'"
                        class="text-slate-800 col-span-2 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="home">🏠 Domicile</option>
                        <option value="away">✈️ Extérieur</option>
                        <option value="neutral">⚪ Neutre</option>
                    </select>
                    
                    <select
                        :name="'h2h_matches[' + index + '][winner]'"
                        class="text-slate-800 col-span-4 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="home">✅ Victoire Domicile</option>
                        <option value="draw">🤝 Match Nul</option>
                        <option value="away">✅ Victoire Extérieur</option>
                    </select>
                    
                    <button
                        type="button"
                        @click="removeH2H(index)"
                        class="col-span-1 text-red-600 hover:text-red-800 hover:bg-red-50 p-2 rounded transition"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </template>
            
            <div x-show="h2hMatches.length === 0" class="text-sm text-slate-400 italic text-center py-4 bg-white rounded-lg border border-slate-200">
                Aucun H2H détaillé ajouté
            </div>
        </div>
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-blue-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-blue-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-blue-800">
                <strong>Historique important :</strong> Les confrontations directes révèlent souvent des dynamiques particulières. Une équipe peut dominer une autre malgré des positions au classement similaires.
            </p>
        </div>
    </div>
    
</div>

<script>
function h2hManager() {
    return {
        h2hMatches: [],
        
        addH2H() {
            this.h2hMatches.push({});
        },
        
        removeH2H(index) {
            this.h2hMatches.splice(index, 1);
        }
    }
}
</script>