<div class="space-y-6">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-bold text-gray-900">Mitteilungen verwalten</h1>
            <p class="text-sm text-gray-500">Erscheinen auf der Startseite. Wichtige Mitteilungen stehen rot oben und lassen sich nicht ausblenden.</p>
        </div>
        <x-page-action :href="route('mitteilungen.create')">Neue Mitteilung</x-page-action>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    @php($any = collect($sections)->flatten(1)->isNotEmpty())
    @unless($any)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">Noch keine Mitteilungen. Mit „Neue Mitteilung“ schreibst du die erste.</div>
    @endunless

    @foreach($sections as $heading => $list)
        @continue($list->isEmpty())
        <section>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ $heading }} <span class="font-normal">({{ $list->count() }})</span></h2>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                @foreach($list as $a)
                    <div class="p-4" wire:key="a-{{ $a->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('mitteilungen.edit', $a) }}" class="font-semibold text-gray-900 hover:text-fw-red">{{ $a->title }}</a>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                    @if($a->important)<span class="rounded bg-red-600 px-1.5 py-0.5 font-bold uppercase text-white">Wichtig</span>@endif
                                    @if($a->needs_ack)<span class="rounded bg-fw-navy/10 px-1.5 py-0.5 font-medium text-fw-navy">Lesebestätigung</span>@endif
                                    @if($a->files->isNotEmpty())<span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700">📎 {{ $a->files->count() }}</span>@endif
                                    <span class="text-gray-500">
                                        {{ $a->periodLabel() }}
                                        · {{ $a->groups->isEmpty() && $a->functions->isEmpty() ? 'alle Kameraden' : $a->groups->pluck('name')->merge($a->functions->pluck('name'))->implode(', ') }}
                                        · {{ $a->author?->name ?? 'Wehrführung' }}
                                        @if($a->archived_at) · archiviert am {{ $a->archived_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}@elseif($a->isArchived()) · Zeitraum vorbei @endif
                                    </span>
                                </div>
                            </div>
                            {{-- Icons with a tooltip and a label for screen readers --}}
                            <div class="flex shrink-0 items-center gap-1.5">
                                <a href="{{ route('mitteilungen.edit', $a) }}" title="Bearbeiten" aria-label="„{{ $a->title }}“ bearbeiten" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:border-fw-navy hover:text-fw-navy">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.2 5.2l3.6 3.6M4 20l4.5-1 10.3-10.3a2.5 2.5 0 00-3.5-3.5L5 15.5 4 20z"/></svg>
                                </a>
                                @if($a->archived_at)
                                    <button type="button" wire:click="restore({{ $a->id }})" title="Wiederherstellen" aria-label="„{{ $a->title }}“ wiederherstellen" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:border-fw-navy hover:text-fw-navy">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3"/></svg>
                                    </button>
                                @elseif(! $a->isArchived())
                                    <button type="button" wire:click="archive({{ $a->id }})" wire:confirm="„{{ $a->title }}“ archivieren? Sie verschwindet von der Startseite und steht im Archiv." title="Archivieren" aria-label="„{{ $a->title }}“ archivieren" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:border-fw-navy hover:text-fw-navy">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4"/></svg>
                                    </button>
                                @endif
                                <button type="button" wire:click="delete({{ $a->id }})" wire:confirm="Mitteilung „{{ $a->title }}“ endgültig löschen?" title="Löschen" aria-label="„{{ $a->title }}“ löschen" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-red-600 hover:border-red-400 hover:bg-red-50">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3"/></svg>
                                </button>
                            </div>
                        </div>
                        <button type="button" wire:click="showStats({{ $a->id }})" class="mt-2 text-xs text-fw-navy underline">{{ $statsFor === $a->id ? 'Ausblenden' : ($a->needs_ack ? 'Lesebestätigungen' : 'Gelesen von') }}</button>
                        @if($statsFor === $a->id && $stats)
                            <div class="mt-1 rounded-lg bg-gray-50 p-2 text-xs text-gray-700">
                                <p>{{ $stats['read'] }} von {{ $stats['recipients'] }} gelesen{{ $a->needs_ack ? ', '.$stats['acknowledged'].' bestätigt' : '' }}</p>
                                @if($stats['missing']->isNotEmpty())
                                    <p class="mt-1">{{ $a->needs_ack ? 'Noch nicht bestätigt' : 'Noch nicht gelesen' }}: {{ $stats['missing']->pluck('name')->implode(', ') }}</p>
                                    <button type="button" wire:click="remind({{ $a->id }})" wire:confirm="{{ $stats['missing']->count() }} Kameraden erinnern (Glocke, Push, Mail)?" class="mt-1 text-fw-navy underline">Erinnern</button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
