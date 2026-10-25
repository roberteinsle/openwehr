<?php

namespace App\Livewire\Dashboard;

use App\Services\Birthdays;
use App\Services\DashboardRegistry;
use App\Services\ModuleOverview;
use App\Support\MemberFields;
use Livewire\Component;

/** The personal start page: cards from the modules (notices on top), and the modules – the most used first, or locked in the Kamerad's own order */
class Home extends Component
{
    public bool $editing = false;

    public function move(string $key, int $direction, ModuleOverview $overview): void
    {
        $keys = array_keys($overview->for(auth()->user()));
        $from = array_search($key, $keys, true);
        $to = $from + $direction;

        if ($from === false || $to < 0 || $to >= count($keys)) {
            return;
        }

        [$keys[$from], $keys[$to]] = [$keys[$to], $keys[$from]];
        $overview->reorder(auth()->user(), $keys);
    }

    /** New order after dragging, as module keys from first to last; unknown keys are ignored */
    public function reorder(array $keys, ModuleOverview $overview): void
    {
        $known = array_keys($overview->for(auth()->user()));
        $ordered = array_values(array_intersect($keys, $known));

        $overview->reorder(auth()->user(), [...$ordered, ...array_diff($known, $ordered)]);
    }

    public function toggleHidden(string $key, ModuleOverview $overview): void
    {
        $modules = $overview->for(auth()->user());

        if (isset($modules[$key])) {
            $overview->setHidden(auth()->user(), $key, ! $modules[$key]['hidden']);
        }
    }

    /** Locked: the own order stays; unlocked: the most used modules move up */
    public function toggleLock(ModuleOverview $overview): void
    {
        $overview->setLocked(auth()->user(), ! auth()->user()->modules_locked);
    }

    public function resetOrder(ModuleOverview $overview): void
    {
        $overview->reset(auth()->user());
    }

    private function birthdayCard(): array
    {
        if (! MemberFields::BIRTH_DATE) {
            return [];
        }

        $next = app(Birthdays::class)->next();

        return $next->isEmpty() ? [] : [view('dashboard.birthday-card', ['birthdays' => $next])->render()];
    }

    public function render(ModuleOverview $overview, DashboardRegistry $dashboard)
    {
        $user = auth()->user();
        $modules = $overview->for($user);

        return view('livewire.dashboard.home', [
            'topCards' => $dashboard->for($user, DashboardRegistry::TOP),
            // The cards in the order of the Kamerad's module tiles, the birthday last
            'cards' => [...$dashboard->for($user, DashboardRegistry::CARDS, array_keys($modules)), ...$this->birthdayCard()],
            'modules' => $this->editing ? $modules : array_filter($modules, fn ($m) => ! $m['hidden']),
            'hiddenCount' => count(array_filter($modules, fn ($m) => $m['hidden'])),
            'locked' => (bool) $user->modules_locked,
        ]);
    }
}
