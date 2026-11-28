{{-- Digitaler Dienstausweis on the phone, laid out like the official card (ID-1 format, photo left,
     fields right, band at the bottom) but in the colours and with the logo of the Wehr, without
     coats of arms: it is the digital copy, checked by its QR code. $id from PersonnelService::digitalIdFor().
     Sizes in cqw, so the card scales with its width. --}}
@php($org = app(\App\Services\OrganizationSettings::class))
@php($u = $id['user'])
@php($fields = [
    ['Geburtsdatum', $id['birth_date']?->format('d.m.Y')],
    ['Geburtsort', $id['birth_place']],
    ['Ausstellende Behörde', $id['issuer']],
    ['Ausweisnummer', $id['number']],
    [($id['youth'] ?? false) ? 'Mitglied der Jugendfeuerwehr' : 'Mitglied der Feuerwehr', $id['brigade']],
    ['Gültig bis', $id['valid_until']?->format('m.Y'), $id['expired']],
])
<div class="mx-auto w-full max-w-md">
    <div class="@container relative aspect-[856/540] w-full overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-black/10" aria-label="{{ ($id['youth'] ?? false) ? 'Jugendfeuerwehr-Dienstausweis' : 'Dienstausweis' }} {{ $u->name }}">
        {{-- Upper part in the red of the Wehr; the Jugendfeuerwehr card in the colour of its Abteilung --}}
        @if(($id['youth'] ?? false) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $id['youth_color']))
            <div class="absolute inset-x-0 top-0 h-[69%]" style="background: linear-gradient(135deg, {{ $id['youth_color'] }}, color-mix(in srgb, {{ $id['youth_color'] }} 75%, black))"></div>
        @else
            <div class="absolute inset-x-0 top-0 h-[69%] bg-gradient-to-br from-fw-red via-fw-red to-fw-red-dark"></div>
        @endif
        <div class="absolute inset-x-0 top-[69%] h-[1.2%] bg-white"></div>
        {{-- Photo --}}
        <div class="absolute left-[5%] top-[8%] h-[55%] w-[24%] border-[0.8cqw] border-white bg-gray-200 shadow">
            @if($u->avatar)
                <img src="data:{{ $u->avatar->mime }};base64,{{ $u->avatar->data }}" alt="" class="h-full w-full object-cover">
            @else
                <svg class="h-full w-full text-gray-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="9" r="4.2"/><path d="M3.5 22c.8-4.6 4.2-7 8.5-7s7.7 2.4 8.5 7z"/></svg>
            @endif
        </div>
        {{-- Name, rank and Abteilung --}}
        <div class="absolute left-[32%] right-[4%] top-[8%] flex h-[15%] flex-col justify-center bg-white px-[2.5cqw]">
            <p class="truncate text-[5cqw] font-semibold leading-tight text-gray-900">{{ $u->name }}</p>
            <p class="truncate text-[2.6cqw] leading-tight text-gray-600">{{ collect([$u->rank, $u->groups->pluck('name')->implode(', ')])->filter()->implode(' · ') }}</p>
        </div>
        {{-- Fields like on the official card --}}
        <dl class="absolute left-[32%] right-[4%] top-[25.5%] grid h-[37.5%] grid-cols-[1.75fr_1fr] content-center gap-x-[2.5cqw] gap-y-[1.2cqw] bg-white/95 px-[2.5cqw]">
            @foreach($fields as $field)
                <div class="min-w-0">
                    <dt class="truncate text-[2.3cqw] leading-tight text-gray-600">{{ $field[0] }}</dt>
                    <dd title="{{ $field[1] }}" class="truncate text-[2.9cqw] font-medium leading-tight {{ ($field[2] ?? false) ? 'text-red-700' : 'text-gray-900' }}">{{ $field[1] ?: '–' }}{{ ($field[2] ?? false) ? ' (abgelaufen)' : '' }}</dd>
                </div>
            @endforeach
        </dl>
        {{-- Band at the bottom: logo of the Wehr and what it is --}}
        <div class="absolute inset-x-0 bottom-0 flex h-[29.8%] items-center justify-between bg-gradient-to-r from-fw-navy to-[#2b3d6e] px-[5%] text-white">
            <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="" class="h-[55%] w-auto max-w-[35%] object-contain brightness-0 invert">
            <div class="text-right leading-none">
                <p class="{{ ($id['youth'] ?? false) ? 'text-[5cqw]' : 'text-[6.4cqw]' }} font-bold tracking-wide">{{ ($id['youth'] ?? false) ? 'JUGENDFEUERWEHR' : 'FEUERWEHR' }}</p>
                <p class="mt-[0.8cqw] text-[4.6cqw] tracking-wide">DIENSTAUSWEIS</p>
            </div>
            <p class="absolute inset-x-0 bottom-[4%] text-center text-[1.9cqw] text-white/70">Digitale Ausfertigung · Echtheit per QR-Code prüfen</p>
        </div>
    </div>
    @if($id['expired'])<p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">Dieser Dienstausweis ist am {{ $id['valid_until']->format('d.m.Y') }} abgelaufen.</p>@endif
    @unless($u->avatar)<p class="mt-2 text-xs text-gray-500">Tipp: Lade unter Profil ein Foto hoch, dann steht es auf dem Ausweis.</p>@endunless
</div>
