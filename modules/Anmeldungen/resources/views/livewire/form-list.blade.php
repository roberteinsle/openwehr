<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Anmeldungen</h1>
            <p class="text-sm text-gray-500">Fahrten, Zeltlager und Veranstaltungen mit Anmeldung und Einverständniserklärung.</p>
        </div>
        @if($manager)<x-page-action :href="route('anmeldungen.create')">Neue Anmeldung</x-page-action>@endif
    </div>

    <section class="space-y-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Für dich offen</h2>
        @forelse($open as $form)
            @php($r = $mine->get($form->id))
            <a href="{{ route('anmeldungen.show', $form) }}" class="flex items-center justify-between gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm hover:bg-gray-50" wire:key="o-{{ $form->id }}">
                <span class="min-w-0">
                    <span class="block font-semibold text-gray-900">{{ $form->title }}</span>
                    <span class="block text-sm text-gray-500">{{ collect([$form->dateLabel(), $form->location, $form->deadline ? 'Anmeldeschluss '.$form->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') : null])->filter()->implode(' · ') }}</span>
                </span>
                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $r?->isActive() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $r?->isActive() ? 'angemeldet' : 'offen' }}</span>
            </a>
        @empty
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Gerade ist keine Anmeldung für dich offen.</p>
        @endforelse
    </section>

    @if($manager)
        @foreach(['open' => 'Offen', 'draft' => 'Entwürfe', 'closed' => 'Geschlossen'] as $status => $title)
            @if(($all[$status] ?? collect())->isNotEmpty())
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ $title }}</h2>
                    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                        @foreach($all[$status] as $form)
                            <a href="{{ route('anmeldungen.show', $form) }}" class="flex items-center justify-between gap-3 p-4 hover:bg-gray-50" wire:key="a-{{ $form->id }}">
                                <span class="min-w-0">
                                    <span class="block font-medium text-gray-900">{{ $form->title }}</span>
                                    <span class="block text-sm text-gray-500">{{ collect([$form->dateLabel(), $form->location])->filter()->implode(' · ') ?: 'ohne Datum' }}</span>
                                </span>
                                <span class="shrink-0 text-sm text-gray-600">{{ $form->registered_count }}{{ $form->max_participants ? ' / '.$form->max_participants : '' }} angemeldet</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    @endif
</div>
