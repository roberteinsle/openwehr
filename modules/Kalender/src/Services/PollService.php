<?php

namespace Modules\Kalender\src\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\EventType;
use Modules\Kalender\src\Models\Poll;
use Modules\Kalender\src\Models\PollOption;
use Modules\Kalender\src\Models\PollVote;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Notifications\PollCreatedNotification;
use Modules\Kalender\src\Notifications\PollReminderNotification;
use Modules\Kalender\src\Support\Audience;
use Throwable;

/**
 * Terminabfrage (like Doodle): the Terminverwalter proposes dates for a target group, everyone
 * answers each with yes, "wenn nötig" or no, the overview shows the best date, and "Festlegen"
 * turns it into an event of the Kalender – with the Zusagen taken over as Rückmeldungen.
 */
class PollService
{
    public const MAX_OPTIONS = 30;

    public function __construct(private readonly EventService $events) {}

    /**
     * @param  array{title: string, type: string, location?: ?string, description?: ?string, deadline?: ?Carbon}  $data
     * @param  list<array{starts_at: Carbon, ends_at: Carbon, all_day?: bool}>  $options
     */
    public function create(User $by, array $data, array $options, array $groupIds = [], array $functionIds = [], array $userIds = [], bool $notify = true): Poll
    {
        $this->assertManager($by);
        if (count($options) < 2) {
            throw ValidationException::withMessages(['options' => 'Bitte mindestens zwei Terminvorschläge angeben.']);
        }
        if (count($options) > self::MAX_OPTIONS) {
            throw ValidationException::withMessages(['options' => 'Höchstens '.self::MAX_OPTIONS.' Terminvorschläge.']);
        }
        foreach ($options as $i => $o) {
            if ($o['ends_at']->lte($o['starts_at'])) {
                throw ValidationException::withMessages(['options' => 'Vorschlag '.($i + 1).': Das Ende muss nach dem Beginn liegen.']);
            }
        }
        if (! array_key_exists($data['type'] ?? '', EventType::labels())) {
            throw ValidationException::withMessages(['type' => 'Bitte die Art des Termins wählen.']);
        }

        $poll = DB::transaction(function () use ($by, $data, $options, $groupIds, $functionIds, $userIds) {
            $poll = Poll::create([...$data, 'deadline' => $data['deadline'] ?? null, 'created_by' => $by->id]);
            foreach (collect($options)->sortBy('starts_at') as $o) {
                $poll->options()->create(['starts_at' => $o['starts_at']->copy()->utc(), 'ends_at' => $o['ends_at']->copy()->utc(), 'all_day' => (bool) ($o['all_day'] ?? false)]);
            }
            $poll->groups()->sync($groupIds);
            $poll->functions()->sync($functionIds);
            $poll->invitees()->sync($userIds);

            return $poll;
        });

        if ($notify) {
            $this->notify($this->audience($poll)->reject(fn (User $u) => $u->is($by)), new PollCreatedNotification($poll));
        }

        return $poll;
    }

    public function audience(Poll $poll): EloquentCollection
    {
        return Audience::users($poll->groups()->pluck('groups.id'), $poll->functions()->pluck('member_functions.id'), $poll->invitees()->pluck('users.id'));
    }

    /** @param  array<int, string>  $answers  option id => yes | maybe | no; options left out stay unanswered */
    public function vote(User $user, Poll $poll, array $answers): void
    {
        if (! $poll->isOpen()) {
            throw ValidationException::withMessages(['vote' => 'Diese Terminabfrage ist geschlossen.']);
        }
        if (! $this->audience($poll)->contains('id', $user->id)) {
            throw ValidationException::withMessages(['vote' => 'Diese Terminabfrage ist nicht für dich.']);
        }
        $optionIds = $poll->options()->pluck('id');

        DB::transaction(function () use ($user, $answers, $optionIds) {
            foreach ($answers as $optionId => $answer) {
                if (! $optionIds->contains((int) $optionId)) {
                    continue;
                }
                if (! array_key_exists($answer, PollVote::ANSWERS)) {
                    PollVote::where('option_id', $optionId)->where('user_id', $user->id)->delete();

                    continue;
                }
                PollVote::updateOrCreate(['option_id' => (int) $optionId, 'user_id' => $user->id], ['answer' => $answer]);
            }
        });
    }

