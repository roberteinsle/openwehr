@php
    $withAudience = \Modules\Kalender\src\Models\Event::showAudienceTo(auth()->user());
    $months = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">Kalender</h1>
        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="type" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
                <option value="">Alle Terminarten</option>
                @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            @if(auth()->user()->is_admin)
                <a href="{{ route('kalender.monitor') }}" target="_blank" rel="noopener" class="text-sm text-fw-navy underline" title="Termin-Monitor für den Bildschirm in der Wache (für den Bildschirm ohne Anmeldung: /kalender/monitor/ + DISPLAY_TOKEN)">Termin-Monitor ↗</a>
            @endif
            @if($manager)
                <x-page-action :href="route('kalender.create')">Termin anlegen</x-page-action>
            @endif
        </div>
    </div>

    {{-- Period: month, week or list --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div class="flex items-center gap-2">
                <button type="button" wire:click="shift(-1)" class="h-8 w-8 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" aria-label="Zurück">‹</button>
                <button type="button" wire:click="today" class="h-8 rounded-lg border border-gray-200 px-3 text-sm text-gray-700 hover:bg-gray-50">Heute</button>
                <button type="button" wire:click="shift(1)" class="h-8 w-8 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" aria-label="Weiter">›</button>
                <p class="ml-2 font-semibold text-gray-900">
                    @if($view === 'woche')
                        {{ $from->format('d.m.') }} – {{ $until->format('d.m.Y') }} <span class="font-normal text-gray-500">· KW {{ $from->isoWeek() }}</span>
                    @else
                        {{ $months[$anchor->month] }} {{ $anchor->year }}
                    @endif
                </p>
            </div>
            <div class="inline-flex rounded-lg border border-gray-200 p-0.5 text-sm" role="tablist" aria-label="Ansicht">
                @foreach($views as $value => $label)
                    <button type="button" role="tab" aria-selected="{{ $view === $value ? 'true' : 'false' }}" wire:click="setView('{{ $value }}')"
                            class="rounded-md px-3 py-1 {{ $view === $value ? 'bg-fw-navy text-white' : 'text-gray-700 hover:bg-gray-50' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if($view === 'monat')
            <div class="grid grid-cols-7 text-center text-xs font-semibold text-gray-500 mb-1">
                @foreach($weekdays as $wd)<div>{{ $wd }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7 gap-px rounded-lg overflow-hidden bg-gray-100 border border-gray-100">
                @foreach($days as $day)
                    <div class="min-h-[4.5rem] sm:min-h-[6rem] bg-white p-1 {{ $day['inMonth'] ? '' : 'bg-gray-50 text-gray-400' }}">
                        <div class="text-xs mb-0.5 {{ $day['isToday'] ? 'inline-flex h-5 w-5 items-center justify-center rounded-full bg-fw-red font-bold text-white' : 'text-gray-500' }}">{{ $day['date']->day }}</div>
                        <div class="space-y-0.5">
                            @foreach($day['events']->take(3) as $event)
                                <a href="{{ route('kalender.show', $event) }}" title="{{ $event->title }}{{ $event->audienceLabel() ? ' ('.$event->audienceLabel().')' : '' }} – {{ $event->timeLabel() }}"
                                   class="block truncate rounded border px-1 text-[11px] leading-4 {{ $event->isCancelled() ? 'line-through opacity-60' : '' }}" style="{{ $event->colorStyle() }}">
                                    @unless($event->all_day){{ $event->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i') }} @endunless{{ $event->title }}@if($withAudience && $event->audienceLabel()) <span class="opacity-75">· {{ $event->audienceLabel() }}</span>@endif
                                </a>
                            @endforeach
                            @if($day['events']->count() > 3)<button type="button" wire:click="$set('date', '{{ $day['date']->toDateString() }}'); $set('view', 'woche')" class="text-[11px] text-gray-500 hover:underline">+{{ $day['events']->count() - 3 }} weitere</button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif($view === 'woche')
            {{-- Seven columns on wide screens, one day under the other on phones --}}
            <div class="grid grid-cols-1 md:grid-cols-7 gap-2">
                @foreach($days as $i => $day)
                    <div class="rounded-lg border p-2 {{ $day['isToday'] ? 'border-fw-red bg-red-50/40' : 'border-gray-100' }}">
                        <p class="text-xs font-semibold {{ $day['isToday'] ? 'text-fw-red' : 'text-gray-500' }}">{{ $weekdays[$i] }} {{ $day['date']->format('d.m.') }}</p>
                        <div class="mt-1 space-y-1">
                            @forelse($day['events'] as $event)
                                <a href="{{ route('kalender.show', $event) }}" class="block rounded border px-1.5 py-1 text-xs {{ $event->isCancelled() ? 'line-through opacity-60' : '' }}" style="{{ $event->colorStyle() }}">
                                    <span class="block font-semibold">{{ $event->all_day ? 'ganztägig' : $event->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i').'–'.$event->ends_at->copy()->setTimezone('Europe/Berlin')->format('H:i') }}</span>
                                    <span class="block">{{ $event->title }}</span>
                                    @if($withAudience && $event->audienceLabel())<span class="block font-semibold opacity-75">{{ $event->audienceLabel() }}</span>@endif
                                    @if($event->location)<span class="block opacity-75">{{ $event->location }}</span>@endif
                                </a>
                            @empty
                                <p class="text-xs text-gray-300">–</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            @php($withEvents = collect($days)->filter(fn ($d) => $d['events']->isNotEmpty()))
            @if($withEvents->isEmpty())
                <p class="text-sm text-gray-500">In diesem Monat gibt es keine Termine.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($withEvents as $day)
                        <div class="py-2 sm:flex sm:gap-4">
                            <p class="w-28 shrink-0 text-sm font-semibold {{ $day['isToday'] ? 'text-fw-red' : 'text-gray-700' }}">{{ $weekdays[$day['date']->dayOfWeekIso - 1] }}, {{ $day['date']->format('d.m.') }}</p>
                            <div class="flex-1 space-y-1">
                                @foreach($day['events'] as $event)
                                    @php($mine = $event->responses->first())
                                    <a href="{{ route('kalender.show', $event) }}" class="flex items-start justify-between gap-3 rounded-lg px-2 py-1 hover:bg-gray-50">
                                        <span class="min-w-0 text-sm">
                                            <span class="text-gray-500">{{ $event->all_day ? 'ganztägig' : $event->starts_at->copy()->setTimezone('Europe/Berlin')->format('H:i').'–'.$event->ends_at->copy()->setTimezone('Europe/Berlin')->format('H:i') }}</span>
                                            <span class="font-medium text-gray-900 {{ $event->isCancelled() ? 'line-through text-gray-500' : '' }}">{{ $event->title }}</span>
                                            @if($withAudience && $event->audienceLabel())<x-audience-chip :label="$event->audienceLabel()" />@endif
                                            @if($event->location)<span class="text-gray-500"> · {{ $event->location }}</span>@endif
                                        </span>
                                        <span class="flex shrink-0 items-center gap-2">
                                            @if($event->rsvp && ! $event->isCancelled())<span class="text-xs {{ $mine?->status ? 'text-gray-500' : 'font-semibold text-fw-red' }}">{{ $mine?->status ? $mine->label() : 'Rückmeldung offen' }}</span>@endif
                                            <span class="rounded border px-1.5 py-0.5 text-xs" style="{{ $event->colorStyle() }}">{{ $event->typeLabel() }}</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            @foreach($types as $value => $label)<span class="rounded border px-1.5" style="{{ \Modules\Kalender\src\Models\EventType::styleOf($value) }}">{{ $label }}</span>@endforeach
        </div>
    </section>

    {{-- Next events (the list view already is a list) --}}
    @if($view !== 'liste')
    <section>
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Die nächsten Termine</h2>
        @if($upcoming->isEmpty())
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">In den nächsten 60 Tagen stehen keine Termine an.</div>
        @else
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                @foreach($upcoming as $event)
                    @php($mine = $event->responses->first())
                    <a href="{{ route('kalender.show', $event) }}" class="block p-4 hover:bg-gray-50" wire:key="up-{{ $event->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 {{ $event->isCancelled() ? 'line-through text-gray-500' : '' }}">{{ $event->title }}@if($withAudience && $event->audienceLabel()) <x-audience-chip :label="$event->audienceLabel()" />@endif</p>
                                <p class="text-sm text-gray-500">{{ $event->timeLabel() }}@if($event->location) · {{ $event->location }}@endif</p>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <span class="rounded border px-1.5 py-0.5 text-xs" style="{{ $event->colorStyle() }}">{{ $event->typeLabel() }}</span>
                                @if($event->isCancelled())
                                    <span class="text-xs font-semibold text-red-700">Abgesagt</span>
                                @elseif($event->rsvp)
                                    <span class="text-xs {{ $mine?->status ? 'text-gray-600' : 'font-semibold text-fw-red' }}">{{ $mine?->status ? $mine->label() : 'Rückmeldung offen' }}</span>
                                    @if($manager)
                                        <span class="text-xs {{ $event->min_participants && $event->yes_count < $event->min_participants ? 'font-semibold text-red-700' : 'text-gray-500' }}">{{ $event->yes_count }}{{ $event->min_participants ? ' / '.$event->min_participants : '' }} Zusagen</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
    @endif

    {{-- Subscription --}}
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <button type="button" wire:click="toggleFeed" class="text-sm font-semibold text-fw-navy underline">{{ $showFeed ? 'Kalender-Abo ausblenden' : 'Im Handy-Kalender abonnieren' }}</button>
        @if($showFeed && $feedUrl)
            <div class="mt-3 space-y-2 text-sm text-gray-700" x-data="{ copied: false }">
                <p>Diese Adresse in Google, Apple oder Outlook als Kalender-Abo hinzufügen. Sie zeigt die Termine, die für dich bestimmt sind, und aktualisiert sich von selbst.</p>
                <div class="flex flex-wrap gap-2">
                    <input type="text" readonly value="{{ $feedUrl }}" class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs" x-ref="feed">
                    <button type="button" x-on:click="navigator.clipboard.writeText($refs.feed.value); copied = true" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" x-text="copied ? 'Kopiert' : 'Kopieren'"></button>
                </div>
                <p class="text-xs text-gray-500">Die Adresse ist persönlich. Wer sie kennt, sieht deine Termine. <button type="button" wire:click="regenerateFeed" wire:confirm="Neue Adresse erzeugen? Die alte funktioniert dann nicht mehr." class="underline">Neue Adresse erzeugen</button></p>
            </div>
        @endif
    </section>
</div>
