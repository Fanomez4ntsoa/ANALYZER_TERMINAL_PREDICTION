<!-- ==================== SECTION 1: INFOS DE BASE ==================== -->
<!-- Informations du match -->
<div class="bg-white rounded-lg p-6 border border-slate-200 mb-6">
    <div class="flex items-center justify-between mb-5">
        <h4 class="text-lg font-semibold text-slate-800">Informations du match</h4>
        <span class="text-xs text-red-400 font-medium">* Obligatoire</span>
    </div>
    
    <!-- Équipes -->
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-2">
                Équipe domicile <span class="text-red-400">*</span>
            </label>
            <input
                type="text"
                name="home_team"
                placeholder="ex: PSG"
                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                required
                value="{{ old('teams.home') }}"
            />
        </div>
        
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-2">
                Équipe extérieur <span class="text-red-400">*</span>
            </label>
            <input
                type="text"
                name="away_team"
                placeholder="ex: Marseille"
                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                required
                value="{{ old('teams.away') }}"
            />
        </div>
    </div>
    
    <!-- Date & Compétition -->
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-2">
                Date & heure <span class="text-red-400">*</span>
            </label>
            <input
                type="datetime-local"
                name="match_date"
                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                required
                value="{{ old('date') }}"
            />
        </div>
        
        <div>
            <label class="block text-sm font-medium text-slate-600 mb-2">
                Compétition <span class="text-red-400">*</span>
            </label>
            <input
                type="text"
                name="competition"
                placeholder="ex: Ligue 1"
                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                required
                value="{{ old('competition') }}"
            />
        </div>
    </div>
    
    <!-- Cotes 1X2 -->
    <div class="pt-4 border-t border-slate-200">
        <div class="flex items-center justify-between mb-4">
            <h5 class="text-sm font-semibold text-slate-700">Cotes 1X2</h5>
            <span class="text-xs text-red-400 font-medium">* Obligatoire</span>
        </div>
        
        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-xs text-slate-500 mb-2">Domicile (1)</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_home"
                    placeholder="1.50"
                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                    required
                    value="{{ old('odds.home') }}"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-500 mb-2">Nul (X)</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_draw"
                    placeholder="4.00"
                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                    required
                    value="{{ old('odds.draw') }}"
                />
            </div>
            
            <div>
                <label class="block text-xs text-slate-500 mb-2">Extérieur (2)</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_away"
                    placeholder="6.00"
                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition"
                    required
                    value="{{ old('odds.away') }}"
                />
            </div>
        </div>
    </div>
</div>

<!-- Cotes complémentaires -->
<div class="bg-white rounded-lg p-6 border border-slate-200">
    <div class="flex items-center justify-between mb-5">
        <h4 class="text-lg font-semibold text-slate-800">Cotes complémentaires</h4>
        <span class="text-xs text-slate-400 bg-slate-50 px-3 py-1 rounded-full">Optionnel</span>
    </div>
    
    <!-- Over/Under 2.5 -->
    <div class="mb-5">
        <h6 class="text-sm text-slate-600 mb-3">Over/Under 2.5</h6>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">Over 2.5</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_over_2_5"
                    placeholder="1.75"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">Under 2.5</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_under_2_5"
                    placeholder="2.10"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
        </div>
    </div>
    
    <!-- BTTS -->
    <div class="mb-5">
        <h6 class="text-sm text-slate-600 mb-3">BTTS</h6>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">Yes</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_btts_yes"
                    placeholder="1.80"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">No</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_btts_no"
                    placeholder="2.00"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
        </div>
    </div>
    
    <!-- Double Chance -->
    <div class="mb-5">
        <h6 class="text-sm text-slate-600 mb-3">Double Chance</h6>
        <div class="grid grid-cols-3 gap-3">
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">1X</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_dc_1x"
                    placeholder="1.20"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">12</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_dc_12"
                    placeholder="1.15"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
            <div>
                <label class="block text-xs text-slate-400 mb-1.5">X2</label>
                <input
                    type="number"
                    step="0.01"
                    name="odds_dc_x2"
                    placeholder="1.50"
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition"
                />
            </div>
        </div>
    </div>
    
    <!-- Total Domicile -->
    <div class="mb-5">
        <h6 class="text-sm text-slate-600 mb-3">Total Domicile</h6>
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Over 0.5</label>
                    <input type="number" step="0.01" name="odds_home_over_0_5" placeholder="1.10"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Under 0.5</label>
                    <input type="number" step="0.01" name="odds_home_under_0_5" placeholder="8.00"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Over 1.5</label>
                    <input type="number" step="0.01" name="odds_home_over_1_5" placeholder="1.80"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Under 1.5</label>
                    <input type="number" step="0.01" name="odds_home_under_1_5" placeholder="2.00"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
            </div>
        </div>
    </div>
    
    <!-- Total Extérieur -->
    <div>
        <h6 class="text-sm text-slate-600 mb-3">Total Extérieur</h6>
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Over 0.5</label>
                    <input type="number" step="0.01" name="odds_away_over_0_5" placeholder="1.25"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Under 0.5</label>
                    <input type="number" step="0.01" name="odds_away_under_0_5" placeholder="4.50"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Over 1.5</label>
                    <input type="number" step="0.01" name="odds_away_over_1_5" placeholder="2.50"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1.5">Under 1.5</label>
                    <input type="number" step="0.01" name="odds_away_under_1_5" placeholder="1.50"
                        class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 text-sm placeholder-gray-600 focus:border-slate-300 outline-none transition" />
                </div>
            </div>
        </div>
    </div>
</div>