<?php

namespace Modules\Kalender\src\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ModuleRegistry;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\FeedService;
use Modules\Kalender\src\Services\IcsExporter;

/**
 * Subscription link for phone calendars: no login, the secret token identifies the Kamerad – or a parent
 * (Eltern-Bereich), whose link has the Termine of all their children, each titled with the child's name.
 */
class FeedController extends Controller
{
    public function __invoke(string $token, FeedService $feeds, IcsExporter $exporter)
    {
        abort_unless(app(ModuleRegistry::class)->isActive('kalender'), 404);

        $user = $feeds->userFor($token);
        if ($user) {
            abort_unless($user->is_active && $user->canUseModule('kalender'), 404);
            $events = $this->eventsFor($user);
            $body = $exporter->render($events);
        } else {
            $children = $feeds->childrenFor($token);
            abort_if($children === null || $children->isEmpty(), 404);
            // Each child's Termine; a Termin for two siblings appears once, with both names
            $events = $children->flatMap(fn (User $child) => $this->eventsFor($child)->each(fn (Event $e) => $e->setAttribute('for_child', $child->name)))
                ->groupBy('id')->map(function ($same) use ($children) {
                    $event = $same->first();
                    if ($children->count() > 1) {
                        $event->title = $same->pluck('for_child')->unique()->implode(', ').': '.$event->title;
                    }

                    return $event;
                })->sortBy('starts_at')->values();
            // Parents can't open the Termin pages of the app: no links in the entries
            $body = $exporter->render($events, withLinks: false);
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="kalender.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    private function eventsFor(User $user)
    {
        return Event::visibleTo($user)->where('ends_at', '>', now()->subDays(90))->where('starts_at', '<', now()->addYear())
            ->orderBy('starts_at')->get();
    }
}
