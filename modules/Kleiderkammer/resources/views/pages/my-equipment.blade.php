<x-layouts.app title="Meine Ausrüstung">

    <h1 class="text-xl font-bold text-gray-900 mb-4">Meine Ausrüstung</h1>

    @include('kleiderkammer::partials.equipment-list', ['items' => $items])

</x-layouts.app>
