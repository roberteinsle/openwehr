<x-layouts.app title="Mitteilungen">
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-gray-900">Mitteilungen</h1>
            @can('post-announcements')
                <a href="{{ route('mitteilungen.manage') }}" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Mitteilungen verwalten</a>
            @endcan
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <a href="{{ route('mitteilungen.index') }}" class="rounded-full px-3 py-1 {{ ! $archive && ! request()->boolean('wichtig') ? 'bg-fw-navy text-white' : 'border border-gray-200 bg-white text-gray-700' }}">Aktuell</a>
            <a href="{{ route('mitteilungen.index', ['wichtig' => 1]) }}" class="rounded-full px-3 py-1 {{ ! $archive && request()->boolean('wichtig') ? 'bg-fw-navy text-white' : 'border border-gray-200 bg-white text-gray-700' }}">Nur wichtige</a>
            <a href="{{ route('mitteilungen.index', ['ansicht' => 'archiv']) }}" class="rounded-full px-3 py-1 {{ $archive ? 'bg-fw-navy text-white' : 'border border-gray-200 bg-white text-gray-700' }}">Archiv</a>
        </div>
        <p class="text-sm text-gray-500">{{ $archive ? 'Mitteilungen, deren Zeitraum vorbei ist.' : 'Alle aktuellen Mitteilungen für dich, auch die auf der Startseite ausgeblendeten.' }}</p>

        @forelse($announcements as $announcement)
            <article class="rounded-2xl border p-4 {{ $announcement->important ? 'border-red-300 bg-red-50' : 'border-gray-100 bg-white shadow-sm' }}">
                <p class="font-semibold {{ $announcement->important ? 'text-red-900' : 'text-gray-900' }}">
                    @if($announcement->important)<span class="mr-1 inline-block rounded bg-red-600 px-1.5 py-0.5 text-xs font-bold uppercase text-white">Wichtig</span>@endif
                    {{ $announcement->title }}
                </p>
                @if(filled($announcement->body))<div class="md mt-1 text-sm {{ $announcement->important ? 'text-red-900' : 'text-gray-700' }}">{{ \App\Support\Markdown::html($announcement->body) }}</div>@endif
                @include('mitteilungen::partials.extras', ['announcement' => $announcement, 'acknowledged' => $acknowledged])
                <p class="mt-2 text-xs text-gray-500">
                    {{ $announcement->author?->name ?? 'Wehrführung' }} · {{ ($announcement->visible_from ?? $announcement->created_at)->setTimezone('Europe/Berlin')->format('d.m.Y') }}
                    @if($announcement->visible_until) · bis {{ $announcement->visible_until->setTimezone('Europe/Berlin')->format('d.m.Y') }}@endif
                    @if(in_array($announcement->id, $dismissed, true)) · auf der Startseite ausgeblendet @endif
                </p>
            </article>
        @empty
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">{{ $archive ? 'Keine abgelaufenen Mitteilungen.' : 'Zurzeit gibt es keine Mitteilungen.' }}</div>
        @endforelse
    </div>
</x-layouts.app>
