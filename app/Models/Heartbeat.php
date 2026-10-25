<?php

namespace App\Models;

use App\Support\Retention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One row per minute in which the scheduler ran: missing minutes are downtime */
class Heartbeat extends Model
{
    use MassPrunable;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'minute';

    protected $fillable = ['minute'];

    protected function casts(): array
    {
        return ['minute' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('minute', '<', now()->subDays(Retention::of('technical')));
    }
}
