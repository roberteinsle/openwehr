<?php

namespace Modules\Wasserkarte\src\Support;

use App\Models\User;
use App\Services\SearchRegistry;
use Illuminate\Support\Collection;
use Modules\Wasserkarte\src\Models\Source;

/** Header search: Wasserquellen by number and location */
final class SearchSource
{
    public static function search(string $term, User $user): Collection
    {
        $like = SearchRegistry::like($term);

        return Source::active()
            ->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('location', 'ilike', $like))
            ->orderBy('number')->limit(SearchRegistry::LIMIT)->get()
            ->map(fn (Source $s) => ['title' => $s->label(), 'detail' => (string) $s->location, 'url' => route('wasserkarte.source', $s)]);
    }
}
