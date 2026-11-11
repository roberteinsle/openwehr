{{-- Start page: Anmeldungen open for the Kamerad (and, for organisers, how many registered) --}}
<a href="{{ route('anmeldungen.index') }}" class="block rounded-2xl border border-gray-100 bg-white p-4 shadow-sm hover:bg-gray-50">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Anmeldungen</p>
    <ul class="mt-1 space-y-1 text-sm text-gray-800">
        @foreach($lines as $line)<li>{{ $line }}</li>@endforeach
    </ul>
</a>
