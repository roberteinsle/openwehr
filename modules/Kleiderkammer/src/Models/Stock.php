<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $table = 'kk_stock';

    protected $fillable = [
        'store_id',
        'item_type_id',
        'size_id',
        'group_id',
        'quantity',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    // Without a Lager a piece or stock row goes into the Lager of its Artikel, or the first one
    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->store_id ??= ItemType::whereKey($row->item_type_id)->value('store_id') ?? Store::defaultId();
        });
    }

    /** @return BelongsTo<ItemType, $this> */
    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    /** @return BelongsTo<ItemGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'group_id');
    }
}
