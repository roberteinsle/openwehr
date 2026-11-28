<div class="space-y-4">
    @if($child)
        @if($children->count() > 1)
            <a href="{{ route('parents.index') }}" class="inline-flex items-center gap-1 text-sm text-fw-navy">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Alle Kinder
            </a>
        @endif
        <div class="flex items-center gap-3">
            @if($guardianOnly)<span class="h-12 w-12 text-sm rounded-full bg-fw-grey-light text-fw-grey font-semibold inline-flex items-center justify-center shrink-0">{{ $child->initials() }}</span>@else<x-avatar :user="$child" size="h-12 w-12" text="text-sm" />@endif
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900">{{ $child->name }}</h1>
                <p class="text-sm text-gray-500">Eltern-Bereich{{ $parentName ? ' · angemeldet als '.$parentName : '' }}</p>
                @foreach(app(\App\Services\ParentRegistry::class)->badges($child) as $todo)<span class="mt-1 inline-block rounded-full bg-fw-red px-2 py-0.5 text-xs font-semibold text-white">{{ $todo }}</span>@endforeach
            </div>
        </div>

        @forelse($sections as $section)
            <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" wire:key="s-{{ $section['component'] }}">
                <h2 class="mb-3 text-base font-semibold text-gray-900">{{ $section['title'] }}</h2>
                @livewire($section['component'], ['childId' => $child->id], key('p-'.$child->id.'-'.$section['component']))
            </section>
        @empty
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Hier gibt es für {{ $child->name }} gerade nichts zu tun.</p>
        @endforelse

        @if($contacts->isNotEmpty())
            <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" data-parent-contacts>
                <h2 class="mb-2 text-base font-semibold text-gray-900">Ansprechpartner</h2>
                <div class="divide-y divide-gray-100">
                    @foreach($contacts->flatMap(fn ($s) => $s['contacts']) as $c)
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $c->role }}</p>
                                <p class="font-semibold text-gray-900">{{ $c->name }}</p>
                                @if($c->note)<p class="text-xs text-gray-500">{{ $c->note }}</p>@endif
                                @if($c->email)<a href="mailto:{{ $c->email }}" class="text-sm text-fw-navy underline">{{ $c->email }}</a>@endif
                            </div>
                            @if($c->telUrl())<a href="{{ $c->telUrl() }}" class="rounded-xl bg-fw-navy px-4 py-2 text-sm font-semibold text-white">{{ $c->phone }}</a>@endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <div>
            <h1 class="text-xl font-bold text-gray-900">Eltern-Bereich</h1>
            <p class="text-sm text-gray-500">Termine, Rückmeldungen, Anmeldungen und Ausrüstung deiner Kinder.</p>
        </div>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @foreach($children as $c)
                <a href="{{ route('parents.child', $c) }}" class="flex items-center gap-3 p-4 hover:bg-gray-50" wire:key="c-{{ $c->id }}">
                    @if($guardianOnly)<span class="h-10 w-10 text-xs rounded-full bg-fw-grey-light text-fw-grey font-semibold inline-flex items-center justify-center shrink-0">{{ $c->initials() }}</span>@else<x-avatar :user="$c" size="h-10 w-10" text="text-xs" />@endif
                    <span class="font-medium text-gray-900">{{ $c->name }}</span>
                    @foreach(app(\App\Services\ParentRegistry::class)->badges($c) as $todo)<span class="rounded-full bg-fw-red px-2 py-0.5 text-xs font-semibold text-white">{{ $todo }}</span>@endforeach
                    <svg class="ml-auto h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            @endforeach
        </div>
    @endif
    @if($guardianOnly)
        <p class="text-center text-xs text-gray-500">Du bist als Erziehungsberechtigte(r) angemeldet. Fragen? Wende dich an die Jugendwarte.</p>
    @endif
</div>
