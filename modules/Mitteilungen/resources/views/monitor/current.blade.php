{{-- Wachen-Monitor: current Mitteilungen for everyone --}}
@forelse($announcements as $a)
    <div @class(['mt-3 first:mt-0 rounded-xl px-4 py-3', 'bg-[#ed1c1c]/25 border border-[#ed1c1c]/60' => $a->important, 'bg-white/5' => ! $a->important])>
        <p class="text-xl font-bold">
            @if($a->important)<span class="mr-2 rounded bg-[#ed1c1c] px-2 py-0.5 align-middle text-sm font-semibold uppercase">Wichtig</span>@endif{{ $a->title }}
        </p>
        @if($excerpt($a) !== '')
            <p class="mt-1 text-lg text-white/80">{{ $excerpt($a) }}</p>
        @endif
        <p class="mt-1 text-sm text-white/50">{{ ($a->visible_from ?? $a->created_at)->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') }}</p>
    </div>
@empty
    <p class="text-xl text-white/60">Keine aktuellen Mitteilungen.</p>
@endforelse
