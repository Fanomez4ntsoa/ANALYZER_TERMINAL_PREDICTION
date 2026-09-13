@extends('layouts.pro')

@section('content')
<div class="container mx-auto px-4 py-8">
    
    <!-- Test Alpine simple -->
    <div class="bg-white p-4 mb-4 rounded" x-data="{ test: 'Alpine fonctionne !' }">
        <p x-text="test" class="text-green-600 font-bold"></p>
    </div>

    <!-- Wizard minimal -->
    <div x-data="simpleWizard()">
        
        <!-- Debug -->
        <div class="bg-yellow-100 p-4 mb-4 rounded">
            <p class="font-bold">DEBUG:</p>
            <p>Step actuel: <span x-text="currentStep" class="font-mono"></span></p>
        </div>

        <!-- Header -->
        <div class="bg-gray-900 rounded-lg p-6 mb-6">
            <h1 class="text-2xl font-bold text-white">Analyse de Match</h1>
        </div>

        <!-- Form -->
        <div class="bg-gray-900 rounded-lg p-6">
            
            <!-- Step 1 -->
            <div x-show="currentStep === 1">
                <h2 class="text-2xl font-bold text-white mb-6">Informations Essentielles</h2>
            
                @include('components.match-form.basic-info')
            </div>

            <!-- Step 2 -->
            <div x-show="currentStep === 2">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">📊 Sources de Prédiction</h2>
            
                @include('components.match-form.sources-abc')
            </div>

            <!-- Step 3 -->
            <div x-show="currentStep === 3">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">🎯 Tactique & Contexte</h2>
            
                <div class="mb-8">
                    <h3 class="text-xl font-semibold text-gray-200 mb-4">⚙️ Configuration Tactique</h3>
                    @include('components.match-form.tactical')
                </div>
                
                <div class="border-t pt-6">
                    <h3 class="text-xl font-semibold text-gray-200 mb-4">Contexte du Match</h3>
                    @include('components.match-form.context')
                </div>
            </div>

            <div x-show="currentStep === 4">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">Données Avancées</h2>
                
                <!-- Sofascore Section -->
                <div class="mb-8 bg-blue-50 rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-blue-800 mb-4">📱 Sofascore</h3>
                    
                    <div class="space-y-6">
                        <div>
                            <h4 class="font-medium text-gray-200 mb-3">Forme Récente</h4>
                            @include('components.match-form.sofascore-form')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">🚑 Blessures & Absences</h4>
                            @include('components.match-form.sofascore-injuries')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">🔄 Historique H2H</h4>
                            @include('components.match-form.sofascore-h2h')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">📊 Statistiques</h4>
                            @include('components.match-form.sofascore-stats')
                        </div>
                    </div>
                </div>

                <!-- FootyStats Section -->
                <div class="mb-8 bg-green-50 rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-green-800 mb-4">⚽ FootyStats</h3>
                    
                    <div class="space-y-6">
                        <div>
                            <h4 class="font-medium text-gray-200 mb-3">🎯 Over/Under</h4>
                            @include('components.match-form.footystats-ou')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">🤝 BTTS (Both Teams To Score)</h4>
                            @include('components.match-form.footystats-btts')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">📉 Expected Goals (xG)</h4>
                            @include('components.match-form.footystats-xg')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">📅 Séries en Cours</h4>
                            @include('components.match-form.footystats-series')
                        </div>
                    </div>
                </div>

                <!-- FBRef Section -->
                <div class="bg-purple-50 rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-purple-800 mb-4">📊 FBRef</h3>
                    
                    <div class="space-y-6">
                        <div>
                            <h4 class="font-medium text-gray-200 mb-3">🏆 Classement Championnat</h4>
                            @include('components.match-form.fbref-league')
                        </div>
                        
                        <div class="border-t pt-6">
                            <h4 class="font-medium text-gray-200 mb-3">⭐ Top Joueurs</h4>
                            @include('components.match-form.fbref-players')
                        </div>
                    </div>
                </div>
            </div>

            <!-- Boutons de navigation -->
            <div class="flex gap-4 mt-8 pt-4 border-t border-gray-700">
                
                <button 
                    type="button"
                    @click="previousStep"
                    x-show="currentStep > 1"
                    class="px-6 py-3 bg-gray-700 text-white rounded hover:bg-gray-600"
                >
                    ← Retour
                </button>

                <div class="flex-1"></div>

                <button 
                    type="button"
                    @click="nextStep"
                    x-show="currentStep < 5"
                    class="px-6 py-3 bg-blue-600 text-white rounded hover:bg-blue-700"
                >
                    Suivant →
                </button>

                <button 
                    type="button"
                    x-show="currentStep === 5"
                    class="px-6 py-3 bg-green-600 text-white rounded hover:bg-green-700"
                >
                    Analyser ce Match
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function simpleWizard() {
    return {
        currentStep: 1,
        
        formData: {
            homeTeam: '',
            awayTeam: '',
            matchDate: '',
            competition: '',
            odds1: '',
            oddsX: '',
            odds2: ''
        },
        
        nextStep() {
            console.log('Next clicked, current:', this.currentStep);
            if (this.currentStep < 5) {
                this.currentStep++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },
        
        previousStep() {
            console.log('Previous clicked, current:', this.currentStep);
            if (this.currentStep > 1) {
                this.currentStep--;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },
        
        validateCurrentStep() {
            if (this.currentStep === 1) {
                const homeTeam = document.querySelector('input[name="home_team"]')?.value;
                const awayTeam = document.querySelector('input[name="away_team"]')?.value;
                
                if (!homeTeam || !awayTeam) {
                    alert('⚠️ Veuillez remplir les équipes');
                    return false;
                }
                
                this.formData.homeTeam = homeTeam;
                this.formData.awayTeam = awayTeam;
            }
            return true;
        }
    }
}
</script>
@endsection