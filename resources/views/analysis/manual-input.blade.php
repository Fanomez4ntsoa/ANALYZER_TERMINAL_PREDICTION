@extends('layouts.pro')

@section('title', 'Nouvelle Analyse')
@section('page-title', '📝 Nouvelle Analyse')
@section('page-subtitle', 'Formulaire Complet Multi-Sources')

@section('content')
<div class="container mx-auto px-4 py-8" x-data="matchWizard()">
    <!-- Header -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 mb-8">
        <h1 class="text-4xl font-bold text-slate-800 mb-2">Analyse de Match</h1>
    </div>

    <!-- Stepper Progress Bar -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-8">
        <div class="flex items-center justify-between mb-4">
            <template x-for="step in steps" :key="step.id">
                <div class="flex items-center" :class="step.id < steps.length ? 'flex-1' : ''">
                    <!-- Circle -->
                    <div class="relative flex flex-col items-center">
                        <div 
                            class="w-12 h-12 rounded-full flex items-center justify-center font-bold transition-all duration-300"
                            :class="{
                                'bg-green-500 text-slate-800': step.id < currentStep,
                                'bg-blue-600 text-slate-800 ring-4 ring-blue-100': step.id === currentStep,
                                'bg-gray-200 text-slate-500': step.id > currentStep
                            }"
                        >
                            <span x-show="step.id < currentStep">✓</span>
                            <span x-show="step.id >= currentStep" x-text="step.id"></span>
                        </div>
                        <span 
                            class="absolute -bottom-8 text-xs font-medium whitespace-nowrap"
                            :class="{
                                'text-green-600': step.id < currentStep,
                                'text-gray-600': step.id === currentStep,
                                'text-slate-500': step.id > currentStep
                            }"
                            x-text="step.label"
                        ></span>
                    </div>
                    
                    <!-- Connecting Line -->
                    <div 
                        x-show="step.id < steps.length"
                        class="flex-1 h-1 mx-4 transition-all duration-300"
                        :class="{
                            'bg-green-500': step.id < currentStep,
                            'bg-gray-200': step.id >= currentStep
                        }"
                    ></div>
                </div>
            </template>
        </div>
    </div>

    <!-- Form Container -->
    <form @submit.prevent="submitForm" novalidate class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
        <!-- Step 1: Essentiels -->
        <div x-show="currentStep === 1" x-transition>
            <h2 class="text-2xl font-bold text-slate-800 mb-6">Informations Essentielles</h2>
            
            @include('components.match-form.basic-info')
        </div>

        <!-- Step 2: Sources A/B/C -->
        <div x-show="currentStep === 2" x-transition>
            <h2 class="text-2xl font-bold text-slate-700 mb-6">Sources de Prédiction</h2>
            
            @include('components.match-form.sources-abc')
        </div>

        <!-- Step 3: Tactique & Contexte -->
        <div x-show="currentStep === 3" x-transition>
            <h2 class="text-2xl font-bold text-gray-800 mb-6">🎯 Tactique & Contexte</h2>
            
            <div class="mb-8">
                <h3 class="text-xl font-semibold text-slate-700 mb-4">⚙️ Configuration Tactique</h3>
                @include('components.match-form.tactical')
            </div>
            
            <div class="border-t pt-6">
                <h3 class="text-xl font-semibold text-slate-700 mb-4">📌 Contexte du Match</h3>
                @include('components.match-form.context')
            </div>
        </div>

        <!-- Step 4: Données Avancées -->
        <div x-show="currentStep === 4" x-transition>
            <h2 class="text-2xl font-bold text-slate-800 mb-6">Données Avancées</h2>
            
            <!-- Sofascore Section -->
            <div class="mb-8 bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-xl font-semibold text-blue-800 mb-4">Sofascore</h3>
                
                <div class="space-y-6">
                    <div>
                        <h4 class="font-medium text-slate-700 mb-3">Forme Récente</h4>
                        @include('components.match-form.sofascore-form')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">Blessures & Absences</h4>
                        @include('components.match-form.sofascore-injuries')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">Historique H2H</h4>
                        @include('components.match-form.sofascore-h2h')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">Statistiques</h4>
                        @include('components.match-form.sofascore-stats')
                    </div>
                </div>
            </div>

            <!-- FootyStats Section -->
            <div class="mb-8 bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-xl font-semibold text-green-200 mb-4">FootyStats</h3>
                
                <div class="space-y-6">
                    <div>
                        <h4 class="font-medium text-slate-700 mb-3">Over/Under</h4>
                        @include('components.match-form.footystats-ou')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">BTTS (Both Teams To Score)</h4>
                        @include('components.match-form.footystats-btts')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">Expected Goals (xG)</h4>
                        @include('components.match-form.footystats-xg')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">Séries en Cours</h4>
                        @include('components.match-form.footystats-series')
                    </div>
                </div>
            </div>

            <!-- FBRef Section -->
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-xl font-semibold text-purple-700 mb-4">FBRef</h3>
                
                <div class="space-y-6">
                    <div>
                        <h4 class="font-medium text-slate-700 mb-3">Classement Championnat</h4>
                        @include('components.match-form.fbref-league')
                    </div>
                    
                    <div class="border-t pt-6">
                        <h4 class="font-medium text-slate-700 mb-3">Top Joueurs</h4>
                        @include('components.match-form.fbref-players')
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 5: Récapitulatif -->
        <div x-show="currentStep === 5" x-transition x-init="$watch('currentStep', value => { if(value === 5) updateRecap() })">
            <h2 class="text-2xl font-bold text-slate-700 mb-6">Récapitulatif Final</h2>
            
            <!-- Infos Essentielles -->
            <div class="bg-white from-blue-50 to-purple-50 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-slate-700 mb-4">Informations Essentielles</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h4 class="font-semibold text-slate-700 mb-2">Match</h4>
                        <div class="space-y-1 text-sm">
                            <p><span class="text-slate-700">Équipes:</span> <strong class="text-slate-800" x-text="formData.homeTeam + ' vs ' + formData.awayTeam"></strong></p>
                            <p><span class="text-slate-700">Date:</span> <span class="text-slate-800" x-text="formData.matchDate"></span></p>
                            <p><span class="text-slate-700">Compétition:</span> <span class="text-slate-800" x-text="formData.competition"></span></p>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h4 class="font-semibold text-slate-700 mb-2">Cotes</h4>
                        <div class="space-y-1 text-sm">
                            <p><span class="text-slate-700">1 (Domicile):</span> <strong class="text-slate-800" x-text="formData.odds1 || '-'"></strong></p>
                            <p><span class="text-slate-700">X (Nul):</span> <strong class="text-slate-800" x-text="formData.oddsX || '-'"></strong></p>
                            <p><span class="text-slate-700">2 (Extérieur):</span> <strong class="text-slate-800" x-text="formData.odds2 || '-'"></strong></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sources A/B/C -->
            <div class="bg-white from-indigo-50 to-blue-50 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-slate-700 mb-4">Sources de Prédiction</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Source A -->
                    <div class="bg-white rounded-lg p-4 border-2 border-blue-300">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="bg-blue-900 text-slate-800 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold">A</span>
                            <h4 class="font-semibold text-blue-900">Source A</h4>
                        </div>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-slate-700">Vainqueur:</span> <strong class="text-slate-800" x-text="formData.sourceA_winner || '-'"></strong></p>
                            <p><span class="text-slate-700">O/U 2.5:</span> <strong class="text-slate-800" x-text="formData.sourceA_ou || '-'"></strong></p>
                            <p><span class="text-slate-700">BTTS:</span> <strong class="text-slate-800" x-text="formData.sourceA_btts || '-'"></strong></p>
                            <p><span class="text-slate-700">Score:</span> <strong class="text-slate-800" x-text="formData.sourceA_score || '-'"></strong></p>
                        </div>
                    </div>
                    
                    <!-- Source B -->
                    <div class="bg-white rounded-lg p-4 border-2 border-green-300">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="bg-green-900 text-slate-800 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold">B</span>
                            <h4 class="font-semibold text-green-900">Source B</h4>
                        </div>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-slate-700">Vainqueur:</span> <strong class="text-slate-800" x-text="formData.sourceB_winner || '-'"></strong></p>
                            <p><span class="text-slate-700">Confiance:</span> <strong class="text-slate-800" x-text="(formData.sourceB_winner_conf || '-') + '%'"></strong></p>
                            <p><span class="text-slate-700">O/U 2.5:</span> <strong class="text-slate-800" x-text="formData.sourceB_ou || '-'"></strong></p>
                            <p><span class="text-slate-700">BTTS:</span> <strong class="text-slate-800" x-text="formData.sourceB_btts || '-'"></strong></p>
                        </div>
                    </div>
                    
                    <!-- Source C -->
                    <div class="bg-white rounded-lg p-4 border-2 border-purple-300">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="bg-purple-900 text-slate-800 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold">C</span>
                            <h4 class="font-semibold text-purple-900">Source C</h4>
                        </div>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-slate-700">1:</span> <strong class="text-slate-800" x-text="(formData.sourceC_home || '-') + '%'"></strong></p>
                            <p><span class="text-slate-700">X:</span> <strong class="text-slate-800" x-text="(formData.sourceC_draw || '-') + '%'"></strong></p>
                            <p><span class="text-slate-700">2:</span> <strong class="text-slate-800" x-text="(formData.sourceC_away || '-') + '%'"></strong></p>
                            <p><span class="text-slate-700">O/U:</span> <strong class="text-slate-800" x-text="formData.sourceC_ou || '-'"></strong></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tactique & Contexte -->
            <div class="bg-white from-orange-50 to-yellow-50 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-slate-700 mb-4">Tactique & Contexte</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h4 class="font-semibold text-slate-700 mb-2">Formations</h4>
                        <div class="space-y-1 text-sm">
                            <p><span class="text-slate-700">Domicile:</span> <strong class="text-slate-800" x-text="formData.homeFormation || 'Non renseignée'"></strong></p>
                            <p><span class="text-slate-700">Extérieur:</span> <strong class="text-slate-800" x-text="formData.awayFormation || 'Non renseignée'"></strong></p>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h4 class="font-semibold text-slate-700 mb-2">Contexte</h4>
                        <div class="space-y-1 text-sm">
                            <p><span class="text-slate-700">Enjeu:</span> <strong class="text-slate-800" x-text="formData.importance || 'Non renseigné'"></strong></p>
                            <p><span class="text-slate-700">Repos 🏠:</span> <strong class="text-slate-800" x-text="(formData.homeRest || '?') + ' jours'"></strong></p>
                            <p><span class="text-slate-700">Repos ✈️:</span> <strong class="text-slate-800" x-text="(formData.awayRest || '?') + ' jours'"></strong></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Données Avancées -->
            <div class="bg-white from-green-50 to-teal-50 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-slate-700 mb-4">Données Avancées Renseignées</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                        <div class="text-2xl mb-1"></div>
                        <p class="text-xs font-semibold text-slate-700">Sofascore</p>
                        <p class="text-xs text-slate-600">Forme, H2H, Stats</p>
                    </div>
                    <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                        <div class="text-2xl mb-1"></div>
                        <p class="text-xs font-semibold text-slate-700">FootyStats</p>
                        <p class="text-xs text-slate-600">O/U, BTTS, xG</p>
                    </div>
                    <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                        <div class="text-2xl mb-1"></div>
                        <p class="text-xs font-semibold text-slate-700">FBRef</p>
                        <p class="text-xs text-slate-600">Classement, Buteurs</p>
                    </div>
                    <div class="bg-white rounded-lg p-3 text-center shadow-sm">
                        <div class="text-2xl mb-1"></div>
                        <p class="text-xs font-semibold text-slate-700">Formulaire</p>
                        <p class="text-xs text-green-900">Complet</p>
                    </div>
                </div>
            </div>

            <!-- Warning -->
            <div class="bg-white border-l-4 border-yellow-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-900" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            <strong>Dernière vérification :</strong> Assurez-vous que toutes les informations essentielles sont correctes. L'analyse AI utilisera ces données pour générer ses prédictions.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="flex justify-between mt-8 pt-6 border-t border-slate-200">
            <button 
                type="button"
                @click="previousStep"
                x-show="currentStep > 1"
                class="px-6 py-3 bg-slate-50 text-slate-800 rounded-lg hover:bg-slate-100 transition-colors font-medium"
            >
                ← Retour
            </button>
    
            <button 
                type="button"
                @click="nextStep"
                x-show="currentStep < 5"
                class="px-6 py-3 bg-blue-600 text-slate-800 rounded-lg hover:bg-blue-700 transition-colors font-medium"
            >
                Étape Suivante →
            </button>
    
            <button 
                type="submit"
                x-show="currentStep === 5"
                class="px-8 py-3 bg-green-600 text-slate-800 rounded-lg hover:bg-green-700 transition-all font-bold"
            >
                ANALYSER CE MATCH
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    function matchWizard() {
        return {
            currentStep: 1,
            steps: [
                { id: 1, label: 'Essentiels' },
                { id: 2, label: 'Sources' },
                { id: 3, label: 'Tactique' },
                { id: 4, label: 'Données' },
                { id: 5, label: 'Récap' }
            ],
            formData: {
                // Step 1
                homeTeam: '',
                awayTeam: '',
                matchDate: '',
                competition: '',
                odds1: '',
                oddsX: '',
                odds2: '',
                sourceA: '',
                sourceB: '',
                sourceC: '',
                
                // Step 2
                homeFormation: '',
                awayFormation: '',
                stake: '',
                rest: '',
                
                // Step 3 (will be populated from includes)
            },
            
            nextStep() {
                if (this.currentStep < 5) {
                    if (this.validateCurrentStep()) {
                        this.currentStep++;
                        // Mettre à jour le récap si on arrive sur l'étape 5
                        if (this.currentStep === 5) {
                            this.updateRecap();
                        }

                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                }
            },
            
            previousStep() {
                if (this.currentStep > 1) {
                    this.currentStep--;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },
            
            validateCurrentStep() {
                // Step 1 validation (required fields)
                if (this.currentStep === 1) {
                    const homeTeam = document.querySelector('input[name="home_team"]')?.value;
                    const awayTeam = document.querySelector('input[name="away_team"]')?.value;
                    const matchDate = document.querySelector('input[name="match_date"]')?.value;
                    const competition = document.querySelector('input[name="competition"]')?.value;
                    const oddsHome = document.querySelector('input[name="odds_home"]')?.value;
                    const oddsDraw = document.querySelector('input[name="odds_draw"]')?.value;
                    const oddsAway = document.querySelector('input[name="odds_away"]')?.value;
                    
                    if (!homeTeam || !awayTeam || !matchDate || !competition) {
                        alert('⚠️ Veuillez remplir au minimum les équipes, la compétition et la date du match.');
                        return false;
                    }

                    if (!oddsHome || !oddsDraw || !oddsAway) {
                        alert('⚠️ Veuillez remplir les 3 cotes (1-X-2).');
                        return false;
                    }
                    
                    // Update formData for recap
                    this.formData.homeTeam = homeTeam;
                    this.formData.awayTeam = awayTeam;
                    this.formData.matchDate = matchDate;
                    this.formData.competition = competition || 'Non spécifiée';
                    this.formData.odds1 = oddsHome;
                    this.formData.oddsX = oddsDraw;
                    this.formData.odds2 = oddsAway;
                }
                return true;
            },
            
            updateRecap() {
                // Étape 3: Tactique & Contexte
                this.formData.homeFormation = document.querySelector('select[name="home_formation"]')?.value || '';
                this.formData.awayFormation = document.querySelector('select[name="away_formation"]')?.value || '';
                this.formData.importance = document.querySelector('select[name="match_importance"]')?.value || '';
                this.formData.homeRest = document.querySelector('input[name="home_rest_days"]')?.value || '';
                this.formData.awayRest = document.querySelector('input[name="away_rest_days"]')?.value || '';
                
                // Étape 2: Sources A/B/C
                this.formData.sourceA_winner = document.querySelector('select[name="source_a_winner_pick"]')?.value || '';
                this.formData.sourceA_ou = document.querySelector('select[name="source_a_ou_pick"]')?.value || '';
                this.formData.sourceA_btts = document.querySelector('select[name="source_a_btts_pick"]')?.value || '';
                this.formData.sourceA_score = document.querySelector('input[name="source_a_exact_score"]')?.value || '';
                
                this.formData.sourceB_winner = document.querySelector('select[name="source_b_winner_pick"]')?.value || '';
                this.formData.sourceB_winner_conf = document.querySelector('input[name="source_b_winner_confidence"]')?.value || '';
                this.formData.sourceB_ou = document.querySelector('select[name="source_b_ou_pick"]')?.value || '';
                this.formData.sourceB_btts = document.querySelector('select[name="source_b_btts_pick"]')?.value || '';
                
                this.formData.sourceC_home = document.querySelector('input[name="source_c_winner_home"]')?.value || '';
                this.formData.sourceC_draw = document.querySelector('input[name="source_c_winner_draw"]')?.value || '';
                this.formData.sourceC_away = document.querySelector('input[name="source_c_winner_away"]')?.value || '';
                this.formData.sourceC_ou = document.querySelector('select[name="source_c_ou_pick"]')?.value || '';
                
                console.log('Recap updated:', this.formData);
            },
            
            submitForm() {
                // Préparer les données du match
                const matchData = {
                    teams: {
                        home: this.formData.homeTeam,
                        away: this.formData.awayTeam
                    },
                    date: this.formData.matchDate,
                    competition: this.formData.competition,
                    odds: {
                        home: parseFloat(this.formData.odds1) || 0,
                        draw: parseFloat(this.formData.oddsX) || 0,
                        away: parseFloat(this.formData.odds2) || 0,
                        over_2_5: parseFloat(document.querySelector('input[name="odds_over_2_5"]')?.value) || null,
                        under_2_5: parseFloat(document.querySelector('input[name="odds_under_2_5"]')?.value) || null,
                        btts_yes: parseFloat(document.querySelector('input[name="odds_btts_yes"]')?.value) || null,
                        btts_no: parseFloat(document.querySelector('input[name="odds_btts_no"]')?.value) || null,
                        dc_1x: parseFloat(document.querySelector('input[name="odds_dc_1x"]')?.value) || null,
                        dc_12: parseFloat(document.querySelector('input[name="odds_dc_12"]')?.value) || null,
                        dc_x2: parseFloat(document.querySelector('input[name="odds_dc_x2"]')?.value) || null,
                        home_over_0_5: parseFloat(document.querySelector('input[name="odds_home_over_0_5"]')?.value) || null,
                        home_under_0_5: parseFloat(document.querySelector('input[name="odds_home_under_0_5"]')?.value) || null,
                        home_over_1_5: parseFloat(document.querySelector('input[name="odds_home_over_1_5"]')?.value) || null,
                        home_under_1_5: parseFloat(document.querySelector('input[name="odds_home_under_1_5"]')?.value) || null,
                        away_over_0_5: parseFloat(document.querySelector('input[name="odds_away_over_0_5"]')?.value) || null,
                        away_under_0_5: parseFloat(document.querySelector('input[name="odds_away_under_0_5"]')?.value) || null,
                        away_over_1_5: parseFloat(document.querySelector('input[name="odds_away_over_1_5"]')?.value) || null,
                        away_under_1_5: parseFloat(document.querySelector('input[name="odds_away_under_1_5"]')?.value) || null,
                    },
                    sources: this.collectSources(),
                    tacticalData: this.collectTacticalData(),
                    contextData: this.collectContextData(),
                    sofascoreData: this.collectSofascoreData(),
                    footyStatsData: this.collectFootyStatsData(),
                    fbrefData: this.collectFbrefData()
                };
                
                // Envoyer au backend
                fetch('/analysis', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        matches: [matchData]
                    })
                })
                .then(response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                    } else {
                        return response.json();
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('❌ Erreur lors de l\'analyse. Veuillez réessayer.');
                });
            },
            
            collectSources() {
                return {
                    sourceA: {
                        winner: {
                            pick: document.querySelector('select[name="source_a_winner_pick"]')?.value || '1',
                            confidence: parseInt(document.querySelector('input[name="source_a_winner_confidence"]')?.value) || 50
                        },
                        overUnder: {
                            pick: document.querySelector('select[name="source_a_ou_pick"]')?.value || 'Under'
                        },
                        btts: {
                            pick: document.querySelector('select[name="source_a_btts_pick"]')?.value || 'No'
                        },
                        doubleChance: {
                            pick: document.querySelector('select[name="source_a_dc_pick"]')?.value || '1X'
                        },
                        exactScore: {
                            pick: document.querySelector('input[name="source_a_exact_score"]')?.value || '2-0'
                        }
                    },
                    sourceB: {
                        winner: {
                            pick: document.querySelector('select[name="source_b_winner_pick"]')?.value || '1',
                            confidence: parseInt(document.querySelector('input[name="source_b_winner_confidence"]')?.value) || 67
                        },
                        overUnder: {
                            pick: document.querySelector('select[name="source_b_ou_pick"]')?.value || 'Under',
                            confidence: parseInt(document.querySelector('input[name="source_b_ou_confidence"]')?.value) || 63
                        },
                        btts: {
                            pick: document.querySelector('select[name="source_b_btts_pick"]')?.value || 'No',
                            confidence: parseInt(document.querySelector('input[name="source_b_btts_confidence"]')?.value) || 64
                        },
                        doubleChance: {
                            pick: document.querySelector('select[name="source_b_dc_pick"]')?.value || '1X',
                            confidence: parseInt(document.querySelector('input[name="source_b_dc_confidence"]')?.value) || 75
                        },
                        exactScore: {
                            pick: document.querySelector('input[name="source_b_exact_score"]')?.value || '1:0',
                            confidence: parseInt(document.querySelector('input[name="source_b_score_confidence"]')?.value) || 18
                        }
                    },
                    sourceC: {
                        winner: {
                            home: parseInt(document.querySelector('input[name="source_c_winner_home"]')?.value) || 45,
                            draw: parseInt(document.querySelector('input[name="source_c_winner_draw"]')?.value) || 30,
                            away: parseInt(document.querySelector('input[name="source_c_winner_away"]')?.value) || 25
                        },
                        overUnder: {
                            pick: document.querySelector('select[name="source_c_ou_pick"]')?.value || 'Under',
                            confidence: parseInt(document.querySelector('input[name="source_c_ou_confidence"]')?.value) || 60
                        },
                        btts: {
                            pick: document.querySelector('select[name="source_c_btts_pick"]')?.value || 'No',
                            confidence: parseInt(document.querySelector('input[name="source_c_btts_confidence"]')?.value) || 58
                        },
                        doubleChance: {
                            pick: document.querySelector('select[name="source_c_dc_pick"]')?.value || '1X',
                            confidence: parseInt(document.querySelector('input[name="source_c_dc_confidence"]')?.value) || 75
                        },
                        exactScore: {
                            pick: document.querySelector('input[name="source_c_exact_score"]')?.value || '1:0',
                            confidence: parseInt(document.querySelector('input[name="source_c_score_confidence"]')?.value) || 15
                        }
                    }
                };
            },
            
            collectTacticalData() {
                const homeFormation = document.querySelector('select[name="home_formation"]')?.value;
                const awayFormation = document.querySelector('select[name="away_formation"]')?.value;
                
                if (!homeFormation && !awayFormation) return null;
                
                return {
                    home: {
                        formation: homeFormation || '4-3-3',
                        style: this.getFormationStyle(homeFormation)
                    },
                    away: {
                        formation: awayFormation || '4-3-3',
                        style: this.getFormationStyle(awayFormation)
                    }
                };
            },
            
            getFormationStyle(formation) {
                const styles = {
                    '4-3-3': 'Offensif', '4-2-3-1': 'Offensif', '4-4-2': 'Équilibré',
                    '4-1-4-1': 'Défensif', '4-5-1': 'Défensif', '3-5-2': 'Contrôle'
                };
                return styles[formation] || 'Équilibré';
            },
            
            collectContextData() {
                return {
                    importance: document.querySelector('select[name="match_importance"]')?.value || 'medium',
                    reason: document.querySelector('input[name="importance_reason"]')?.value || undefined,
                    restDays: {
                        home: parseInt(document.querySelector('input[name="home_rest_days"]')?.value) || 7,
                        away: parseInt(document.querySelector('input[name="away_rest_days"]')?.value) || 7
                    }
                };
            },
            
            collectSofascoreData() {
                const data = {};

                // 1. Forme récente (déjà fonctionnel)
                const homeForm = document.querySelector('input[name="home_form_streak"]')?.value?.trim();
                const awayForm = document.querySelector('input[name="away_form_streak"]')?.value?.trim();

                if (homeForm || awayForm) {
                    data.recentForm = {
                        home: this.parseStreak(homeForm || 'DDDDD'),
                        away: this.parseStreak(awayForm || 'DDDDD')
                    };
                }

                // 2. Blessures / Absences
                const injuries = {
                    home: this.collectAbsences('home_absences'),
                    away: this.collectAbsences('away_absences')
                };

                if (injuries.home.length > 0 || injuries.away.length > 0) {
                    data.injuries = injuries;
                }

                // 3. H2H Résumé
                const h2hTotal = parseInt(document.querySelector('input[name="h2h_total_games"]')?.value) || 0;
                if (h2hTotal > 0) {
                    data.h2h = {
                        totalGames: h2hTotal,
                        homeWins: parseInt(document.querySelector('input[name="h2h_home_wins"]')?.value) || 0,
                        draws: parseInt(document.querySelector('input[name="h2h_draws"]')?.value) || 0,
                        awayWins: parseInt(document.querySelector('input[name="h2h_away_wins"]')?.value) || 0,
                        lastMatches: this.collectH2HMatches()
                    };
                }

                // 4. Stats match et saison
                const homePossession = document.querySelector('input[name="home_possession"]')?.value;
                if (homePossession || document.querySelector('input[name="away_possession"]')?.value) {
                    data.matchStats = {
                        home: {
                            possession: parseFloat(homePossession) || null,
                            shots: parseFloat(document.querySelector('input[name="home_shots"]')?.value) || null,
                            shotsOnTarget: parseFloat(document.querySelector('input[name="home_shots_on_target"]')?.value) || null,
                        },
                        away: {
                            possession: parseFloat(document.querySelector('input[name="away_possession"]')?.value) || null,
                            shots: parseFloat(document.querySelector('input[name="away_shots"]')?.value) || null,
                            shotsOnTarget: parseFloat(document.querySelector('input[name="away_shots_on_target"]')?.value) || null,
                        }
                    };

                    data.seasonStats = {
                        home: {
                            goalsScored: parseInt(document.querySelector('input[name="home_goals_scored"]')?.value) || null,
                            goalsConceded: parseInt(document.querySelector('input[name="home_goals_conceded"]')?.value) || null,
                        },
                        away: {
                            goalsScored: parseInt(document.querySelector('input[name="away_goals_scored"]')?.value) || null,
                            goalsConceded: parseInt(document.querySelector('input[name="away_goals_conceded"]')?.value) || null,
                        }
                    };
                }

                // Si rien du tout → return null
                if (Object.keys(data).length === 0) return null;

                return data;
            },

            parseStreak(streak) {
                return {
                    streak: streak,
                    W: (streak.match(/W/g) || []).length,
                    D: (streak.match(/D/g) || []).length,
                    L: (streak.match(/L/g) || []).length
                };
            },

            collectAbsences(prefix) {
                const absences = [];

                for (let i = 0; i < 20; i++) {
                    const nameInput = document.querySelector(`input[name="${prefix}[${i}][name]"]`);
                    if (!nameInput || !nameInput.value.trim()) break;

                    absences.push({
                        name: nameInput.value.trim(),
                        position: document.querySelector(`select[name="${prefix}[${i}][position]"]`)?.value || 'Unknown',
                        importance: document.querySelector(`select[name="${prefix}[${i}][importance]"]`)?.value || 'rotation',
                        reason: document.querySelector(`select[name="${prefix}[${i}][reason]"]`)?.value || 'injury'
                    });
                }

                return absences;
            },

            collectH2HMatches() {
                const matches = [];
                for (let i = 0; i < 20; i++) {
                    const dateInput = document.querySelector(`input[name="h2h_matches[${i}][date]"]`);
                    if (!dateInput || !dateInput.value) break;

                    matches.push({
                        date: dateInput.value,
                        score: document.querySelector(`input[name="h2h_matches[${i}][score]"]`)?.value || '',
                        location: document.querySelector(`select[name="h2h_matches[${i}][location]"]`)?.value || 'neutral',
                        winner: document.querySelector(`select[name="h2h_matches[${i}][winner]"]`)?.value || 'draw'
                    });
                }
                return matches;
            },
            
            collectFootyStatsData() {
                const data = {};

                // Expected Goals
                const homeXGFor = document.querySelector('input[name="home_xg_for"]')?.value;
                const awayXGFor = document.querySelector('input[name="away_xg_for"]')?.value;

                if (homeXGFor || awayXGFor) {
                    data.expectedGoals = {
                        home: {
                            xGFor: parseFloat(homeXGFor) || 0,
                            xGAgainst: parseFloat(document.querySelector('input[name="home_xg_against"]')?.value) || 0
                        },
                        away: {
                            xGFor: parseFloat(awayXGFor) || 0,
                            xGAgainst: parseFloat(document.querySelector('input[name="away_xg_against"]')?.value) || 0
                        }
                    };
                }

                // Over/Under
                const hasOverUnder = document.querySelector('input[name="home_over_15"]')?.value ||
                                    document.querySelector('input[name="away_over_15"]')?.value;

                if (hasOverUnder) {
                    data.overUnder = {
                        home: {
                            over15: parseFloat(document.querySelector('input[name="home_over_15"]')?.value) || 0,
                            over25: parseFloat(document.querySelector('input[name="home_over_25"]')?.value) || 0,
                            over35: parseFloat(document.querySelector('input[name="home_over_35"]')?.value) || 0
                        },
                        away: {
                            over15: parseFloat(document.querySelector('input[name="away_over_15"]')?.value) || 0,
                            over25: parseFloat(document.querySelector('input[name="away_over_25"]')?.value) || 0,
                            over35: parseFloat(document.querySelector('input[name="away_over_35"]')?.value) || 0
                        }
                    };
                }

                // BTTS
                const hasBtts = document.querySelector('input[name="home_btts_yes"]')?.value ||
                                document.querySelector('input[name="away_btts_yes"]')?.value;

                if (hasBtts) {
                    data.btts = {
                        home: {
                            yes: parseFloat(document.querySelector('input[name="home_btts_yes"]')?.value) || 0,
                            no: parseFloat(document.querySelector('input[name="home_btts_no"]')?.value) || 0
                        },
                        away: {
                            yes: parseFloat(document.querySelector('input[name="away_btts_yes"]')?.value) || 0,
                            no: parseFloat(document.querySelector('input[name="away_btts_no"]')?.value) || 0
                        }
                    };
                }

                // Séries en cours
                const hasSeries = document.querySelector('input[name="home_win_streak"]')?.value ||
                                document.querySelector('input[name="away_win_streak"]')?.value;

                if (hasSeries) {
                    data.series = {
                        home: {
                            currentWinStreak: parseInt(document.querySelector('input[name="home_win_streak"]')?.value) || 0,
                            currentUnbeatenStreak: parseInt(document.querySelector('input[name="home_unbeaten_streak"]')?.value) || 0,
                            currentScoringStreak: parseInt(document.querySelector('input[name="home_scoring_streak"]')?.value) || 0,
                            currentCleanSheetStreak: parseInt(document.querySelector('input[name="home_clean_sheet_streak"]')?.value) || 0
                        },
                        away: {
                            currentWinStreak: parseInt(document.querySelector('input[name="away_win_streak"]')?.value) || 0,
                            currentUnbeatenStreak: parseInt(document.querySelector('input[name="away_unbeaten_streak"]')?.value) || 0,
                            currentScoringStreak: parseInt(document.querySelector('input[name="away_scoring_streak"]')?.value) || 0,
                            currentCleanSheetStreak: parseInt(document.querySelector('input[name="away_clean_sheet_streak"]')?.value) || 0
                        }
                    };
                }

                if (Object.keys(data).length === 0) return null;

                return data;
            },
            
            collectFbrefData() {
                const data = {};

                // Classement
                const homePos = document.querySelector('input[name="home_position"]')?.value;
                const awayPos = document.querySelector('input[name="away_position"]')?.value;

                if (homePos || awayPos) {
                    data.league = {
                        home: {
                            position: parseInt(homePos) || 0,
                            points: parseInt(document.querySelector('input[name="home_points"]')?.value) || 0
                        },
                        away: {
                            position: parseInt(awayPos) || 0,
                            points: parseInt(document.querySelector('input[name="away_points"]')?.value) || 0
                        }
                    };
                }

                // Top Buteurs
                const homeScorer = document.querySelector('input[name="home_top_scorer"]')?.value?.trim();
                const awayScorer = document.querySelector('input[name="away_top_scorer"]')?.value?.trim();

                if (homeScorer || awayScorer) {
                    data.topPlayers = {
                        home: {
                            topScorer: {
                                name: homeScorer || '',
                                goals: parseInt(document.querySelector('input[name="home_ts_goals"]')?.value) || 0,
                                available: document.querySelector('input[name="home_ts_available"]')?.checked ?? true
                            }
                        },
                        away: {
                            topScorer: {
                                name: awayScorer || '',
                                goals: parseInt(document.querySelector('input[name="away_ts_goals"]')?.value) || 0,
                                available: document.querySelector('input[name="away_ts_available"]')?.checked ?? true
                            }
                        }
                    };
                }

                if (Object.keys(data).length === 0) return null;

                return data;
            },
        }
    }
</script>
@endpush