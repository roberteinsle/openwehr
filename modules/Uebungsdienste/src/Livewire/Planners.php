<?php

namespace Modules\Uebungsdienste\src\Livewire;

use App\Models\Group;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/** Admins: for which Abteilungen each Übungsdienstplaner plans (none ticked = all and the whole Wehr) */
class Planners extends Component
{
    /** @var array<int, list<string>> */
    public array $scopes = [];

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function mount(): void
    {
        foreach ($this->planners() as $planner) {
            $this->scopes[$planner->id] = array_map('strval', ServicePlan::scopeOf($planner));
        }
    }

    private function planners()
    {
        return User::active()->where('is_admin', false)
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', ServicePlan::MODULE)->where('role', ServicePlan::ROLE))
            ->orderBy('name')->get();
    }

    public function save(int $userId, ServicePlan $plan): void
    {
        $this->resetErrorBag();
        try {
            $plan->setScope(auth()->user(), User::findOrFail($userId), $this->scopes[$userId] ?? []);
        } catch (ValidationException $e) {
            $this->addError('scope', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Gespeichert.';
    }

    public function render()
    {
        return view('uebungsdienste::livewire.planners', [
            'planners' => $this->planners(),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
