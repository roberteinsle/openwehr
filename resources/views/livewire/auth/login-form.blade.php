<div>
    @if($sent)
        <div class="text-center py-4">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-green-100 rounded-xl mb-3">
                <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <p class="text-sm text-gray-600">
                Falls diese E-Mail-Adresse bekannt ist, haben wir dir einen Link geschickt.
            </p>
        </div>

        {{-- On the iPhone a link from the mail opens Safari, never the app on the home screen: the code works in both --}}
        <form wire:submit="verify" class="mt-2 space-y-3 border-t border-gray-100 pt-4">
            <label for="code" class="block text-sm font-medium text-gray-700">
                Oder den Code aus der Mail eingeben
            </label>
            <input
                wire:model="code"
                id="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9 ]*"
                maxlength="7"
                placeholder="123456"
                class="w-full px-3 py-3 border border-gray-300 rounded-xl text-center text-2xl tracking-[0.4em] font-mono focus:outline-none focus:ring-2 focus:ring-fw-red focus:border-transparent @error('code') border-fw-red @enderror"
            >
            @error('code')
                <p class="text-sm text-fw-red">{{ $message }}</p>
            @enderror
            <button
                type="submit"
                class="w-full bg-fw-red text-white font-semibold py-3 px-4 rounded-xl hover:bg-fw-red-dark transition-colors"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-75"
            >
                Anmelden
            </button>
            <p class="text-xs text-gray-500 text-center">
                Für die App auf dem Home-Bildschirm: Der Link in der Mail öffnet dort den Browser, der Code meldet dich direkt in der App an.
            </p>
        </form>
        <button type="button" wire:click="restart" class="mt-4 w-full text-sm text-gray-500 hover:text-gray-700">
            Andere E-Mail-Adresse verwenden
        </button>
    @else
        <form wire:submit="submit" class="space-y-4">
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                    E-Mail-Adresse
                </label>
                <input
                    wire:model="email"
                    id="email"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    autofocus
                    placeholder="deine@email.de"
                    class="w-full px-3 py-3 border border-gray-300 rounded-xl text-base focus:outline-none focus:ring-2 focus:ring-fw-red focus:border-transparent @error('email') border-fw-red @enderror"
                >
                @error('email')
                    <p class="mt-1 text-sm text-fw-red">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full bg-fw-red text-white font-semibold py-3 px-4 rounded-xl hover:bg-fw-red-dark transition-colors"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-75"
            >
                <span wire:loading.remove>Login-Link anfordern</span>
                <span wire:loading>Wird gesendet…</span>
            </button>
        </form>
    @endif
</div>
