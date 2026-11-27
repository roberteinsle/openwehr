{{-- Wachen-Monitor: current Mitteilungen under "Alle" and their Abteilungen --}}
@forelse($sections as $section)
    <div class="mt-5 first:mt-0">
        <p class="text-lg font-bold text-white/70">{{ $section['heading'] }}:</p>
        @foreach($section['items'] as $a)
            <div @class(['mt-2 rounded-xl px-4 py-2', 'bg-[#ed1c1c]/25 border border-[#ed1c1c]/60' => $a->important, 'bg-white/5' => ! $a->important])>
                <p class="text-xl font-bold">
                    @if($a->important)<span class="mr-2 rounded bg-[#ed1c1c] px-2 py-0.5 align-middle text-sm font-semibold uppercase">Wichtig</span>@endif{{ $a->title }}
                </p>
                @if($excerpt($a) !== '')
                    <p class="mt-1 text-lg text-white/80">{{ $excerpt($a) }}</p>
                @endif
            </div>
        @endforeach
    </div>
@empty
    <p class="text-xl text-white/60">Keine aktuellen Mitteilungen.</p>
@endforelse
