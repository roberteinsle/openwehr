<?php

namespace App\Services;

use Illuminate\Contracts\View\View;

/**
 * Contents for the fields of the Wachen-Monitor (/monitor). Each module offers what fits on a screen
 * at the station – next Dienste, Fahrzeugbelegung, Mitteilungen … – and the admin picks which field
 * shows what (App\Support\MonitorSettings). Contents of switched-off modules are not offered.
 *
 * The screen is seen by anyone in the station, without login: a content shows only what may hang on
 * the wall there (no personal Termine, short names, Mitteilungen for everyone).
 */
class MonitorRegistry
{
    /** @var array<string, array{key: string, module: ?string, title: string, description: string, order: int, render: callable(array): (View|string|null), covers: list<string>}> */
    private array $panels = [];

    /**
     * $render gets the size of the field ("full", "half" or "quarter" of the screen), the
     * Abteilungen chosen for overviews (group_ids, [] = all) and what other fields on the screen
     * already show (covered) and returns a view, HTML or null.
     *
     * $covers names what this content shows, so others can leave it out while it is on the screen:
     * the module key of Kalender Termine it mirrors (kal_events.managed_by), e.g. "uebungsdienste".
     *
     * @param  string  $key  unique, e.g. "kalender.termine" (stored in the settings)
     * @param  ?string  $module  module key, null for the core
     * @param  callable(array{size: string, group_ids: list<int>, covered: list<string>}): (View|string|null)  $render
     * @param  list<string>  $covers
     */
    public function register(string $key, ?string $module, string $title, callable $render, string $description = '', int $order = 50, array $covers = []): void
    {
        $this->panels[$key] = compact('key', 'module', 'title', 'description', 'order', 'render', 'covers');
    }

    /**
     * What the given contents show together (their $covers), e.g. ["uebungsdienste"]
     *
     * @param  list<?string>  $keys
     * @return list<string>
     */
    public function covered(array $keys): array
    {
        $available = $this->available();

        return collect($keys)->filter()->flatMap(fn ($key) => isset($available[$key]) ? $available[$key]['covers'] : [])->unique()->values()->all();
    }

    /** @return array<string, array{key: string, module: ?string, title: string, description: string, order: int, render: callable, covers: list<string>}> what can be chosen now, in order */
    public function available(): array
    {
        $modules = app(ModuleRegistry::class);

        return collect($this->panels)
            ->filter(fn ($p) => $p['module'] === null || $modules->isActive($p['module']))
            ->sortBy('order')
            ->all();
    }

    public function isAvailable(string $key): bool
    {
        return isset($this->available()[$key]);
    }

    /** @return array<string, string> key => title, for a select */
    public function options(): array
    {
        return array_map(fn ($p) => $p['title'], $this->available());
    }

    public function title(string $key): ?string
    {
        return $this->available()[$key]['title'] ?? null;
    }

    /**
     * The rendered field, or null when the content is unknown or its module is off.
     *
     * @param  array{size: string, group_ids: list<int>, covered: list<string>}  $options
     */
    public function render(string $key, array $options): View|string|null
    {
        $panel = $this->available()[$key] ?? null;

        return $panel ? ($panel['render'])($options) : null;
    }
}
