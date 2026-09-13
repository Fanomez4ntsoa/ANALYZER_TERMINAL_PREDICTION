<?php


use App\Http\Controllers\MatchAnalysisController;
use Illuminate\Support\Facades\Route;


// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// API (pour appels AJAX dans le dashboard)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Route::middleware(['auth'])->prefix('api')->name('api.')->group(function () {
    
    Route::post('/analyze', [MatchAnalysisController::class, 'apiAnalyze'])
        ->name('analyze');
});