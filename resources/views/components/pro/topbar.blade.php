{{-- Top bar --}}
<header class="h-14 bg-white border-b border-slate-200 flex items-center justify-between px-6 flex-shrink-0">
    <div class="flex items-center gap-4">
        {{-- Mobile menu toggle --}}
        <button @click="mobileMenu = true" class="lg:hidden text-slate-500 hover:text-slate-700">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
        </button>
        <div>
            <h1 class="text-base font-semibold text-slate-800">@yield('page-title', 'Dashboard')</h1>
            @hasSection('page-subtitle')
                <p class="text-xs text-slate-500">@yield('page-subtitle')</p>
            @endif
        </div>
    </div>

    <div class="flex items-center gap-4">
        {{-- Status indicators --}}
        <div class="hidden sm:flex items-center gap-3 text-xs text-slate-500">
            <span class="px-1.5 py-0.5 rounded bg-slate-100 font-mono text-slate-500">UTC+3</span>
            <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>Pipeline</span>
        </div>

        {{-- User menu --}}
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open" class="flex items-center gap-2 text-sm text-slate-600 hover:text-slate-800">
                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-xs font-semibold text-slate-600">
                    {{ substr(Auth::user()->name ?? 'U', 0, 1) }}
                </div>
                <span class="hidden sm:inline">{{ Auth::user()->name ?? 'User' }}</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-cloak @click.away="open = false"
                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 class="absolute right-0 mt-2 w-44 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50">
                <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Profil</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Deconnexion</button>
                </form>
            </div>
        </div>
    </div>
</header>
