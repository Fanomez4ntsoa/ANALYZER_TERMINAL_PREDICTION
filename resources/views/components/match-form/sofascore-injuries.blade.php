<div class="bg-white rounded-lg p-5 border border-blue-400" x-data="injuriesManager()">
    
    <!-- ABSENCES DOMICILE -->
    <div class="mb-6">
        <div class="flex justify-between items-center mb-3">
            <label class="block text-sm font-semibold text-slate-500">
                Absences Équipe Domicile
            </label>
            <button
                type="button"
                @click="addHomeAbsence()"
                class="px-3 py-1.5 bg-blue-600 text-slate-800 text-sm rounded-lg hover:bg-blue-700 transition flex items-center gap-1"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Ajouter
            </button>
        </div>
        
        <div class="space-y-2">
            <template x-for="(absence, index) in homeAbsences" :key="index">
                <div class="grid grid-cols-12 gap-2 items-center bg-white p-3 rounded-lg border border-blue-400">
                    <input
                        type="text"
                        :name="'home_absences[' + index + '][name]'"
                        placeholder="Nom joueur"
                        class="text-slate-800 col-span-3 px-3 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    />
                    
                    <select
                        :name="'home_absences[' + index + '][position]'"
                        class=" text-slate-800 col-span-2 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="GK">GK</option>
                        <option value="CB">CB</option>
                        <option value="FB">FB</option>
                        <option value="DM">DM</option>
                        <option value="CM" selected>CM</option>
                        <option value="AM">AM</option>
                        <option value="W">W</option>
                        <option value="ST">ST</option>
                    </select>
                    
                    <select
                        :name="'home_absences[' + index + '][importance]'"
                        class="text-slate-800 col-span-3 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="key">⭐ Clé</option>
                        <option value="regular" selected>🟢 Régulier</option>
                        <option value="rotation">🔵 Rotation</option>
                    </select>
                    
                    <select
                        :name="'home_absences[' + index + '][reason]'"
                        class="text-slate-800 col-span-3 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="injury" selected>🚑 Blessé</option>
                        <option value="suspension">🟥 Suspendu</option>
                        <option value="other">❓ Autre</option>
                    </select>
                    
                    <button
                        type="button"
                        @click="removeHomeAbsence(index)"
                        class="col-span-1 text-red-600 hover:text-red-800 hover:bg-red-50 p-2 rounded transition"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </template>
            
            <div x-show="homeAbsences.length === 0" class="text-sm text-slate-700 italic text-center py-4 bg-white rounded-lg border border-green-200">
                Aucune absence - Effectif au complet
            </div>
        </div>
    </div>
    
    <!-- ABSENCES EXTÉRIEUR -->
    <div>
        <div class="flex justify-between items-center mb-3">
            <label class="block text-sm font-semibold text-slate-500">
                Absences Équipe Extérieur
            </label>
            <button
                type="button"
                @click="addAwayAbsence()"
                class="px-3 py-1.5 bg-blue-600 text-slate-800 text-sm rounded-lg hover:bg-blue-700 transition flex items-center gap-1"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Ajouter
            </button>
        </div>
        
        <div class="space-y-2">
            <template x-for="(absence, index) in awayAbsences" :key="index">
                <div class="grid grid-cols-12 gap-2 items-center bg-white p-3 rounded-lg border border-blue-200">
                    <input
                        type="text"
                        :name="'away_absences[' + index + '][name]'"
                        placeholder="Nom joueur"
                        class="text-slate-800 col-span-3 px-3 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    />
                    
                    <select
                        :name="'away_absences[' + index + '][position]'"
                        class="text-slate-800 col-span-2 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="GK">GK</option>
                        <option value="CB">CB</option>
                        <option value="FB">FB</option>
                        <option value="DM">DM</option>
                        <option value="CM" selected>CM</option>
                        <option value="AM">AM</option>
                        <option value="W">W</option>
                        <option value="ST">ST</option>
                    </select>
                    
                    <select
                        :name="'away_absences[' + index + '][importance]'"
                        class="text-slate-800 col-span-3 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="key">⭐ Clé</option>
                        <option value="regular" selected>🟢 Régulier</option>
                        <option value="rotation">🔵 Rotation</option>
                    </select>
                    
                    <select
                        :name="'away_absences[' + index + '][reason]'"
                        class="text-slate-800 col-span-3 px-2 py-2 border bg-white border-gray-300 rounded text-sm focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="injury" selected>🚑 Blessé</option>
                        <option value="suspension">🟥 Suspendu</option>
                        <option value="other">❓ Autre</option>
                    </select>
                    
                    <button
                        type="button"
                        @click="removeAwayAbsence(index)"
                        class="col-span-1 text-red-600 hover:text-red-800 hover:bg-red-50 p-2 rounded transition"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </template>
            
            <div x-show="awayAbsences.length === 0" class="text-sm text-slate-700 italic text-center py-4 bg-white rounded-lg border border-green-200">
                Aucune absence - Effectif au complet
            </div>
        </div>
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-yellow-800 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-yellow-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-yellow-800">
                <strong>Impact des absences :</strong> Les joueurs clés absents peuvent considérablement affecter la performance d'une équipe. Pensez aux buteurs, créateurs, et défenseurs centraux.
            </p>
        </div>
    </div>
    
</div>

<script>
function injuriesManager() {
    return {
        homeAbsences: [],
        awayAbsences: [],
        
        addHomeAbsence() {
            this.homeAbsences.push({});
        },
        
        removeHomeAbsence(index) {
            this.homeAbsences.splice(index, 1);
        },
        
        addAwayAbsence() {
            this.awayAbsences.push({});
        },
        
        removeAwayAbsence(index) {
            this.awayAbsences.splice(index, 1);
        }
    }
}
</script>