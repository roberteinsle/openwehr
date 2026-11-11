@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-3">
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('form')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    @if($registration?->isActive() && ! $editing)
        <div class="rounded-xl bg-green-50 p-3 text-sm text-green-900">
            <p><strong>{{ $member->name }} ist angemeldet</strong> – unterschrieben von {{ $registration->signer_name }} am {{ $registration->signed_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr.</p>
            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                @foreach($form->fields as $f)
                    <div><dt class="text-xs text-green-800/70">{{ $f['label'] }}</dt><dd>{{ $registration->answerLabel($f) }}</dd></div>
                @endforeach
            </dl>
            @if($form->acceptsRegistrations())
                <div class="mt-3 flex flex-wrap gap-3">
                    <button type="button" wire:click="$set('editing', true)" class="font-semibold text-fw-navy underline">Angaben ändern</button>
                    <button type="button" wire:click="withdraw" wire:confirm="{{ $member->name }} wirklich abmelden?" class="text-red-700 underline">Abmelden</button>
                </div>
            @endif
        </div>
    @elseif(! $self)
        <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Die Anmeldung machen deine Erziehungsberechtigten im Eltern-Bereich – sie haben dazu eine E-Mail bekommen.
            @if($registration?->status === 'cancelled') Zurzeit bist du abgemeldet.@endif</p>
    @elseif(! $form->acceptsRegistrations())
        <p class="text-sm text-gray-500">{{ $registration?->status === 'cancelled' ? 'Abgemeldet. ' : '' }}Die Anmeldung ist geschlossen.</p>
    @elseif($form->isFull() && ! $registration?->isActive())
        <p class="text-sm text-gray-500">Alle Plätze sind vergeben.</p>
    @else
        <form wire:submit="submit" class="space-y-4">
            @if($registration?->status === 'cancelled')<p class="text-sm text-gray-500">{{ $member->name }} ist zurzeit abgemeldet – hier wieder anmelden.</p>@endif
            @foreach($form->fields as $f)
                <div wire:key="f-{{ $f['key'] }}">
                    <label class="block text-sm font-medium text-gray-800">{{ $f['label'] }}@if($f['required'])<span class="text-fw-red"> *</span>@endif
                        @if($f['health'])<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-normal text-gray-600">Gesundheit – wird nach der Veranstaltung gelöscht</span>@endif</label>
                    @if($f['help'])<p class="text-xs text-gray-500">{{ $f['help'] }}</p>@endif
                    @switch($f['type'])
                        @case('textarea')<textarea wire:model="answers.{{ $f['key'] }}" rows="3" maxlength="2000" class="{{ $input }} mt-1"></textarea>@break
                        @case('phone')<input type="tel" wire:model="answers.{{ $f['key'] }}" maxlength="50" class="{{ $input }} mt-1">@break
                        @case('date')<input type="date" wire:model="answers.{{ $f['key'] }}" class="{{ $input }} mt-1">@break
                        @case('yesno')
                            <div class="mt-1 flex gap-4 text-sm">
                                <label class="flex items-center gap-1.5"><input type="radio" value="1" wire:model="answers.{{ $f['key'] }}" class="text-fw-red focus:ring-fw-red"> Ja</label>
                                <label class="flex items-center gap-1.5"><input type="radio" value="0" wire:model="answers.{{ $f['key'] }}" class="text-fw-red focus:ring-fw-red"> Nein</label>
                            </div>@break
                        @case('choice')
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                @foreach($f['options'] as $option)
                                    <label class="flex items-center gap-1.5"><input type="radio" value="{{ $option }}" wire:model="answers.{{ $f['key'] }}" class="text-fw-red focus:ring-fw-red"> {{ $option }}</label>
                                @endforeach
                            </div>@break
                        @default<input type="text" wire:model="answers.{{ $f['key'] }}" maxlength="300" class="{{ $input }} mt-1">
                    @endswitch
                    @error('answers.'.$f['key'])<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            @endforeach

            @if($form->consents !== [])
                <fieldset class="space-y-2 rounded-xl bg-gray-50 p-3">
                    <legend class="text-sm font-semibold text-gray-900">Einverständniserklärung</legend>
                    @foreach($form->consents as $c)
                        <label class="flex items-start gap-2 text-sm text-gray-800" wire:key="c-{{ $c['key'] }}">
                            <input type="checkbox" wire:model="consents.{{ $c['key'] }}" class="mt-0.5 rounded border-gray-300 text-fw-red focus:ring-fw-red">
                            <span>{{ $c['text'] }}</span>
                        </label>
                    @endforeach
                    @error('consents')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>
            @endif

            @if($form->require_signature)
                <div class="space-y-3">
                    @include('partials.signature-pad', ['model' => 'signature', 'label' => $asParent ? 'Unterschrift Erziehungsberechtigte(r)' : 'Unterschrift '.$member->name])
                    @error('signature')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @if($asParent && $form->member_signature)
                        @include('partials.signature-pad', ['model' => 'memberSignature', 'label' => 'Unterschrift '.$member->name])
                        @error('member_signature')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">{{ $registration?->isActive() ? 'Änderungen unterschreiben' : 'Verbindlich anmelden' }}</button>
                @if($editing)<button type="button" wire:click="$set('editing', false)" class="text-sm text-gray-600 underline">Abbrechen</button>@endif
            </div>
        </form>
    @endif
</div>
