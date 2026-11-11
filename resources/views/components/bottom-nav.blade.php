{{-- Phones: the tab bar at the bottom that people know from other apps (Jakob's Law) – Start, Termine,
     all modules, Mitteilungen and Profil within thumb reach. Larger screens keep the top navigation. --}}
@props(['unreadFeedback' => false])
@php
    $user = auth()->user();
    $registry = app(\App\Services\ModuleRegistry::class);
    $tabs = array_values(array_filter([
        ['label' => 'Start', 'url' => route('home'), 'active' => request()->routeIs('home'), 'icon' => 'home'],
        $user->canUseModule('kalender') ? ['label' => 'Termine', 'url' => $registry->startUrl('kalender', $user), 'active' => request()->routeIs('kalender.*'), 'icon' => 'calendar'] : null,
        ['label' => 'Module', 'launcher' => true, 'icon' => 'grid'],
        $user->canUseModule('mitteilungen') ? ['label' => 'Mitteilungen', 'url' => $registry->startUrl('mitteilungen', $user), 'active' => request()->routeIs('mitteilungen.*'), 'icon' => 'megaphone'] : null,
        ['label' => 'Profil', 'url' => route('profile'), 'active' => request()->routeIs('profile') || str_starts_with(request()->path(), 'personalakte'), 'icon' => 'profile'],
    ]));
@endphp
<div class="sm:hidden" aria-hidden="true" style="height: calc(4rem + env(safe-area-inset-bottom))"></div>
<nav data-bottom-nav aria-label="Hauptnavigation"
     class="sm:hidden fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 backdrop-blur"
     style="padding-bottom: env(safe-area-inset-bottom)">
    <ul class="grid h-16" style="grid-template-columns: repeat({{ count($tabs) }}, minmax(0, 1fr))">
        @foreach($tabs as $tab)
            @php($classes = 'flex h-full w-full flex-col items-center justify-center gap-0.5 text-[11px] font-medium '.(($tab['active'] ?? false) ? 'text-fw-red' : 'text-gray-500'))
            <li>
                @if($tab['launcher'] ?? false)
                    <button type="button" data-launcher-toggle x-data x-on:click="$dispatch('toggle-launcher')" class="{{ $classes }}" aria-label="Alle Module">
                @else
                    <a href="{{ $tab['url'] }}" class="{{ $classes }}" @if($tab['active']) aria-current="page" @endif>
                @endif
                    <span class="relative">
                        @switch($tab['icon'])
                            @case('home')<svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10"/></svg>@break
                            @case('calendar')<svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18"/></svg>@break
                            @case('grid')<svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">@foreach([5, 12, 19] as $y)@foreach([5, 12, 19] as $x)<circle cx="{{ $x }}" cy="{{ $y }}" r="1.9" />@endforeach @endforeach</svg>@break
                            @case('megaphone')<svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.9v12.2a1 1 0 0 1-1.7.7L6.6 16H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1h2.6l2.7-2.8a1 1 0 0 1 1.7.7zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>@break
                            @default<x-avatar :user="$user" size="h-6 w-6" text="text-[9px]" />
                        @endswitch
                        @if($tab['icon'] === 'profile' && $unreadFeedback)<span class="absolute -right-0.5 -top-0.5 h-2 w-2 rounded-full bg-fw-red"></span>@endif
                    </span>
                    {{ $tab['label'] }}
                @if($tab['launcher'] ?? false)</button>@else</a>@endif
            </li>
        @endforeach
    </ul>
</nav>
