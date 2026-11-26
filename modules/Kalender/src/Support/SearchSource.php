<?php

namespace Modules\Kalender\src\Support;

use App\Models\User;
use App\Services\SearchRegistry;
use Illuminate\Support\Collection;
use Modules\Kalender\src\Models\Event;

/** Header search: Termine the Kamerad may see, coming ones first, then those of the last 30 days */
final class SearchSource
{
    public static function search(string $term, User $user): Collection
    {
        $like = SearchRegistry::like($term);

        return Event::visibleTo($user)->whereNull('cancelled_at')
            ->where('ends_at', '>', now()->subDays(30))
            ->where(fn ($q) => $q->where('title', 'ilike', $like)->orWhere('location', 'ilike', $like)->orWhere('description', 'ilike', $like))
            ->orderByRaw('CASE WHEN ends_at > ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('starts_at')->limit(SearchRegistry::LIMIT)->get()
            ->map(function (Event $e) {
                $start = $e->starts_at->copy()->setTimezone('Europe/Berlin');

                return [
                    'title' => $e->title,
                    'detail' => collect([$e->all_day ? $start->isoFormat('dd, D.M.YYYY') : $start->isoFormat('dd, D.M.YYYY, HH:mm').' Uhr', $e->typeLabel(), $e->location])->filter()->implode(' · '),
                    'url' => route('kalender.show', $e),
                ];
            });
    }
}
