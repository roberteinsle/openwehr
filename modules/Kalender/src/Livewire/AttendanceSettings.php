<?php

namespace Modules\Kalender\src\Livewire;

use App\Models\MemberFunction;
use App\Models\User;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\AttendanceService;

/** Admins: for which Arten the Anwesenheit is closed, who closes it and who gets the list */
class AttendanceSettings extends Component
{
    /** @var list<string> */
    public array $types = [];

    /** @var list<int|string> */
    public array $closerFunctionIds = [];

    /** @var list<int|string> */
    public array $recipientFunctionIds = [];

    /** @var list<int|string> */
    public array $recipientUserIds = [];

    public string $addUserId = '';

    public string $emails = '';

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function mount(AttendanceService $attendance): void
    {
        $settings = $attendance->settings();
        $this->types = $settings['types'];
        $this->closerFunctionIds = $attendance->closerFunctionIds();
        $this->recipientFunctionIds = $attendance->recipientFunctionIds();
        $this->recipientUserIds = $settings['recipient_user_ids'];
        $this->emails = implode("\n", $settings['recipient_emails']);
    }

    public function addUser(): void
    {
        if ($this->addUserId !== '' && ! in_array((int) $this->addUserId, array_map('intval', $this->recipientUserIds), true)) {
            $this->recipientUserIds[] = (int) $this->addUserId;
        }
        $this->addUserId = '';
    }

    public function removeUser(int $id): void
    {
        $this->recipientUserIds = array_values(array_filter($this->recipientUserIds, fn ($u) => (int) $u !== $id));
    }

    public function save(AttendanceService $attendance): void
    {
        $this->flash = null;
        $this->resetErrorBag();
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), preg_split('/[\s,;]+/', $this->emails) ?: []))));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('emails', '„'.$email.'“ ist keine gültige E-Mail-Adresse.');

                return;
            }
        }
        $functionIds = MemberFunction::pluck('id')->all();
        $attendance->saveSettings([
            'types' => array_values(array_intersect($this->types, array_keys(Event::types()))),
            'closer_function_ids' => array_values(array_intersect(array_map('intval', $this->closerFunctionIds), $functionIds)),
            'recipient_function_ids' => array_values(array_intersect(array_map('intval', $this->recipientFunctionIds), $functionIds)),
            'recipient_user_ids' => User::whereIn('id', array_map('intval', $this->recipientUserIds))->pluck('id')->all(),
            'recipient_emails' => $emails,
        ]);
        $this->emails = implode("\n", $emails);
        $this->flash = 'Gespeichert.';
    }

    public function render(AttendanceService $attendance)
    {
        return view('kalender::livewire.attendance-settings', [
            'typeOptions' => Event::types(),
            'functions' => MemberFunction::with('parent')->orderBy('sort_order')->orderBy('name')->get()
                ->sortBy(fn (MemberFunction $f) => $f->parent ? [$f->parent->sort_order, $f->parent->name, 1, $f->name] : [$f->sort_order, $f->name, 0, ''])->values(),
            'users' => User::active()->whereNotNull('email')->orderBy('name')->get(['id', 'name']),
            'recipientLabel' => $attendance->recipientLabel(),
        ]);
    }
}
