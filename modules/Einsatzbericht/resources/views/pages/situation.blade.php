<x-layouts.app :title="'Lage '.($report->keyword ?: $report->number)">
    <div class="mx-auto max-w-2xl space-y-4">
        <div>
            <a href="{{ \Modules\Einsatzbericht\src\Services\ReportService::canLead(auth()->user()) ? route('einsatzbericht.show', $report) : route('einsatzbericht.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← {{ \Modules\Einsatzbericht\src\Services\ReportService::canLead(auth()->user()) ? 'Bericht' : 'Laufende Einsätze' }}</a>
            <h1 class="mt-1 text-xl font-bold text-gray-900">Lagemeldungen · {{ $report->keyword ?: 'Einsatz '.$report->number }}</h1>
            <p class="text-sm text-gray-500">{{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $report->address ? ' · '.$report->address : '' }}@if($report->navigationUrl()) · <a href="{{ $report->navigationUrl() }}" target="_blank" rel="noopener" class="underline">Navigation</a>@endif</p>
        </div>
        {{-- Lagemonitor: the nearest Löschwasser, when the Wasserkarte is on --}}
        @if(app(\App\Services\ModuleRegistry::class)->isActive('wasserkarte') && auth()->user()->canUseModule('wasserkarte'))
            @include('wasserkarte::partials.report-section', ['report' => $report, 'class' => ''])
        @endif
        {{-- Lagemonitor: the plans of the object at the Einsatzstelle, when the Objektpläne are on --}}
        @if(app(\App\Services\ModuleRegistry::class)->isActive('objektplaene') && auth()->user()->canUseModule('objektplaene'))
            @include('objektplaene::partials.report-section', ['report' => $report, 'class' => ''])
        @endif
        {{-- Lagemonitor: the Gefahrstoff named in the report and its Warntafel, when Gefahrstoffe is on --}}
        @if(app(\App\Services\ModuleRegistry::class)->isActive('gefahrstoffe') && auth()->user()->canUseModule('gefahrstoffe'))
            @include('gefahrstoffe::partials.report-section', ['report' => $report, 'class' => ''])
        @endif
        <livewire:einsatzbericht.situation-log :report="$report" :key="'log-'.$report->id" />
    </div>
</x-layouts.app>
