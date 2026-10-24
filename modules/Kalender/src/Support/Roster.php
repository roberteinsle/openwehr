<?php

namespace Modules\Kalender\src\Support;

use App\Models\DressCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Kalender\src\Models\Event;

/**
 * The Dienstplan to print and hang up: the Termine of a year or half-year with time, place, Kleidung,
 * who takes part and who runs it. Only what the viewer may see; personal Termine (invitees only, e.g.
 * Führerschein dates), external calendars and cancelled ones are left out.
 */
class Roster
{
    public const PERIODS = ['jahr' => 'Ganzes Jahr', 'h1' => '1. Halbjahr', 'h2' => '2. Halbjahr'];

    /** Text colour per type, like a printed Dienstplan */
    public const COLORS = ['uebung' => '#111827', 'dienst' => '#1d4ed8', 'veranstaltung' => '#15803d', 'wartung' => '#b45309', 'arbeitsdienst' => '#c2410c', 'sonstiges' => '#6b7280'];

    /** @return array{0: Carbon, 1: Carbon} start and end (exclusive) in Berlin time */
    public static function range(int $year, string $period): array
    {
        $start = Carbon::create($year, $period === 'h2' ? 7 : 1, 1, 0, 0, 0, 'Europe/Berlin');

        return [$start, $period === 'h1' ? $start->copy()->addMonths(6) : Carbon::create($year + 1, 1, 1, 0, 0, 0, 'Europe/Berlin')];
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>> date, time, title, location, dress, audience, organizer, color and the event
     */
    public static function rows(User $viewer, int $year, string $period, ?int $groupId, array $types): array
    {
        [$from, $until] = self::range($year, $period);
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

        return Event::visibleTo($viewer)->with(['groups', 'functions'])->withCount('invitees')
            ->where('starts_at', '>=', $from->copy()->utc())->where('starts_at', '<', $until->copy()->utc())
            ->whereNull('cancelled_at')->whereNull('source_id')
            ->where(fn ($q) => $q->whereNull('managed_by')->orWhereNotIn('managed_by', ['fuehrerscheine', 'fahrzeuge']))
            ->when($types !== [], fn ($q) => $q->whereIn('type', $types))
            ->orderBy('starts_at')->get()
            // Personal Termine (only single invitees) don't belong on a Dienstplan
            ->filter(fn (Event $e) => $e->groups->isNotEmpty() || $e->functions->isNotEmpty() || $e->invitees_count === 0)
            // For one Abteilung: its Termine and those for everyone
            ->filter(fn (Event $e) => ! $groupId || ($e->groups->isEmpty() && $e->functions->isEmpty()) || $e->groups->contains('id', $groupId))
            ->map(function ($e) use ($days) {
                $start = Carbon::parse($e->getAttribute('starts_at'))->setTimezone('Europe/Berlin');
                $partners = $e->getAttribute('partners');
                $audience = [...$e->groups->pluck('name')->all(), ...$e->functions->pluck('name')->all()];

                return [
                    'event' => $e,
                    'date' => $days[$start->dayOfWeek].' '.$start->format('d.m.Y'),
                    'time' => $e->all_day ? '' : $start->format('H:i'),
                    'title' => $e->title.($partners ? ' (mit '.$partners.')' : ''),
                    'location' => $e->location,
                    'dress' => implode(', ', DressCode::names($e->getAttribute('dress_code_ids'))),
                    'audience' => $audience === [] ? 'Alle' : implode(', ', $audience),
                    'organizer' => $e->getAttribute('organizer'),
                    'color' => self::COLORS[$e->type] ?? '#111827',
                ];
            })->values()->all();
    }
}
