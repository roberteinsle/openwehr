<?php

namespace Modules\Kalender\src\Livewire;

use App\Models\DressCode;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Place;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\EventType;
use Modules\Kalender\src\Models\Template;
use Modules\Kalender\src\Services\EventService;

/**
 * Create or change an event. Dates and times come from the browser's date pickers, like in the
 * Fahrzeugbuchung; a new event lasts 60 minutes, and moving the start keeps the duration.
 */
class EventForm extends Component
{
    public ?Event $event = null;

    public string $title = '';

    public string $type = 'uebung';

    /** Length of a new event */
    public const DEFAULT_MINUTES = 60;

    private const LOCAL = 'Y-m-d\TH:i';

    /** Start and end with time (datetime-local), Berlin time */
    public string $startsAt = '';

    public string $endsAt = '';

    /** First and last day of an all-day event (date) */
    public string $startDate = '';

    public string $endDate = '';

    public bool $allDay = false;

    public string $location = '';

    /** Gemeinsam mit: other Wehren or organisations taking part */
    public string $partners = '';

    /** Who runs it ("Ausführend" in the Dienstplan) */
    public string $organizer = '';

    /** A saved place (shared list); its name becomes the Ort */
    public string $placeId = '';

    /** @var array<int, string> Kleidung asked for (shared list) */
    public array $dressCodeIds = [];

    public string $description = '';

    /** @var array<int, string> */
    public array $groupIds = [];

    /** @var array<int, string> Kameraden invited individually */
    public array $inviteeIds = [];

    /** @var array<int, string> Funktionen of the target group */
    public array $functionIds = [];

    public string $templateId = '';

    public string $templateName = '';

    public ?string $flash = null;

    public string $minParticipants = '';

    public bool $isPublic = false;

    /** New Termine ask for a Rückmeldung by default (Zusagen, Vielleicht, Absagen) */
    public bool $rsvp = true;

    /** The Frist as date and time (like Beginn and Ende); preset one day before the start and moved with it */
    public string $rsvpUntil = '';

    /** The Frist follows the start until it is set by hand */
    public bool $rsvpFollows = true;

    public string $repeat = '';

    public string $repeatUntil = '';

    public bool $notify = false;

    public bool $applyToFollowing = false;

    public function mount(?Event $event = null): void
    {
        if ($event?->exists) {
            $this->authorize('manage', $event);
            $this->event = $event;
            $start = $event->starts_at->copy()->setTimezone('Europe/Berlin');
            $end = $event->ends_at->copy()->setTimezone('Europe/Berlin');
            $this->title = $event->title;
            $this->type = $event->type;
            $this->allDay = $event->all_day;
            $this->startsAt = $start->format(self::LOCAL);
            $this->endsAt = $end->format(self::LOCAL);
            $this->startDate = $start->format('Y-m-d');
            $this->endDate = ($event->all_day ? $end->copy()->subSecond() : $end)->format('Y-m-d');
            $this->location = (string) $event->location;
            $this->partners = (string) $event->partners;
            $this->organizer = (string) $event->getAttribute('organizer');
            $this->placeId = (string) ($event->place_id ?? '');
            $this->dressCodeIds = array_map('strval', $event->dress_code_ids ?? []);
            $this->description = (string) $event->description;
            $this->groupIds = $event->groups->pluck('id')->map(fn ($id) => (string) $id)->all();
            $this->inviteeIds = $event->invitees->pluck('id')->map(fn ($id) => (string) $id)->all();
            $this->functionIds = $event->functions->pluck('id')->map(fn ($id) => (string) $id)->all();
            $this->minParticipants = (string) ($event->min_participants ?? '');
            $this->isPublic = $event->is_public;
            $this->rsvp = $event->rsvp;
            $this->rsvpUntil = $event->rsvp_deadline?->setTimezone('Europe/Berlin')->format(self::LOCAL) ?? '';
            $this->rsvpFollows = false;
        } else {
            $this->authorize('manage', Event::class);
            $start = now('Europe/Berlin')->addDay()->setTime(19, 0);
            $this->startsAt = $start->format(self::LOCAL);
            $this->endsAt = $start->copy()->addMinutes(self::DEFAULT_MINUTES)->format(self::LOCAL);
            $this->startDate = $this->endDate = $start->format('Y-m-d');
            $this->rsvpUntil = $this->defaultDeadline();
        }
    }

