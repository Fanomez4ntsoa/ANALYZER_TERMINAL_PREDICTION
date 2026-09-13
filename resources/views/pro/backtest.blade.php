@extends('layouts.pro')

@section('title', 'Backtest')
@section('page-title', 'Backtesting')
@section('page-subtitle', isset($run) && $run ? "Run #" . $run->id . " — " . $run->total_matches . " matchs" : "Tester le modele sur des donnees historiques")

@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Configuration --}}
    <div class="lg:col-span-1 space-y-4">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
            <div class="px-5 py-3 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">Configuration</h3>
            </div>
            <form action="{{ route('backtest.run') }}" method="POST" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Ligues</label>
                    <select name="leagues[]" multiple class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500" size="5">
                        <option value="61" selected>Ligue 1</option>
                        <option value="39">Premier League</option>
                        <option value="140">La Liga</option>
                        <option value="135">Serie A</option>
                        <option value="78">Bundesliga</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Date debut</label>
                        <input type="date" name="date_from" value="{{ now()->subMonths(1)->format('Y-m-d') }}" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Date fin</label>
                        <input type="date" name="date_to" value="{{ now()->subDay()->format('Y-m-d') }}" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Marches</label>
                    <div class="space-y-1.5">
                        @foreach(['winner' => '1X2', 'overUnder' => 'Over/Under 2.5', 'btts' => 'BTTS', 'doubleChance' => 'Double Chance'] as $key => $label)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="markets[]" value="{{ $key }}" checked class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Confiance minimum</label>
                    <input type="number" name="min_confidence" value="0" min="0" max="100" step="5" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Strategie de mise</label>
                    <select name="staking_strategy" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500">
                        <option value="flat">Mise plate</option>
                        <option value="kelly">Kelly Criterion (1/4)</option>
                        <option value="proportional">Proportionnelle</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Bankroll</label>
                        <input type="number" name="bankroll" value="1000" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Mise unitaire</label>
                        <input type="number" name="unit_stake" value="10" class="w-full border border-slate-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    Lancer le backtest
                </button>
                <p class="text-xs text-slate-400 text-center">Consomme des requetes API-Football (1/semaine/ligue)</p>
            </form>
        </div>

        {{-- Historique des runs --}}
        @if($runs->count() > 0)
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
            <div class="px-5 py-3 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">Runs precedents</h3>
            </div>
            <div class="divide-y divide-slate-50">
                @foreach($runs as $r)
                    <a href="{{ route('backtest.show', $r->id) }}"
                       class="flex items-center justify-between px-5 py-2.5 hover:bg-slate-50 transition-colors text-sm {{ isset($run) && $run && $r->id === $run->id ? 'bg-blue-50' : '' }}">
                        <div>
                            <span class="font-medium text-slate-700">#{{ $r->id }}</span>
                            <span class="text-slate-400 text-xs ml-1">{{ $r->created_at->format('d/m H:i') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-slate-500">{{ $r->total_predictions }} preds</span>
                            <span class="text-xs font-semibold {{ $r->win_rate >= 60 ? 'text-emerald-600' : ($r->win_rate >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $r->win_rate }}%</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Resultats --}}
    <div class="lg:col-span-2 space-y-6">

        @if(!$run)
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-10 text-center">
                <p class="text-sm text-slate-500">Lancez un backtest pour voir les resultats</p>
            </div>
        @else
            {{-- Metriques --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @php
                    $metrics = [
                        ['label' => 'Win Rate', 'value' => $run->win_rate . '%', 'color' => $run->win_rate >= 55 ? 'text-emerald-600' : 'text-red-600', 'sub' => $run->wins . '/' . $run->total_predictions],
                        ['label' => 'ROI', 'value' => ($run->roi >= 0 ? '+' : '') . $run->roi . '%', 'color' => $run->roi >= 0 ? 'text-emerald-600' : 'text-red-600', 'sub' => 'return on investment'],
                        ['label' => 'Yield', 'value' => ($run->yield_pct >= 0 ? '+' : '') . $run->yield_pct . '%', 'color' => $run->yield_pct >= 0 ? 'text-emerald-600' : 'text-red-600', 'sub' => 'profit / mise totale'],
                        ['label' => 'Max Drawdown', 'value' => number_format($run->max_drawdown, 0), 'color' => 'text-slate-800', 'sub' => 'perte max consecutive'],
                    ];
                @endphp
                @foreach($metrics as $m)
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                        <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ $m['label'] }}</p>
                        <p class="mt-1 text-xl font-bold {{ $m['color'] }}">{{ $m['value'] }}</p>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $m['sub'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Metriques secondaires --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <p class="text-xs font-medium text-slate-500">Brier Score</p>
                    <p class="mt-1 text-lg font-bold text-slate-800">{{ $run->brier_score }}</p>
                    <p class="text-xs text-slate-400">{{ $run->brier_score < 0.25 ? 'Bon' : ($run->brier_score < 0.30 ? 'Moyen' : 'Faible') }}</p>
                </div>
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <p class="text-xs font-medium text-slate-500">Bankroll finale</p>
                    <p class="mt-1 text-lg font-bold {{ $run->final_bankroll >= $run->bankroll ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($run->final_bankroll, 0) }}</p>
                    <p class="text-xs text-slate-400">/ {{ number_format($run->bankroll, 0) }} initial</p>
                </div>
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
                    <p class="text-xs font-medium text-slate-500">Matchs</p>
                    <p class="mt-1 text-lg font-bold text-slate-800">{{ $run->total_matches }}</p>
                    <p class="text-xs text-slate-400">{{ $run->date_from->format('d/m') }} - {{ $run->date_to->format('d/m') }}</p>
                </div>
            </div>

            {{-- Courbe bankroll --}}
            @if($run->bankroll_curve && count($run->bankroll_curve) > 0)
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-800">Evolution du bankroll</h3>
                </div>
                <div class="p-5" style="height: 280px;"><canvas id="bankrollChart"></canvas></div>
            </div>
            @endif

            {{-- Segmentation --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                @foreach([
                    ['title' => 'Par marche', 'data' => $run->by_market],
                    ['title' => 'Par ligue', 'data' => $run->by_league],
                    ['title' => 'Par confiance', 'data' => $run->by_confidence],
                    ['title' => 'Par cote', 'data' => $run->by_odds_range],
                ] as $seg)
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                        <div class="px-5 py-3 border-b border-slate-100">
                            <h3 class="text-sm font-semibold text-slate-800">{{ $seg['title'] }}</h3>
                        </div>
                        @if(!empty($seg['data']))
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead><tr class="border-b border-slate-100">
                                        <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Segment</th>
                                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">Total</th>
                                        <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500">Win %</th>
                                        <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Profit</th>
                                    </tr></thead>
                                    <tbody class="divide-y divide-slate-50">
                                        @foreach($seg['data'] as $row)
                                            <tr class="hover:bg-slate-50">
                                                <td class="px-4 py-2 text-slate-700 font-medium">{{ $row['label'] }}</td>
                                                <td class="text-center px-2 py-2 text-slate-500">{{ $row['total'] }}</td>
                                                <td class="text-center px-2 py-2 font-semibold {{ $row['win_rate'] >= 55 ? 'text-emerald-600' : ($row['win_rate'] >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $row['win_rate'] }}%</td>
                                                <td class="text-right px-4 py-2 font-mono text-sm {{ $row['profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $row['profit'] >= 0 ? '+' : '' }}{{ number_format($row['profit'], 0) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="p-5 text-center text-xs text-slate-400">Pas de donnees</div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Calibration --}}
            @if($run->calibration && count($run->calibration) > 0)
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-800">Calibration</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Confiance predite vs frequence reelle</p>
                </div>
                <div class="p-5" style="height: 250px;"><canvas id="calibrationChart"></canvas></div>
            </div>
            @endif
        @endif
    </div>
</div>

@endsection

@if(isset($run) && $run)
@push('scripts')
<script>
@if($run->bankroll_curve && count($run->bankroll_curve) > 0)
new Chart(document.getElementById('bankrollChart'), {
    type: 'line',
    data: {
        labels: @json(collect($run->bankroll_curve)->pluck('date')),
        datasets: [{
            data: @json(collect($run->bankroll_curve)->pluck('bankroll')),
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.05)',
            fill: true, borderWidth: 2, pointRadius: 0, tension: 0.3,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94a3b8', maxTicksLimit: 8 } },
            y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#94a3b8' } },
        }
    }
});
@endif

@if($run->calibration && count($run->calibration) > 0)
new Chart(document.getElementById('calibrationChart'), {
    type: 'scatter',
    data: {
        datasets: [
            { label: 'Parfaite', data: [{x:30,y:30},{x:50,y:50},{x:70,y:70},{x:90,y:90}], borderColor: '#e2e8f0', borderWidth: 1, pointRadius: 0, showLine: true, type: 'line' },
            { label: 'Modele', data: @json(collect($run->calibration)->map(fn($c) => ['x' => $c['predicted'], 'y' => $c['actual']])),
              borderColor: '#3b82f6', backgroundColor: '#3b82f6', pointRadius: 5, showLine: true, borderWidth: 2 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, color: '#94a3b8' } } },
        scales: {
            x: { title: { display: true, text: 'Confiance predite %', color: '#94a3b8' }, min: 20, max: 100, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8' } },
            y: { title: { display: true, text: 'Frequence reelle %', color: '#94a3b8' }, min: 20, max: 100, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8' } },
        }
    }
});
@endif
</script>
@endpush
@endif
