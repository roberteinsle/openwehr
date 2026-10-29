<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Kleiderkammer\src\Concerns\TracksUser;
use Modules\Kleiderkammer\src\Enums\ItemStatus;

/**
 * @property ItemStatus $status
 * @property ?int $group_id
 */
class Item extends Model
{
    use SoftDeletes, TracksUser;

    protected $table = 'kk_items';

    protected $fillable = [
        'store_id',
        'item_type_id',
        'size_id',
        'group_id',
        'code',
        'status',
        'notes',
    ];

    // Without a Lager a piece or stock row goes into the Lager of its Artikel, or the first one
    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->store_id ??= ItemType::whereKey($row->item_type_id)->value('store_id') ?? Store::defaultId();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
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

    public function issuedItems(): HasMany
    {
        return $this->hasMany(IssuedItem::class, 'item_id');
    }

    /** The current open issuance record for this item, if any */
    public function currentIssuance(): HasMany
    {
        return $this->issuedItems()->whereNull('returned_at');
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(ItemTreatment::class, 'item_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === ItemStatus::Available;
    }

    public function isIssued(): bool
    {
        return $this->status === ItemStatus::Issued;
    }

    public function isRetired(): bool
    {
        return $this->status === ItemStatus::Retired;
    }
}
