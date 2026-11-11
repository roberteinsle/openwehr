<?php

namespace App\Services;

use App\Models\User;
use Closure;

/**
 * The Eltern-Bereich (/eltern/{kind}) is built from module parts: Kalender (Termine, Rückmeldung, Abo),
 * Kleiderkammer (Ausrüstung), Anmeldungen … Each module registers a Livewire component that gets the
 * child as #[Locked] childId and checks it again with ParentAccess::child() – the core knows no module.
 */
class ParentRegistry
{
    /** @var array<string, array{module: string, title: string, component: string, order: int, visible: ?Closure}> */
    private array $sections = [];

    /** @param  ?Closure(User): bool  $visible  e.g. only when the child has something there */
    public function section(string $module, string $title, string $component, int $order = 50, ?Closure $visible = null): void
    {
        $this->sections[$module.'.'.$component] = compact('module', 'title', 'component', 'order', 'visible');
    }

    /** @return list<array{module: string, title: string, component: string, order: int, visible: ?Closure}> the parts for this child, in order */
    public function for(User $child): array
    {
        $modules = app(ModuleRegistry::class);

        return collect($this->sections)
            ->filter(fn (array $s) => $modules->isActive($s['module']) && ($s['visible'] === null || ($s['visible'])($child)))
            ->sortBy('order')->values()->all();
    }
}
