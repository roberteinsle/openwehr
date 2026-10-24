@php($input = 'rounded-lg border border-gray-300 px-2 py-1.5 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($cols = 'md:grid md:grid-cols-[9rem_5.5rem_minmax(0,1fr)_8rem_15rem] md:gap-3 md:items-start')
<div class="space-y-4">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Übungsdienstplaner</h1>
            <p class="text-sm text-gray-500">{{ $planner ? 'Dienste anlegen, ändern und löschen, Thema und Verantwortliche eintragen.' : 'Thema und Verantwortliche tragen die Übungsdienstplaner ein. Für deinen eigenen Dienst kannst du einen Tausch anfragen – der andere muss zustimmen.' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('uebungsdienste.print', ['jahr' => $year]) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Drucken / PDF</a>
            @if($planner)
                <x-page-action :href="route('uebungsdienste.create')">Übungsdienst anlegen</x-page-action>
            @endif
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('plan')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    {{-- Swaps waiting for an answer: everyone sees them, the asked Kamerad answers, the requester can take it back --}}
    @if($swaps->isNotEmpty())
        <section class="space-y-2 rounded-2xl border-2 border-amber-300 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Tausch angefragt</p>
            @foreach($swaps as $swap)
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-amber-900" wire:key="swap-{{ $swap->id }}">
                    <span><strong>{{ $swap->requester->name }}</strong> ({{ $swap->service->dateLabel() }}) ⇄ <strong>{{ $swap->targetUser->name }}</strong> ({{ $swap->targetService->dateLabel() }}) – wartet auf {{ $swap->targetUser->name }}</span>
                    <span class="flex flex-wrap gap-2">
                        @if($swap->target_user_id === auth()->id())
                            <button type="button" wire:click="answerSwap({{ $swap->id }}, true)" class="rounded-lg bg-green-600 px-3 py-1.5 font-semibold text-white hover:bg-green-700">Zustimmen</button>
                            <button type="button" wire:click="answerSwap({{ $swap->id }}, false)" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">Ablehnen</button>
                        @elseif($swap->requester_id === auth()->id())
                            <button type="button" wire:click="withdrawSwap({{ $swap->id }})" class="text-gray-600 underline">Zurückziehen</button>
                        @endif
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    {{-- The next Dienst, always on top --}}
    @if($next)
        <section class="rounded-2xl border-2 border-fw-orange bg-orange-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-fw-orange">Nächster Übungsdienst</p>
            <p class="mt-1 text-lg font-bold text-gray-900">{{ $next->dateLabel() }}{{ $next->starts_at ? ' · '.$next->starts_at.' Uhr' : '' }}</p>
            @if($next->location)<p class="text-sm text-gray-600">{{ $next->location }}</p>@endif
            <p class="mt-2 text-base text-gray-900 whitespace-pre-line">{{ $next->topic ?: 'Thema noch offen' }}</p>
            <p class="mt-1 text-sm text-gray-700"><span class="text-gray-500">Verantwortlich:</span> {{ $next->responsible->isNotEmpty() ? $next->responsible->pluck('name')->implode(', ') : 'noch niemand' }}</p>
        </section>
    @endif

    {{-- Year and past Dienste --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-1 text-sm">
            @foreach($years as $y)
                <button type="button" wire:click="$set('year', {{ $y }})" class="rounded-full px-3 py-1.5 {{ $y === $year ? 'bg-fw-navy text-white' : 'bg-white border border-gray-200 text-gray-700 hover:border-fw-navy' }}">{{ $y }}</button>
            @endforeach
        </div>
        @if($pastCount > 0)
            <button type="button" wire:click="$toggle('showPast')" class="text-sm text-fw-navy underline">{{ $showPast ? 'Vergangene Dienste ausblenden' : 'Vergangene Dienste anzeigen ('.$pastCount.')' }}</button>
        @endif
    </div>

    {{-- The list: cards on phones, a table on wide screens --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
        <div class="hidden {{ $cols }} border-b border-gray-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
            <span>Datum</span><span>Beginn</span><span>Thema</span><span>Ort</span><span>Verantwortlich</span>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($services as $service)
                @php($past = $service->date->lt($today))
                <div wire:key="s-{{ $service->id }}" class="px-4 py-3 {{ $cols }} {{ $service->id === $next?->id ? 'bg-orange-50/60' : '' }} {{ $past ? 'opacity-60' : '' }}">
                    {{-- Date, time, place: one line on phones, own columns on wide screens --}}
                    <div class="flex items-start justify-between gap-2 md:block">
                        <p class="font-semibold text-gray-900">{{ $service->dateLabel() }}<span class="font-normal text-gray-600 md:hidden">{{ $service->timeLabel() ? ' · '.$service->timeLabel() : '' }}{{ $service->location ? ' · '.$service->location : '' }}</span></p>
                        @php($canEdit = in_array($service->id, $editable, true))
                        @if($canEdit)<a href="{{ route('uebungsdienste.edit', $service) }}" class="shrink-0 text-sm text-fw-navy underline md:mt-1 md:inline-block md:text-xs">Ändern</a>@endif
                        @php($extras = array_filter([$service->ownerGroup ? 'Abteilung: '.$service->ownerGroup->name : null, $service->organizer ? 'Ausführend: '.$service->organizer : null,
                            \App\Models\DressCode::names($service->dress_code_ids) ? 'Kleidung: '.implode(', ', \App\Models\DressCode::names($service->dress_code_ids)) : null, $service->audienceLabel() ? 'Für: '.$service->audienceLabel() : null,
                            $service->vehicles->isNotEmpty() ? 'Fahrzeuge: '.$service->vehicles->map(fn ($v) => ($vehicleNames[$v->vehicle_id] ?? 'Fahrzeug').($v->booking_id ? '' : ' (nicht gebucht)'))->implode(', ') : null]))
                        @if($extras)<p class="text-xs text-gray-500">{{ implode(' · ', $extras) }}</p>@endif
                    </div>
                    <p class="hidden md:block text-gray-700">{{ $service->timeLabel() ?? '–' }}</p>
                    <div class="mt-2 md:mt-0">
                        @if($canEdit)
                            <label class="sr-only" for="topic-{{ $service->id }}">Thema</label>
                            <textarea id="topic-{{ $service->id }}" rows="2" wire:change="saveTopic({{ $service->id }}, $event.target.value)" maxlength="1000" placeholder="Thema eintragen …"
                                      class="{{ $input }} w-full resize-y">{{ $service->topic }}</textarea>
                        @else
                            <p class="whitespace-pre-line text-gray-900">{{ $service->topic ?: 'Thema noch offen' }}</p>
                        @endif
                    </div>
                    <p class="hidden md:block text-gray-700">{{ $service->location ?: '–' }}</p>
                    <div class="mt-2 md:mt-0">
                        <p class="mb-1 text-xs text-gray-500 md:hidden">Verantwortlich</p>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @foreach($service->responsible as $person)
                                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 py-1 pl-2.5 {{ $canEdit ? 'pr-1' : 'pr-2.5' }} text-sm text-gray-800" wire:key="r-{{ $service->id }}-{{ $person->id }}">
                                    {{ $person->name }}
                                    @if($canEdit)<button type="button" wire:click="removeResponsible({{ $service->id }}, {{ $person->id }})" class="inline-flex h-6 w-6 items-center justify-center rounded-full text-gray-500 hover:bg-gray-200 hover:text-red-600" aria-label="{{ $person->name }} entfernen">×</button>@endif
                                </span>
                            @endforeach
                            @if(! $canEdit && $service->responsible->isEmpty())<span class="text-sm text-gray-500">noch niemand</span>@endif
                            @if($canEdit)
                            <select wire:change="addResponsible({{ $service->id }}, $event.target.value)" wire:key="add-{{ $service->id }}-{{ $service->responsible->pluck('id')->implode('-') }}" class="{{ $input }} py-1 max-w-full" aria-label="Verantwortliche hinzufügen">
                                <option value="">+ hinzufügen</option>
                                @foreach($people as $id => $name)
                                    @unless($service->responsible->contains('id', $id))<option value="{{ $id }}">{{ $name }}</option>@endunless
                                @endforeach
                            </select>
                            @endif
                        </div>
                        {{-- Own coming Dienst: ask someone else to swap --}}
                        @if(! $past && $service->responsible->contains('id', auth()->id()))
                            <button type="button" wire:click="startSwap({{ $service->id }})" class="mt-1 text-sm text-fw-navy underline">{{ $swapFrom === $service->id ? 'Tauschen abbrechen' : 'Tauschen' }}</button>
                            @if($swapFrom === $service->id)
                                <form wire:submit="requestSwap" class="mt-2 space-y-2">
                                    <select wire:model="swapWith" class="{{ $input }} w-full" aria-label="Tauschen mit">
                                        <option value="">Tauschen mit …</option>
                                        @foreach($swapOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                    <button type="submit" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Tausch anfragen</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-gray-500">
                    @if($pastCount > 0 && ! $showPast)
                        Für {{ $year }} stehen keine Dienste mehr an.
                    @else
                        Für {{ $year }} sind noch keine Dienste geplant.{{ $planner ? ' Mit „Übungsdienst anlegen“ oben anlegen.' : '' }}
                    @endif
                </p>
            @endforelse
        </div>
    </div>
</div>
