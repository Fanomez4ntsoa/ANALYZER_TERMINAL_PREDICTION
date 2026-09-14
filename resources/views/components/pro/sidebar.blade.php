{{-- Sidebar navigation --}}
<aside
    :class="sidebarOpen ? 'w-60' : 'w-16'"
    class="hidden lg:flex flex-col bg-surface-900 text-white transition-all duration-200 flex-shrink-0"
>
    {{-- Logo --}}
    <div class="h-14 flex items-center px-4 border-b border-slate-700/50">
        <div class="w-8 h-8 rounded-lg bg-brand-600 flex items-center justify-center font-bold text-sm flex-shrink-0">FA</div>
        <span x-show="sidebarOpen" x-cloak class="ml-3 font-semibold text-sm tracking-tight">Football Analyzer</span>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 py-4 space-y-1 px-2 overflow-y-auto">
        <p x-show="sidebarOpen" x-cloak class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-widest text-slate-500">Analyse</p>

        @php
            $links = [
                ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4'],
                ['route' => 'analysis.index', 'label' => 'Matchs du jour', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
                ['route' => 'analysis.results', 'label' => 'Analyse', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0h6m4 0v-4a2 2 0 00-2-2h-2a2 2 0 00-2 2v4a2 2 0 002 2h2a2 2 0 002-2z'],            ];
        @endphp

        @foreach($links as $link)
            <a href="{{ route($link['route']) }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs($link['route']) ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $link['icon'] }}"/>
                </svg>
                <span x-show="sidebarOpen" x-cloak>{{ $link['label'] }}</span>
            </a>
        @endforeach

        <p x-show="sidebarOpen" x-cloak class="px-3 mt-5 mb-2 text-[10px] font-semibold uppercase tracking-widest text-slate-500">Marche</p>

        @php
            $marketLinks = [
                ['route' => 'history.index', 'label' => 'Historique', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['route' => 'market.index', 'label' => 'Marche / CLV', 'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],                ['route' => 'settings.index', 'label' => 'Parametres', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ];
        @endphp

        @foreach($marketLinks as $link)
            <a href="{{ route($link['route']) }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors
                      {{ request()->routeIs($link['route'].'*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $link['icon'] }}"/>
                </svg>
                <span x-show="sidebarOpen" x-cloak>{{ $link['label'] }}</span>
            </a>
        @endforeach
    </nav>

    {{-- Footer --}}
    <div class="p-3 border-t border-slate-700/50">
        <button @click="sidebarOpen = !sidebarOpen" class="w-full flex items-center justify-center p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
            </svg>
        </button>
    </div>
</aside>

{{-- Mobile overlay --}}
<div x-show="mobileMenu" x-cloak class="lg:hidden fixed inset-0 z-40 bg-black/50" @click="mobileMenu = false"></div>
<aside x-show="mobileMenu" x-cloak
       x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
       class="lg:hidden fixed inset-y-0 left-0 z-50 w-60 bg-surface-900 text-white flex flex-col">
    <div class="h-14 flex items-center justify-between px-4 border-b border-slate-700/50">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-brand-600 flex items-center justify-center font-bold text-sm">FA</div>
            <span class="font-semibold text-sm">Football Analyzer</span>
        </div>
        <button @click="mobileMenu = false" class="text-slate-400 hover:text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <nav class="flex-1 py-4 space-y-1 px-2 overflow-y-auto">
        @foreach(array_merge($links, $marketLinks) as $link)
            <a href="{{ route($link['route']) }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs($link['route'].'*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $link['icon'] }}"/></svg>
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>
</aside>
