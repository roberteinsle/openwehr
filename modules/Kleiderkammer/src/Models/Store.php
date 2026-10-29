<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Lager of the Kleiderkammer (e.g. Hauptlager, Lager Gerätehaus Nord); there is always at least one */
class Store extends Model
{
    protected $table = 'kk_stores';

    protected $fillable = ['name', 'notes', 'sort_order'];

    /** @return HasMany<Stock, $this> */
    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class, 'store_id');
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'store_id');
    }

    /** The first Lager; created when there is none */
    public static function defaultId(): int
    {
        return (int) (self::orderBy('sort_order')->orderBy('id')->value('id') ?? self::create(['name' => 'Hauptlager', 'sort_order' => 1])->id);
    }

    /** Only with more than one Lager do forms and lists ask for it */
    public static function several(): bool
    {
        return self::count() > 1;
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return self::orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Pieces or stock are still in it */
    public function inUse(): bool
    {
        return $this->items()->whereIn('status', ['available', 'issued'])->exists() || $this->stock()->where('quantity', '>', 0)->exists();
    }
}
