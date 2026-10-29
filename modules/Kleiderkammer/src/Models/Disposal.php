<?php

namespace Modules\Kleiderkammer\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Ausgang (Ausmusterung): pieces or a quantity that left the Kleiderkammer for good */
class Disposal extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'kk_disposals';

    protected $fillable = ['item_type_id', 'item_id', 'size_id', 'group_id', 'quantity', 'reason', 'from_user_id', 'user_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }

    /** Suggestions for the reason */
    public const REASONS = ['Verschlissen', 'Defekt / beschädigt', 'Kontaminiert, nicht mehr zu reinigen', 'Verloren', 'Ablauf der Lebensdauer'];

    /** @return BelongsTo<ItemType, $this> */
    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
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

    /** @return BelongsTo<User, $this> */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