    /** One day before the start (all-day: the evening before, 20:00) */
    private function defaultDeadline(): string
    {
        $start = $this->allDay ? ($this->startDate ? Carbon::createFromFormat('!Y-m-d', $this->startDate, 'Europe/Berlin')->setTime(20, 0) : null) : $this->local($this->startsAt);

        return $start ? $start->copy()->subDay()->format(self::LOCAL) : '';
    }

    /** Set by hand: the Frist no longer moves with the start */
    public function updatedRsvpUntil(): void
    {
        $this->rsvpFollows = false;
    }

    /** Switched on: a Frist one day before the start if there is none yet */
    public function updatedRsvp(bool $on): void
    {
        if ($on && $this->rsvpUntil === '') {
            $this->rsvpUntil = $this->defaultDeadline();
            $this->rsvpFollows = true;
        }
    }

    /** Moving the start moves the end along, keeping the duration (60 minutes if there was none) */
    public function updatingStartsAt(string $value): void
    {
        $new = $this->local($value);
        $old = $this->local($this->startsAt);
        $oldEnd = $this->local($this->endsAt);

        if ($new) {
            $minutes = $old && $oldEnd && $oldEnd->gt($old) ? $old->diffInMinutes($oldEnd) : self::DEFAULT_MINUTES;
            $this->endsAt = $new->copy()->addMinutes($minutes)->format(self::LOCAL);
            $this->startDate = $new->format('Y-m-d');
            if ($this->rsvpFollows) {
                $this->rsvpUntil = $new->copy()->subDay()->format(self::LOCAL);
            }
        }
    }

    /** Choosing a saved place sets the Ort to its name */
    public function updatedPlaceId(string $value): void
    {
        if ($place = Place::find((int) $value)) {
            $this->location = $place->name;
        }
    }

    /** The last day of an all-day event follows the first as long as it would end before it */
    public function updatedStartDate(string $value): void
    {
        if ($value && (! $this->endDate || $this->endDate < $value)) {
            $this->endDate = $value;
        }
        if ($this->allDay && $this->rsvpFollows) {
            $this->rsvpUntil = $this->defaultDeadline();
        }
    }

    /** Switching to whole days keeps the day of the timed start, and back */
    public function updatedAllDay(bool $allDay): void
    {
        if ($allDay && ($start = $this->local($this->startsAt))) {
            $this->startDate = $start->format('Y-m-d');
            $this->endDate = max($this->endDate, $this->startDate);
        } elseif (! $allDay && $this->startDate && ! $this->local($this->startsAt)) {
            $this->startsAt = $this->startDate.'T19:00';
            $this->endsAt = Carbon::createFromFormat(self::LOCAL, $this->startsAt)->addMinutes(self::DEFAULT_MINUTES)->format(self::LOCAL);
        }
        if ($this->rsvpFollows) {
            $this->rsvpUntil = $this->defaultDeadline();
        }
    }

