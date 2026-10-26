<div class="max-w-3xl mx-auto space-y-4">
    <a href="{{ route('kalender.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Kalender</a>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <span class="inline-block rounded border px-1.5 py-0.5 text-xs" style="{{ $event->colorStyle() }}">{{ $event->typeLabel() }}</span>
                @if($event->isCancelled())<span class="ml-1 inline-block rounded bg-red-600 px-1.5 py-0.5 text-xs font-bold text-white">Abgesagt</span>@endif
                <h1 class="mt-1 text-xl font-bold text-gray-900 {{ $event->isCancelled() ? 'line-through' : '' }}">{{ $event->title }}</h1>
                <p class="text-sm text-gray-600">{{ $event->timeLabel() }}</p>
                <x-place-info :place="$event->place" :location="$event->location" />
                @if($event->partners)<p class="text-sm text-gray-600">Gemeinsam mit {{ $event->partners }}</p>@endif
                @if($event->organizer)<p class="text-sm text-gray-600">Ausführend: {{ $event->organizer }}</p>@endif
                <x-dress-codes :ids="$event->dress_code_ids" />
                @if($event->managed_by && isset(\Modules\Kalender\src\Models\Event::MANAGED_BY[$event->managed_by]))
                    <p class="mt-1 text-xs text-gray-500">Kommt aus dem {{ \Modules\Kalender\src\Models\Event::MANAGED_BY[$event->managed_by] }} – Zeit, Ort, Kleidung und Zielgruppe werden dort gepflegt und hier übernommen.</p>
                @endif
                <p class="mt-1 text-xs text-gray-500">
                    Für: {{ $event->groups->isEmpty() && $event->invitees->isEmpty() ? 'alle' : $event->groups->pluck('name')->concat($event->invitees->pluck('name'))->implode(', ') }}
                    @if($event->is_public) · öffentlich @endif
                    @if($event->isExternal()) · aus „{{ $event->source?->name }}“ @endif
                    @if($event->series_id) · Teil einer Terminreihe @endif
                </p>
            </div>
            @if($manager)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('kalender.edit', $event) }}" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Bearbeiten</a>
                    @if($event->isCancelled())
                        <button type="button" wire:click="reinstate" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Doch stattfinden lassen</button>
                    @else
                        <button type="button" wire:click="cancelEvent" wire:confirm="Termin absagen? Wer zugesagt hat, bekommt eine Mail." class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Absagen</button>
                    @endif
                    <button type="button" wire:click="deleteEvent(false)" wire:confirm="Diesen Termin löschen?" class="text-sm text-red-600 underline">Löschen</button>
                    @if($event->series_id)
                        <button type="button" wire:click="deleteEvent(true)" wire:confirm="Diesen und alle folgenden Termine der Reihe löschen?" class="text-sm text-red-600 underline">Mit Folgeterminen löschen</button>
                    @endif
                </div>
            @endif
        </div>
        @if($event->description)<p class="mt-4 text-sm text-gray-800 whitespace-pre-line">{{ $event->description }}</p>@endif
    </div>

    @if($event->rsvp && $isAudience)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
            <h2 class="text-sm font-semibold text-gray-900">Deine Rückmeldung
                @if($event->rsvp_deadline)<span class="font-normal {{ $event->pastDeadline() && ! $mine?->status ? 'text-fw-red' : 'text-gray-500' }}">(bitte bis {{ $event->rsvp_deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr)</span>@endif
            </h2>
            @if($event->acceptsResponses())
                <input type="text" wire:model="comment" maxlength="500" placeholder="Kommentar (optional), z. B. komme später" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                <div class="flex flex-wrap gap-2">
                    @foreach(['yes' => ['Zusagen', 'bg-green-600'], 'maybe' => ['Vielleicht', 'bg-amber-500'], 'no' => ['Absagen', 'bg-gray-600']] as $status => [$label, $color])
                        <button type="button" wire:click="respond('{{ $status }}')"
                                class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-white {{ $color }} {{ $mine?->status === $status ? 'ring-2 ring-offset-2 ring-gray-900' : 'opacity-80 hover:opacity-100' }}">{{ $label }}</button>
                    @endforeach
                </div>
            @endif
            <p class="text-sm text-gray-600">
                @if($mine?->status)
                    Du hast <strong class="{{ ['yes' => 'text-green-700', 'no' => 'text-gray-900', 'maybe' => 'text-amber-700'][$mine->status] ?? '' }}">{{ $mine->label() }}</strong>
                    – am {{ $mine->updated_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') }} um {{ $mine->updated_at->copy()->setTimezone('Europe/Berlin')->format('H:i') }} Uhr{{ $event->rsvp_deadline && $mine->updated_at->gt($event->rsvp_deadline) ? ' (nach der Frist)' : '' }}{{ $mine->set_by && $mine->set_by !== auth()->id() ? ', eingetragen von '.($mine->setter?->name ?? 'der Terminverwaltung') : '' }}.
                    @if($event->acceptsResponses()) Ändern geht bis zum Beginn.@endif
                @else
                    {{ $event->acceptsResponses() ? 'Du hast noch nicht geantwortet.' : 'Eine Rückmeldung ist nicht mehr möglich.' }}
                @endif
            </p>
            @error('status')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    @endif

    @if($summary)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h2 class="text-sm font-semibold text-gray-900">Rückmeldungen: {{ $summary['yes']->count() }} zugesagt · {{ $summary['maybe']->count() }} vielleicht · {{ $summary['no']->count() }} abgesagt · {{ $summary['open']->count() }} offen</h2>
            @if($event->min_participants)
                <p class="mt-1 text-sm {{ $summary['missing'] > 0 ? 'font-semibold text-red-700' : 'text-green-700' }}">
                    {{ $summary['missing'] > 0 ? 'Es fehlen noch '.$summary['missing'].' von mindestens '.$event->min_participants.' Zusagen.' : 'Mindestbesetzung von '.$event->min_participants.' erreicht.' }}
                </p>
            @endif
            <div class="mb-3"></div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                @foreach(['yes' => 'Zugesagt', 'maybe' => 'Vielleicht', 'no' => 'Abgesagt'] as $key => $title)
                    @if($summary[$key]->isNotEmpty())
                        <div>
                            <p class="font-medium text-gray-700">{{ $title }}</p>
                            <ul class="text-gray-600">@foreach($summary[$key] as $r)<li>{{ $r->user->name }}@if($r->comment) <span class="text-gray-400">– {{ $r->comment }}</span>@endif</li>@endforeach</ul>
                        </div>
                    @endif
                @endforeach
                @if($manager && $summary['open']->isNotEmpty())
                    <div>
                        <p class="font-medium text-gray-700">Noch keine Antwort</p>
                        <ul class="text-gray-500">@foreach($summary['open'] as $u)<li>{{ $u->name }}</li>@endforeach</ul>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if($attendance)
        {{-- "Anwesenheit abschließen": the list goes to the Wehrführung, otherwise automatically 24 hours after the end --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Anwesenheit an die Wehrführung</h2>
            @error('attendance')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @if(! $event->attendance_closed_at)
                <p class="text-sm text-gray-600">Mit „Anwesenheit abschließen“ geht die Liste per E-Mail (mit CSV) an: {{ $attendance['recipients'] }}. Ohne Abschluss geht sie 24 Stunden nach dem Ende automatisch raus.</p>
                @if($attendance['mayClose'])
                    <button type="button" wire:click="closeAttendance" wire:confirm="Anwesenheit jetzt abschließen und die Liste verschicken?" class="rounded-xl bg-fw-red px-4 py-2 text-sm font-semibold text-white">Anwesenheit abschließen</button>
                @else
                    <p class="text-xs text-gray-500">Abschließen können der Wehrführer und seine Stellvertreter.</p>
                @endif
            @else
                <p class="text-sm text-gray-600">Abgeschlossen am {{ $event->attendance_closed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr{{ $event->attendanceClosedBy ? ' von '.$event->attendanceClosedBy->name : '' }}.</p>
                @if($event->attendance_changed_at)
                    <p class="text-sm font-semibold text-amber-700">Seit dem Abschluss geändert.</p>
                    @if($attendance['mayClose'])
                        <button type="button" wire:click="sendAttendanceCorrection" wire:confirm="Die geänderte Liste als Korrektur verschicken?" class="rounded-xl bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Korrektur senden</button>
                    @endif
                @endif
            @endif
        </div>
    @endif

    @if($recorder && ! $event->isExternal())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-sm font-semibold text-gray-900">Teilnehmer verwalten ({{ $people->count() }})</h2>
                @if($manager)
                    <a href="{{ route('kalender.participants', $event) }}" class="text-sm text-fw-navy underline">Liste als CSV</a>
                    <a href="{{ route('kalender.participants.print', $event) }}" target="_blank" class="ml-3 text-sm text-fw-navy underline">Liste drucken</a>
                @endif
            </div>
            <p class="mb-3 text-xs text-gray-500">Rückmeldungen lassen sich hier für andere eintragen, etwa nach einem Anruf, auch nach der Frist. Die Anwesenheit ist ab Beginn des Termins erfassbar.@if($event->hasStarted()) Anwesend: {{ $summary ? $summary['attended']->count() : $responses->where('attended', true)->count() }}.@endif</p>
            @error('attended')<p class="mb-2 text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500"><th class="py-1 pr-3">Name</th>@if($event->rsvp)<th class="py-1 pr-3">Rückmeldung</th>@endif @if($event->hasStarted())<th class="py-1">Anwesend</th>@endif</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($people as $person)
                            @php($r = $responses->get($person->id))
                            <tr wire:key="pp-{{ $person->id }}">
                                <td class="py-1.5 pr-3">{{ $person->name }}@if($r?->comment)<span class="block text-xs text-gray-500">{{ $r->comment }}</span>@endif</td>
                                @if($event->rsvp)
                                    <td class="py-1.5 pr-3">
                                        @if($manager)
                                            <select wire:change="respondFor({{ $person->id }}, $event.target.value)" class="rounded-md border border-gray-300 px-2 py-1 text-sm">
                                                <option value="" @selected(! $r?->status)>– offen –</option>
                                                @foreach(\Modules\Kalender\src\Models\Response::STATUSES as $value => $label)<option value="{{ $value }}" @selected($r?->status === $value)>{{ $label }}</option>@endforeach
                                            </select>
                                        @else
                                            {{ $r?->status ? $r->label() : 'offen' }}
                                        @endif
                                    </td>
                                @endif
                                @if($event->hasStarted())
                                    <td class="py-1.5"><input type="checkbox" @checked($r?->attended) wire:change="setAttendance({{ $person->id }}, $event.target.checked)" class="rounded border-gray-300 text-fw-red focus:ring-fw-red" aria-label="{{ $person->name }} anwesend"></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
