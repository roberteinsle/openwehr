<?php

namespace App\Livewire\Monitor;

use App\Services\MonitorRegistry;
use App\Support\MonitorSettings;
use Livewire\Component;

/**
 * The Wachen-Monitor: a header with clock and Wehr, below it 1, 2 or 4 fields with the contents the
 * admin chose. Read-only, refreshes itself every minute (wire:poll).
 */
class Board extends Component
{
    public function render()
    {
        $settings = MonitorSettings::get();
        $registry = app(MonitorRegistry::class);
        $options = ['size' => MonitorSettings::sizeFor($settings['fields']), 'group_ids' => $settings['group_ids']];

        $fields = collect(array_slice($settings['panels'], 0, $settings['fields']))
            ->map(fn (?string $key) => [
                'title' => $key ? $registry->title($key) : null,
                'content' => $key ? $registry->render($key, $options) : null,
            ])->all();

        return view('livewire.monitor.board', [
            'count' => $settings['fields'],
            'fields' => $fields,
            'updatedAt' => now('Europe/Berlin'),
        ]);
    }
}
