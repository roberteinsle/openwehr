{{-- Who answered: the Erziehungsberechtigten's answer (counts) next to the child's own, and a marker when they differ --}}
@if($r?->guardian_status)
    <span class="block text-xs text-gray-500">
        Eltern ({{ $r->guardian_name }}): {{ \Modules\Kalender\src\Models\Response::labelOf($r->guardian_status) }}@if($r->guardian_comment) – {{ $r->guardian_comment }}@endif
        @if($r->own_status) · selbst: {{ \Modules\Kalender\src\Models\Response::labelOf($r->own_status) }}@endif
    </span>
    @if($r->mismatch())<span class="mt-0.5 inline-block rounded bg-red-50 px-1.5 py-0.5 text-[11px] font-semibold text-red-700">passt nicht zusammen</span>@endif
@endif
