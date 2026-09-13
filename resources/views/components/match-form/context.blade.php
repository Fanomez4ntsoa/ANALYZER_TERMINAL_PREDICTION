<div class="bg-white rounded-lg p-6 border-2 border-orange-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- ENJEU DU MATCH -->
        <div>
            <label class="block text-sm font-semibold text-slate-500 mb-2">
                Enjeu du Match
            </label>
            <select 
                name="match_importance"
                class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent transition"
                onchange="updateImportanceInfo(this.value)"
            >
                <option value="low">⚪ Faible (Match de routine)</option>
                <option value="medium" selected>🟡 Moyen (Match standard)</option>
                <option value="high">🟠 Important (Derby, top 6, lutte maintien)</option>
                <option value="critical">🔴 Critique (Finale, décisif pour titre/relégation)</option>
            </select>
            
            <!-- Info Enjeu -->
            <div id="importance-info" class="mt-3 p-3 bg-slate-100 rounded-lg">
                <p class="text-sm text-slate-800" id="importance-desc">
                    Match sans enjeu particulier, motivations équilibrées.
                </p>
            </div>
        </div>
        
        <!-- RAISON ENJEU -->
        <div>
            <label class="block text-sm font-semibold text-slate-500 mb-2">
                Raison de l'enjeu (optionnel)
            </label>
            <input 
                type="text"
                name="importance_reason"
                placeholder="Ex: Derby local, Finale de coupe, Lutte pour le titre..."
                class="text_white w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent transition"
            />
            <p class="text-xs text-slate-400 mt-1">
                Précisez pourquoi ce match est important (derby régional, match pour la relégation, etc.)
            </p>
        </div>
        
    </div>
    
    <!-- REPOS DES ÉQUIPES -->
    <div class="mt-6 pt-6 border-t border-orange-300">
        <h5 class="font-semibold text-gray-800 mb-4 flex items-center gap-2">
            <span></span>
            <span>Repos entre les matchs</span>
        </h5>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- REPOS DOMICILE -->
            <div>
                <label class="block text-sm font-semibold text-slate-500 mb-2">
                    Repos Équipe Domicile (jours)
                </label>
                <input 
                    type="number"
                    name="home_rest_days"
                    min="0"
                    max="30"
                    value="7"
                    class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent transition"
                    onchange="updateRestInfo('home', this.value)"
                />
                
                <!-- Info Repos Domicile -->
                <div id="home-rest-info" class="mt-2 p-3 bg-white border-l-4 border-green-400 rounded">
                    <p class="text-sm text-slate-800" id="home-rest-desc">
                        ✅ Repos normal - Équipe fraîche et préparée
                    </p>
                </div>
            </div>
            
            <!-- REPOS EXTÉRIEUR -->
            <div>
                <label class="block text-sm font-semibold text-slate-500 mb-2">
                    Repos Équipe Extérieur (jours)
                </label>
                <input 
                    type="number"
                    name="away_rest_days"
                    min="0"
                    max="30"
                    value="7"
                    class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent transition"
                    onchange="updateRestInfo('away', this.value)"
                />
                
                <!-- Info Repos Extérieur -->
                <div id="away-rest-info" class="mt-2 p-3 bg-white border-l-4 border-green-400 rounded">
                    <p class="text-sm text-slate-800" id="away-rest-desc">
                        ✅ Repos normal - Équipe fraîche et préparée
                    </p>
                </div>
            </div>
            
        </div>
        
        <!-- Légende Repos -->
        <div class="mt-4 p-4 bg-white rounded-lg">
            <p class="text-xs font-medium text-slate-500 mb-2">Légende :</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs text-gray-600">
                <div><span class="font-semibold text-green-600">≥7 jours</span> : Repos optimal</div>
                <div><span class="font-semibold text-blue-600">4-6 jours</span> : Repos correct</div>
                <div><span class="font-semibold text-orange-600">2-3 jours</span> : Fatigue possible</div>
                <div><span class="font-semibold text-red-600">≤1 jour</span> : Risque élevé de fatigue</div>
            </div>
        </div>
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-yellow-400 p-4 rounded">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700">
                    <strong>Impact du contexte :</strong> Un derby avec 3 jours de repos aura une intensité différente d'un match de routine avec repos complet. Ces facteurs influencent la performance physique et mentale.
                </p>
            </div>
        </div>
    </div>
    
</div>

<script>
const importanceDescriptions = {
    'low': '⚪ Match sans pression particulière. Motivations standards des deux côtés.',
    'medium': '🟡 Match important mais sans enjeu vital. Les équipes veulent gagner mais pas de drame en cas de défaite.',
    'high': '🟠 Match à enjeu élevé (derby, lutte pour les places européennes, maintien). Forte motivation et intensité attendues.',
    'critical': '🔴 Match décisif ! Finale, dernier match pour le titre ou contre la relégation. Pression maximale, tout se joue ici.'
};

function updateImportanceInfo(importance) {
    const infoDesc = document.getElementById('importance-desc');
    if (importanceDescriptions[importance]) {
        infoDesc.textContent = importanceDescriptions[importance];
    }
}

function updateRestInfo(team, days) {
    const infoDiv = document.getElementById(`${team}-rest-info`);
    const descEl = document.getElementById(`${team}-rest-desc`);
    
    days = parseInt(days);
    
    let message, bgClass, borderClass;
    
    if (days >= 7) {
        message = '✅ Repos optimal - Équipe fraîche, récupération complète';
        bgClass = 'bg-white';
        borderClass = 'border-green-400';
    } else if (days >= 4) {
        message = '🟢 Repos correct - Bonne récupération, légère fatigue possible';
        bgClass = 'bg-white';
        borderClass = 'border-blue-400';
    } else if (days >= 2) {
        message = '⚠️ Repos faible - Fatigue probable, rotation nécessaire';
        bgClass = 'bg-white';
        borderClass = 'border-orange-400';
    } else {
        message = '🔴 Repos insuffisant - Risque élevé de fatigue physique et mentale';
        bgClass = 'bg-white';
        borderClass = 'border-red-400';
    }
    
    descEl.textContent = message;
    infoDiv.className = `mt-2 p-3 ${bgClass} border-l-4 ${borderClass} rounded`;
}
</script>