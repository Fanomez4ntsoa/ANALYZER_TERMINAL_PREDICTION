<div class="bg-white rounded-lg p-6 border-2 border-slate-200" x-data="{ sourceMode: 'manual' }">
    
    <div class="mb-6">
        <h4 class="font-bold text-xl text-gray-800 mb-3">Prédictions des Sources A/B/C</h4>
        <p class="text-sm text-gray-600 mb-4">
            Renseignez les prédictions de vos 3 sources favorites (sites, experts, IA...)
        </p>
        
        <!-- MODE TOGGLE -->
        <div class="flex gap-4">
            <button
                type="button"
                @click="sourceMode = 'manual'"
                :class="sourceMode === 'manual' ? 'bg-slate-50 text-slate-800' : 'bg-gray-200 text-black hover:bg-gray-300'"
                class="px-4 py-2 rounded-lg font-medium transition flex items-center gap-2"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                </svg>
                Formulaire Manuel
            </button>
            <button
                type="button"
                @click="sourceMode = 'import'"
                :class="sourceMode === 'import' ? 'bg-slate-50 text-slate-800' : 'bg-gray-200 text-black hover:bg-gray-300'"
                class="px-4 py-2 rounded-lg font-medium transition flex items-center gap-2"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd" />
                </svg>
                Import Texte
            </button>
        </div>
    </div>

    
    <!-- MODE IMPORT TEXTE -->
    <div x-show="sourceMode === 'import'" x-transition class="space-y-4">
        
        <div class="bg-white border-2 border-blue-200 rounded-lg p-4">
            <p class="text-sm text-slate-800">
                💡 <strong>Copiez-collez</strong> le texte brut de chaque source, puis cliquez "Parser" pour extraire automatiquement les données.
            </p>
        </div>
        
        <!-- SOURCE A - Import -->
        <div>
            <label class="block font-medium text-slate-800 mb-2 items-center gap-2">
                <span class="bg-white text-slate-800 w-6 h-6 rounded-full flex items-center justify-center text-xs">A</span>
                Source A (good-sport.co)
            </label>
            <textarea
                name="source_a_raw"
                rows="4"
                placeholder="Collez le texte brut de la Source A ici..."
                class="w-full border-2 bg-white border-gray-300 rounded-lg p-3 font-mono text-sm focus:border-blue-500 outline-none resize-none"
            ></textarea>
        </div>
        
        <!-- SOURCE B - Import -->
        <div>
            <label class="block font-medium text-slate-800 mb-2 items-center gap-2">
                <span class="bg-white text-slate-800 w-6 h-6 rounded-full flex items-center justify-center text-xs">B</span>
                Source B (mybets.today)
            </label>
            <textarea
                name="source_b_raw"
                rows="4"
                placeholder="Collez le texte brut de la Source B ici..."
                class="w-full border-2 bg-white border-gray-300 rounded-lg p-3 font-mono text-sm focus:border-green-500 outline-none resize-none"
            ></textarea>
        </div>
        
        <!-- SOURCE C - Import -->
        <div>
            <label class="block font-medium text-slate-800 mb-2 items-center gap-2">
                <span class="bg-white text-slate-800 w-6 h-6 rounded-full flex items-center justify-center text-xs">C</span>
                Source C
            </label>
            <textarea
                name="source_c_raw"
                rows="4"
                placeholder="Collez le texte brut de la Source C ici..."
                class="w-full border-2 bg-white border-gray-300 rounded-lg p-3 font-mono text-sm focus:border-purple-500 outline-none resize-none"
            ></textarea>
        </div>
        
        <button
            type="button"
            class="bg-purple-600 text-slate-800 px-6 py-3 rounded-lg font-bold hover:bg-purple-700 transition shadow-sm w-full"
            @click="alert('🤖 Parser automatique sera implémenté prochainement !')"
        >
            🤖 PARSER AUTOMATIQUEMENT
        </button>
    </div>
    
    <!-- MODE MANUEL -->
    <div x-show="sourceMode === 'manual'" x-transition class="space-y-6">
        
        <!-- SOURCE A - Manuel -->
        <div class="bg-white rounded-lg p-5 border-2 border-blue-200">
            <div class="flex items-center justify-between mb-4">
                <h5 class="font-bold text-slate-800 flex items-center gap-2">
                    <span class="bg-blue-900 text-slate-800 w-8 h-8 rounded-full flex items-center justify-center text-sm">A</span>
                    Source A
                </h5>
            </div>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">Nom de la source</label>
                    <input
                        type="text"
                        name="source_a_name"
                        placeholder="Ex: Forebet, Expert Paul, ChatGPT..."
                        class="text-slate-800 w-full px-4 py-2 border  bg-slate-50 border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                    >
                </div>
                
                <!-- Winner -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Vainqueur</label>
                        <select name="source_a_winner_pick" class="text-slate-800 w-full px-3 py-2 bg-slate-50 border border-gray-900 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="1">1 (Domicile)</option>
                            <option value="X">X (Nul)</option>
                            <option value="2">2 (Extérieur)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_a_winner_confidence" min="0" max="100" value="50" class=" text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                
                <!-- Over/Under -->
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">Over/Under 2.5</label>
                    <select name="source_a_ou_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="Over">Over 2.5</option>
                        <option value="Under" selected>Under 2.5</option>
                    </select>
                </div>
                
                <!-- BTTS -->
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">BTTS (Both Teams To Score)</label>
                    <select name="source_a_btts_pick" class="text-slate-800 w-full px-3 py-2 bg-slate-50 order border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="Yes">Yes</option>
                        <option value="No" selected>No</option>
                    </select>
                </div>
                
                <!-- Double Chance -->
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">Double Chance</label>
                    <select name="source_a_dc_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="1X" selected>1X</option>
                        <option value="12">12</option>
                        <option value="X2">X2</option>
                    </select>
                </div>
                
                <!-- Exact Score -->
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">Score Exact</label>
                    <input type="text" name="source_a_exact_score" placeholder="Ex: 2-0" value="2-0" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
        </div>
        
        <!-- SOURCE B - Manuel -->
        <div class="bg-white rounded-lg p-5 border-2 border-green-200">
            <div class="flex items-center justify-between mb-4">
                <h5 class="font-bold text-green-900 flex items-center gap-2">
                    <span class="bg-green-900 text-slate-800 w-8 h-8 rounded-full flex items-center justify-center text-sm">B</span>
                    Source B
                </h5>
            </div>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">Nom de la source</label>
                    <input type="text" name="source_b_name" placeholder="Ex: MyBets.today" class="text-slate-800 w-full px-4 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                
                <!-- Winner -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Vainqueur</label>
                        <select name="source_b_winner_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="1" selected>1 (Domicile)</option>
                            <option value="X">X (Nul)</option>
                            <option value="2">2 (Extérieur)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_b_winner_confidence" min="0" max="100" value="67" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                
                <!-- Over/Under -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Over/Under</label>
                        <select name="source_b_ou_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="Over">Over 2.5</option>
                            <option value="Under" selected>Under 2.5</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_b_ou_confidence" min="0" max="100" value="63" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                
                <!-- BTTS -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">BTTS</label>
                        <select name="source_b_btts_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="Yes">Yes</option>
                            <option value="No" selected>No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_b_btts_confidence" min="0" max="100" value="64" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                
                <!-- Double Chance -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Double Chance</label>
                        <select name="source_b_dc_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="1X" selected>1X</option>
                            <option value="12">12</option>
                            <option value="X2">X2</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_b_dc_confidence" min="0" max="100" value="75" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                
                <!-- Exact Score -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Score Exact</label>
                        <input type="text" name="source_b_exact_score" placeholder="Ex: 1:0" value="1:0" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_b_score_confidence" min="0" max="100" value="18" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SOURCE C - Manuel -->
        <div class="bg-white rounded-lg p-5 border-2 border-purple-200">
            <div class="flex items-center justify-between mb-4">
                <h5 class="font-bold text-purple-900 flex items-center gap-2">
                    <span class="bg-purple-900 text-slate-800 w-8 h-8 rounded-full flex items-center justify-center text-sm">C</span>
                    Source C
                </h5>
            </div>
            
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-1">Nom de la source</label>
                    <input type="text" name="source_c_name" placeholder="Ex: BetExplorer" class="text-slate-800 w-full px-4 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                
                <!-- Winner - Probabilités -->
                <div>
                    <label class="block text-sm font-medium text-slate-800 mb-2">Probabilités de victoire (%)</label>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Domicile</label>
                            <input type="number" name="source_c_winner_home" min="0" max="100" value="45" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Nul</label>
                            <input type="number" name="source_c_winner_draw" min="0" max="100" value="30" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Extérieur</label>
                            <input type="number" name="source_c_winner_away" min="0" max="100" value="25" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>
                </div>
                
                <!-- Over/Under -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Over/Under</label>
                        <select name="source_c_ou_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="Over">Over 2.5</option>
                            <option value="Under" selected>Under 2.5</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_c_ou_confidence" min="0" max="100" value="60" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
                
                <!-- BTTS -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">BTTS</label>
                        <select name="source_c_btts_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="Yes">Yes</option>
                            <option value="No" selected>No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_c_btts_confidence" min="0" max="100" value="58" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
                
                <!-- Double Chance -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Double Chance</label>
                        <select name="source_c_dc_pick" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="1X" selected>1X</option>
                            <option value="12">12</option>
                            <option value="X2">X2</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_c_dc_confidence" min="0" max="100" value="75" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
                
                <!-- Exact Score -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Score Exact</label>
                        <input type="text" name="source_c_exact_score" placeholder="Ex: 1:0" value="1:0" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-800 mb-1">Confiance (%)</label>
                        <input type="number" name="source_c_score_confidence" min="0" max="100" value="15" class="text-slate-800 w-full px-3 py-2 border bg-slate-50 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Info Helper -->
    <div class="mt-6 bg-white border-l-4 border-yellow-400 p-4 rounded">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700">
                    <strong>Pourquoi 3 sources ?</strong> Croiser plusieurs prédictions permet de détecter les consensus et les divergences, pour une analyse plus robuste.
                </p>
            </div>
        </div>
    </div>

</div>