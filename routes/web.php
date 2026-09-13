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

        // Ancien formulaire de saisie manuelle (Sources A/B/C)
        Route::get('/manual', [MatchAnalysisController::class, 'manualInput'])
            ->name('manual');

        // Ancien POST analyse manuelle (inchangé)
        Route::post('/', [MatchAnalysisController::class, 'analyze'])
            ->name('analyze');

        // Résultats
        Route::get('/results', [MatchAnalysisController::class, 'results'])
            ->name('results');

        // Sauvegarder
        Route::post('/save', [MatchAnalysisController::class, 'save'])
            ->name('save');

        // Nouveau match (reset)
        Route::get('/new', [MatchAnalysisController::class, 'newMatch'])
            ->name('new');
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

        // Route::get('/{match}', [MatchAnalysisController::class, 'showMatch'])
        //     ->name('show');
        
        // Supprimer un match
        Route::delete('/{match}', [MatchAnalysisController::class, 'deleteMatch'])
            ->name('delete');
    });

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // BACKTEST
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/backtest', [\App\Http\Controllers\BacktestController::class, 'index'])->name('backtest.index');
    Route::post('/backtest/run', [\App\Http\Controllers\BacktestController::class, 'run'])->name('backtest.run');
    Route::get('/backtest/{run}', [\App\Http\Controllers\BacktestController::class, 'show'])->name('backtest.show');

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // COMBOS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    Route::get('/combos', function () {
        $date = request('date', now()->format('Y-m-d'));
        $combos = \App\Models\DailyCombo::where('date', $date)->orderBy('rank')->get();

        // Dates voisines
        $prevDate = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
        $nextDate = \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d');

        return view('pro.combos', compact('combos', 'date', 'prevDate', 'nextDate'));
    })->name('combos.index');

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

        return view('pro.settings', [
            'oddsQuota' => $oddsApi->getMonthlyUsage(),
            'apiFootballStatus' => $apiFootball->getAccountStatus(),
            'config' => [
                'api_football_key' => !empty(config('api-football.key')),
                'odds_api_key' => !empty(config('odds-api.key')),
                'openweathermap_key' => !empty(env('OPENWEATHERMAP_KEY')),
                'anthropic_key' => !empty(env('ANTHROPIC_API_KEY')),
                'pipeline_time' => env('PIPELINE_SCHEDULE_TIME', '14:00'),
                'pipeline_update' => env('PIPELINE_SCHEDULE_UPDATE', '17:00'),
                'combo_min_confidence' => env('COMBO_MIN_CONFIDENCE', 65),
                'combo_min_odds' => env('COMBO_MIN_ODDS', 1.90),
                'combo_max_odds' => env('COMBO_MAX_ODDS', 2.10),
                'combo_min_matches' => env('COMBO_MIN_MATCHES', 3),
                'combo_max_matches' => env('COMBO_MAX_MATCHES', 4),
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
