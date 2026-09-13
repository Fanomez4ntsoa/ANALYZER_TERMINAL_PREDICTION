<?php

namespace App\Http\Controllers;

use App\Models\BacktestRun;
use App\Services\Backtesting\BacktestEngine;
use Illuminate\Http\Request;

class BacktestController extends Controller
{
    public function index()
    {
        $runs = BacktestRun::orderByDesc('created_at')->take(10)->get();
        $latestRun = $runs->first();

        return view('pro.backtest', [
            'runs' => $runs,
            'run' => $latestRun,
        ]);
    }

    public function run(Request $request, BacktestEngine $engine)
    {
        $config = [
            'leagues' => $request->input('leagues', [61]),
            'season' => (int) $request->input('season', date('Y')),
            'date_from' => $request->input('date_from', now()->subMonths(3)->format('Y-m-d')),
            'date_to' => $request->input('date_to', now()->subDay()->format('Y-m-d')),
            'markets' => $request->input('markets', ['winner', 'overUnder', 'btts', 'doubleChance']),
            'min_confidence' => (int) $request->input('min_confidence', 0),
            'staking_strategy' => $request->input('staking_strategy', 'flat'),
            'bankroll' => (float) $request->input('bankroll', 1000),
            'unit_stake' => (float) $request->input('unit_stake', 10),
        ];

        try {
            $run = $engine->run($config);
            return redirect()->route('backtest.show', $run->id)->with('success', "Backtest termine : {$run->total_predictions} predictions sur {$run->total_matches} matchs.");
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur backtest : ' . $e->getMessage());
        }
    }

    public function show(BacktestRun $run)
    {
        $run->load('predictions');
        $runs = BacktestRun::orderByDesc('created_at')->take(10)->get();

        return view('pro.backtest', [
            'runs' => $runs,
            'run' => $run,
        ]);
    }
}
