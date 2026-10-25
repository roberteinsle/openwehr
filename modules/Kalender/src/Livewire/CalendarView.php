<?php

namespace Modules\Kalender\src\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\EventType;
use Modules\Kalender\src\Services\FeedService;

/**
 * Month, week and list view, filtered by type. The view a Kamerad starts with is the default
 * calendar view from the profile, the same setting the Fahrzeugbuchung uses.
 */
class CalendarView extends Component
{
    /** Profile setting (FullCalendar names) → view here */
    private const FROM_PROFILE = ['dayGridMonth' => 'monat', 'timeGridWeek' => 'woche', 'listWeek' => 'liste'];

    public const VIEWS = ['monat' => 'Monat', 'woche' => 'Woche', 'liste' => 'Liste'];

    #[Url(as: 'ansicht')]
    public string $view = '';

    /** Any day in the period shown, Y-m-d */
    #[Url(as: 'datum')]
    public string $date = '';

    /** Older links: ?monat=2027-06 */
    #[Url(as: 'monat')]
    public string $month = '';

    #[Url(as: 'art')]
    public string $type = '';

    public bool $showFeed = false;

    public ?string $feedUrl = null;

    public function mount(): void
    {
        if (! array_key_exists($this->view, self::VIEWS)) {
            $this->view = self::FROM_PROFILE[auth()->user()->calendarView()] ?? 'monat';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->date = $this->month.'-01';
            $this->month = '';
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = now('Europe/Berlin')->toDateString();
        }
    }

    /** One month, one week or (in the list) one month back or forth */
    public function shift(int $steps): void
    {
        $day = Carbon::createFromFormat('!Y-m-d', $this->date, 'Europe/Berlin');
        $this->date = ($this->view === 'woche' ? $day->addWeeks($steps) : $day->startOfMonth()->addMonthsNoOverflow($steps))->toDateString();
    }

    public function today(): void
    {
        $this->date = now('Europe/Berlin')->toDateString();
    }

    public function setView(string $view): void
    {
        if (array_key_exists($view, self::VIEWS)) {
            $this->view = $view;
        }
    }

    public function toggleFeed(FeedService $feeds): void
    {
        $this->showFeed = ! $this->showFeed;
        $this->feedUrl = $this->showFeed ? $feeds->url(auth()->user()) : null;
    }

    public function regenerateFeed(FeedService $feeds): void
    {
        $feeds->regenerate(auth()->user());
        $this->feedUrl = $feeds->url(auth()->user());
    }

    public function render()
    {
        $user = auth()->user();
        $anchor = Carbon::createFromFormat('!Y-m-d', $this->date, 'Europe/Berlin');

        // The period shown: whole weeks around the month, one week, or the month as a list
        // $until is the midnight after the last day (exclusive)
        [$from, $until] = match ($this->view) {
            'woche' => [$from = $anchor->copy()->startOfWeek(Carbon::MONDAY), $from->copy()->addWeek()],
            'liste' => [$anchor->copy()->startOfMonth(), $anchor->copy()->startOfMonth()->addMonthNoOverflow()],
            default => [$anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->startOfDay()->addDay()],
        };

        $base = fn () => Event::visibleTo($user)->when(array_key_exists($this->type, EventType::labels()), fn ($q) => $q->where('type', $this->type));
        $events = $base()->between($from->copy()->utc(), $until->copy()->utc())->orderBy('starts_at')
            ->with(['responses' => fn ($q) => $q->where('user_id', $user->id)])->get();

        // Every day of the period with the events touching it (multi-day events appear on each day)
        $days = [];
        for ($day = $from->copy(); $day->lt($until); $day->addDay()) {
            $dayStart = $day->copy();
            $dayEnd = $day->copy()->addDay();
            $days[] = [
                'date' => $dayStart,
                'inMonth' => $dayStart->month === $anchor->month,
                'isToday' => $dayStart->isSameDay(now('Europe/Berlin')),
                'events' => $events->filter(fn (Event $e) => $e->starts_at->lt($dayEnd) && $e->ends_at->gt($dayStart))->values(),
            ];
        }

        $upcoming = $this->view === 'liste' ? collect() : $base()->where('ends_at', '>', now())->where('starts_at', '<', now()->addDays(60))
            ->orderBy('starts_at')->limit(30)->with(['responses' => fn ($q) => $q->where('user_id', $user->id)])
            ->withCount(['responses as yes_count' => fn ($q) => $q->where('status', 'yes')])->get();

        return view('kalender::livewire.calendar-view', [
            'anchor' => $anchor,
            'from' => $from,
            'until' => $until->copy()->subSecond(),
            'days' => $days,
            'upcoming' => $upcoming,
            'types' => Event::types(),
            'views' => self::VIEWS,
            'manager' => $user->canManageKalender(),
        ]);
    }
}
