<?php

namespace Modules\Objektplaene\src\Support;

use App\Models\User;
use App\Services\SearchRegistry;
use Illuminate\Support\Collection;
use Modules\Objektplaene\src\Models\PlanObject;

/** Header search: objects with plans, by name and address */
final class SearchSource
{
    public static function search(string $term, User $user): Collection
    {
        $like = SearchRegistry::like($term);

        return PlanObject::active()
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('address', 'ilike', $like))
            ->orderBy('name')->limit(SearchRegistry::LIMIT)->get()
            ->map(fn (PlanObject $o) => ['title' => $o->name, 'detail' => (string) $o->address, 'url' => route('objektplaene.object', $o)]);
    }
}
