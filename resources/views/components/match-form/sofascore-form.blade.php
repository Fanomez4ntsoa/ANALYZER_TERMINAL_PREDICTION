<div class="bg-white rounded-lg p-5 border border-blue-400">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- FORME DOMICILE -->
        <div>
            <label class="block text-sm font-semibold text-slate-500 mb-2">
                Forme Domicile (5 derniers matchs)
            </label>
            <input 
                type="text"
                name="home_form_streak"
                placeholder="Ex: WWDLW"
                maxlength="5"
                class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition uppercase font-mono text-lg tracking-widest"
                oninput="this.value = this.value.toUpperCase(); validateForm('home', this.value)"
            />
            <div class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                <span class="font-semibold text-green-800">W</span> = Victoire
                <span class="font-semibold text-gray-800">D</span> = Nul
                <span class="font-semibold text-red-800">L</span> = Défaite
            </div>
            
            <!-- Visualisation Forme Domicile -->
            <div id="home-form-visual" class="mt-3 flex gap-1"></div>
        </div>
        
        <!-- FORME EXTÉRIEUR -->
        <div>
            <label class="block text-sm font-semibold text-slate-500 mb-2">
                Forme Extérieur (5 derniers matchs)
            </label>
            <input 
                type="text"
                name="away_form_streak"
                placeholder="Ex: DWLLW"
                maxlength="5"
                class="text-slate-800 w-full px-4 py-3 border bg-white border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition uppercase font-mono text-lg tracking-widest"
                oninput="this.value = this.value.toUpperCase(); validateForm('away', this.value)"
            />
            <div class="mt-2 flex items-center gap-2 text-xs text-gray-600">
                <span class="font-semibold text-green-800">W</span> = Victoire
                <span class="font-semibold text-gray-800">D</span> = Nul
                <span class="font-semibold text-red-800">L</span> = Défaite
            </div>
            
            <!-- Visualisation Forme Extérieur -->
            <div id="away-form-visual" class="mt-3 flex gap-1"></div>
        </div>
        
    </div>
    
    <!-- Info Helper -->
    <div class="mt-4 bg-white border-l-4 border-blue-400 p-3 rounded">
        <div class="flex items-start gap-2">
            <svg class="h-5 w-5 text-blue-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm text-blue-800">
                <strong>Comment remplir :</strong> Entrez les résultats des 5 derniers matchs du plus récent au plus ancien. Ex: "WWDLW" = 2 victoires, 1 nul, 1 défaite, puis 1 victoire.
            </p>
        </div>
    </div>
    
</div>

<script>
function validateForm(team, value) {
    const visualDiv = document.getElementById(`${team}-form-visual`);
    visualDiv.innerHTML = '';
    
    // Valider les caractères
    const validChars = value.split('').filter(char => ['W', 'D', 'L'].includes(char));
    
    // Créer les badges visuels
    validChars.forEach(char => {
        const badge = document.createElement('div');
        badge.className = 'w-10 h-10 rounded-full flex items-center justify-center font-bold text-slate-800 text-sm';
        
        if (char === 'W') {
            badge.className += ' bg-green-700';
            badge.textContent = 'W';
        } else if (char === 'D') {
            badge.className += ' bg-slate-100';
            badge.textContent = 'D';
        } else if (char === 'L') {
            badge.className += ' bg-red-700';
            badge.textContent = 'L';
        }
        
        visualDiv.appendChild(badge);
    });
    
    // Ajouter les emplacements vides si moins de 5
    for (let i = validChars.length; i < 5; i++) {
        const emptyBadge = document.createElement('div');
        emptyBadge.className = 'w-10 h-10 rounded-full border-2 border-dashed border-gray-300 flex items-center justify-center text-slate-500 text-xs';
        emptyBadge.textContent = '?';
        visualDiv.appendChild(emptyBadge);
    }
}
</script>