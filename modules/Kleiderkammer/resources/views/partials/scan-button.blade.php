{{-- Kleiderwarte and admins on the phone: a full-width red Scan button at the bottom of every
     Kleiderkammer page (app and admin area), leading to the Scan-Seite. Not on the Scan-Seite itself. --}}
@php
    $path = request()->path();
    // In the app the phone's tab bar is at the bottom: the button sits right above it
    $bottom = ($aboveNav ?? false) ? 'calc(4rem + env(safe-area-inset-bottom))' : '0';
    $pad = ($aboveNav ?? false) ? '1rem' : 'calc(1rem + env(safe-area-inset-bottom))';
    $show = auth()->user()?->canManageKleiderkammer()
        && (request()->routeIs('kleiderkammer.*') || str_starts_with($path, 'admin/kleiderkammer'))
        && ! str_starts_with($path, 'admin/kleiderkammer/scanner');
@endphp
@if($show)
    <div data-kk-scan-button aria-hidden="false" style="height: 4.5rem;" class="kk-scan-spacer"></div>
    <a href="{{ $scanUrl }}" data-kk-scan-button
       style="position: fixed; left: 0; right: 0; bottom: {{ $bottom }}; z-index: 40; display: flex; align-items: center; justify-content: center; gap: .5rem; padding: 1rem 1rem {{ $pad }}; background: #ED2F12; color: #fff; font-weight: 600; font-size: 1rem; text-decoration: none; box-shadow: 0 -2px 8px rgb(0 0 0 / .15);"
       class="kk-scan-button">
        <svg style="width: 1.5rem; height: 1.5rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10"/></svg>
        Scannen
    </a>
    <style>@media (min-width: 640px) { [data-kk-scan-button] { display: none !important; } }</style>
@endif
