<div class="space-y-4">
    <a href="{{ route('anmeldungen.index') }}" class="inline-flex items-center gap-1 text-sm text-fw-navy">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Alle Anmeldungen
    </a>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('status')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-2">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h1 class="text-xl font-bold text-gray-900">{{ $form->title }}</h1>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ ['open' => 'bg-green-100 text-green-800', 'draft' => 'bg-gray-100 text-gray-700', 'closed' => 'bg-gray-200 text-gray-700'][$form->status] }}">{{ \Modules\Anmeldungen\src\Models\Form::STATUSES[$form->status] }}</span>
        </div>
        <p class="text-sm text-gray-600">{{ collect([$form->dateLabel(), $form->location])->filter()->implode(' · ') }}</p>
        @if($form->deadline)<p class="text-sm text-gray-600">Anmeldeschluss: {{ $form->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr</p>@endif
        @if($form->description)<div class="md text-sm text-gray-800">{!! \App\Support\Markdown::html($form->description) !!}</div>@endif
        @if($form->healthFields() !== [])
            <p class="text-xs text-gray-500">Gesundheitsangaben werden verschlüsselt gespeichert und {{ $form->purgeDate() ? 'am '.$form->purgeDate()->format('d.m.Y') : $form->purge_days.' Tage nach der Veranstaltung' }} gelöscht.@if($form->health_purged_at) Sie sind bereits gelöscht.@endif</p>
        @endif
    </div>

    @if($canRegister)
        <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-base font-semibold text-gray-900">Deine Anmeldung</h2>
            <livewire:anmeldungen.registration-form :form-id="$form->id" :key="'own-'.$form->id" />
        </section>
    @endif

    @if($manager)
        <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('anmeldungen.edit', $form) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Bearbeiten</a>
                @if($form->status !== 'open')
                    <button type="button" wire:click="open" wire:confirm="Anmeldung öffnen? {{ $form->opened_at ? '' : 'Die Kameraden und ihre Erziehungsberechtigten werden benachrichtigt.' }}" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white">{{ $form->opened_at ? 'Wieder öffnen' : 'Öffnen und benachrichtigen' }}</button>
                @else
                    <button type="button" wire:click="remind" wire:confirm="Alle, die noch nicht angemeldet sind, erinnern?" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Erinnern</button>
                    <button type="button" wire:click="close" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Schließen</button>
                @endif
                <a href="{{ route('anmeldungen.export', $form) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Teilnehmerliste (CSV)</a>
                <a href="{{ route('anmeldungen.print', $form) }}" target="_blank" rel="noopener" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Drucken</a>
                <button type="button" wire:click="duplicate" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Kopieren</button>
                <button type="button" wire:click="delete" wire:confirm="Anmeldung mit allen Antworten und Unterschriften löschen?" class="ml-auto text-sm text-red-700 underline">Löschen</button>
            </div>

            <h2 class="text-base font-semibold text-gray-900">Teilnehmer: {{ $registrations->where('status', 'registered')->count() }}{{ $form->max_participants ? ' von '.$form->max_participants : '' }} angemeldet</h2>
            <div class="divide-y divide-gray-100">
                @forelse($registrations as $r)
                    <div class="py-2" wire:key="r-{{ $r->id }}">
                        <button type="button" wire:click="$set('showId', {{ $showId === $r->id ? 'null' : $r->id }})" class="flex w-full items-center justify-between gap-3 text-left text-sm">
                            <span><span class="font-medium {{ $r->isActive() ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $r->user->name }}</span>
                                <span class="text-gray-500">· {{ $r->isActive() ? 'unterschrieben von '.$r->signer_name.' am '.$r->signed_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') : 'abgemeldet' }}</span></span>
                            <span class="text-xs text-fw-navy underline">{{ $showId === $r->id ? 'Zuklappen' : 'Angaben' }}</span>
                        </button>
                        @if($showId === $r->id)
                            <div class="mt-2 rounded-xl bg-gray-50 p-3 text-sm">
                                <dl class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                                    @foreach($form->fields as $f)
                                        <div><dt class="text-xs text-gray-500">{{ $f['label'] }}</dt><dd class="text-gray-900 whitespace-pre-line">{{ $r->answerLabel($f) }}</dd></div>
                                    @endforeach
                                </dl>
                                @if($form->consents !== [])<p class="mt-2 text-xs text-gray-600">Alle {{ count($form->consents) }} Erklärungen bestätigt.</p>@endif
                                <div class="mt-2 flex flex-wrap gap-4">
                                    @if($r->signature)<figure><img src="data:image/png;base64,{{ $r->signature }}" alt="Unterschrift {{ $r->signer_name }}" class="h-16 rounded border border-gray-200 bg-white"><figcaption class="text-xs text-gray-500">{{ $r->signer_name }}</figcaption></figure>@endif
                                    @if($r->member_signature)<figure><img src="data:image/png;base64,{{ $r->member_signature }}" alt="Unterschrift {{ $r->user->name }}" class="h-16 rounded border border-gray-200 bg-white"><figcaption class="text-xs text-gray-500">{{ $r->user->name }}</figcaption></figure>@endif
                                </div>
                                @if($r->isActive())<button type="button" wire:click="cancelFor({{ $r->id }})" wire:confirm="{{ $r->user->name }} abmelden?" class="mt-2 text-xs text-red-700 underline">Abmelden</button>@endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="py-2 text-sm text-gray-500">Noch keine Anmeldungen.</p>
                @endforelse
            </div>
            @if($missing->isNotEmpty())
                <details class="text-sm">
                    <summary class="cursor-pointer text-gray-600">Noch nicht angemeldet ({{ $missing->count() }})</summary>
                    <p class="mt-1 text-gray-500">{{ $missing->pluck('name')->implode(', ') }}</p>
                </details>
            @endif
        </section>
    @endif
</div>
