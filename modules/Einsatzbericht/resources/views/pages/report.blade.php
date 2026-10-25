<x-layouts.app :title="'Einsatz '.$report->number">
    <livewire:einsatzbericht.report-editor :report="$report" :key="'report-'.$report->id" />
    {{-- Atemschutzüberwachung of this Einsatz, when that module is on and the Kamerad may use it --}}
    @if(app(\App\Services\ModuleRegistry::class)->isActive('wasserkarte') && auth()->user()->canUseModule('wasserkarte'))
        @include('wasserkarte::partials.report-section', ['report' => $report])
    @endif
    @if(app(\App\Services\ModuleRegistry::class)->isActive('objektplaene') && auth()->user()->canUseModule('objektplaene'))
        @include('objektplaene::partials.report-section', ['report' => $report])
    @endif
    @if(app(\App\Services\ModuleRegistry::class)->isActive('gefahrstoffe') && auth()->user()->canUseModule('gefahrstoffe'))
        @include('gefahrstoffe::partials.report-section', ['report' => $report])
    @endif
    @if(app(\App\Services\ModuleRegistry::class)->isActive('fahrtenbuch'))
        @include('fahrtenbuch::partials.report-section', ['report' => $report])
    @endif
    @if(app(\App\Services\ModuleRegistry::class)->isActive('expositionen') && auth()->user()->canUseModule('expositionen'))
        @include('expositionen::partials.report-section', ['report' => $report])
    @endif
    @if(app(\App\Services\ModuleRegistry::class)->isActive('atemschutz') && auth()->user()->canUseModule('atemschutz'))
        @include('atemschutz::partials.report-section', ['report' => $report, 'print' => false])
    @endif
</x-layouts.app>
