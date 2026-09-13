<div class="bg-white rounded-lg p-6 border-2 border-purple-200">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- FORMATION DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-500 mb-2">
                Formation Équipe Domicile
            </label>
            <select 
                name="home_formation"
                class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition"
                onchange="updateFormationInfo('home', this.value)"
            >
                <option value="">-- Sélectionnez une formation --</option>
                <optgroup label="🔴 Formations Offensives">
                    <option value="4-3-3">4-3-3</option>
                    <option value="4-2-3-1">4-2-3-1</option>
                    <option value="4-3-1-2">4-3-1-2</option>
                    <option value="3-4-3">3-4-3 (Très offensif)</option>
                    <option value="4-2-4">4-2-4 (Très offensif)</option>
                    <option value="3-2-4-1">3-2-4-1</option>
                </optgroup>
                <optgroup label="⚖️ Formations Équilibrées">
                    <option value="4-4-2">4-4-2</option>
                    <option value="3-4-2-1">3-4-2-1</option>
                    <option value="5-2-3">5-2-3</option>
                    <option value="3-3-3-1">3-3-3-1</option>
                </optgroup>
                <optgroup label="🔵 Formations Défensives">
                    <option value="4-1-4-1">4-1-4-1</option>
                    <option value="4-5-1">4-5-1</option>
                    <option value="5-3-2">5-3-2</option>
                    <option value="5-4-1">5-4-1 (Très défensif)</option>
                </optgroup>
                <optgroup label="🎯 Formations Contrôle">
                    <option value="4-4-2-losange">4-4-2 (losange)</option>
                    <option value="4-1-2-1-2">4-1-2-1-2</option>
                    <option value="3-5-2">3-5-2</option>
                    <option value="3-6-1">3-6-1</option>
                    <option value="3-1-4-2">3-1-4-2</option>
                </optgroup>
                <optgroup label="🌟 Formations Rares">
                    <option value="2-3-5">2-3-5 (Historique)</option>
                </optgroup>
            </select>
            
            <!-- Info Formation Domicile -->
            <div id="home-formation-info" class="mt-3 p-4 bg-slate-100 rounded-lg hidden">
                <div class="flex items-start gap-2">
                    <span class="text-2xl">⚙️</span>
                    <div>
                        <p class="font-semibold text-slate-800" id="home-formation-style"></p>
                        <p class="text-sm text-slate-800 mt-1" id="home-formation-desc"></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- FORMATION EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-500 mb-2">
                Formation Équipe Extérieur
            </label>
            <select 
                name="away_formation"
                class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent transition"
                onchange="updateFormationInfo('away', this.value)"
            >
                <option value="">-- Sélectionnez une formation --</option>
                <optgroup label="🔴 Formations Offensives">
                    <option value="4-3-3">4-3-3</option>
                    <option value="4-2-3-1">4-2-3-1</option>
                    <option value="4-3-1-2">4-3-1-2</option>
                    <option value="3-4-3">3-4-3 (Très offensif)</option>
                    <option value="4-2-4">4-2-4 (Très offensif)</option>
                    <option value="3-2-4-1">3-2-4-1</option>
                </optgroup>
                <optgroup label="⚖️ Formations Équilibrées">
                    <option value="4-4-2">4-4-2</option>
                    <option value="3-4-2-1">3-4-2-1</option>
                    <option value="5-2-3">5-2-3</option>
                    <option value="3-3-3-1">3-3-3-1</option>
                </optgroup>
                <optgroup label="🔵 Formations Défensives">
                    <option value="4-1-4-1">4-1-4-1</option>
                    <option value="4-5-1">4-5-1</option>
                    <option value="5-3-2">5-3-2</option>
                    <option value="5-4-1">5-4-1 (Très défensif)</option>
                </optgroup>
                <optgroup label="🎯 Formations Contrôle">
                    <option value="4-4-2-losange">4-4-2 (losange)</option>
                    <option value="4-1-2-1-2">4-1-2-1-2</option>
                    <option value="3-5-2">3-5-2</option>
                    <option value="3-6-1">3-6-1</option>
                    <option value="3-1-4-2">3-1-4-2</option>
                </optgroup>
                <optgroup label="🌟 Formations Rares">
                    <option value="2-3-5">2-3-5 (Historique)</option>
                </optgroup>
            </select>
            
            <!-- Info Formation Extérieur -->
            <div id="away-formation-info" class="mt-3 p-4 bg-slate-100 rounded-lg hidden">
                <div class="flex items-start gap-2">
                    <span class="text-2xl">⚙️</span>
                    <div>
                        <p class="font-semibold text-slate-800" id="away-formation-style"></p>
                        <p class="text-sm text-slate-800 mt-1" id="away-formation-desc"></p>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-blue-400 p-4 rounded">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-blue-700">
                    <strong>Astuce :</strong> La formation influence le style de jeu et peut créer des avantages tactiques. Un 4-3-3 offensif contre un 5-4-1 défensif, par exemple.
                </p>
            </div>
        </div>
    </div>
    
