<?php

namespace Modules\Mitteilungen\src\Livewire;

use App\Models\Group;
use App\Models\MemberFunction;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Services\AnnouncementService;

/** Write and edit the notices on the start page (Wehrführung, admins) */
class Manage extends Component
{
    use WithFileUploads;

    /** @var array<int, string> audience by Funktion, besides the Abteilungen */
    public array $functionIds = [];

    /** Lesebestätigung: every Kamerad confirms having read it */
    public bool $needsAck = false;

    /** New attachments */
    public array $uploads = [];

    /** Mitteilung whose readers are shown */
    public ?int $statsFor = null;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public bool $important = false;

    public string $visibleFrom = '';

    public string $visibleUntil = '';

    /** @var array<int, string> */
    public array $groupIds = [];

    /**
     * Who learns about it besides the start page: none, push (bell and push, the default for new ones)
     * or mail (bell, push and e-mail). Editing starts at none, so a correction doesn't go out again.
     */
    public string $notify = 'push';

    public ?string $flash = null;

    /** The text as the Kameraden will see it (Markdown rendered), instead of the text field */
    public bool $preview = false;

    public function edit(int $id): void
    {
        $a = Announcement::with('groups')->findOrFail($id);
        $this->editingId = $a->id;
        $this->title = $a->title;
        $this->body = (string) $a->body;
        $this->important = $a->important;
        $this->visibleFrom = $a->visible_from?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '';
        $this->visibleUntil = $a->visible_until?->setTimezone('Europe/Berlin')->format('d.m.Y') ?? '';
        $this->groupIds = $a->groups->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->functionIds = $a->functions()->pluck('member_functions.id')->map(fn ($id) => (string) $id)->all();
        $this->needsAck = $a->needs_ack;
        $this->uploads = [];
        $this->notify = 'none';
        $this->preview = false;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'title', 'body', 'important', 'visibleFrom', 'visibleUntil', 'groupIds', 'functionIds', 'needsAck', 'uploads', 'notify', 'preview');
    }

    public function save(AnnouncementService $announcements): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:5000'],
            'notify' => ['required', 'in:none,push,mail'],
            'visibleFrom' => ['nullable', 'date_format:d.m.Y'],
            'visibleUntil' => ['nullable', 'date_format:d.m.Y'],
            'groupIds.*' => ['exists:groups,id'],
            'functionIds.*' => ['exists:member_functions,id'],
            'uploads.*' => ['file', 'max:'.AnnouncementFile::MAX_KB],
        ], [
            'uploads.*.max' => 'Eine Datei ist zu groß (höchstens 10 MB).',
            'title.required' => 'Bitte gib einen Titel an.',
            'visibleFrom.date_format' => 'Bitte im Format TT.MM.JJJJ.',
            'visibleUntil.date_format' => 'Bitte im Format TT.MM.JJJJ.',
        ]);

        // Dates are whole days in Berlin: from the start of the first to the end of the last
        $from = filled($this->visibleFrom) ? Carbon::createFromFormat('!d.m.Y', $this->visibleFrom, 'Europe/Berlin')->startOfDay()->utc() : null;
        $until = filled($this->visibleUntil) ? Carbon::createFromFormat('!d.m.Y', $this->visibleUntil, 'Europe/Berlin')->endOfDay()->utc() : null;

        if ($from && $until && $until->lt($from)) {
            $this->addError('visibleUntil', '„Bis“ liegt vor „ab“.');

            return;
        }

        try {
            $announcement = $announcements->save(
                $this->editingId ? Announcement::findOrFail($this->editingId) : null,
                ['title' => trim($this->title), 'body' => trim($this->body) ?: null, 'important' => $this->important, 'needs_ack' => $this->needsAck, 'visible_from' => $from, 'visible_until' => $until],
                array_map('intval', $this->groupIds),
                auth()->user(),
                $this->notify === 'mail',
                $this->notify === 'push',
                array_map('intval', $this->functionIds),
                $this->uploads,
            );
        } catch (ValidationException $e) {
            $this->addError('uploads', collect($e->errors())->flatten()->first());

            return;
        }

        $this->flash = 'Mitteilung „'.$announcement->title.'“ gespeichert'.match ($this->notify) {
            'mail' => ' und per E-Mail, in die Glocke und als Push verschickt.',
            'push' => ' und in die Glocke und als Push verschickt.',
            default => '.',
        };
        $this->cancel();
    }

    public function deleteFile(int $id): void
    {
        AnnouncementFile::whereKey($id)->delete();
        $this->flash = 'Anhang gelöscht.';
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
        return view('mitteilungen::livewire.manage', [
            'announcements' => Announcement::with(['groups', 'functions', 'author', 'files'])->latest()->get(),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'functions' => MemberFunction::topLevel()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'editingFiles' => $this->editingId ? AnnouncementFile::where('announcement_id', $this->editingId)->get(['id', 'filename', 'size']) : collect(),
            'stats' => $this->statsFor && ($a = Announcement::find($this->statsFor)) ? $announcements->stats($a) : null,
        ]);
    }
}
