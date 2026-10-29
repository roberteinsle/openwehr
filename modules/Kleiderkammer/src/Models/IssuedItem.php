<?php

namespace Modules\Kleiderkammer\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Kleiderkammer\src\Concerns\TracksUser;

/** @property ?int $group_id */
class IssuedItem extends Model
{
    use SoftDeletes, TracksUser;

    protected $table = 'kk_issued_items';

    protected $fillable = [
        'user_id',
        'item_id',
        'item_type_id',
        'size_id',
        'group_id',
        'quantity',
        'issued_at',
        'returned_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

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

    public function messages(): HasMany
    {
        return $this->hasMany(EquipmentMessage::class);
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }
}
