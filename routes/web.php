<?php

use App\Http\Controllers\MarketController;
use App\Http\Controllers\MatchAnalysisController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TerminalController;
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
    
    // Page principale du terminal : sélections, Monte-Carlo, calibration, clôture
    Route::get('/dashboard', [TerminalController::class, 'index'])
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
    // MARCHÉ (écart de clôture Pinnacle)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/market', [MarketController::class, 'index'])->name('market.index');

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PARAMÈTRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PROFIL UTILISATEUR (Breeze)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


require __DIR__.'/auth.php';
