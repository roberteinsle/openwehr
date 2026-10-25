<?php

namespace App\Models;

use App\Support\Retention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Page requests per hour and area (module key or "kern"): count, server errors, response times */
class RequestMetric extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['hour', 'area', 'count', 'server_errors', 'total_ms', 'max_ms'];

    protected function casts(): array
    {
        return ['hour' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('hour', '<', now()->subDays(Retention::of('technical')));
    }
}
