@php($u = auth()->user())
@php($modules = app(\App\Services\ModuleRegistry::class))
@php($steps = array_filter([
    ['Profilfoto hochladen', 'Damit dich alle erkennen – es steht auch auf dem digitalen Dienstausweis.', route('profile'), (bool) $u->avatar],
    ['Als App auf den Startbildschirm', 'Im Browser-Menü „App installieren“ bzw. auf dem iPhone „Teilen“ → „Zum Home-Bildschirm“.', null, null],
    ['Push-Benachrichtigungen einschalten', 'Neue Termine, Mitteilungen und Antworten kommen dann aufs Handy.', route('profile'), \App\Models\PushSubscription::where('user_id', $u->id)->exists()],
    $modules->isActive('personalverwaltung') && $u->canUseModule('personalverwaltung') && \Modules\Personalverwaltung\src\Services\PersonnelService::selfService()
        ? ['Persönliche Daten eintragen', 'Anschrift, Geburtsdatum, Mobilnummer – nur die Personalverwaltung sieht sie.', route('personalverwaltung.own-data'), filled($u->street) || filled($u->phone) || filled($u->birth_date)] : null,
    $modules->isActive('fuehrerscheine') && $u->canUseModule('fuehrerscheine')
        ? ['Führerschein hinterlegen', 'Einmal fotografieren statt zur Kontrolle in die Wache kommen.', route('fuehrerscheine.index'), \Modules\Fuehrerscheine\src\Models\License::where('user_id', $u->id)->exists()] : null,
    \App\Models\Contact::anyFor(auth()->user()) ? ['Ansprechpartner ansehen', 'Wen du bei Fragen anrufst.', route('contacts'), null] : null,
]))
<x-layouts.app title="Willkommen">
    <div class="mx-auto max-w-lg space-y-4">
        <h1 class="text-2xl font-bold text-gray-900">Willkommen, {{ $u->name }}!</h1>
        <p class="text-sm text-gray-600">Schön, dass du da bist. Ein paar Schritte, dann ist die App für dich eingerichtet – alles lässt sich auch später im Profil erledigen.</p>
        <ol class="space-y-2">
            @foreach($steps as $i => [$title, $text, $url, $done])
                <li class="flex items-start gap-3 rounded-2xl border bg-white p-4 shadow-sm {{ $done ? 'border-green-200' : 'border-gray-100' }}">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $done ? 'bg-green-600 text-white' : 'bg-fw-navy/10 text-fw-navy' }}">{{ $done ? '✓' : $loop->iteration }}</span>
                    <span class="min-w-0 text-sm"><span class="block font-semibold text-gray-900">{{ $title }}</span><span class="block text-gray-600">{{ $text }}</span>
                        @if($url && ! $done)<a href="{{ $url }}" class="mt-1 inline-block text-fw-navy underline">Jetzt erledigen →</a>@endif</span>
                </li>
            @endforeach
        </ol>
        <a href="{{ route('home') }}" class="inline-block rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Zur Startseite</a>
    </div>
</x-layouts.app>
