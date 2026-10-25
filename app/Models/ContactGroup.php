<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A freely defined group of Ansprechpartner, e.g. "Extern" (Leitstelle, Gemeinde, Kreisbrandmeister) */
class ContactGroup extends Model
{
    protected $fillable = ['name', 'on_roster', 'sort_order'];

    protected function casts(): array
    {
        return ['on_roster' => 'boolean'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
