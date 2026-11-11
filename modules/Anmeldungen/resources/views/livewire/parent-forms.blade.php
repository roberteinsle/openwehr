<div class="space-y-3">
    @forelse($forms as $form)
        <div class="rounded-xl border border-gray-100 p-3" x-data="{ open: false }" wire:key="pf-{{ $form->id }}">
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex w-full items-start justify-between gap-3 text-left">
                <span class="min-w-0">
                    <span class="block font-medium text-gray-900">{{ $form->title }}</span>
                    <span class="block text-sm text-gray-500">{{ collect([$form->dateLabel(), $form->location])->filter()->implode(' · ') }}</span>
                    @if($form->deadline)<span class="block text-xs text-gray-500">Anmeldeschluss {{ $form->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr</span>@endif
                </span>
                <span class="text-sm text-fw-navy underline" x-text="open ? 'Schließen' : 'Öffnen'"></span>
            </button>
            <div x-show="open" x-cloak class="mt-3 space-y-3">
                @if($form->description)<div class="md text-sm text-gray-800">{!! \App\Support\Markdown::html($form->description) !!}</div>@endif
                <livewire:anmeldungen.registration-form :form-id="$form->id" :child-id="$child->id" :key="'rf-'.$form->id.'-'.$child->id" />
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">Gerade ist keine Anmeldung offen.</p>
    @endforelse
</div>
