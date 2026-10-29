<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ItemType extends Model
{
    protected $table = 'kk_item_types';

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'code_prefix',
        'price_net_cents',
        'is_tracked',
        'has_size',
        'is_washable',
        'is_chainsaw_protection',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_tracked' => 'boolean',
            'has_size' => 'boolean',
            'is_washable' => 'boolean',
            'is_chainsaw_protection' => 'boolean',
            'price_net_cents' => 'integer',
        ];
    }

    /** Umsatzsteuer on clothing (Regelsteuersatz) */
    public const VAT_PERCENT = 19;

    /** Gross from net, in cent */
    public static function gross(?int $netCents): ?int
    {
        return $netCents === null ? null : (int) round($netCents * (100 + self::VAT_PERCENT) / 100);
    }

    /**
     * A copy with its groups and sizes (each size keeps its group); the code prefix stays empty,
     * because the scanner guesses the Artikel from it and it must stay unique.
     */
    public function copyAs(string $name): self
    {
        return DB::transaction(function () use ($name) {
            $copy = $this->replicate(['code_prefix']);
            $copy->name = $name;
            $copy->sort_order = (int) self::max('sort_order') + 1;
            $copy->save();

            $groups = [];
            foreach ($this->groups()->get() as $group) {
                $groups[$group->id] = $copy->groups()->create(['name' => $group->name, 'sort_order' => $group->sort_order])->id;
            }
            foreach ($this->sizes()->get() as $size) {
                $copy->sizes()->create(['name' => $size->name, 'sort_order' => $size->sort_order, 'group_id' => $size->group_id ? $groups[$size->group_id] ?? null : null]);
            }

            return $copy;
        });
    }

    /** No pieces (also archived), no issues and nothing in stock: then it can go (empty stock rows go with it) */
    public function isDeletable(): bool
    {
        return ! Item::withTrashed()->where('item_type_id', $this->id)->exists()
            && ! $this->issuedItems()->exists()
            && ! $this->stock()->where('quantity', '>', 0)->exists();
    }

    public function deleteIfUnused(): bool
    {
        if (! $this->isDeletable()) {
            return false;
        }
        DB::transaction(function () {
            $this->stock()->delete();
            $this->delete();
        });

        return true;
    }

    public function priceGrossCents(): ?int
    {
        return self::gross($this->price_net_cents);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /** @return HasMany<Size, $this> */
    public function sizes(): HasMany
    {
        return $this->hasMany(Size::class, 'item_type_id')->orderBy('sort_order');
    }

    /** @return HasMany<ItemGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(ItemGroup::class, 'item_type_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'item_type_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'item_type_id');
    }

    public function issuedItems(): HasMany
    {
        return $this->hasMany(IssuedItem::class, 'item_type_id');
    }
}
