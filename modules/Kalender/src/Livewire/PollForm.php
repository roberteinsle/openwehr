<?php

namespace Modules\Kalender\src\Livewire;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\EventType;
use Modules\Kalender\src\Services\PollService;

/** A new Terminabfrage: what, for whom, and the date proposals */
class PollForm extends Component
{
    public string $title = '';

    public string $type = 'veranstaltung';

    public string $location = '';

    public string $description = '';

    public string $deadline = '';

    /** @var list<array{date: string, from: string, until: string, all_day: bool}> */
    public array $options = [];

    /** @var array<int, string> */
    public array $groupIds = [];

    /** @var array<int, string> */
    public array $functionIds = [];

    /** @var array<int, string> */
    public array $inviteeIds = [];

    public bool $notify = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->canManageKalender(), 403);
        $first = now('Europe/Berlin')->addWeek();
        $this->options = [
            ['date' => $first->format('Y-m-d'), 'from' => '18:00', 'until' => '21:00', 'all_day' => false],
            ['date' => $first->copy()->addDays(2)->format('Y-m-d'), 'from' => '18:00', 'until' => '21:00', 'all_day' => false],
        ];
    }

    /** A further proposal, a week after the last one at the same time */
    public function addOption(): void
    {
        $last = end($this->options) ?: ['date' => now('Europe/Berlin')->addWeek()->format('Y-m-d'), 'from' => '18:00', 'until' => '21:00', 'all_day' => false];
        $date = Carbon::canBeCreatedFromFormat($last['date'], 'Y-m-d') ? Carbon::parse($last['date'])->addWeek()->format('Y-m-d') : '';
        $this->options[] = [...$last, 'date' => $date];
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    public function save(PollService $polls)
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(EventType::labels()))],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'deadline' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'options.*.date' => ['required', 'date_format:Y-m-d'],
            'options.*.from' => ['nullable', 'date_format:H:i'],
            'options.*.until' => ['nullable', 'date_format:H:i'],
            'groupIds.*' => ['exists:groups,id'],
            'functionIds.*' => ['exists:member_functions,id'],
            'inviteeIds.*' => ['exists:users,id'],
        ], ['title.required' => 'Wofür wird ein Termin gesucht?', 'options.*.date.required' => 'Bitte bei jedem Vorschlag ein Datum angeben.', '*.date_format' => 'Bitte ein gültiges Datum bzw. eine Uhrzeit angeben.']);

        $options = [];
        foreach ($this->options as $i => $o) {
            if ($o['all_day']) {
                $start = Carbon::createFromFormat('!Y-m-d', $o['date'], 'Europe/Berlin');
                $options[] = ['starts_at' => $start, 'ends_at' => $start->copy()->addDay(), 'all_day' => true];

                continue;
            }
            if (blank($o['from']) || blank($o['until'])) {
                $this->addError('options', 'Vorschlag '.($i + 1).': Bitte Beginn und Ende angeben oder „ganztägig“ wählen.');

                return null;
            }
            $start = Carbon::createFromFormat('Y-m-d H:i', $o['date'].' '.$o['from'], 'Europe/Berlin');
            $end = Carbon::createFromFormat('Y-m-d H:i', $o['date'].' '.$o['until'], 'Europe/Berlin');
            // An end before the start means the next day (e.g. 18:00–01:00)
            $options[] = ['starts_at' => $start, 'ends_at' => $end->lte($start) ? $end->addDay() : $end, 'all_day' => false];
        }

        try {
            $poll = $polls->create(auth()->user(), [
                'title' => trim($this->title), 'type' => $this->type, 'location' => trim($this->location) ?: null,
                'description' => trim($this->description) ?: null,
                'deadline' => filled($this->deadline) ? Carbon::createFromFormat('Y-m-d\TH:i', $this->deadline, 'Europe/Berlin')->utc() : null,
            ], $options, array_map('intval', $this->groupIds), array_map('intval', $this->functionIds), array_map('intval', $this->inviteeIds), $this->notify);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        return redirect()->route('kalender.polls.show', $poll);
    }

    public function render()
    {
        return view('kalender::livewire.poll-form', [
            'types' => Event::types(),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'functions' => MemberFunction::with('parent')->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()])->sort(),
            'people' => User::active()->orderBy('name')->get()->filter(fn (User $u) => $u->canUseModule('kalender'))->pluck('name', 'id'),
        ]);
    }
}
