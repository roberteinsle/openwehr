@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<div class="mx-auto max-w-4xl space-y-4">
    <a href="{{ route('personalverwaltung.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Kameraden</a>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('personal')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @error('history')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <x-avatar :user="$member" size="h-12 w-12" text="text-sm" />
                <div>
                    <h1 class="text-xl font-bold text-gray-900">{{ $member->name }}</h1>
                    <p class="text-sm text-gray-500">{{ $member->rank ?: 'ohne Dienstgrad' }} · {{ $member->status?->label() }}@if($years !== null) · {{ $years.' Dienstjahre' }}@endif</p>
                </div>
            </div>
            @if(! $member->hasLogin())
                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600" title="Ohne E-Mail-Adresse angelegt: in den Modulen geführt, aber kein Login und keine Mails">ohne Login</span>
            @elseif($canEdit && $member->is_active)
                <span class="flex flex-wrap gap-2">
                    @if($isAdmin || (! $member->is_admin && ! $member->last_login_at))<button type="button" wire:click="invite" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Einladung (QR-Code)</button>@endif
                    <button type="button" wire:click="mailInvite" wire:confirm="Einladung an {{ $member->email }} schicken?" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Einladung per Mail</button>
                </span>
            @endif
            @if($isAdmin)<a href="{{ \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $member]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Mitgliedsdaten bearbeiten</a>@endif
        </div>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-xs uppercase tracking-wide text-gray-500">Eintritt</dt><dd>{{ $member->joined_at?->format('d.m.Y') ?? '–' }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-gray-500">Abteilungen</dt><dd>{{ $member->groups->pluck('name')->implode(', ') ?: '–' }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-gray-500">Funktionen</dt><dd>{{ $member->functions->map->fullLabel()->implode(', ') ?: '–' }}</dd></div>
        </dl>
        @include('personalverwaltung::partials.service-time', ['member' => $member])
        <p class="text-xs text-gray-500">Dienstgrad, Abteilungen, Funktionen und Status ändern Admins unter Admin → Kameraden, Beförderungen und Funktionen auch Personalverwalter über die Sammelverarbeitung in der Liste; jede Änderung landet hier von selbst in der Diensthistorie.</p>
    </section>

    @if($inviteQr)
        <section class="space-y-2 rounded-2xl border border-fw-navy bg-white p-4 text-center shadow-sm">
            <img src="{{ $inviteQr }}" alt="QR-Code der Einladung" class="mx-auto h-48 w-48">
            <p class="text-xs text-gray-500">Mit dem Handy scannen – {{ $member->name }} ist angemeldet, ohne Passwort. Gilt bis {{ $invite['until'] }} Uhr und nur einmal.</p>
            <p class="break-all text-xs text-gray-600">{{ $invite['url'] }}</p>
        </section>
    @endif

    @if($fields && $canEdit)
        <section class="{{ $card }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-900">Persönliche Daten</h2>
                @if($member->birth_date && in_array('birth_date', $fields, true))<span class="text-xs text-gray-500">{{ (int) $member->birth_date->diffInYears(now('Europe/Berlin')) }} Jahre</span>@endif
            </div>
            <form wire:submit="savePersonalData" class="space-y-3">
                @include('personalverwaltung::partials.personal-fields', ['fields' => $fields, 'input' => $input])
                <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Speichern</button>
            </form>
            <p class="text-xs text-gray-500">Nur für Personalverwalter und den Kameraden selbst sichtbar.</p>
        </section>
    @endif

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Diensthistorie</h2>
        @include('personalverwaltung::partials.history', ['history' => $history, 'edit' => $canEdit])
        @if($canEdit)
<form wire:submit="addStage" class="space-y-2 border-t border-gray-100 pt-3">
            <p class="text-sm font-medium text-gray-700">Nachtragen (z. B. aus der Papierakte)</p>
            <p class="text-xs text-gray-500">Frühere Stationen mit „seit“ und „bis“. Ein <b>Ereignis</b> (Verpflichtung, Ernennung, Versetzung, Übertritt …) hat nur ein Datum („seit“). Eine <b>Dienstzeit in anderer Wehr</b> (z. B. „FF Nachbarstadt“) zählt zur anrechenbaren Dienstzeit.</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-5">
                <select wire:model="stage.kind" class="{{ $input }}">@foreach(\Modules\Personalverwaltung\src\Models\HistoryEntry::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                <input type="text" wire:model="stage.label" maxlength="200" placeholder="z. B. Oberfeuerwehrmann, Verpflichtung" class="{{ $input }} sm:col-span-2">
                <input type="date" wire:model="stage.since" title="seit" class="{{ $input }}">
                <input type="date" wire:model="stage.until" title="bis" class="{{ $input }}">
            </div>
            <input type="text" wire:model="stage.note" maxlength="300" placeholder="Bemerkung (optional)" class="{{ $input }}">
            @foreach(['history_kind', 'history_label', 'history_since', 'history_until'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Nachtragen</button>
        </form>
        @endif
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Ehrungen</h2>
        @foreach($due as $row)
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Fällig: {{ $row['type']->name }} ({{ $row['type']->after_years }} Dienstjahre am {{ $row['reached_on']->format('d.m.Y') }})</p>
        @endforeach
        <ul class="divide-y divide-gray-100">
            @forelse($honors as $h)
                <li class="flex flex-wrap items-start justify-between gap-2 py-1.5 text-sm" wire:key="o-{{ $h->id }}">
                    <span><span class="font-medium text-gray-900">{{ $h->title }}</span>@if($h->awarded_by)<span class="text-gray-500"> · {{ $h->awarded_by }}</span>@endif
                        @if($h->note)<span class="block text-xs text-gray-500">{{ $h->note }}</span>@endif</span>
                    <span class="flex items-center gap-3 text-xs text-gray-500">{{ $h->awarded_on->format('d.m.Y') }}
                        @if($canEdit)<button type="button" wire:click="deleteHonor({{ $h->id }})" wire:confirm="Ehrung löschen?" class="text-red-600 underline">Löschen</button>@endif</span>
                </li>
            @empty
                <li class="py-1.5 text-sm text-gray-500">Noch keine Ehrungen.</li>
            @endforelse
        </ul>
        @if($canEdit)
<form wire:submit="addHonor" class="space-y-2 border-t border-gray-100 pt-3">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <select wire:model="honor.honor_type_id" class="{{ $input }}"><option value="">– Ehrung wählen –</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
                <input type="text" wire:model="honor.title" maxlength="200" placeholder="oder frei benennen" class="{{ $input }}">
                <input type="date" wire:model="honor.awarded_on" class="{{ $input }}">
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <input type="text" wire:model="honor.awarded_by" maxlength="150" placeholder="verliehen von (optional)" class="{{ $input }}">
                <input type="text" wire:model="honor.note" maxlength="300" placeholder="Bemerkung (optional)" class="{{ $input }}">
            </div>
            @foreach(['honor_title', 'honor_date'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Ehrung eintragen</button>
        </form>
        @endif
    </section>

    @if($canEdit)
    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Erziehungsberechtigte</h2>
        <p class="text-xs text-gray-500">Bekommen die Kalender-Mails des Kameraden und sagen über einen Link für ihn zu oder ab. Mit ihrer Adresse melden sie sich im Eltern-Bereich an (Eltern-Login): Termine, Kalender-Abo, Ausrüstung und Anmeldungen des Kindes.</p>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($guardians as $g)
                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="g-{{ $g->id }}">
                    <span><span class="font-medium text-gray-900">{{ $g->name }}</span> <span class="text-gray-500">· {{ $g->email }}</span>
                        @if($g->phone) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $g->phone) }}" class="text-fw-navy underline">{{ $g->phone }}</a>@endif
                        <span class="block text-xs {{ $g->last_login_at ? 'text-green-700' : 'text-gray-400' }}">Eltern-Login: {{ $g->last_login_at ? 'zuletzt '.$g->last_login_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') : 'noch nie angemeldet' }}</span></span>
                    <button type="button" wire:click="deleteGuardian({{ $g->id }})" wire:confirm="Erziehungsberechtigte(n) entfernen?" class="text-xs text-red-600 underline">Entfernen</button>
                </li>
            @empty
                <li class="py-1.5 text-gray-500">Keine eingetragen.</li>
            @endforelse
        </ul>
        <form wire:submit="addGuardian" class="grid grid-cols-1 gap-2 border-t border-gray-100 pt-3 sm:grid-cols-4">
            <input type="text" wire:model="guardian.name" maxlength="150" placeholder="Name" class="{{ $input }}">
            <input type="email" wire:model="guardian.email" maxlength="255" placeholder="E-Mail" class="{{ $input }}">
            <input type="tel" wire:model="guardian.phone" maxlength="50" placeholder="Telefon (optional)" class="{{ $input }}">
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Hinzufügen</button>
            @foreach(['guardian_name', 'guardian_email', 'guardian_phone'] as $f)@error($f)<p class="text-sm text-red-600 sm:col-span-4">{{ $message }}</p>@enderror @endforeach
        </form>
    </section>
    @endif

    @if(app(\App\Services\ModuleRegistry::class)->isActive('unterweisungen'))
        @include('unterweisungen::partials.member-summary', ['member' => $member])
    @endif

    @if(app(\App\Services\ModuleRegistry::class)->isActive('fuehrerscheine'))
        @include('fuehrerscheine::partials.member-status', ['member' => $member])
    @endif

    @if(app(\App\Services\ModuleRegistry::class)->isActive('atemschutz'))
        @include('atemschutz::partials.member-status', ['member' => $member])
    @endif

    @if(app(\App\Services\ModuleRegistry::class)->isActive('lehrgaenge'))
        @include('lehrgaenge::partials.member-summary', ['member' => $member])
    @endif

    @if(app(\App\Services\ModuleRegistry::class)->isActive('schluessel'))
        @include('schluessel::partials.held-by', ['member' => $member])
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Dienstausweis</h2>
            @unless($canEdit)<p class="text-sm text-gray-700">{{ $idCard['number'] ?: 'ohne Nummer' }}{{ $idCard['valid_until'] ? ' · gültig bis '.\Illuminate\Support\Carbon::parse($idCard['valid_until'])->format('d.m.Y') : '' }}{{ $idCard['birth_place'] ? ' · geboren in '.$idCard['birth_place'] : '' }}</p>@endunless
            @if($canEdit)
<form wire:submit="saveIdCard" class="space-y-2">
                <div class="grid grid-cols-2 gap-2">
                    <label class="block text-xs text-gray-600">Ausweisnummer<input type="text" wire:model="idCard.number" maxlength="50" class="{{ $input }}"></label>
                    <label class="block text-xs text-gray-600">gültig bis<input type="date" wire:model="idCard.valid_until" class="{{ $input }}"></label>
                    <label class="col-span-2 block text-xs text-gray-600">Geburtsort (für den Ausweis)<input type="text" wire:model="idCard.birth_place" maxlength="100" class="{{ $input }}"></label>
                </div>
                @error('id_card_valid_until')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Speichern</button>
            </form>
        @endif
        </section>
        @if($hours->isNotEmpty())
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-900">Stunden {{ $year }}: {{ \App\Services\HoursRegistry::format($hours->sum()) }}</h2>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">@foreach($hours as $source => $minutes)<dt class="text-gray-500">{{ $source }}</dt><dd class="text-right">{{ \App\Services\HoursRegistry::format($minutes) }}</dd>@endforeach</dl>
                <a href="{{ route('personalverwaltung.hours') }}" class="text-xs text-fw-navy underline">Stundennachweis aller Kameraden</a>
            </section>
        @endif
        @if($attendance->isNotEmpty())
            <section class="{{ $card }}">
                <h2 class="text-sm font-semibold text-gray-900">Dienstbeteiligung</h2>
                @include('personalverwaltung::partials.attendance', ['attendance' => $attendance])
                <p class="text-xs text-gray-500">Übungen, Dienste und Arbeitsdienste im Kalender, für die der Kamerad eingeladen war und bei denen Anwesenheit erfasst wurde.</p>
            </section>
        @endif
    </div>
</div>
