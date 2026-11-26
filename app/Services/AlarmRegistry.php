<?php

namespace App\Services;

use App\Models\Alarm;
use Throwable;

/**
 * Who else learns about an alarm from the DIVERA webhook (Admin → DIVERA-Webhook): modules register a
 * listener, e.g. the Einsatzbericht makes its draft. Listeners of inactive modules are skipped, a
 * failing listener is reported and doesn't stop the others. Test alarms reach the listeners too
 * (source "test") – a listener decides itself whether to ignore them.
 */
class AlarmRegistry
{
    /** @var array<string, callable(Alarm, bool): void> */
    private array $listeners = [];

    /** @param  callable(Alarm $alarm, bool $isNew): void  $listener  $isNew false for an update of the same alarm */
    public function listen(string $module, callable $listener): void
    {
        $this->listeners[$module] = $listener;
    }

    public function dispatch(Alarm $alarm, bool $isNew): void
    {
        $modules = app(ModuleRegistry::class);
        foreach ($this->listeners as $module => $listener) {
            if (! $modules->isActive($module)) {
                continue;
            }
            try {
                $listener($alarm, $isNew);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
