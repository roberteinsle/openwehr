<?php

namespace Modules\Uebungsdienste\src\Support;

use App\Models\User;
use App\Services\SearchRegistry;
use Illuminate\Support\Collection;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/** Header search: coming Übungsdienste the Kamerad may see, by topic and place */
final class SearchSource
{
    public static function search(string $term, User $user): Collection
    {
        $like = SearchRegistry::like($term);

        return ServicePlan::visibleTo($user)
            ->where('date', '>=', now('Europe/Berlin')->toDateString())
            ->where(fn ($q) => $q->where('topic', 'ilike', $like)->orWhere('location', 'ilike', $like))
            ->orderBy('date')->orderBy('starts_at')->limit(SearchRegistry::LIMIT)->get()
            ->map(fn (Service $s) => [
                'title' => $s->topic ?: 'Übungsdienst',
                'detail' => collect([$s->date->isoFormat('dd, D.M.YYYY').($s->starts_at ? ', '.substr((string) $s->starts_at, 0, 5).' Uhr' : ''), $s->location])->filter()->implode(' · '),
                'url' => route('uebungsdienste.index'),
            ]);
    }
}
