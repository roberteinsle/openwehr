<div class="max-w-4xl mx-auto py-6 space-y-8">
    {{-- Full-width cards from the modules on top, e.g. the notices --}}
    @foreach($topCards as $card){!! $card !!}@endforeach

    {{-- Cards from the modules, e.g. the next event; first the Eltern-Bereich for Kameraden who are parents --}}
    @if(count($cards) > 0 || $parentChildren->isNotEmpty())
        <section class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @if($parentChildren->isNotEmpty())
                <a href="{{ route('parents.index') }}" class="block rounded-2xl border border-gray-100 bg-white p-4 shadow-sm hover:shadow-md transition-shadow" data-parent-card>
                    <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-fw-navy">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm12 2a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0zM1 20a5 5 0 0 1 10 0M14 20a4.5 4.5 0 0 1 9 0"/></svg>
                        Eltern-Bereich
                    </p>
                    <ul class="mt-2 space-y-1">
                        @foreach($parentChildren as $child)
                            <li class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-semibold text-gray-900">{{ $child->name }}</span>
                                @foreach($child->groups as $group)<span class="rounded px-1.5 text-xs font-medium" style="{{ $group->badgeStyle() }}">{{ $group->name }}</span>@endforeach
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500">Termine, Rückmeldungen, Anmeldungen und Ausrüstung {{ $parentChildren->count() > 1 ? 'deiner Kinder' : 'deines Kindes' }}</p>
                </a>
            @endif
            @foreach($cards as $card){!! $card !!}@endforeach
        </section>
    @endif

    {{-- Modules --}}
    <section>
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-900">Module
                @unless($editing)<span class="text-xs font-normal text-gray-400" title="{{ $locked ? 'Deine Reihenfolge ist fixiert' : 'Häufig genutzte Module stehen oben' }}">{{ $locked ? 'eigene Reihenfolge' : 'nach Nutzung' }}</span>@endunless
            </h2>
            <div class="flex items-center gap-3 text-sm">
                @if($editing)
                    <button type="button" wire:click="resetOrder" wire:confirm="Reihenfolge und ausgeblendete Module zurücksetzen? Danach gilt wieder die Standardreihenfolge." class="text-gray-500 underline">Zurücksetzen</button>
                    <button type="button" wire:click="$set('editing', false)" class="rounded-lg bg-fw-navy px-3 py-1.5 font-semibold text-white">Fertig</button>
                @else
                    <button type="button" wire:click="$set('editing', true)" class="inline-flex items-center gap-1.5 text-fw-navy hover:underline">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                        Anpassen{{ $hiddenCount ? ' ('.$hiddenCount.' ausgeblendet)' : '' }}
                    </button>
                @endif
            </div>
        </div>

        @if($editing)
            {{-- Sort list: drag the handle with the mouse, or use the arrows (touch, keyboard) --}}
            <button type="button" role="switch" aria-checked="{{ $locked ? 'true' : 'false' }}" wire:click="toggleLock"
                    class="mb-3 flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left {{ $locked ? 'border-fw-navy bg-fw-navy/5' : 'border-gray-200 bg-white' }}">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $locked ? 'bg-fw-navy text-white' : 'bg-gray-100 text-gray-500' }}"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
                <span class="min-w-0 flex-1 text-sm">
                    <span class="block font-semibold text-gray-900">{{ $locked ? 'Reihenfolge fixiert' : 'Automatisch nach Nutzung sortieren' }}</span>
                    <span class="block text-gray-500">{{ $locked ? 'Die Module bleiben so, wie du sie sortiert hast. Tippen, um wieder automatisch zu sortieren.' : 'Module, die du oft nutzt, wandern nach oben. Tippen, um deine eigene Reihenfolge festzuhalten.' }}</span>
                </span>
                <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors {{ $locked ? 'bg-fw-navy' : 'bg-gray-300' }}">
                    <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform {{ $locked ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                </span>
            </button>
            <p class="mb-3 text-sm text-gray-500">Zum Sortieren am Griff ziehen oder die Pfeile nutzen{{ $locked ? '' : ' – damit wird die Reihenfolge fixiert' }}. Der Schalter legt fest, ob ein Modul auf der Startseite erscheint; über das Menü oben rechts bleibt jedes Modul erreichbar.</p>
            <ul class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100"
                x-data="{
                    dragging: null,
                    over: null,
                    drop(target) {
                        if (! this.dragging || this.dragging === target) return;
                        const keys = [...this.$el.querySelectorAll('[data-module]')].map(el => el.dataset.module).filter(k => k !== this.dragging);
                        keys.splice(keys.indexOf(target), 0, this.dragging);
                        $wire.reorder(keys);
                    },
                }"
                x-on:dragend="dragging = null; over = null">
                @foreach($modules as $key => $module)
                    <li wire:key="sort-{{ $key }}" data-module="{{ $key }}" draggable="true"
                        x-on:dragstart="dragging = '{{ $key }}'; $event.dataTransfer.effectAllowed = 'move'"
                        x-on:dragover.prevent="over = '{{ $key }}'"
                        x-on:drop.prevent="drop('{{ $key }}'); dragging = null; over = null"
                        x-bind:class="{ 'opacity-40': dragging === '{{ $key }}', 'bg-gray-50': over === '{{ $key }}' && dragging !== '{{ $key }}' }"
                        class="flex items-center gap-3 px-3 py-2.5">
                        <span class="cursor-grab text-gray-400 active:cursor-grabbing" title="Ziehen zum Sortieren" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                        </span>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10 {{ $module['hidden'] ? 'opacity-40' : '' }}">
                            <x-module-icon :key="$key" class="h-5 w-5 text-fw-navy" />
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium {{ $module['hidden'] ? 'text-gray-400' : 'text-gray-900' }}">{{ $module['name'] }}</span>
                        <span class="flex shrink-0 items-center gap-1">
                            <button type="button" wire:click="move('{{ $key }}', -1)" @disabled($loop->first) class="h-8 w-8 rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-25" aria-label="{{ $module['name'] }} nach oben">↑</button>
                            <button type="button" wire:click="move('{{ $key }}', 1)" @disabled($loop->last) class="h-8 w-8 rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-25" aria-label="{{ $module['name'] }} nach unten">↓</button>
                        </span>
                        <button type="button" role="switch" aria-checked="{{ $module['hidden'] ? 'false' : 'true' }}" wire:click="toggleHidden('{{ $key }}')"
                                title="{{ $module['hidden'] ? 'Auf der Startseite einblenden' : 'Auf der Startseite ausblenden' }}"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors {{ $module['hidden'] ? 'bg-gray-300' : 'bg-fw-red' }}">
                            <span class="sr-only">{{ $module['name'] }} auf der Startseite zeigen</span>
                            <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform {{ $module['hidden'] ? 'translate-x-0.5' : 'translate-x-5' }}"></span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($modules as $key => $module)
                    <a href="{{ $module['url'] }}" wire:key="mod-{{ $key }}"
                       class="block h-full bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-fw-navy hover:shadow-md transition-all group">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-fw-navy/10 flex items-center justify-center group-hover:bg-fw-navy/15 transition-colors">
                                <x-module-icon :key="$key" class="h-6 w-6 text-fw-navy" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-900 group-hover:text-fw-navy transition-colors">{{ $module['name'] }}</h3>
                                <p class="text-sm text-gray-500 mt-0.5">{{ $module['description'] }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="text-sm text-gray-500 col-span-full">{{ $hiddenCount ? 'Alle Module sind ausgeblendet. Über „Anpassen“ wieder einblenden.' : 'Keine Module verfügbar.' }}</p>
                @endforelse
            </div>
        @endif
    </section>
</div>
