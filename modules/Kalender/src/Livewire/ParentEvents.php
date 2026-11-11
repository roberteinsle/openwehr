<?php

namespace Modules\Kalender\src\Livewire;

use App\Services\ParentAccess;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\FeedService;

/**
 * Eltern-Bereich: the coming Termine of a child with the Rückmeldung of the parent (kept apart from the
 * child's own, and counting), plus the parent's Kalender-Abo with the Termine of all their children.
 */
class ParentEvents extends Component
{
    #[Locked]
    public int $childId;

    /** @var array<int, string> comment per event */
    public array $comments = [];

    public ?string $flash = null;

    public bool $showFeed = false;

    /** On the first render and on every update: only a child of the signed-in parent */
    public function mount(): void
    {
        abort_unless(app(ParentAccess::class)->child($this->childId) !== null, 404);
    }

    public function hydrate(): void
    {
        $this->mount();
    }

    public function respond(int $eventId, string $status, EventService $events): void
    {
        $this->resetErrorBag();
        $parents = app(ParentAccess::class);
        $guardian = $parents->guardianFor($this->childId);
        $event = $this->events()->firstWhere('id', $eventId);
        abort_unless($guardian && $event, 404);

        try {
            $events->respondAsGuardian($event, $guardian, $status, $this->comments[$eventId] ?? null);
        } catch (ValidationException $e) {
            $this->addError('e'.$eventId, collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = 'Rückmeldung für „'.$event->title.'“ gespeichert.';
    }

    public function newFeed(FeedService $feeds): void
    {
        $feeds->regenerateParent((string) app(ParentAccess::class)->email());
        $this->showFeed = true;
        $this->flash = 'Neue Abo-Adresse erzeugt – die alte funktioniert nicht mehr.';
    }

    private function events()
    {
        $child = app(ParentAccess::class)->child($this->childId);

        return Event::visibleTo($child)->upcoming()->whereNull('source_id')->where('starts_at', '<', now()->addMonths(6))
            ->with(['place', 'responses' => fn ($q) => $q->where('user_id', $this->childId)])->limit(40)->get();
    }

    public function render(FeedService $feeds)
    {
        $parents = app(ParentAccess::class);

        return view('kalender::livewire.parent-events', [
            'child' => $parents->child($this->childId),
            'events' => $this->events(),
            'feedUrl' => $this->showFeed ? $feeds->parentUrl((string) $parents->email()) : null,
        ]);
    }
}
