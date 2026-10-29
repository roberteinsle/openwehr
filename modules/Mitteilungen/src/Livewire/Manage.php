<?php

namespace Modules\Mitteilungen\src\Livewire;

use Livewire\Component;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;

/** Overview for the Redakteure: current, planned and expired Mitteilungen with read figures; writing is on its own page */
class Manage extends Component
{
    /** Mitteilung whose readers are shown */
    public ?int $statsFor = null;

    public ?string $flash = null;

    /** Every request, also Livewire updates (which don't run the route's can: middleware again) */
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('post-announcements'), 403);
    }

    public function mount(): void
    {
        $this->flash = session('mitteilungen.flash');
    }

    public function showStats(?int $id): void
    {
        $this->statsFor = $this->statsFor === $id ? null : $id;
    }

    /** Asks everyone again who hasn't confirmed (or read) yet */
    public function remind(int $id, AnnouncementService $announcements): void
    {
        $count = $announcements->remindUnacknowledged(Announcement::findOrFail($id));
        $this->flash = $count ? $count.' Kameraden erinnert.' : 'Alle haben schon bestätigt.';
    }

    public function delete(int $id): void
    {
        Announcement::findOrFail($id)->delete();
        $this->flash = 'Mitteilung gelöscht.';
    }

    public function render(AnnouncementService $announcements)
    {
        $all = Announcement::with(['groups', 'functions', 'author', 'files'])->orderByDesc('important')->latest()->get();

        return view('mitteilungen::livewire.manage', [
            'sections' => [
                'Aktuell sichtbar' => $all->filter(fn (Announcement $a) => ! $a->visible_from?->isFuture() && ! $a->visible_until?->isPast()),
                'Geplant' => $all->filter(fn (Announcement $a) => (bool) $a->visible_from?->isFuture()),
                'Abgelaufen' => $all->filter(fn (Announcement $a) => (bool) $a->visible_until?->isPast()),
            ],
            'stats' => $this->statsFor && ($a = $all->firstWhere('id', $this->statsFor)) ? $announcements->stats($a) : null,
        ]);
    }
}
