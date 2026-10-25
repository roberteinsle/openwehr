<?php

namespace App\Services;

use App\Models\User;

/**
 * Cards that modules put on the personal start page (e.g. "Nächster Termin"). A card is shown only
 * while its module is active and the Kamerad may use it; the callable returns a view or null.
 */
class DashboardRegistry
{
    /** Full width above everything else (e.g. notices) */
    public const TOP = 'top';

    /** Small cards side by side (e.g. next event) */
    public const CARDS = 'cards';

    /** @var array<string, array{module: string, position: string, render: callable(User): mixed, everyone: bool}> */
    private array $cards = [];

    /**
     * $everyone: the card is shown to every active Kamerad while the module is on, also without
     * access to the module (e.g. the next Termin or Übungsdienst); the callable decides what they see.
     */
    public function register(string $module, string $name, callable $render, string $position = self::CARDS, bool $everyone = false): void
    {
        $this->cards[$module.'.'.$name] = ['module' => $module, 'position' => $position, 'render' => $render, 'everyone' => $everyone];
    }

    /**
     * Rendered cards for this Kamerad. With $moduleOrder (module keys, e.g. the Kamerad's own order of the
     * module tiles) the cards follow it; cards of modules not in it come last, in registration order.
     *
     * @param  ?list<string>  $moduleOrder
     * @return array<int, mixed>
     */
    public function for(User $user, string $position = self::CARDS, ?array $moduleOrder = null): array
    {
        $registry = app(ModuleRegistry::class);
        $rank = $moduleOrder !== null ? array_flip($moduleOrder) : [];

        return collect($this->cards)
            ->filter(fn ($card) => $card['position'] === $position)
            ->sortBy(fn ($card, $id) => [$rank[$card['module']] ?? PHP_INT_MAX, array_search($id, array_keys($this->cards), true)])
            ->filter(fn ($card) => $registry->isActive($card['module']) && ($card['everyone'] || $user->canUseModule($card['module'])))
            ->map(fn ($card) => ($card['render'])($user))
            ->filter()
            ->values()
            ->all();
    }
}