    /** Fills the form from a Terminvorlage; the day stays, time, length and the rest come from it */
    public function applyTemplate(): void
    {
        $template = Template::find((int) $this->templateId);
        if (! $template) {
            return;
        }
        $d = $template->data;
        $day = $this->local($this->startsAt)?->format('Y-m-d') ?? ($this->startDate ?: now('Europe/Berlin')->addDay()->format('Y-m-d'));

        $this->fill([
            'title' => $d['title'] ?? $this->title, 'type' => $d['type'] ?? $this->type, 'allDay' => (bool) ($d['all_day'] ?? false),
            'location' => (string) ($d['location'] ?? ''), 'partners' => (string) ($d['partners'] ?? ''), 'organizer' => (string) data_get($d, 'organizer', ''), 'description' => (string) ($d['description'] ?? ''), 'rsvp' => (bool) ($d['rsvp'] ?? false),
            'minParticipants' => (string) ($d['min_participants'] ?? ''), 'isPublic' => (bool) ($d['is_public'] ?? false),
            'groupIds' => array_map('strval', $d['group_ids'] ?? []), 'functionIds' => array_map('strval', $d['function_ids'] ?? []),
            'placeId' => isset($d['place_id']) && Place::whereKey($d['place_id'])->exists() ? (string) $d['place_id'] : '',
            'dressCodeIds' => array_map('strval', $d['dress_code_ids'] ?? []),
        ]);
        $start = Carbon::createFromFormat('Y-m-d H:i', $day.' '.($d['start_time'] ?? '19:00'), 'Europe/Berlin');
        $this->startsAt = $start->format(self::LOCAL);
        $this->endsAt = $start->copy()->addMinutes((int) ($d['minutes'] ?? self::DEFAULT_MINUTES))->format(self::LOCAL);
        $this->startDate = $day;
        $this->endDate = Carbon::parse($day)->addDays(max(0, (int) ($d['days'] ?? 1) - 1))->format('Y-m-d');
        // The Frist of the template: minutes before the start (older templates: days before, end of that day)
        $this->rsvpFollows = true;
        $this->rsvpUntil = $this->defaultDeadline();
        $minutes = data_get($d, 'rsvp_minutes');
        $days = data_get($d, 'rsvp_days');
        if ($this->rsvp && $minutes !== null) {
            $this->rsvpUntil = ($this->allDay ? Carbon::createFromFormat('!Y-m-d', $day, 'Europe/Berlin') : $start)->copy()->subMinutes((int) $minutes)->format(self::LOCAL);
            $this->rsvpFollows = false;
        } elseif ($this->rsvp && $days !== null) {
            $this->rsvpUntil = Carbon::parse($day)->subDays((int) $days)->setTime(23, 59)->format(self::LOCAL);
            $this->rsvpFollows = false;
        }
        $this->flash = 'Vorlage „'.$template->name.'“ übernommen.';
    }

    /** Saves the form as Terminvorlage (same name overwrites) */
    public function saveTemplate(): void
    {
        $this->validate(['templateName' => ['required', 'string', 'max:100']], ['templateName.required' => 'Wie soll die Vorlage heißen?']);
        $start = $this->local($this->startsAt);
        $end = $this->local($this->endsAt);
        $day = $start?->format('Y-m-d') ?? $this->startDate;

        Template::updateOrCreate(['name' => trim($this->templateName)], ['created_by' => auth()->id(), 'data' => [
            'title' => trim($this->title), 'type' => $this->type, 'all_day' => $this->allDay,
            'start_time' => $start?->format('H:i'), 'minutes' => $start && $end && $end->gt($start) ? (int) $start->diffInMinutes($end) : self::DEFAULT_MINUTES,
            'days' => $this->allDay && $this->startDate && $this->endDate ? Carbon::parse($this->startDate)->diffInDays(Carbon::parse($this->endDate)) + 1 : 1,
            'location' => trim($this->location), 'partners' => trim($this->partners), 'organizer' => trim($this->organizer), 'description' => trim($this->description), 'rsvp' => $this->rsvp,
            'rsvp_minutes' => $this->rsvp && ($until = $this->local($this->rsvpUntil)) && ($from = $this->allDay ? ($day ? Carbon::createFromFormat('!Y-m-d', $day, 'Europe/Berlin') : null) : $start)
                ? (int) $until->diffInMinutes($from) : null,
            'min_participants' => filled($this->minParticipants) ? (int) $this->minParticipants : null, 'is_public' => $this->isPublic,
            'group_ids' => array_map('intval', $this->groupIds), 'function_ids' => array_map('intval', $this->functionIds),
            'place_id' => filled($this->placeId) ? (int) $this->placeId : null, 'dress_code_ids' => array_map('intval', $this->dressCodeIds),
        ]]);
        $this->flash = 'Vorlage „'.trim($this->templateName).'“ gespeichert.';
        $this->templateName = '';
    }

    public function deleteTemplate(): void
    {
        Template::whereKey((int) $this->templateId)->delete();
        $this->templateId = '';
        $this->flash = 'Vorlage gelöscht.';
    }

    private function local(?string $value): ?Carbon
    {
        return $value && Carbon::canBeCreatedFromFormat($value, self::LOCAL) ? Carbon::createFromFormat(self::LOCAL, $value, 'Europe/Berlin') : null;
    }