</div>

<script>
const formationProfiles = {
    '4-3-3': { style: 'Offensif', description: 'Attaque par les ailes, pressing haut, jeu rapide' },
    '4-2-3-1': { style: 'Offensif', description: 'Créativité centrale, meneur de jeu important' },
    '4-4-2': { style: 'Équilibré', description: 'Solide et polyvalent, duo d\'attaquants classique' },
    '4-4-2-losange': { style: 'Contrôle', description: 'Domination du milieu, jeu en passes courtes' },
    '4-1-4-1': { style: 'Défensif', description: 'Sentinelle protectrice, contre-attaques rapides' },
    '4-5-1': { style: 'Défensif', description: 'Bloc compact, attaquant de référence isolé' },
    '4-3-1-2': { style: 'Offensif', description: 'Deux attaquants soutenus par un meneur' },
    '4-1-2-1-2': { style: 'Contrôle', description: 'Losange étroit, construction par le milieu' },
    '3-4-3': { style: 'Très offensif', description: 'Trois attaquants, jeu spectaculaire' },
    '3-5-2': { style: 'Contrôle', description: 'Maîtrise du milieu avec pistons latéraux' },
    '3-4-2-1': { style: 'Équilibré', description: 'Deux meneurs soutenant l\'attaquant' },
    '3-6-1': { style: 'Contrôle', description: 'Surcharge extrême au milieu, possession maximale' },
    '3-3-3-1': { style: 'Offensif', description: 'Structure symétrique, transitions dynamiques' },
    '3-1-4-2': { style: 'Offensif', description: 'Libéro moderne, duo offensif mobile' },
    '5-3-2': { style: 'Défensif', description: 'Bloc bas très solide, jeu direct en contre' },
    '5-4-1': { style: 'Très défensif', description: 'Ultra-défensif, attaquant solitaire' },
    '5-2-3': { style: 'Équilibré', description: 'Défense à 5 mais trio offensif dynamique' },
    '2-3-5': { style: 'Très offensif', description: 'Formation historique offensive (rare aujourd\'hui)' },
    '4-2-4': { style: 'Très offensif', description: 'Quatre attaquants, jeu vertical et direct' },
    '3-2-4-1': { style: 'Offensif', description: 'Milieux offensifs larges, pressing intense' }
};

function updateFormationInfo(team, formation) {
    const infoDiv = document.getElementById(`${team}-formation-info`);
    const styleEl = document.getElementById(`${team}-formation-style`);
    const descEl = document.getElementById(`${team}-formation-desc`);
    
    if (formation && formationProfiles[formation]) {
        const profile = formationProfiles[formation];
        styleEl.textContent = `Style : ${profile.style}`;
        descEl.textContent = profile.description;
        infoDiv.classList.remove('hidden');
    } else {
        infoDiv.classList.add('hidden');
    }
}
</script>