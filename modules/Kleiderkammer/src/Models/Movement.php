<?php

namespace Modules\Kleiderkammer\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Protokoll of the Kleiderkammer: every Zugang, Abgang, Ausgabe, Rücknahme and Korrektur, with
 * names kept as text so it stays readable when an Artikel or a Kamerad is gone. Written only by
 * Movement::log (StoreService, IssuingService), never changed.
 */
class Movement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'kk_movements';

    protected $fillable = ['kind', 'item_type_id', 'item_name', 'item_id', 'code', 'size_id', 'group_id', 'variant', 'store_id',
        'quantity', 'user_id', 'user_name', 'by_id', 'by_name', 'reason'];

    public const KINDS = ['zugang' => 'Zugang', 'abgang' => 'Abgang', 'ausgabe' => 'Ausgabe', 'ruecknahme' => 'Rücknahme', 'korrektur' => 'Korrektur'];

    public const COLORS = ['zugang' => 'success', 'abgang' => 'danger', 'ausgabe' => 'warning', 'ruecknahme' => 'info', 'korrektur' => 'gray'];

    /** Suggestions for the reason of an Abgang */
    public const REASONS = ['Verschlissen', 'Defekt / beschädigt', 'Kontaminiert, nicht mehr zu reinigen', 'Verloren', 'Ablauf der Lebensdauer'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }

    public static function log(string $kind, ItemType $type, int $quantity, ?Item $item = null, ?Size $size = null, ?ItemGroup $group = null,
        ?int $storeId = null, ?User $user = null, ?User $by = null, ?string $reason = null): self
    {
        $size ??= $item?->size;
        $group ??= $item?->group;

        return self::create([
            'kind' => $kind, 'item_type_id' => $type->id, 'item_name' => $type->name, 'item_id' => $item?->id, 'code' => $item?->code,
            'size_id' => $size?->id, 'group_id' => $group?->id, 'variant' => collect([$group?->name, $size?->name])->filter()->implode(' · ') ?: null,
            'store_id' => $storeId ?? $item?->store_id, 'quantity' => $quantity,
            'user_id' => $user?->id, 'user_name' => $user?->name, 'by_id' => $by?->id, 'by_name' => $by?->name,
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 300) : null,
        ]);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** @return BelongsTo<ItemType, $this> */
    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'item_type_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
