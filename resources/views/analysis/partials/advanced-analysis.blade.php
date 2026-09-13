{{-- Analyse avancée V2 --}}
<div class="bg-white rounded-xl shadow-sm p-6">
    <h3 class="text-2xl font-bold text-slate-700 mb-6 flex items-center gap-2">
        <svg class="w-6 h-6 text-purple-600" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>
        </svg>
        ANALYSE AVANCÉE V2
    </h3>
    
    @php
        // Les données sont dans $match sous forme de string JSON → on les décode
        $tacticalData = null;
        if (isset($match['tacticalData']) && is_string($match['tacticalData'])) {
            $tacticalData = json_decode($match['tacticalData'], true);
        }

        $sofascoreData = null;
        if (isset($match['sofascoreData']) && is_string($match['sofascoreData'])) {
            $sofascoreData = json_decode($match['sofascoreData'], true);
        }

        $footyStatsData = null;
        if (isset($match['footyStatsData']) && is_string($match['footyStatsData'])) {
            $footyStatsData = json_decode($match['footyStatsData'], true);
        }

        $fbrefData = null;
        if (isset($match['fbrefData']) && is_string($match['fbrefData'])) {
            $fbrefData = json_decode($match['fbrefData'], true);
        }

        $contextData = null;
        if (isset($match['contextData']) && is_string($match['contextData'])) {
            $contextData = json_decode($match['contextData'], true);
        }

        // Noms d'équipes
        $homeTeam = $match['teams']['home'] ?? 'Domicile';
        $awayTeam = $match['teams']['away'] ?? 'Extérieur';
    @endphp

    {{-- ==================== TACTIQUE ==================== --}}
    @if($tacticalData)
        <div class="mb-6 border-2 border-purple-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-purple-900">Analyse Tactique</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-50 p-4 rounded-lg border border-purple-700">
                    <div class="font-bold text-slate-700 mb-2">{{ $homeTeam }}</div>
                    <div class="text-2xl font-bold text-purple-400">
                        {{ $tacticalData['home']['formation'] ?? 'N/A' }}
                    </div>
                    <div class="text-sm text-slate-500 mt-1">
                        Style: {{ $tacticalData['home']['style'] ?? 'N/A' }}
                    </div>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-purple-700">
                        <div class="font-bold text-slate-700 mb-2">{{ $awayTeam }}</div>
                        <div class="text-2xl font-bold text-purple-400">
                            {{ $tacticalData['away']['formation'] ?? 'N/A' }}
                        </div>
                        <div class="text-sm text-slate-500 mt-1">
                            Style: {{ $tacticalData['away']['style'] ?? 'N/A' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
    
    {{-- ==================== CONTEXTE ==================== --}}
    @if($contextData)
        <div class="mb-6 border-2 border-orange-200 rounded-xl overflow-hidden">
        <div class="bg-white p-3">
            <h4 class="font-bold text-lg text-orange-900">Contexte du Match</h4>
        </div>
        <div class="p-4 bg-white">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @php
                    $importanceColors = [
                        'critical' => 'text-red-600',
                        'high'     => 'text-orange-600',
                        'medium'   => 'text-yellow-600',
                        'low'      => 'text-green-600',
                    ];
                    $importanceColor = $importanceColors[$contextData['importance'] ?? 'medium'] ?? 'text-gray-600';

                    $restHome = $contextData['restDays']['home'] ?? 0;
                    $restAway = $contextData['restDays']['away'] ?? 0;

                    $restHomeColor = $restHome <= 2 ? 'text-red-600' : ($restHome <= 4 ? 'text-orange-600' : 'text-green-600');
                    $restAwayColor = $restAway <= 2 ? 'text-red-600' : ($restAway <= 4 ? 'text-orange-600' : 'text-green-600');
                @endphp

                <!-- Enjeu -->
                <div class="bg-white p-4 rounded-lg border border-orange-200 text-center">
                    <div class="text-sm text-slate-600">Enjeu du match</div>
                    <div class="text-2xl font-bold {{ $importanceColor }}">
                        {{ strtoupper($contextData['importance'] ?? 'Moyen') }}
                    </div>
                    @if(!empty($contextData['reason']))
                        <div class="text-xs text-slate-400 mt-2 italic">
                            {{ $contextData['reason'] }}
                        </div>
                    @endif
                </div>

                <!-- Repos domicile -->
                <div class="bg-white p-4 rounded-lg border border-orange-200 text-center">
                    <div class="text-sm text-slate-600">Repos {{ $homeTeam }}</div>
                    <div class="text-2xl font-bold {{ $restHomeColor }}">
                        {{ $restHome }} jour{{ $restHome > 1 ? 's' : '' }}
                    </div>
                </div>

                <!-- Repos extérieur -->
                <div class="bg-white p-4 rounded-lg border border-orange-200 text-center">
                    <div class="text-sm text-slate-600">Repos {{ $awayTeam }}</div>
                    <div class="text-2xl font-bold {{ $restAwayColor }}">
                        {{ $restAway }} jour{{ $restAway > 1 ? 's' : '' }}
                    </div>
                </div>
            </div>
        </div>
        </div>
    @endif
    
    {{-- ==================== FORME RÉCENTE ==================== --}}
    @if($sofascoreData && isset($sofascoreData['recentForm']))
        <div class="mb-6 border-2 border-green-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-green-900">Forme Récente (5 derniers matchs)</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-2 gap-4">
                    @foreach(['home', 'away'] as $side)
                        @php
                            $streak = $sofascoreData['recentForm'][$side]['streak'] ?? '';
                            $wins = $sofascoreData['recentForm'][$side]['W'] ?? 0;
                            $draws = $sofascoreData['recentForm'][$side]['D'] ?? 0;
                            $losses = $sofascoreData['recentForm'][$side]['L'] ?? 0;
                            $teamName = $side === 'home' ? $homeTeam : $awayTeam;
                        @endphp

                        <div class="bg-white p-4 rounded-lg border border-green-200 text-center">
                            <div class="font-bold text-slate-700 mb-3">{{ $teamName }}</div>
                            
                            <div class="text-4xl font-bold tracking-widest mb-4">
                                @foreach(str_split($streak) as $char)
                                    <span class="{{ 
                                        $char === 'W' ? 'text-green-500' : 
                                        ($char === 'D' ? 'text-yellow-500' : 'text-red-500') 
                                    }} mx-1">
                                        {{ strtoupper($char) }}
                                    </span>
                                @endforeach
                            </div>
                            
                            <div class="text-sm text-slate-600 space-x-4">
                                <span class="text-green-400 font-medium">{{ $wins }} Victoire{{ $wins > 1 ? 's' : '' }}</span>
                                <span class="text-yellow-400 font-medium">{{ $draws }} Nul{{ $draws > 1 ? 's' : '' }}</span>
                                <span class="text-red-400 font-medium">{{ $losses }} Défaite{{ $losses > 1 ? 's' : '' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== BLESSURES & ABSENCES ==================== --}}
    @if($sofascoreData && isset($sofascoreData['injuries']) && (isset($sofascoreData['injuries']['home']) || isset($sofascoreData['injuries']['away'])))
        <div class="mb-6 border-2 border-red-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-red-900">Blessures & Suspensions</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach(['home', 'away'] as $side)
                        @php
                            $injuries = $sofascoreData['injuries'][$side] ?? [];
                            $teamName = $side === 'home' ? $homeTeam : $awayTeam;
                        @endphp

                        <div>
                            <div class="font-bold text-slate-700 mb-3 text-lg">{{ $teamName }}</div>
                            
                            @if(empty($injuries))
                                <div class="bg-slate-50 text-green-500 p-4 rounded-lg text-sm flex items-center gap-3 border border-green-700">
                                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                    <span class="font-medium">Effectif au complet</span>
                                </div>
                            @else
                                <div class="space-y-3">
                                    @foreach($injuries as $absence)
                                        @php
                                            $importance = $absence['importance'] ?? 'rotation';
                                            $bgColor = match($importance) {
                                                'key' => 'bg-red-900 border-red-600',
                                                'regular' => 'bg-orange-900 border-orange-600',
                                                default => 'bg-slate-50 border-slate-300'
                                            };
                                            $textColor = match($importance) {
                                                'key' => 'text-red-300',
                                                'regular' => 'text-orange-300',
                                                default => 'text-slate-600'
                                            };
                                            $label = match($importance) {
                                                'key' => '⭐ Joueur clé',
                                                'regular' => 'Titulaire régulier',
                                                default => 'Rotation'
                                            };
                                        @endphp
                                        <div class="p-3 rounded-lg flex justify-between items-center border {{ $bgColor }}">
                                            <div>
                                                <div class="font-medium text-slate-800">{{ $absence['name'] ?? 'Joueur inconnu' }}</div>
                                                <div class="text-xs {{ $textColor }} mt-1">
                                                    {{ $absence['position'] ?? '' }} • {{ $label }}
                                                </div>
                                            </div>
                                            @if(isset($absence['reason']))
                                                <div class="text-xs text-slate-500 italic">
                                                    {{ $absence['reason'] }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    
    {{-- ==================== H2H ==================== --}}
    @if($sofascoreData && isset($sofascoreData['h2h']) && ($sofascoreData['h2h']['totalGames'] ?? 0) > 0)
        <div class="mb-6 border-2 border-blue-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-blue-900">
                    Historique H2H ({{ $sofascoreData['h2h']['totalGames'] }} matchs)
                </h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-4 gap-4 text-center mb-6">
                    <div class="bg-slate-50 p-4 rounded-lg border border-blue-300">
                        <div class="text-3xl font-bold text-blue-400">{{ $sofascoreData['h2h']['totalGames'] }}</div>
                        <div class="text-xs text-slate-600">Total</div>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-green-300">
                        <div class="text-3xl font-bold text-green-500">{{ $sofascoreData['h2h']['homeWins'] ?? 0 }}</div>
                        <div class="text-xs text-slate-600">Victoires {{ $homeTeam }}</div>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-gray-300">
                        <div class="text-3xl font-bold text-slate-500">{{ $sofascoreData['h2h']['draws'] ?? 0 }}</div>
                        <div class="text-xs text-slate-600">Nuls</div>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-lg border border-blue-300">
                        <div class="text-3xl font-bold text-blue-500">{{ $sofascoreData['h2h']['awayWins'] ?? 0 }}</div>
                        <div class="text-xs text-slate-600">Victoires {{ $awayTeam }}</div>
                    </div>
                </div>

                @if(!empty($sofascoreData['h2h']['lastMatches']))
                    <div class="space-y-2">
                        <div class="text-sm font-medium text-slate-600 mb-2">Dernières confrontations :</div>
                        @foreach($sofascoreData['h2h']['lastMatches'] as $h2h)
                            @php
                                $winnerColor = match($h2h['winner'] ?? 'draw') {
                                    'home' => 'bg-green-900 text-green-300 border-green-600',
                                    'away' => 'bg-blue-900 text-blue-300 border-blue-600',
                                    default => 'bg-slate-50 text-slate-600 border-slate-300'
                                };
                                $winnerLabel = match($h2h['winner'] ?? 'draw') {
                                    'home' => $homeTeam,
                                    'away' => $awayTeam,
                                    default => 'Nul'
                                };
                            @endphp
                            <div class="bg-slate-50 p-3 rounded-lg flex justify-between items-center text-sm border {{ $winnerColor }}">
                                <span class="text-slate-500">{{ $h2h['date'] ?? 'Date inconnue' }}</span>
                                <span class="font-bold text-slate-800 text-lg">{{ $h2h['score'] ?? '-' }}</span>
                                <span class="px-3 py-1 rounded text-xs font-bold">
                                    {{ $winnerLabel }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
    
    {{-- ==================== EXPECTED GOALS (xG) ==================== --}}
    @if($footyStatsData && isset($footyStatsData['expectedGoals']))
        <div class="mb-6 border-2 border-orange-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-orange-900">Expected Goals (xG)</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-2 gap-6">
                    @foreach(['home', 'away'] as $side)
                        @php
                            $xg = $footyStatsData['expectedGoals'][$side];
                            $teamName = $side === 'home' ? $homeTeam : $awayTeam;
                        @endphp
                        <div class="bg-slate-50 p-6 rounded-lg border border-orange-300 text-center">
                            <div class="font-bold text-slate-700 mb-4 text-xl">{{ $teamName }}</div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-4xl font-bold text-green-500">
                                        {{ number_format($xg['xGFor'] ?? 0, 2) }}
                                    </div>
                                    <div class="text-sm text-slate-500 mt-1">xG Créés</div>
                                </div>
                                <div>
                                    <div class="text-4xl font-bold text-red-500">
                                        {{ number_format($xg['xGAgainst'] ?? 0, 2) }}
                                    </div>
                                    <div class="text-sm text-slate-500 mt-1">xG Concédés</div>
                                </div>
                            </div>
                            <div class="mt-4 text-sm text-slate-400">
                                Différentiel xG : 
                                <span class="{{ ($xg['xGFor'] - $xg['xGAgainst']) >= 0 ? 'text-green-400' : 'text-red-400' }} font-bold">
                                    {{ number_format(($xg['xGFor'] ?? 0) - ($xg['xGAgainst'] ?? 0), 2) }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    
    {{-- ==================== OVER/UNDER & BTTS ==================== --}}
    @if($footyStatsData && (isset($footyStatsData['overUnder']) || isset($footyStatsData['btts'])))
        <div class="mb-6 border-2 border-yellow-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-yellow-900">Tendances Over/Under & BTTS</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @if(isset($footyStatsData['overUnder']))
                        <div class="bg-slate-50 p-5 rounded-lg border border-yellow-300">
                            <div class="font-bold text-slate-700 mb-4">Over/Under (%)</div>
                            <div class="space-y-3 text-sm">
                                @foreach([
                                    'over15' => 'Over 1.5',
                                    'over25' => 'Over 2.5',
                                    'over35' => 'Over 3.5'
                                ] as $key => $label)
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">{{ $label }}</span>
                                        <div class="text-right">
                                            <span class="text-slate-800 font-medium">{{ $homeTeam }} : {{ $footyStatsData['overUnder']['home'][$key] ?? 0 }}%</span>
                                            <span class="mx-3 text-slate-400">|</span>
                                            <span class="text-slate-800 font-medium">{{ $awayTeam }} : {{ $footyStatsData['overUnder']['away'][$key] ?? 0 }}%</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(isset($footyStatsData['btts']))
                        <div class="bg-slate-50 p-5 rounded-lg border border-yellow-300">
                            <div class="font-bold text-slate-700 mb-4">BTTS - Les 2 équipes marquent</div>
                            <div class="space-y-4 text-lg">
                                <div class="flex justify-between">
                                    <span class="text-slate-600">{{ $homeTeam }}</span>
                                    <span class="font-bold text-green-400">{{ $footyStatsData['btts']['home']['yes'] ?? 0 }}% Yes</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">{{ $awayTeam }}</span>
                                    <span class="font-bold text-green-400">{{ $footyStatsData['btts']['away']['yes'] ?? 0 }}% Yes</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
    
    {{-- ==================== SÉRIES EN COURS ==================== --}}
    @if($footyStatsData && isset($footyStatsData['series']))
        <div class="mb-6 border-2 border-indigo-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-indigo-900">Séries en cours</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-2 gap-6">
                    @foreach(['home', 'away'] as $side)
                        @php
                            $series = $footyStatsData['series'][$side] ?? [];
                            $teamName = $side === 'home' ? $homeTeam : $awayTeam;
                        @endphp
                        <div class="bg-slate-50 p-5 rounded-lg border border-indigo-300">
                            <div class="font-bold text-slate-700 mb-4 text-center text-xl">{{ $teamName }}</div>
                            <div class="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <div class="text-3xl font-bold text-green-500">{{ $series['currentWinStreak'] ?? 0 }}</div>
                                    <div class="text-xs text-slate-500 mt-1">Victoires consécutives</div>
                                </div>
                                <div>
                                    <div class="text-3xl font-bold text-blue-500">{{ $series['currentUnbeatenStreak'] ?? 0 }}</div>
                                    <div class="text-xs text-slate-500 mt-1">Invaincu</div>
                                </div>
                                <div>
                                    <div class="text-3xl font-bold text-yellow-500">{{ $series['currentScoringStreak'] ?? 0 }}</div>
                                    <div class="text-xs text-slate-500 mt-1">Buts marqués</div>
                                </div>
                                <div>
                                    <div class="text-3xl font-bold text-purple-500">{{ $series['currentCleanSheetStreak'] ?? 0 }}</div>
                                    <div class="text-xs text-slate-500 mt-1">Clean sheets</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    
    {{-- ==================== CLASSEMENT ==================== --}}
    @if($fbrefData && isset($fbrefData['league']))
        <div class="mb-6 border-2 border-red-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
                <h4 class="font-bold text-lg text-red-900">Classement actuel</h4>
            </div>
            <div class="p-4 bg-white">
                <div class="grid grid-cols-2 gap-6">
                    @foreach(['home', 'away'] as $side)
                        @php
                            $league = $fbrefData['league'][$side] ?? [];
                            $teamName = $side === 'home' ? $homeTeam : $awayTeam;
                        @endphp
                        <div class="bg-slate-50 p-6 rounded-lg border border-red-300 text-center">
                            <div class="font-bold text-slate-700 mb-3 text-xl">{{ $teamName }}</div>
                            <div class="text-5xl font-black text-red-500">
                                {{ $league['position'] ?? '?' }}<sup class="text-2xl">e</sup>
                            </div>
                            <div class="text-lg text-slate-600 mt-2">
                                {{ $league['points'] ?? 0 }} points
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    
    {{-- ==================== TOP BUTEURS ==================== --}}
    @if($fbrefData && isset($fbrefData['topPlayers']))
        <div class="mb-6 border-2 border-pink-200 rounded-xl overflow-hidden">
            <div class="bg-white p-3">
            <h4 class="font-bold text-lg text-pink-900">Meilleur buteur</h4>
            </div>
            <div class="p-4 bg-white">
            <div class="grid grid-cols-2 gap-6">
                @foreach(['home', 'away'] as $side)
                    @php
                        $scorer = $fbrefData['topPlayers'][$side]['topScorer'] ?? null;
                        $teamName = $side === 'home' ? $homeTeam : $awayTeam;
                    @endphp
                    <div class="bg-slate-50 p-6 rounded-lg border border-pink-300 text-center">
                        <div class="font-bold text-slate-700 mb-4 text-xl">{{ $teamName }}</div>
                        @if($scorer && $scorer['name'])
                            <div class="text-2xl font-bold text-slate-800 mb-2">
                                {{ $scorer['name'] }}
                            </div>
                            <div class="text-4xl font-black text-pink-500">
                                {{ $scorer['goals'] ?? 0 }}
                            </div>
                            <div class="text-sm text-slate-500 mt-2">buts</div>
                            <div class="mt-3 text-sm {{ $scorer['available'] ?? true ? 'text-green-500' : 'text-red-500' }}">
                                {{ $scorer['available'] ?? true ? '✅ Disponible' : '❌ Absent' }}
                            </div>
                        @else
                            <div class="text-slate-400 italic">Non renseigné</div>
                        @endif
                    </div>
                @endforeach
            </div>
            </div>
        </div>
    @endif
</div>