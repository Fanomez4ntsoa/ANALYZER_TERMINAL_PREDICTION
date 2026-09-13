<aside 
    class="bg-gradient-to-b from-gray-900 to-gray-800 text-white w-64 flex-shrink-0 overflow-y-auto transition-all duration-300"
    :class="{ 'w-64': sidebarOpen, 'w-20': !sidebarOpen }"
>
    <!-- LOGO -->
    <div class="p-6 border-b border-gray-700">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3" x-show="sidebarOpen">
                <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-2 rounded-lg">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold">Football</h2>
                    <p class="text-xs text-gray-400">Analyzer Pro</p>
                </div>
            </div>
            <button 
                @click="sidebarOpen = !sidebarOpen" 
                class="text-gray-400 hover:text-white focus:outline-none"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- NAVIGATION -->
    <nav class="p-4 space-y-2">
        
        <!-- Dashboard -->
        <a href="{{ route('dashboard') }}" 
           class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-gray-700 {{ request()->routeIs('dashboard') ? 'bg-blue-600 text-white' : 'text-gray-300' }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            <span x-show="sidebarOpen" class="font-medium">Dashboard</span>
        </a>
        
        <!-- Nouvelle Analyse -->
        <a href="{{ route('analysis.index') }}" 
           class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-gray-700 {{ request()->routeIs('analysis.index') ? 'bg-blue-600 text-white' : 'text-gray-300' }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            <span x-show="sidebarOpen" class="font-medium">Nouvelle Analyse</span>
        </a>
        
        <!-- Résultats -->
        <a href="{{ route('analysis.results') }}" 
           class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-gray-700 {{ request()->routeIs('analysis.results') ? 'bg-blue-600 text-white' : 'text-gray-300' }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <span x-show="sidebarOpen" class="font-medium">Résultats</span>
        </a>
        
        <!-- Historique -->
        <a href="{{ route('history.index') }}" 
           class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-gray-700 {{ request()->routeIs('history.*') ? 'bg-blue-600 text-white' : 'text-gray-300' }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span x-show="sidebarOpen" class="font-medium">Historique</span>
        </a>
        
        
        <!-- Divider -->
        <div class="border-t border-gray-700 my-4" x-show="sidebarOpen"></div>
        
        <!-- Profil -->
        <a href="{{ route('profile.edit') }}" 
           class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-gray-700 {{ request()->routeIs('profile.*') ? 'bg-blue-600 text-white' : 'text-gray-300' }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
            <span x-show="sidebarOpen" class="font-medium">Profil</span>
        </a>
        
        <!-- Déconnexion -->
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-red-600 text-gray-300 hover:text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span x-show="sidebarOpen" class="font-medium">Déconnexion</span>
            </button>
        </form>
        
    </nav>
    
    <!-- VERSION -->
    <div class="p-4 text-center text-xs text-gray-500 border-t border-gray-700 mt-auto" x-show="sidebarOpen">
        <p>Football Analyzer v2.0</p>
        <p class="mt-1">Laravel + Layer 2</p>
    </div>
</aside>