{{-- Start page: the current notices, important ones first; orange so they stand out from the other cards --}}
<section>
    <div class="flex items-center justify-between gap-3 mb-3">
        <h2 class="text-lg font-semibold text-gray-900">Mitteilungen</h2>
        <a href="{{ $canPost ? route('mitteilungen.manage') : route('mitteilungen.index') }}" class="text-sm text-fw-navy underline">{{ $canPost ? 'Mitteilungen verwalten' : 'Alle Mitteilungen' }}</a>
    </div>
    @foreach($announcements as $announcement)
        <article class="mb-3 rounded-2xl p-4 shadow-sm text-white {{ $announcement->important ? 'bg-fw-red-dark' : 'bg-fw-orange' }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-white/90">
                        <svg class="mr-1 inline h-4 w-4 align-[-3px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                        {{ $announcement->important ? 'Wichtige Mitteilung' : 'Mitteilung' }}
                    </p>
                    <p class="mt-1 text-base font-bold">{{ $announcement->title }}</p>
                    @if(filled($announcement->body))
                        <div class="md mt-1 text-sm font-medium">{{ \App\Support\Markdown::html($announcement->body) }}</div>
                    @endif
                    @include('mitteilungen::partials.extras', ['announcement' => $announcement, 'acknowledged' => $acknowledged, 'light' => true])
                    <p class="mt-2 text-xs text-white/85">{{ $announcement->author?->name ?? 'Wehrführung' }} · {{ ($announcement->visible_from ?? $announcement->created_at)->setTimezone('Europe/Berlin')->format('d.m.Y') }}</p>
                </div>
                @unless($announcement->important || ($announcement->needs_ack && ! in_array($announcement->id, $acknowledged, true)))
                    <form method="POST" action="{{ route('mitteilungen.dismiss', $announcement) }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="text-xs text-white/85 underline hover:text-white" title="Auf der Startseite ausblenden">Ausblenden</button>
                    </form>
                @endunless
            </div>
        </article>
    @endforeach
</section>
