<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Suche</h1>
        <p class="text-sm text-gray-500">Module öffnen und Termine, Mitteilungen, Objekte, Bestand und mehr finden – nur, was du sehen darfst.</p>
    </div>

    <form role="search" wire:submit="$refresh" class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <label for="search-q" class="sr-only">Suchbegriff</label>
        <div class="flex gap-2">
            <input id="search-q" type="search" wire:model.live.debounce.400ms="q" autofocus autocomplete="off" maxlength="{{ \App\Services\SearchRegistry::MAX_LENGTH }}"
                   placeholder="z. B. Kalender, Grillabend, HLF, Atemschutz"
                   class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            <button type="submit" class="shrink-0 rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Suchen</button>
        </div>
        @if($tooShort)<p class="mt-2 text-xs text-gray-500">Bitte mindestens {{ \App\Services\SearchRegistry::MIN_LENGTH }} Zeichen eingeben.</p>@endif
    </form>

    @if($ready)
        @if($code)
            <a href="{{ $code }}" class="flex items-center justify-between gap-3 rounded-2xl border border-fw-navy/20 bg-fw-navy/5 px-4 py-3 text-sm font-medium text-fw-navy hover:bg-fw-navy/10">
                <span>Code „{{ $term }}“ gefunden – öffnen</span><span aria-hidden="true">→</span>
            </a>
        @endif

        @if($pages->isNotEmpty())
            <section class="rounded-2xl border border-gray-100 bg-white shadow-sm">
                <h2 class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Module und Seiten</h2>
                <ul class="divide-y divide-gray-100">
                    @foreach($pages as $page)
                        <li>
                            <a href="{{ $page['url'] }}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10 text-fw-navy">
                                    @if($page['module'])<x-module-icon :key="$page['module']" class="h-4 w-4" />@else<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>@endif
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900">{{ $page['title'] }}</span>
                                    @if(filled($page['detail']))<span class="block truncate text-xs text-gray-500">{{ $page['detail'] }}</span>@endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @foreach($sections as $section)
            <section class="rounded-2xl border border-gray-100 bg-white shadow-sm">
                <h2 class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $section['label'] }}</h2>
                <ul class="divide-y divide-gray-100">
                    @foreach($section['rows'] as $row)
                        <li>
                            @if(filled($row['url'] ?? null))
                                <a href="{{ $row['url'] }}" class="block px-4 py-2.5 hover:bg-gray-50">
                            @else
                                <div class="px-4 py-2.5">
                            @endif
                                <span class="block text-sm font-medium text-gray-900">{{ $row['title'] }}</span>
                                @if(filled($row['detail'] ?? null))<span class="block truncate text-xs text-gray-500">{{ $row['detail'] }}</span>@endif
                            @if(filled($row['url'] ?? null))</a>@else</div>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if(! $code && $pages->isEmpty() && $sections->isEmpty())
            <p class="rounded-2xl border border-gray-100 bg-white px-4 py-6 text-center text-sm text-gray-500 shadow-sm">Nichts gefunden zu „{{ $term }}“.</p>
        @endif
    @endif
</div>
