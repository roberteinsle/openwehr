<?php

namespace Modules\Uebungsdienste\src\Services;

use App\Models\DressCode;
use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Place;
use App\Models\User;
use App\Services\VehicleBookingRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Models\ServiceVehicle;
use Modules\Uebungsdienste\src\Models\Swap;
use Modules\Uebungsdienste\src\Notifications\ResponsibleNotification;
use Modules\Uebungsdienste\src\Notifications\ServiceExchangedNotification;
use Modules\Uebungsdienste\src\Notifications\SwapAnsweredNotification;
use Modules\Uebungsdienste\src\Notifications\SwapRequestedNotification;
use Throwable;

/**
 * Changes to the Übungsdienstplan. Admins and Übungsdienstplaner (role, optionally limited to
 * Abteilungen in ud_scopes) create, change and delete Dienste, set topic and responsible people and
 * book the vehicles a Dienst needs. Everyone else with access swaps Dienste: the other responsible
 * Kamerad is asked and agrees or declines.
 */
class ServicePlan
{
    public const MODULE = 'uebungsdienste';

    public const ROLE = 'übungsdienstplaner';

    public const MAX_SERIES = 60;

    /** Vehicles are booked this long before the start and after the end */
    public const VEHICLE_BUFFER_MINUTES = 30;

    /** @var list<string> what went wrong with the vehicle bookings of the last change */
    private array $warnings = [];

    // ─── who may do what ─────────────────────────────────────────────────────

    /** Admins and Übungsdienstplaner */
    public static function canPlan(User $user): bool
    {
        return $user->is_active && ($user->is_admin || ($user->canUseModule(self::MODULE) && $user->hasModuleRole(self::MODULE, self::ROLE)));
    }

