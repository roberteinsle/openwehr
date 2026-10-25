{{-- The card is no link itself: the place has its own "Navigation" link, and links can't be nested.
     With access, the title opens the Termin and its ::after covers the whole card; the Navigation link sits above it. --}}
@php($canOpen ??= true)
<div class="relative flex h-full flex-col bg-white rounded-2xl border border-gray-100 shadow-sm p-5 {{ $canOpen ? 'hover:border-fw-navy hover:shadow-md transition-all' : '' }}">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nächster Termin</p>
    <p class="mt-1 font-semibold text-gray-900">
        <span class="mr-1 inline-block rounded border px-1.5 py-0.5 text-xs" style="{{ $event->colorStyle() }}">{{ $event->typeLabel() }}</span>
        @if($canOpen)
            <a href="{{ route('kalender.show', $event) }}" class="after:absolute after:inset-0 after:rounded-2xl after:content-['']">{{ $event->title }}</a>
        @else
            {{ $event->title }}
        @endif
    </p>
    <p class="mt-1 text-sm text-gray-600">{{ $event->timeLabel() }}</p>
    <x-place-info :place="$event->place" :location="$event->location" class="relative z-10 text-sm text-gray-500" />
    <x-dress-codes :ids="$event->dress_code_ids" class="text-sm text-gray-500" />
    @if($event->rsvp && $canOpen)
        <p class="mt-2 text-sm {{ $response?->status ? 'text-gray-700' : 'font-semibold text-fw-red' }}">
            {{ $response?->status ? 'Deine Rückmeldung: '.$response->label() : ($event->acceptsResponses() ? 'Bitte Rückmeldung geben' : 'Rückmeldung geschlossen') }}
        </p>
    @endif
    <div class="mt-auto flex justify-end pt-3"><x-countdown :at="$event->starts_at" :all-day="$event->all_day" /></div>
</div>
