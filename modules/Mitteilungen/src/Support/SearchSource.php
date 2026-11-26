<?php

namespace Modules\Mitteilungen\src\Support;

use App\Models\User;
use App\Services\SearchRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Mitteilungen\src\Models\Announcement;

/** Header search: current Mitteilungen meant for the Kamerad, by title and text */
final class SearchSource
{
    public static function search(string $term, User $user): Collection
    {
        $like = SearchRegistry::like($term);

        return Announcement::for($user)->current()
            ->where(fn ($q) => $q->where('title', 'ilike', $like)->orWhere('body', 'ilike', $like))
            ->orderByDesc('important')->latest()->limit(SearchRegistry::LIMIT)->get()
            ->map(fn (Announcement $a) => [
                'title' => $a->title,
                'detail' => Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $a->body)), 120),
                'url' => route('mitteilungen.index'),
            ]);
    }
}