    /** The Abteilungen an Übungsdienstplaner plans for; [] = all (and the whole Wehr) */
    public static function scopeOf(User $user): array
    {
        return $user->is_admin ? [] : DB::table('ud_scopes')->where('user_id', $user->id)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Admins: which Abteilungen an Übungsdienstplaner plans for (none = all) */
    public function setScope(User $by, User $planner, array $groupIds): void
    {
        if (! $by->is_admin) {
            throw ValidationException::withMessages(['scope' => 'Das dürfen nur Admins.']);
        }
        DB::transaction(function () use ($planner, $groupIds) {
            DB::table('ud_scopes')->where('user_id', $planner->id)->delete();
            DB::table('ud_scopes')->insert(Group::whereIn('id', array_map('intval', $groupIds))->pluck('id')
                ->map(fn ($id) => ['user_id' => $planner->id, 'group_id' => $id])->all());
        });
    }

    /**
     * For whom $user may plan: [null => 'Ganze Wehr', groupId => name …]. Admins and planners without
     * Abteilungen plan for everyone, the others for their Abteilungen only.
     *
     * @return array<int|string, string>
     */
    public static function ownerOptions(User $user): array
    {
        if (! self::canPlan($user)) {
            return [];
        }
        $scope = self::scopeOf($user);
        $groups = Group::orderBy('sort_order')->orderBy('name')->when($scope !== [], fn ($q) => $q->whereIn('id', $scope))->pluck('name', 'id')->all();

        return $scope === [] ? ['' => 'Ganze Wehr'] + $groups : $groups;
    }

    /**
     * Swap two Dienste directly (topic and responsible people, without asking): admins, Übungsdienstplaner
     * and the Vorstand (the Funktion "Vorstand" or a sub-function) with access to the module.
     */
    public static function canExchange(User $user): bool
    {
        return self::canPlan($user) || ($user->is_active && $user->canUseModule(self::MODULE) && $user->isVorstand());
    }

    /** Change, delete, topic and responsible people: planners for the Dienste of their Abteilungen */
    public static function mayEdit(User $user, Service $service): bool
    {
        if (! self::canPlan($user)) {
            return false;
        }
        $scope = self::scopeOf($user);

        return $scope === [] || ($service->owner_group_id && in_array((int) $service->owner_group_id, $scope, true));
    }

    /**
     * The Dienste $user sees in the plan: everything for admins and planners without Abteilungen,
     * otherwise those of the whole Wehr and of the own Abteilungen (member or planner).
     */
    /** @return Builder<Service> */
    public static function visibleTo(User $user): Builder
    {
        $query = Service::query();
        if ($user->is_admin || (self::canPlan($user) && self::scopeOf($user) === [])) {
            return $query;
        }
        $groups = array_values(array_unique([...$user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all(), ...self::scopeOf($user)]));

        return $query->where(fn ($q) => $q->whereNull('owner_group_id')->orWhereIn('owner_group_id', $groups));
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    /** The next Dienst from today on, of those $user sees in the plan */
    public function next(?User $user = null): ?Service
    {
        return ($user ? self::visibleTo($user) : Service::query())->with('responsible')
            ->whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->first();
    }

    /** The next Übungsdienst for a Kamerad: one without target group, or of their Abteilung or Funktion (with sub-functions) */
    public function nextFor(User $user): ?Service
    {
        $groups = $user->groups()->pluck('groups.id')->all();
        $functions = $user->functions()->pluck('member_functions.id')->all();

        return Service::whereDate('date', '>=', self::today())->orderBy('date')->orderBy('starts_at')->get()
            ->first(function (Service $s) use ($groups, $functions) {
                $wantGroups = array_map('intval', $s->group_ids ?? []);
                $wantFunctions = MemberFunction::idsWithChildren(array_map('intval', $s->function_ids ?? []));

                return ($wantGroups === [] && $wantFunctions === []) || array_intersect($groups, $wantGroups) || array_intersect($functions, $wantFunctions);
            });
    }

    /**
     * @param  array{ends_at?: ?string, place_id?: ?int, dress_code_ids?: list<int>, group_ids?: list<int>, function_ids?: list<int>, owner_group_id?: ?int, organizer?: ?string, vehicle_ids?: list<int|string>, rsvp?: bool, rsvp_days?: int|string|null, notify?: bool}  $details
     */
    public function add(User $by, string $date, ?string $time, ?string $location, array $details = []): Service
    {
        $this->warnings = [];
        $data = $this->schedule($date, $time, $location, $details);
        $this->assertMayPlanFor($by, $data['owner_group_id']);
        $service = Service::create($data);
        app(CalendarLink::class)->sync($service, $by, (bool) ($details['notify'] ?? false));
        $this->bookVehicles($service, $details['vehicle_ids'] ?? [], $by);

        return $service;
    }

    /** Every $weeks weeks from $from to $until, e.g. every second Tuesday */
    public function addSeries(User $by, string $from, string $until, int $weeks, ?string $time, ?string $location, array $details = []): Collection
    {
        $this->warnings = [];
        $this->assertMayPlanFor($by, $this->schedule($from, $time, $location, $details)['owner_group_id']);
        $start = $this->date($from);
        $end = $this->date($until);

        if ($end->lt($start) || $weeks < 1) {
            throw ValidationException::withMessages(['series' => 'Bitte ein Enddatum nach dem Beginn und einen Abstand von mindestens einer Woche angeben.']);
        }

        $created = collect();
        for ($day = $start->copy(); $day->lte($end) && $created->count() < self::MAX_SERIES; $day->addWeeks($weeks)) {
            $created->push(Service::create($this->schedule($day->toDateString(), $time, $location, $details)));
        }
        $created->each(function (Service $service) use ($by, $details) {
            app(CalendarLink::class)->sync($service, $by);
            $this->bookVehicles($service, $details['vehicle_ids'] ?? [], $by);
        });
        // One mail for the whole series (with the number of Dienste), not one per Dienst
        if ($details['notify'] ?? false) {
            app(CalendarLink::class)->announceSeries($created, $by);
        }

        return $created;
    }

    public function reschedule(User $by, Service $service, string $date, ?string $time, ?string $location, ?array $details = null): void
    {
        $this->warnings = [];
        $this->assertMayEdit($by, $service);
        // Callers that don't know the details keep them
        $details ??= ['ends_at' => $service->ends_at, 'place_id' => $service->place_id, 'dress_code_ids' => $service->dress_code_ids ?? [],
            'group_ids' => $service->group_ids ?? [], 'function_ids' => $service->function_ids ?? [],
            'owner_group_id' => $service->owner_group_id, 'organizer' => $service->organizer, 'rsvp' => $service->rsvp, 'rsvp_days' => $service->rsvp_days, 'vehicle_ids' => $service->vehicles()->pluck('vehicle_id')->all()];
        $data = $this->schedule($date, $time, $location, $details);
        $this->assertMayPlanFor($by, $data['owner_group_id']);
        $service->update($data);
        app(CalendarLink::class)->sync($service->refresh(), $by, (bool) ($details['notify'] ?? false));
        // The time may have moved: book the vehicles anew
        $this->releaseVehicles($service);
        $this->bookVehicles($service, $details['vehicle_ids'] ?? [], $by);
    }

    public function delete(User $by, Service $service): void
    {
        $this->assertMayEdit($by, $service);
        app(CalendarLink::class)->remove($service);
        $this->releaseVehicles($service);
        $service->delete();
    }

    // ─── vehicles ────────────────────────────────────────────────────────────

    /**
     * Books the vehicles for the Dienst, from VEHICLE_BUFFER_MINUTES before the start until as long after
     * the end, through the core VehicleBookingRegistry (Fahrzeugbuchung). A vehicle that can't be booked
     * (already booked, Werkstatt …) stays on the Dienst with the reason and becomes a warning.
     *
     * @param  list<int|string>  $vehicleIds
     */
    private function bookVehicles(Service $service, array $vehicleIds, User $by): void
    {
        $bookings = app(VehicleBookingRegistry::class);
        $vehicleIds = array_values(array_unique(array_map('intval', array_filter($vehicleIds))));
        if ($vehicleIds === [] || ! $bookings->available()) {
            return;
        }
        $names = $bookings->vehicles();
        $period = $service->period();
        foreach ($vehicleIds as $vehicleId) {
            if (! $names->has($vehicleId)) {
                continue;
            }
            $bookingId = null;
            $note = null;
            if (! $period) {
                $note = 'nicht gebucht: der Dienst hat keine Uhrzeit';
            } else {
                try {
                    $bookingId = $bookings->book($vehicleId, $period[0]->copy()->subMinutes(self::VEHICLE_BUFFER_MINUTES),
                        $period[1]->copy()->addMinutes(self::VEHICLE_BUFFER_MINUTES), 'Übungsdienst'.($service->ownerGroup ? ' '.$service->ownerGroup->name : ''), $by);
                } catch (ValidationException $e) {
                    $note = 'nicht gebucht: '.collect($e->errors())->flatten()->first();
                }
            }
            ServiceVehicle::updateOrCreate(['service_id' => $service->id, 'vehicle_id' => $vehicleId], ['booking_id' => $bookingId, 'note' => $note]);
            if ($note) {
                $this->warnings[] = $names[$vehicleId].' am '.$service->dateLabel().' '.$note;
            }
        }
    }

    /** Cancels the vehicle bookings of the Dienst and forgets the vehicles */
    private function releaseVehicles(Service $service): void
    {
        $bookings = app(VehicleBookingRegistry::class);
        $service->vehicles()->get()->each(function (ServiceVehicle $v) use ($bookings) {
            if ($v->booking_id) {
                try {
                    $bookings->cancel((int) $v->booking_id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
            $v->delete();
        });
    }

    // ─── topic, responsible people, swaps ────────────────────────────────────

    public function setTopic(Service $service, ?string $topic, ?User $by = null): void
    {
        if ($by) {
            $this->assertMayEdit($by, $service);
        }
        $topic = trim((string) $topic);

        if (mb_strlen($topic) > 1000) {
            throw ValidationException::withMessages(['topic' => 'Das Thema ist zu lang (höchstens 1000 Zeichen).']);
        }

        $service->update(['topic' => $topic !== '' ? $topic : null]);
    }

    /** Whoever is added to a coming Übungsdienst hears about it, unless they added themselves */
    public function addResponsible(Service $service, User $user, ?User $by = null): void
    {
        if ($by) {
            $this->assertMayEdit($by, $service);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['responsible' => 'Nur aktive Kameraden können verantwortlich sein.']);
        }

        $added = $service->responsible()->syncWithoutDetaching([$user->id])['attached'] !== [];
        app(CalendarLink::class)->syncResponsible($service);

        if ($added && ! $user->is($by) && $service->date->gte(self::today())) {
            try {
                $user->notify(new ResponsibleNotification($service, $by));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function removeResponsible(Service $service, int $userId, ?User $by = null): void
    {
        if ($by) {
            $this->assertMayEdit($by, $service);
        }
        $service->responsible()->detach($userId);
    }

    /**
     * $by, responsible for $service, asks $target (responsible for $other) to swap. Both Dienste must lie
     * ahead and not wait for another swap; the target gets a notification and a mail.
     */
    public function requestSwap(User $by, Service $service, Service $other, User $target): Swap
    {
        $errors = match (true) {
            ! $by->canUseModule(self::MODULE) => 'Tauschen können alle mit Zugang zum Übungsdienstplaner.',
            ! $service->responsible()->whereKey($by->id)->exists() => 'Tauschen kann nur, wer für den Dienst verantwortlich ist.',
            $service->is($other) => 'Bitte einen anderen Dienst wählen.',
            ! $other->responsible()->whereKey($target->id)->exists() => $target->name.' ist für diesen Dienst nicht verantwortlich.',
            $target->is($by) || $other->responsible()->whereKey($by->id)->exists() => 'Für diesen Dienst bist du schon verantwortlich.',
            $service->date->lt(self::today()) || $other->date->lt(self::today()) => 'Nur kommende Dienste lassen sich tauschen.',
            Swap::where('status', 'pending')->whereIn('service_id', [$service->id, $other->id])->exists()
                || Swap::where('status', 'pending')->whereIn('target_service_id', [$service->id, $other->id])->exists() => 'Für einen der Dienste ist schon ein Tausch angefragt.',
            ! self::visibleTo($by)->whereKey($other->id)->exists() => 'Diesen Dienst kannst du nicht sehen.',
            default => null,
        };
        if ($errors) {
            throw ValidationException::withMessages(['swap' => $errors]);
        }
        $swap = Swap::create(['service_id' => $service->id, 'requester_id' => $by->id, 'target_service_id' => $other->id, 'target_user_id' => $target->id, 'status' => 'pending']);
        $this->notify($target, new SwapRequestedNotification($swap->load(['requester', 'service', 'targetService'])));

        return $swap;
    }

    /**
     * The Vorstand swaps two coming Dienste in one go: topic and responsible people change places, date,
     * time and place stay. Everyone responsible afterwards is told (bell, push, mail); open swap requests
     * for the two Dienste are withdrawn.
     */
    public function exchange(User $by, Service $a, Service $b): void
    {
        $error = match (true) {
            ! self::canExchange($by) => 'Dienste tauschen können der Vorstand, die Übungsdienstplaner und die Admins.',
            $a->is($b) => 'Bitte einen anderen Dienst wählen.',
            $a->date->lt(self::today()) || $b->date->lt(self::today()) => 'Nur kommende Dienste lassen sich tauschen.',
            default => null,
        };
        if ($error || self::visibleTo($by)->whereKey([$a->id, $b->id])->count() !== 2) {
            throw ValidationException::withMessages(['exchange' => $error ?? 'Diesen Dienst kannst du nicht sehen.']);
        }

        [$aPeople, $bPeople] = DB::transaction(function () use ($a, $b) {
            $a = Service::lockForUpdate()->findOrFail($a->id);
            $b = Service::lockForUpdate()->findOrFail($b->id);
            $aPeople = $a->responsible()->pluck('users.id')->all();
            $bPeople = $b->responsible()->pluck('users.id')->all();
            [$aTopic, $bTopic] = [$a->topic, $b->topic];
            $a->update(['topic' => $bTopic]);
            $b->update(['topic' => $aTopic]);
            $a->responsible()->sync($bPeople);
            $b->responsible()->sync($aPeople);
            Swap::where('status', 'pending')->where(fn ($q) => $q->whereIn('service_id', [$a->id, $b->id])->orWhereIn('target_service_id', [$a->id, $b->id]))
                ->update(['status' => 'withdrawn', 'decided_at' => now()]);

            return [$aPeople, $bPeople];
        });
        $a->refresh();
        $b->refresh();
        app(CalendarLink::class)->syncResponsible($a);
        app(CalendarLink::class)->syncResponsible($b);
        // Who was on $a is now on $b and the other way round – unless they moved themselves
        foreach ([[$bPeople, $a, $b], [$aPeople, $b, $a]] as [$people, $now, $before]) {
            User::whereIn('id', $people)->where('id', '!=', $by->id)->get()
                ->each(fn (User $u) => $this->notify($u, new ServiceExchangedNotification($now, $before, $by)));
        }
    }

    /** The target agrees (both swap their place as responsible) or declines; the requester is told */
    public function answerSwap(User $by, Swap $swap, bool $accept): void
    {
        $swap = DB::transaction(function () use ($by, $swap, $accept) {
            $swap = Swap::lockForUpdate()->findOrFail($swap->id);
            if (! $swap->isPending()) {
                throw ValidationException::withMessages(['swap' => 'Dieser Tausch ist schon beantwortet.']);
            }
            if ($swap->target_user_id !== $by->id) {
                throw ValidationException::withMessages(['swap' => 'Antworten kann nur, wer gefragt wurde.']);
            }
            if ($accept) {
                $service = Service::findOrFail($swap->service_id);
                $other = Service::findOrFail($swap->target_service_id);
                $service->responsible()->detach($swap->requester_id);
                $service->responsible()->syncWithoutDetaching([$swap->target_user_id]);
                $other->responsible()->detach($swap->target_user_id);
                $other->responsible()->syncWithoutDetaching([$swap->requester_id]);
            }
            $swap->update(['status' => $accept ? 'accepted' : 'declined', 'decided_at' => now()]);

            return $swap;
        });
        if ($swap->status === 'accepted') {
            app(CalendarLink::class)->syncResponsible($swap->service);
            app(CalendarLink::class)->syncResponsible($swap->targetService);
        }
        $this->notify($swap->requester, new SwapAnsweredNotification($swap->load(['targetUser', 'service', 'targetService'])));
    }

    /** The requester takes the request back */
    public function withdrawSwap(User $by, Swap $swap): void
    {
        if (! $swap->isPending() || $swap->requester_id !== $by->id) {
            throw ValidationException::withMessages(['swap' => 'Zurückziehen kann nur, wer angefragt hat, solange noch keine Antwort da ist.']);
        }
        $swap->update(['status' => 'withdrawn', 'decided_at' => now()]);
    }

    private function notify(?User $user, $notification): void
    {
        try {
            $user?->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function schedule(string $date, ?string $time, ?string $location, array $details = []): array
    {
        $time = trim((string) $time);
        $end = trim((string) ($details['ends_at'] ?? ''));
        $clock = '/^([01]\d|2[0-3]):[0-5]\d$/';

        if ($time !== '' && ! preg_match($clock, $time)) {
            throw ValidationException::withMessages(['time' => 'Bitte die Uhrzeit als HH:MM angeben.']);
        }
        if ($end !== '' && (! preg_match($clock, $end) || $time === '')) {
            throw ValidationException::withMessages(['time' => 'Bitte das Ende als HH:MM angeben, und nur mit Beginn.']);
        }
        $ids = fn (string $key, string $class) => array_values(array_map('intval', $class::whereIn('id', array_map('intval', $details[$key] ?? []))->pluck('id')->all()));
        $place = filled($details['place_id'] ?? null) ? Place::find((int) $details['place_id']) : null;
        $owner = filled($details['owner_group_id'] ?? null) ? Group::find((int) $details['owner_group_id'])?->id : null;
        $groupIds = $ids('group_ids', Group::class);
        $functionIds = $ids('function_ids', MemberFunction::class);
        // A Dienst of an Abteilung is for that Abteilung unless "Für wen?" says otherwise
        if ($owner && $groupIds === [] && $functionIds === []) {
            $groupIds = [$owner];
        }
        $organizer = trim((string) ($details['organizer'] ?? ''));

        $rsvpDays = filled($details['rsvp_days'] ?? null) ? max(0, min(60, (int) $details['rsvp_days'])) : null;

        return [
            'rsvp' => (bool) ($details['rsvp'] ?? false),
            'rsvp_days' => ($details['rsvp'] ?? false) ? $rsvpDays : null,
            'owner_group_id' => $owner,
            'organizer' => $organizer !== '' ? mb_substr($organizer, 0, 100) : null,
            'date' => $this->date($date)->toDateString(),
            'starts_at' => $time !== '' ? $time : null,
            'ends_at' => $end !== '' ? $end : null,
            // A saved place gives the Ort its name
            'location' => $place?->name ?? (filled($location) ? mb_substr(trim($location), 0, 255) : null),
            'place_id' => $place?->id,
            'dress_code_ids' => $ids('dress_code_ids', DressCode::class) ?: null,
            'group_ids' => $groupIds ?: null,
            'function_ids' => $functionIds ?: null,
        ];
    }

    private function date(string $value): Carbon
    {
        return rescue(fn () => Carbon::createFromFormat('!Y-m-d', $value), null, false)
            ?? throw ValidationException::withMessages(['date' => 'Bitte ein gültiges Datum angeben.']);
    }

    /** Planning for the whole Wehr (null) or an Abteilung */
    private function assertMayPlanFor(User $user, ?int $groupId): void
    {
        if (! array_key_exists($groupId ?? '', self::ownerOptions($user))) {
            throw ValidationException::withMessages(['date' => self::canPlan($user)
                ? 'Für diese Abteilung planst du keine Übungsdienste.' : 'Übungsdienste anlegen, ändern und löschen nur Admins und Übungsdienstplaner.']);
        }
    }

    private function assertMayEdit(User $user, Service $service): void
    {
        if (! self::mayEdit($user, $service)) {
            throw ValidationException::withMessages(['date' => self::canPlan($user)
                ? 'Diesen Dienst plant eine andere Abteilung.' : 'Übungsdienste ändern nur Admins und Übungsdienstplaner – tauschen kannst du über „Tauschen“.']);
        }
    }
}
