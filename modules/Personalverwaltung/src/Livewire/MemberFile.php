<?php

namespace Modules\Personalverwaltung\src\Livewire;

use App\Livewire\Profile\Hours;
use App\Models\User;
use App\Services\HoursRegistry;
use App\Services\Invitation;
use App\Support\QrCode;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\HistoryFile;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Services\PersonnelService;

/** The Personalakte of one Kamerad for the Personalverwaltung: data, career, Ehrungen, Dienstausweis, Dienstbeteiligung */
class MemberFile extends Component
{
    use WithFileUploads;

    /** Entry of the Diensthistorie a Nachweis is being attached to */
    public ?int $attaching = null;

    public $upload = null;

    public User $member;

    public array $idCard = ['number' => '', 'valid_until' => '', 'birth_place' => ''];

    public array $stage = ['kind' => 'rank', 'label' => '', 'since' => '', 'until' => '', 'note' => ''];

    public ?int $editing = null;

    public array $edit = ['label' => '', 'since' => '', 'until' => '', 'note' => ''];

    public array $honor = ['honor_type_id' => '', 'title' => '', 'awarded_on' => '', 'awarded_by' => '', 'note' => ''];

    /** Switched-on personal data (Anschrift, Geburtsdatum, Mobilnummer) */
    public array $data = [];

    public ?string $flash = null;

    public function mount(PersonnelService $personnel): void
    {
        abort_unless(PersonnelService::mayRead(auth()->user(), $this->member), 403);
        $personnel->syncHistory($this->member);
        $profile = MemberProfile::find($this->member->id);
        $this->idCard = ['number' => (string) $profile?->id_card_number, 'valid_until' => (string) $profile?->id_card_valid_until?->toDateString(), 'birth_place' => (string) $profile?->birth_place];
        $this->honor['awarded_on'] = PersonnelService::today()->toDateString();
        $m = $this->member;
        $this->data = ['street' => (string) $m->street, 'postal_code' => (string) $m->postal_code, 'city' => (string) $m->city,
            'birth_date' => (string) $m->birth_date?->toDateString(), 'phone' => (string) $m->phone];
    }

