{{-- Start page: the next Übungsdienst. The Vorstand opens the plan and sees topic and who is responsible;
     every other Kamerad sees date, time and place (the topic stays in the plan, like in the Kalender). --}}
@php($tag = $planner ? 'a' : 'div')
<{{ $tag }} @if($planner) href="{{ route('uebungsdienste.index') }}" @endif class="flex h-full flex-col bg-white rounded-2xl border border-gray-100 shadow-sm p-5 {{ $planner ? 'hover:border-fw-navy' : '' }}">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nächster Übungsdienst</p>
    @if(($withAudience ?? false) && $service->audienceLabel())
        <p class="mt-1"><x-audience-chip :label="'Für: '.$service->audienceLabel()" /></p>
    @endif
    <p class="mt-1 font-semibold text-gray-900">{{ $service->dateLabel() }}{{ $service->starts_at ? ' · '.$service->starts_at.' Uhr' : '' }}</p>
    @if($planner)
        <p class="text-sm text-gray-700">{{ $service->topic ?: 'Thema noch offen' }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ $service->responsible->isNotEmpty() ? $service->responsible->pluck('name')->implode(', ') : 'Noch niemand verantwortlich' }}{{ $service->location ? ' · '.$service->location : '' }}</p>
    @else
        @if($service->location || $service->place_id)<x-place-info :place="$service->place" :location="$service->location" class="text-sm text-gray-500" />@endif
        @if($service->dress_code_ids)<x-dress-codes :ids="$service->dress_code_ids" class="text-sm text-gray-500" />@endif
    @endif
    <div class="mt-auto flex justify-end pt-3"><x-countdown :at="$service->period()[0] ?? \Illuminate\Support\Carbon::parse($service->date->toDateString(), 'Europe/Berlin')" :all-day="! $service->starts_at" /></div>
</{{ $tag }}>
