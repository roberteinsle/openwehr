<?php

namespace App\Livewire\Monitor;

use App\Services\AlarmService;
use App\Services\MonitorRegistry;
use App\Support\MonitorSettings;
use Livewire\Component;

/**
 * The Wachen-Monitor: a header with clock and Wehr, below it 1, 2, 3 or 4 fields with the contents the
 * admin chose. Read-only, refreshes itself in the interval set by the admin (wire:poll, 1–60 minutes).
 */
class Board extends Component
{
    public function render()
    {
        $settings = MonitorSettings::get();

        // An alarm from DIVERA takes the whole screen (the page checks /monitor-status every 10 seconds)
        $running = AlarmService::running();
        if ($running->isNotEmpty()) {
            return view('livewire.monitor.alarm', [
                'alarm' => $running->first(),
                'others' => $running->slice(1)->values(),
                'state' => AlarmService::state(),
                'refresh' => $settings['refresh'],
                'show' => array_fill_keys(AlarmService::settings()['fields'], true),
            ]);
        }

        $registry = app(MonitorRegistry::class);
        $shown = array_slice($settings['panels'], 0, $settings['fields']);
        $options = ['size' => MonitorSettings::sizeFor($settings['fields']), 'group_ids' => $settings['group_ids']];

        // Each field leaves out what another field already shows (e.g. "Nächste Termine" without the Übungsdienste)
        $fields = collect($shown)
            ->map(fn (?string $key, int $i) => [
                'title' => $key ? $registry->title($key) : null,
                'content' => $key ? $registry->render($key, [...$options, 'covered' => $registry->covered(array_values(array_diff_key($shown, [$i => true])))]) : null,
            ])->all();

        return view('livewire.monitor.board', [
            'count' => $settings['fields'],
            'fields' => $fields,
            'refresh' => $settings['refresh'],
            'state' => '',
            'updatedAt' => now('Europe/Berlin'),
        ]);
    }
}
