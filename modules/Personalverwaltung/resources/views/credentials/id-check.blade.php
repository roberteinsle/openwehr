{{-- What the checker sees of the Dienstausweis after the QR scan: the fields of the card, the
     Geburtsdatum only if the Personalverwaltung allows it --}}
<dl>
    @if($id['birth_on_check'] && $id['birth_date'])<dt>Geburtsdatum</dt><dd>{{ $id['birth_date']->format('d.m.Y') }}</dd>@endif
    @if($id['birth_place'])<dt>Geburtsort</dt><dd>{{ $id['birth_place'] }}</dd>@endif
    @if($id['issuer'])<dt>Ausstellende Behörde</dt><dd>{{ $id['issuer'] }}</dd>@endif
    <dt>{{ ($id['youth'] ?? false) ? 'Mitglied der Jugendfeuerwehr' : 'Mitglied der Feuerwehr' }}</dt><dd>{{ $id['brigade'] }}</dd>
    @if($id['user']->rank)<dt>Dienstgrad</dt><dd>{{ $id['user']->rank }}</dd>@endif
    @if($id['user']->groups->isNotEmpty())<dt>Abteilung</dt><dd>{{ $id['user']->groups->pluck('name')->implode(', ') }}</dd>@endif
    @if($id['number'])<dt>Ausweis-Nr.</dt><dd>{{ $id['number'] }}</dd>@endif
    <dt>Gültig bis</dt><dd style="{{ $id['expired'] ? 'color:#991b1b;font-weight:600' : '' }}">{{ $id['valid_until'] ? ($id['expired'] ? 'abgelaufen am ' : '').$id['valid_until']->format('d.m.Y') : 'ohne Ablaufdatum' }}</dd>
</dl>
