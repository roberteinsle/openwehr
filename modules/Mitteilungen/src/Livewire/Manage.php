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

    /** Off the start page and into the archive at once, without touching the planned period */
    public function archive(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->update(['archived_at' => now()]);
        $this->flash = 'Mitteilung „'.$announcement->title.'“ archiviert.';
    }

    /** Back from a manual archiving (a period that is over stays over – change it under Bearbeiten) */
    public function restore(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->update(['archived_at' => null]);
        $this->flash = 'Mitteilung „'.$announcement->title.'“ wiederhergestellt.'.($announcement->isArchived() ? ' Ihr Zeitraum ist allerdings vorbei – zum Anzeigen unter „Bearbeiten“ verlängern.' : '');
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
                'Aktuell sichtbar' => $all->filter(fn (Announcement $a) => ! $a->isArchived() && ! $a->visible_from?->isFuture()),
                'Geplant' => $all->filter(fn (Announcement $a) => ! $a->isArchived() && $a->visible_from?->isFuture()),
                'Archiv' => $all->filter(fn (Announcement $a) => $a->isArchived()),
            ],
            'stats' => $this->statsFor && ($a = $all->firstWhere('id', $this->statsFor)) ? $announcements->stats($a) : null,
        ]);
    }
}
