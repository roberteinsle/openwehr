<x-layouts.app title="Ansprechpartner">
    <div class="mx-auto max-w-2xl space-y-4">
        <h1 class="text-xl font-bold text-gray-900">Ansprechpartner</h1>
        @forelse($sections as $section)
            <section class="space-y-2">
                @if($section['title'] !== '')<h2 class="text-sm font-semibold text-gray-700">{{ $section['title'] }}</h2>@endif
                <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                    @foreach($section['contacts'] as $c)
                        <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $c->role }}</p>
                                <p class="font-semibold text-gray-900">{{ $c->name }}</p>
                                @if($c->note)<p class="text-xs text-gray-500">{{ $c->note }}</p>@endif
                                @if($c->email)<a href="mailto:{{ $c->email }}" class="text-sm text-fw-navy underline">{{ $c->email }}</a>@endif
                            </div>
                            @if($c->telUrl())
                                <a href="{{ $c->telUrl() }}" class="inline-flex items-center gap-2 rounded-xl bg-fw-navy px-4 py-2.5 text-sm font-semibold text-white">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.95.68l1.5 4.5a1 1 0 01-.5 1.21l-2.26 1.13a11.04 11.04 0 005.52 5.52l1.13-2.26a1 1 0 011.21-.5l4.5 1.5a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.72 21 3 14.28 3 6V5z"/></svg>
                                    {{ $c->phone }}
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine Ansprechpartner eingetragen. Admins pflegen sie unter Admin → Kameraden → Ansprechpartner.</p>
        @endforelse
    </div>
</x-layouts.app>
