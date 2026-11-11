{{-- 3×3 dots like Microsoft 365: every module the Kamerad may use, also the ones hidden on the start page.
     On the phone also Profil and Admin, as the text navigation is hidden there; the Bestand for
     whoever can use one of its modules. --}}
@props(['adminUrl' => null, 'unreadFeedback' => false])
@php
    $launcherModules = app(\App\Services\ModuleOverview::class)->for(auth()->user());
@endphp
{{-- On the phone the button sits in the tab bar at the bottom (x-bottom-nav, event "toggle-launcher") and the menu opens above it --}}
<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:toggle-launcher.window="open = ! open"
     x-on:click.outside="if (! $event.target.closest('[data-launcher-toggle]')) open = false">
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true" aria-label="Alle Module"
            title="Alle Module" class="relative hidden sm:flex h-9 w-9 items-center justify-center rounded-lg hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/40">
        {{-- Phone: the feedback dot of "Profil" sits on the menu --}}
        @if($unreadFeedback)<span class="sm:hidden absolute right-1 top-1 h-2 w-2 rounded-full bg-fw-red"></span>@endif
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            @foreach([4, 12, 20] as $y)@foreach([4, 12, 20] as $x)<circle cx="{{ $x }}" cy="{{ $y }}" r="2" />@endforeach @endforeach
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="fixed inset-x-2 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-50 overflow-hidden rounded-2xl border border-gray-100 bg-white text-gray-900 shadow-xl sm:absolute sm:inset-x-auto sm:bottom-auto sm:right-0 sm:mt-2 sm:w-72 sm:max-w-[calc(100vw-2rem)]">
        <a href="{{ route('home') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50 {{ request()->routeIs('home') ? 'bg-gray-50' : '' }}">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10">
                <svg class="h-4 w-4 text-fw-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10"/></svg>
            </span>
            Startseite
        </a>
        <a href="{{ route('profile') }}" class="sm:hidden flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50 {{ request()->routeIs('profile') ? 'bg-gray-50' : '' }}" data-launcher-profile>
            <x-avatar :user="auth()->user()" size="h-8 w-8" text="text-[10px]" />
            Profil
            @if($unreadFeedback)<span class="ml-auto inline-block h-2 w-2 rounded-full bg-fw-red" title="Neue Antwort auf dein Feedback"></span>@endif
        </a>
        @if(app(\App\Services\ParentAccess::class)->hasChildren())
            <a href="{{ route('parents.index') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50 {{ request()->routeIs('parents.*') ? 'bg-gray-50' : '' }}">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10">
                    <svg class="h-4 w-4 text-fw-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm12 2a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0zM1 20a5 5 0 0 1 10 0M14 20a4.5 4.5 0 0 1 9 0"/></svg>
                </span>
                Eltern-Bereich
            </a>
        @endif
        @if(app(\App\Services\InventoryRegistry::class)->availableFor(auth()->user()))
            <a href="{{ route('inventory') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50 {{ request()->routeIs('inventory') ? 'bg-gray-50' : '' }}">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10">
                    <svg class="h-4 w-4 text-fw-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7 12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </span>
                Bestand
            </a>
        @endif
        @if(\App\Models\Contact::exists())
            <a href="{{ route('contacts') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50 {{ request()->routeIs('contacts') ? 'bg-gray-50' : '' }}">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10">
                    <svg class="h-4 w-4 text-fw-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.95.68l1.5 4.5a1 1 0 01-.5 1.21l-2.26 1.13a11.04 11.04 0 005.52 5.52l1.13-2.26a1 1 0 011.21-.5l4.5 1.5a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.72 21 3 14.28 3 6V5z"/></svg>
                </span>
                Ansprechpartner
            </a>
        @endif
        @if($adminUrl)
            <a href="{{ $adminUrl }}" class="sm:hidden flex items-center gap-3 px-4 py-3 text-sm font-medium hover:bg-gray-50" data-launcher-admin>
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10">
                    <svg class="h-4 w-4 text-fw-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </span>
                Admin
            </a>
        @endif

        @if(! empty($launcherModules))
            <p class="border-t border-gray-100 px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Module</p>
            <nav class="max-h-[50vh] sm:max-h-[70vh] overflow-y-auto pb-2">
                @foreach($launcherModules as $key => $module)
                    {{-- Module routes are named after their key, e.g. kalender.* --}}
                    @php($active = request()->routeIs($key.'.*') && ! str_starts_with((string) request()->route()?->uri(), 'personalakte'))
                    <a href="{{ $module['url'] }}" class="flex items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50 {{ $active ? 'bg-gray-50 font-semibold text-fw-navy' : '' }}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $active ? 'bg-fw-navy text-white' : 'bg-fw-navy/10 text-fw-navy' }}">
                            <x-module-icon :key="$key" class="h-4 w-4" />
                        </span>
                        <span class="min-w-0 truncate">{{ $module['name'] }}</span>
                    </a>
                @endforeach
            </nav>
        @else
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500">Für dich ist noch kein Modul freigeschaltet.</p>
        @endif
    </div>
</div>
