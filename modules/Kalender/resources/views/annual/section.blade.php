{{-- Jahresbericht: Termine of the year by type; attendance where it was taken --}}
<div class="ar-grid">
    <div class="ar-tile"><span>Termine</span><strong>{{ $byType->sum() }}</strong></div>
    <div class="ar-tile"><span>Mit Anwesenheit</span><strong>{{ $tracked }}</strong></div>
    <div class="ar-tile"><span>Teilnahmen</span><strong>{{ $attendances }}</strong></div>
    @if($tracked)<div class="ar-tile"><span>Ø Teilnehmer je Termin</span><strong>{{ round($attendances / $tracked, 1) }}</strong></div>@endif
</div>
@if($byType->isNotEmpty())
    <table><tbody>@foreach($byType as $type => $count)<tr><td>{{ \Modules\Kalender\src\Models\EventType::labelOf($type) }}</td><td class="n">{{ $count }}</td></tr>@endforeach</tbody></table>
@endif
<p class="note">Eigene Termine (ohne abgesagte und eingebundene externe Kalender), auch die Übungsdienste aus dem Übungsdienstplaner.</p>
