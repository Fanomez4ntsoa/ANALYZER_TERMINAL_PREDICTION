<header class="bg-gray-900 shadow-md">
    <div class="flex items-center justify-between px-6 py-4">
        
        <!-- PAGE TITLE -->
        <div>
            <h1 class="text-2xl font-bold text-white">
                @yield('page-title', 'Tableau de Bord')
            </h1>
        </div>
        
        <!-- USER MENU -->
        <div class="flex items-center gap-4" x-data="{ userMenuOpen: false }">
            
            <!-- Notifications (optionnel) -->
            <button class="relative text-white hover:text-gray-900 focus:outline-none">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
                <span class="absolute top-0 right-0 block h-2 w-2 rounded-full bg-red-500 ring-2 ring-white"></span>
            </button>
            
            <!-- User Dropdown -->
            <div class="relative">
                <button 
                    @click="userMenuOpen = !userMenuOpen"
                    class="flex items-center gap-3 focus:outline-none"
                >
                    <div class="text-right">
                        <p class="text-sm font-medium text-gray-800">{{ Auth::user()->name }}</p>
                        <p class="text-xs text-gray-500">{{ Auth::user()->email }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                        {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                    </div>
                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                
                <!-- Dropdown Menu -->
                <div 
                    x-show="userMenuOpen" 
                    @click.away="userMenuOpen = false"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl py-2 z-50"
                    style="display: none;"
                >
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-white hover:bg-gray-100">
                        👤 Mon Profil
                    </a>
                    <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm text-white hover:bg-gray-100">
                        Dashboard
                    </a>
                    <hr class="my-2">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left block px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                            🚪 Déconnexion
                        </button>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
</header>