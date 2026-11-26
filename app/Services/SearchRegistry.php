<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The search in the header (/suche): each module registers a search over what a Kamerad may see there
 * (Termine, Mitteilungen, Objekte …). The closure gets the term (at least two characters) and the user
 * and returns rows, or null/empty for nothing. Sources of inactive modules and of modules the user
 * can't use are skipped; module null = core.
 *
 * Rows: {title, detail (optional, one line), url}
 */
class SearchRegistry
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /** Rows per source on the results page */
    public const LIMIT = 8;

    /** @var array<string, array{module: ?string, label: string, search: callable, order: int}> */
    private array $sources = [];

    /**
     * @param  string  $key  unique, e.g. "kalender.termine"
     * @param  ?string  $module  module key, null for the core
     * @param  callable(string $term, User $user): ?iterable  $search
     */
    public function register(string $key, ?string $module, string $label, callable $search, int $order = 50): void
    {
        $this->sources[$key] = ['module' => $module, 'label' => $label, 'search' => $search, 'order' => $order];
    }

    /** @return Collection<string, array{label: string, rows: Collection<int, mixed>}> by source, only those with hits, in order */
    public function search(string $term, User $user): Collection
    {
        $term = self::clean($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return collect();
        }
        $modules = app(ModuleRegistry::class);

        return collect($this->sources)
            ->filter(fn ($s) => $s['module'] === null || ($modules->isActive($s['module']) && $user->canUseModule($s['module'])))
            ->sortBy('order')
            ->map(fn ($s) => ['label' => $s['label'], 'rows' => $this->call($s['search'], $term, $user)->take(self::LIMIT)->values()])
            ->filter(fn ($s) => $s['rows']->isNotEmpty());
    }

    /** Trimmed, single spaces, at most MAX_LENGTH characters */
    public static function clean(string $term): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $term)), 0, self::MAX_LENGTH);
    }

    /** The term for ILIKE, with % and _ taken literally: "%term%" */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }

    private function call(callable $fn, string $term, User $user): Collection
    {
        try {
            return collect($fn($term, $user) ?? []);
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }
}
