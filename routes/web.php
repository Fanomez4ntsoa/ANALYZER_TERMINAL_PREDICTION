<?php

use App\Http\Controllers\MatchAnalysisController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// ROUTES PUBLIQUES
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// Rediriger vers login ou dashboard
Route::get('/', function () {
    return Auth::check() 
        ? redirect()->route('dashboard') 
        : redirect()->route('login');
});

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// ROUTES PROTÉGÉES (AUTH REQUIRED)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Route::middleware(['auth', 'verified'])->group(function () {
    
    // Dashboard principal (vue d'ensemble)
    Route::get('/dashboard', [MatchAnalysisController::class, 'dashboard'])
        ->name('dashboard');
    
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ANALYSE (CRUD + Actions)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    Route::prefix('analysis')->name('analysis.')->group(function () {

        // Matchs du jour (nouveau flow pipeline)
        Route::get('/', [MatchAnalysisController::class, 'index'])
            ->name('index');

        // Analyser un match existant en DB (AJAX, retourne JSON)
        Route::post('/analyze-match/{match}', [MatchAnalysisController::class, 'analyzeExistingMatch'])
            ->name('analyze_match');

        // Prédictions du dernier match calculé (ou ?match_id=)
        Route::get('/results', [MatchAnalysisController::class, 'results'])
            ->name('results');
    });
    
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HISTORIQUE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    
    Route::prefix('history')->name('history.')->group(function () {
        
        // Liste tous les matchs sauvegardés
        Route::get('/', [MatchAnalysisController::class, 'history'])
            ->name('index');
        
        // Voir détail d'un match
        Route::get('/{id}', [MatchAnalysisController::class, 'show'])
            ->name('show');

        // Supprimer un match
        Route::delete('/{match}', [MatchAnalysisController::class, 'deleteMatch'])
            ->name('delete');
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MARCHÉ (CLV + Sharp money)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/market', function () {
        $clvTracker = app(\App\Services\Market\CLVTrackerService::class);
        $oddsApi = app(\App\Services\Api\OddsApiService::class);

        return view('pro.market', [
            'summary' => $clvTracker->getSummary(),
            'quota' => $oddsApi->getMonthlyUsage(),
            'recentMovements' => \App\Models\OddsMovement::with('match')
                ->orderBy('snapshot_at', 'desc')
                ->take(20)
                ->get(),
            'sharpAlerts' => \App\Models\OddsMovement::with('match')
                ->where('sharp_alert', true)
                ->orderBy('snapshot_at', 'desc')
                ->take(10)
                ->get(),
        ]);
    })->name('market.index');

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PARAMÈTRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/settings', function () {
        $oddsApi = app(\App\Services\Api\OddsApiService::class);
        $apiFootball = app(\App\Services\Api\ApiFootballService::class);

        try {
            $apiFootballStatus = $apiFootball->getAccountStatus();
        } catch (\App\Services\Api\ApiFootballException $e) {
            \Illuminate\Support\Facades\Log::warning('Paramètres: statut API-Football indisponible', ['error' => $e->getMessage()]);
            $apiFootballStatus = null;
        }

        return view('pro.settings', [
            'oddsQuota' => $oddsApi->getMonthlyUsage(),
            'apiFootballStatus' => $apiFootballStatus,
            'config' => [
                'api_football_key' => !empty(config('api-football.key')),
                'odds_api_key' => !empty(config('odds-api.key')),
                'openweathermap_key' => !empty(config('services.openweathermap.key')),
                'match_start_hour' => config('pipeline.match_start_hour'),
                'match_end_hour' => config('pipeline.match_end_hour'),
                'schedule_time' => config('pipeline.schedule_time') . ' ' . config('pipeline.schedule_timezone'),
                'bookmaker_odds_api' => config('odds-api.clv_bookmaker'),
                'bookmaker_api_football' => config('api-football.preferred_bookmaker'),
            ],
        ]);
    })->name('settings.index');

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PROFIL UTILISATEUR (Breeze)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


require __DIR__.'/auth.php';
