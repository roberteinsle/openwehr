<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property ?int $group_id */
class Size extends Model
{
    protected $table = 'kk_sizes';

    protected $fillable = [
        'item_type_id',
        'group_id',
        'name',
        'sort_order',
    ];

    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'group_id');
    }

    /** Sizes without a group apply to every group of the item type. */
    public function scopeForGroup(Builder $query, mixed $groupId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('group_id')->when($groupId, fn (Builder $q) => $q->orWhere('group_id', $groupId)));
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'size_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'size_id');
    }
}
