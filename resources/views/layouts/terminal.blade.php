{{--
    Layout du terminal (docs/design-system.md). Usage :
    <x-terminal-layout title="Sélections" :states="$states"> … </x-terminal-layout>
    $inverse (au plus un) et $lines viennent de TerminalLayout.
--}}
<!DOCTYPE html>
<html lang="fr" data-motion="on">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Terminal Prédiction</title>
    <script>
        // Mouvement : choix mémorisé, sinon réglage système, posé avant le premier rendu
        (function () {
            var on = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            try { var saved = localStorage.getItem('terminal-motion'); if (saved === 'on' || saved === 'off') on = saved === 'on'; } catch (e) {}
            document.documentElement.dataset.motion = on ? 'on' : 'off';
        })();
    </script>
    @vite(['resources/css/terminal.css', 'resources/js/terminal.js'])
    {{ $head ?? '' }}
</head>
<body @class(['is-fit' => $fit])>
<div class="t-wrap">

    <header class="topbar">
        <div class="topbar-mark" aria-hidden="true">▚</div>
        <div>
            <div class="topbar-title">TERMINAL PRÉDICTION</div>
            <div class="lab">{{ $title }}</div>
        </div>

        <nav class="nav" aria-label="Navigation principale">
            @foreach ([
                'dashboard' => 'Aujourd\'hui',
                'analysis.index' => 'Matchs',
                'history.index' => 'Historique',
                'market.index' => 'Marché',
                'settings.index' => 'Réglages',
            ] as $route => $label)
                @if (Route::has($route))
                    <a href="{{ route($route) }}" @if (request()->routeIs($route, Str::before($route, '.') . '.*')) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
        </nav>

        <div class="sp"></div>

        <x-terminal.kv label="Dernier passage réussi">
            @if ($freshness['lastSuccess'])
                {{ $freshness['lastSuccess']->run_date->format('d/m') }}
                @if ($freshness['lastSuccess']->finished_at)
                    · {{ $freshness['lastSuccess']->finished_at->utc()->format('H:i') }} UTC
                @endif
            @else
                aucun
            @endif
        </x-terminal.kv>

        <x-terminal.motion-toggle />

        <div class="flex items-center gap-gap">
            <span class="dot is-on" aria-hidden="true"></span>
            <x-terminal.clock />
            <span class="lab">UTC</span>
        </div>
    </header>

    @if ($inverse)
        <x-terminal.state :state="$inverse" inverse />
    @endif
    @foreach ($lines as $line)
        <x-terminal.state :state="$line" />
    @endforeach

    <main class="t-main">
        {{ $slot }}
    </main>

    <footer class="footbar">
        <span class="lab">Poisson · mode marché seul · <span class="normal-case">λ</span> sur cotes Bet365</span>
        <span class="lab">Aucune décision automatique</span>
        <div class="sp"></div>
        <span class="lab">Prêt<span class="cursor" aria-hidden="true"></span></span>
    </footer>
</div>
{{ $scripts ?? '' }}
</body>
</html>