    /**
     * Per option: yes, maybe, no with names and a score (yes 2, wenn nötig 1); who of the target
     * group has not answered; the best options (highest score, then most yes).
     *
     * @return array{options: Collection, open: Collection, best: list<int>, voters: Collection}
     */
    public function results(Poll $poll): array
    {
        $options = $poll->options()->with('votes.user')->get()->map(function (PollOption $o) {
            $by = $o->votes->groupBy('answer');

            return ['option' => $o, 'yes' => $by->get('yes', collect())->pluck('user'), 'maybe' => $by->get('maybe', collect())->pluck('user'),
                'no' => $by->get('no', collect())->pluck('user'), 'score' => 2 * $by->get('yes', collect())->count() + $by->get('maybe', collect())->count()];
        });
        $voterIds = PollVote::whereIn('option_id', $options->pluck('option.id'))->distinct()->pluck('user_id');
        $top = $options->max('score');
        $best = $top > 0 ? $options->where('score', $top)->sortByDesc(fn ($o) => $o['yes']->count())->pipe(function ($c) {
            $mostYes = $c->first()['yes']->count();

            return $c->filter(fn ($o) => $o['yes']->count() === $mostYes)->pluck('option.id')->values()->all();
        }) : [];

        return [
            'options' => $options,
            'open' => $this->audience($poll)->reject(fn (User $u) => $voterIds->contains($u->id))->values(),
            'best' => $best,
            'voters' => User::whereIn('id', $voterIds)->orderBy('name')->get(),
        ];
    }

    /**
     * Fixes an option: an event of the same title, type, place and target group; with Rückmeldung,
     * the yes and "wenn nötig" answers become Zusagen and Vielleicht. The poll is closed.
     */
    public function decide(User $by, Poll $poll, PollOption $option, bool $rsvp = true, bool $notify = true): Event
    {
        $this->assertManager($by);
        if ($option->poll_id !== $poll->id) {
            throw ValidationException::withMessages(['decide' => 'Dieser Vorschlag gehört nicht zur Abfrage.']);
        }
        if ($poll->isDecided()) {
            throw ValidationException::withMessages(['decide' => 'Der Termin ist schon festgelegt.']);
        }

        $event = DB::transaction(function () use ($by, $poll, $option, $rsvp) {
            $event = $this->events->create([
                'title' => $poll->title, 'type' => $poll->type, 'starts_at' => $option->starts_at->copy(), 'ends_at' => $option->ends_at->copy(),
                'all_day' => $option->all_day, 'location' => $poll->location, 'description' => $poll->description, 'rsvp' => $rsvp,
            ], $poll->groups()->pluck('groups.id')->all(), $by, userIds: $poll->invitees()->pluck('users.id')->all(),
                functionIds: $poll->functions()->pluck('member_functions.id')->all())->first();

            if ($rsvp) {
                foreach ($option->votes()->whereIn('answer', ['yes', 'maybe'])->get() as $vote) {
                    Response::updateOrCreate(['event_id' => $event->id, 'user_id' => $vote->user_id], ['status' => $vote->answer]);
                }
            }
            $poll->update(['event_id' => $event->id, 'closed_at' => now()]);

            return $event;
        });

        if ($notify) {
            // Everyone of the target group learns the date, like for a new event
            $this->events->announce($event, $by);
        }

        return $event;
    }

    public function close(User $by, Poll $poll): void
    {
        $this->assertManager($by);
        $poll->update(['closed_at' => $poll->closed_at ?? now()]);
    }

    public function reopen(User $by, Poll $poll): void
    {
        $this->assertManager($by);
        if ($poll->isDecided()) {
            throw ValidationException::withMessages(['decide' => 'Der Termin ist schon festgelegt.']);
        }
        $poll->update(['closed_at' => null, 'deadline' => $poll->deadline?->isPast() ? null : $poll->deadline]);
    }

    public function delete(User $by, Poll $poll): void
    {
        $this->assertManager($by);
        $poll->delete();
    }

    /** One reminder to everyone who has not answered, two days before the deadline */
    public function remind(): int
    {
        $sent = 0;
        $ids = Poll::whereNull('closed_at')->whereNull('reminder_sent_at')->whereNotNull('deadline')
            ->where('deadline', '>', now())->where('deadline', '<=', now()->addDays(2))->pluck('id');

        foreach ($ids as $id) {
            if (DB::table('kal_polls')->where('id', $id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()]) !== 1) {
                continue;
            }
            $poll = Poll::find($id);
            $open = $this->results($poll)['open'];
            $this->notify($open, new PollReminderNotification($poll));
            $sent += $open->count();
        }

        return $sent;
    }

    private function assertManager(User $user): void
    {
        if (! $user->canManageKalender()) {
            throw ValidationException::withMessages(['poll' => 'Terminabfragen legen Terminverwalter und Admins an.']);
        }
    }

    private function notify(Collection $users, $notification): void
    {
        $users->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
