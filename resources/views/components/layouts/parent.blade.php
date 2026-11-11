{{-- Eltern-Bereich for Erziehungsberechtigte signed in with the guard "guardian": no Kamerad navigation,
     only the organisation, the parent's name and Abmelden. Kameraden who are parents use the app layout. --}}
@props(['title' => null])
@inject('org', \App\Services\OrganizationSettings::class)
<!DOCTYPE html>
<html lang="de" class="h-full bg-fw-grey-bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1B2645">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/images/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{{ $title ? $title.' – '.$org->shortName() : $org->shortName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
    <div class="min-h-full flex flex-col">
        <header class="bg-fw-navy text-white shadow-sm">
            <div class="max-w-3xl mx-auto px-4 flex items-center justify-between h-12 sm:h-14">
                <a href="{{ route('parents.index') }}" class="flex items-center gap-2.5 min-w-0">
                    <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="{{ $org->shortName() }}" class="h-6 sm:h-8 w-auto brightness-0 invert">
                    <span class="font-semibold text-sm truncate">{{ $org->shortName() }} · Eltern</span>
                </a>
                <form method="POST" action="{{ route('parents.logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg px-2 py-1.5 text-sm hover:bg-white/10">Abmelden</button>
                </form>
            </div>
        </header>
        <main class="flex-1 px-4 py-4 max-w-3xl mx-auto w-full">
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
