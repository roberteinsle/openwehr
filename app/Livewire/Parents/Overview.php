<?php

namespace App\Livewire\Parents;

use App\Services\ParentAccess;
use App\Services\ParentRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Eltern-Bereich: the children of the signed-in parent, and per child the parts the modules registered
 * (Termine with Rückmeldung, Anmeldungen, Ausrüstung). With one child it opens right away.
 */
class Overview extends Component
{
    #[Locked]
    public ?int $childId = null;

    public function mount(?int $child = null): void
    {
        $children = app(ParentAccess::class)->children();
        if ($child === null && $children->count() === 1) {
            $child = $children->first()->id;
        }
        abort_if($child !== null && ! $children->contains('id', $child), 404);
        $this->childId = $child;
    }

    public function render()
    {
        $parents = app(ParentAccess::class);
        $children = $parents->children();
        $child = $this->childId ? $children->firstWhere('id', $this->childId) : null;
        abort_if($this->childId && ! $child, 404);

        return view('livewire.parents.overview', [
            'children' => $children,
            'child' => $child,
            'sections' => $child ? app(ParentRegistry::class)->for($child) : [],
            'parentName' => $parents->name(),
            'guardianOnly' => $parents->guardianOnly(),
        ])->layout(Auth::guard('web')->check() ? 'components.layouts.app' : 'components.layouts.parent', ['title' => $child ? $child->name : 'Eltern-Bereich']);
    }
}