    public function save(EventService $events)
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(EventType::labels()))],
            'startsAt' => [$this->allDay ? 'nullable' : 'required', 'date_format:'.self::LOCAL],
            'endsAt' => [$this->allDay ? 'nullable' : 'required', 'date_format:'.self::LOCAL],
            'startDate' => [$this->allDay ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'endDate' => [$this->allDay ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'location' => ['nullable', 'string', 'max:150'],
            'partners' => ['nullable', 'string', 'max:300'],
            'organizer' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'groupIds.*' => ['exists:groups,id'],
            'inviteeIds.*' => ['exists:users,id'],
            'functionIds.*' => ['exists:member_functions,id'],
            'placeId' => ['nullable', 'exists:places,id'],
            'dressCodeIds.*' => ['exists:dress_codes,id'],
            'minParticipants' => ['nullable', 'integer', 'min:1', 'max:999'],
            'rsvpUntil' => ['nullable', 'date_format:'.self::LOCAL],
            'repeat' => ['nullable', Rule::in(array_keys(EventService::REPEATS))],
            'repeatUntil' => [filled($this->repeat) && ! $this->event ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ], [
            'title.required' => 'Bitte gib einen Titel an.',
            '*.required' => 'Bitte Datum und Uhrzeit angeben.',
            '*.date_format' => 'Bitte ein gültiges Datum wählen.',
            'repeatUntil.required' => 'Bis wann soll sich der Termin wiederholen?',
        ]);

        $day = fn (string $d) => Carbon::createFromFormat('!Y-m-d', $d, 'Europe/Berlin');
        $start = $this->allDay ? $day($this->startDate) : $this->local($this->startsAt);
        $end = $this->allDay ? $day($this->endDate)->addDay() : $this->local($this->endsAt);

        $data = [
            'title' => trim($this->title),
            'type' => $this->type,
            'starts_at' => $start,
            'ends_at' => $end,
            'all_day' => $this->allDay,
            'location' => trim($this->location) ?: null,
            'partners' => trim($this->partners) ?: null,
            'organizer' => trim($this->organizer) ?: null,
            'place_id' => filled($this->placeId) ? (int) $this->placeId : null,
            'dress_code_ids' => array_map('intval', $this->dressCodeIds) ?: null,
            'description' => trim($this->description) ?: null,
            'rsvp' => $this->rsvp,
            'min_participants' => filled($this->minParticipants) ? (int) $this->minParticipants : null,
            'is_public' => $this->isPublic,
            'rsvp_deadline' => $this->rsvp ? $this->local($this->rsvpUntil) : null,
        ];
        $groups = array_map('intval', $this->groupIds);
        $invitees = array_map('intval', $this->inviteeIds);
        $functions = array_map('intval', $this->functionIds);

        try {
            if ($this->event) {
                $data['rsvp_deadline'] = $data['rsvp_deadline']?->utc();
                $events->update($this->event, $data, $groups, $this->applyToFollowing, $invitees, $functions, auth()->user(), $this->notify);
                $target = $this->event;
            } else {
                $repeat = filled($this->repeat) ? ['freq' => $this->repeat, 'until' => $day($this->repeatUntil)] : null;
                $target = $events->create($data, $groups, auth()->user(), $repeat, $this->notify, $invitees, $functions)->first();
            }
        } catch (ValidationException $e) {
            $this->addError($this->allDay ? 'endDate' : 'endsAt', collect($e->errors())->flatten()->first());

            return null;
        }

        return redirect()->route('kalender.show', $target);
    }

    public function render()
    {
        return view('kalender::livewire.event-form', [
            'types' => Event::types($this->event?->type),
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'people' => User::active()->orderBy('name')->get()->filter(fn (User $u) => $u->canUseModule('kalender'))->pluck('name', 'id'),
            'repeats' => EventService::REPEATS,
            'inSeries' => (bool) $this->event?->series_id,
            'functions' => MemberFunction::with('parent')->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()])->sort(),
            'templates' => Template::orderBy('name')->pluck('name', 'id'),
            'places' => Place::options(),
            'dressCodes' => DressCode::options(),
        ]);
    }
}
