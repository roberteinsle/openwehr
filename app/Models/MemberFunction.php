<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a member does ("Jugendwart"), as opposed to the Abteilung they belong to ("Jugendfeuerwehr").
 * Without a parent it is a function, with a parent a sub-function (two levels).
 */
class MemberFunction extends Model
{
    protected $fillable = ['parent_id', 'name', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'member_function_user');
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /** What a member can be given: sub-functions, and functions that have none */
    public function scopeAssignable($query)
    {
        return $query->whereDoesntHave('children');
    }

    /** The function that makes a member part of the Vorstand, together with its sub-functions */
    public const VORSTAND = 'Vorstand';

    /** @return list<int> the "Vorstand" function and all its sub-functions */
    public static function vorstandIds(): array
    {
        return self::idsWithChildren(self::topLevel()->whereRaw('lower(name) = ?', [mb_strtolower(self::VORSTAND)])->pluck('id')->all());
    }

    /** @return list<int> the given functions and their sub-functions, so a function stands for all below it */
    public static function idsWithChildren(array $ids): array
    {
        return array_values(array_unique([...array_map('intval', $ids), ...self::whereIn('parent_id', $ids)->pluck('id')->all()]));
    }

    /** "Funktion › Unterfunktion" */
    public function fullLabel(): string
    {
        return collect([$this->parent?->name, $this->name])->filter()->implode(' › ');
    }
}