    public function savePersonalData(PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->savePersonalData(auth()->user(), $this->member, $this->data), 'Persönliche Daten gespeichert.');
    }

    public function saveIdCard(PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->saveIdCard(auth()->user(), $this->member, $this->idCard['number'], $this->idCard['valid_until'] ?: null, $this->idCard['birth_place'] ?? null), 'Dienstausweis gespeichert.');
    }

    public function addStage(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->addHistory(auth()->user(), $this->member, $this->stage), ($this->stage['kind'] ?? '') === 'event' ? 'Ereignis eingetragen.' : 'Nachgetragen.')) {
            $this->stage = ['kind' => $this->stage['kind'], 'label' => '', 'since' => '', 'until' => '', 'note' => ''];
        }
    }

    public function startEdit(int $id): void
    {
        $entry = $this->entry($id);
        $this->editing = $entry->id;
        $this->edit = ['label' => $entry->label, 'since' => (string) $entry->since?->toDateString(), 'until' => (string) $entry->until?->toDateString(), 'note' => (string) $entry->note];
    }

    public function saveEdit(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->updateHistory(auth()->user(), $this->entry($this->editing), $this->edit), 'Gespeichert.')) {
            $this->editing = null;
        }
    }

    public function deleteStage(int $id, PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->deleteHistory(auth()->user(), $this->entry($id)), 'Station gelöscht.');
    }

    public function addHonor(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->addHonor(auth()->user(), $this->member, $this->honor), 'Ehrung eingetragen.')) {
            $this->honor = ['honor_type_id' => '', 'title' => '', 'awarded_on' => PersonnelService::today()->toDateString(), 'awarded_by' => '', 'note' => ''];
        }
    }

    /** @var array{name: string, email: string, phone: string} */
    public array $guardian = ['name' => '', 'email' => '', 'phone' => ''];

    public function addGuardian(PersonnelService $personnel): void
    {
        if ($this->run(fn () => $personnel->addGuardian(auth()->user(), $this->member, $this->guardian), 'Erziehungsberechtigte(n) eingetragen.')) {
            $this->guardian = ['name' => '', 'email' => '', 'phone' => ''];
        }
    }

    public function deleteGuardian(int $id, PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->deleteGuardian(auth()->user(), $this->member->guardians()->findOrFail($id)), 'Erziehungsberechtigte(n) entfernt.');
    }

    /** Einladung: a one-time login link, as QR code to scan or by mail */
    public ?array $invite = null;

    public function invite(Invitation $invitation): void
    {
        abort_unless(PersonnelService::mayView(auth()->user(), $this->member), 403);
        $this->run(function () use ($invitation) {
            $link = $invitation->createForScreen($this->member, auth()->user());
            $this->invite = ['url' => $link['url'], 'until' => $link['expires_at']->setTimezone('Europe/Berlin')->format('d.m.Y H:i')];
        }, 'Einladung erstellt.');
    }

    public function mailInvite(Invitation $invitation): void
    {
        abort_unless(PersonnelService::mayView(auth()->user(), $this->member), 403);
        $this->run(fn () => $invitation->send($this->member, auth()->user()), 'Einladung an '.$this->member->email.' geschickt.');
    }

    public function saveFile(PersonnelService $personnel): void
    {
        $this->validate(['upload' => ['required', 'file', 'max:'.HistoryFile::MAX_KB, 'mimetypes:'.implode(',', HistoryFile::MIMES)]], [
            'upload.required' => 'Bitte eine Datei wählen.', 'upload.max' => 'Die Datei ist zu groß (höchstens 10 MB).', 'upload.mimetypes' => 'Bitte ein PDF oder ein Foto (JPG, PNG).',
        ]);
        $entry = $this->entry($this->attaching);
        if ($this->run(fn () => $personnel->attachHistoryFile(auth()->user(), $entry, $this->upload), 'Nachweis gespeichert.')) {
            $this->reset('upload', 'attaching');
        }
    }

    public function deleteFile(int $id, PersonnelService $personnel): void
    {
        $file = HistoryFile::whereHas('entry', fn ($q) => $q->where('user_id', $this->member->id))->findOrFail($id);
        $this->run(fn () => $personnel->deleteHistoryFile(auth()->user(), $file), 'Nachweis gelöscht.');
    }

    public function deleteHonor(int $id, PersonnelService $personnel): void
    {
        $this->run(fn () => $personnel->deleteHonor(auth()->user(), Honor::where('user_id', $this->member->id)->findOrFail($id)), 'Ehrung gelöscht.');
    }

    private function entry(?int $id): HistoryEntry
    {
        return HistoryEntry::where('user_id', $this->member->id)->findOrFail($id);
    }

    private function run(callable $action, string $done): bool
    {
        $this->resetErrorBag();
        $this->flash = null;
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return false;
        }
        $this->flash = $done;

        return true;
    }

    public function render(PersonnelService $personnel)
    {
        $member = $this->member->load(['groups', 'functions.parent']);
        $year = (int) now('Europe/Berlin')->year;

        return view('personalverwaltung::livewire.member-file', [
            'history' => HistoryEntry::with('files')->where('user_id', $member->id)->orderByRaw('until is not null')->orderByDesc('since')->orderByDesc('id')->get()->groupBy('kind'),
            'honors' => Honor::where('user_id', $member->id)->orderByDesc('awarded_on')->get(),
            'types' => HonorType::orderBy('sort_order')->orderBy('name')->get(),
            'due' => $personnel->dueHonors(auth()->user(), $year, $member),
            'years' => PersonnelService::serviceYears($member),
            'attendance' => PersonnelService::attendanceAvailable()
                ? collect([$year, $year - 1, $year - 2])->mapWithKeys(fn ($y) => [$y => $personnel->attendance($member, $y)]) : collect(),
            'hours' => app(HoursRegistry::class)->forUser($member, ...Hours::period($year))->groupBy('source')->map->sum('minutes'),
            'year' => $year,
            'isAdmin' => auth()->user()->is_admin,
            'canEdit' => PersonnelService::mayView(auth()->user(), $member),
            'inviteQr' => $this->invite ? QrCode::dataUri($this->invite['url'], 220) : null,
            'guardians' => $member->guardians()->get(),
            'fields' => PersonnelService::activeFields(),
        ]);
    }
}
