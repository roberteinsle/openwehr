{{-- The issued items with Wäsche and Mängel: "Meine Ausrüstung" of the Kamerad, and the child's in the Eltern-Bereich ($childId) --}}
@php($childId ??= null)
    @if($items->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">
            {{ $childId ? 'Zurzeit ist keine Ausrüstung ausgegeben.' : 'Dir ist zurzeit keine Ausrüstung ausgegeben.' }}
        </div>
    @else
        <livewire:kleiderkammer.washing-request-form :child-id="$childId" :key="'wash-'.($childId ?? 'own')" />

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($items as $issued)
                @php($type = $issued->item?->itemType ?? $issued->itemType)
                @php($size = $issued->item?->size ?? $issued->size)
                @php($group = $issued->item?->group ?? $issued->group)
                <div class="p-4" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-start justify-between gap-4 text-left"
                            x-on:click="open = ! open" x-bind:aria-expanded="open">
                        <span class="min-w-0">
                            <span class="block font-semibold text-gray-900">
                                {{ $type?->name ?? 'Unbekannter Artikel' }}
                                @if($issued->quantity > 1)<span class="text-gray-500 font-normal">× {{ $issued->quantity }}</span>@endif
                            </span>
                            <span class="block text-sm text-gray-500 mt-0.5">
                                {{ collect([$group?->name, $size ? 'Größe '.$size->name : null])->filter()->implode(' · ') }}
                                @if(($size || $group) && $issued->item) · @endif
                                @if($issued->item)<span class="font-mono">{{ $issued->item->code }}</span>@endif
                            </span>
                        </span>
                        <span class="text-right text-sm text-gray-500 whitespace-nowrap">
                            seit {{ $issued->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}
                            @if($issued->messages->contains(fn ($m) => $m->isWashing() && ! $m->isHandled()))<span class="block text-xs text-amber-700">Wäsche angefordert</span>@endif
                            <span class="block text-xs text-fw-red">Mängel melden</span>
                        </span>
                    </button>
                    <div x-show="open" x-cloak>
                        <livewire:kleiderkammer.equipment-message-form :issued-item-id="$issued->id" :child-id="$childId" :key="'msg-'.$issued->id" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

