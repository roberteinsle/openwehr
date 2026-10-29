<x-layouts.app :title="$announcement ? 'Mitteilung bearbeiten' : 'Neue Mitteilung'">
    <livewire:mitteilungen.editor :announcement="$announcement" />
</x-layouts.app>
