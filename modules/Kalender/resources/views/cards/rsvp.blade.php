{{-- Start page: the Kamerad's Rückmeldungen – open ones with Zusagen/Absagen right here, the others with their answer --}}
@php($badge = ['yes' => 'bg-green-100 text-green-800', 'maybe' => 'bg-amber-100 text-amber-800', 'no' => 'bg-gray-100 text-gray-700'])
<section class="rounded-2xl border {{ $open > 0 ? 'border-fw-red/40' : 'border-gray-100' }} bg-white p-4 shadow-sm" data-rsvp-card>
    <div class="flex items-center justify-between gap-3">
        <p class="text-xs font-bold uppercase tracking-wide {{ $open > 0 ? 'text-fw-red' : 'text-gray-500' }}">Rückmeldungen</p>
        @if($open > 0)
            <span class="rounded-full bg-fw-red px-2 py-0.5 text-xs font-semibold text-white">{{ $open }} offen</span>
        @else
            <span class="text-xs text-gray-500">alles beantwortet</span>
        @endif
    </div>
    <ul class="mt-2 divide-y divide-gray-100">
        @foreach($rows->take(6) as $row)
            @php($event = $row['event'])
            @php($response = $row['response'])
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <a href="{{ route('kalender.show', $event) }}" class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-gray-900">{{ $event->title }}</span>
                    <span class="block text-xs text-gray-500">{{ $event->timeLabel() }}@if($event->pastDeadline()) · Frist vorbei@elseif($event->rsvp_deadline) · bitte bis {{ $event->rsvp_deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.') }}@endif</span>
                </a>
                @if($response?->status)
                    <span class="flex items-center gap-2">
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $badge[$response->status] ?? '' }}">{{ $response->label() }}{{ $response->guardian_status ? ' (Eltern)' : '' }}</span>
                        <a href="{{ route('kalender.show', $event) }}" class="text-xs text-fw-navy underline">ändern</a>
                    </span>
                @else
                    <span class="flex items-center gap-1.5">
                        @foreach(['yes' => ['Zusagen', 'bg-green-600 text-white hover:bg-green-700'], 'no' => ['Absagen', 'border border-gray-300 text-gray-700 hover:bg-gray-50']] as $status => [$label, $class])
                            <form method="POST" action="{{ route('kalender.respond', $event) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $status }}">
                                <button type="submit" class="rounded-lg px-3 py-1 text-xs font-semibold {{ $class }}">{{ $label }}</button>
                            </form>
                        @endforeach
                    </span>
                @endif
            </li>
        @endforeach
    </ul>
    @if($rows->count() > 6)<a href="{{ route('kalender.index', ['ansicht' => 'liste']) }}" class="text-xs text-fw-navy underline">{{ $rows->count() - 6 }} weitere im Kalender</a>@endif
    @if(session('kalender.rsvp_error'))<p class="mt-1 text-sm text-red-600">{{ session('kalender.rsvp_error') }}</p>@endif
</section>
