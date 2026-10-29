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
                                    </span>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3 text-sm">
                                <a href="{{ route('mitteilungen.edit', $a) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 font-medium text-fw-navy hover:border-fw-navy">Bearbeiten</a>
                                <button type="button" wire:click="delete({{ $a->id }})" wire:confirm="Mitteilung „{{ $a->title }}“ löschen?" class="text-red-600 underline">Löschen</button>
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
