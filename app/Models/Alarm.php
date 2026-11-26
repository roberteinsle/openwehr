<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** An alarm from DIVERA 24/7 (or a test from the admin), see App\Services\AlarmService */
class Alarm extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    protected $fillable = ['source', 'external_id', 'keyword', 'message', 'address', 'latitude', 'longitude', 'located_by', 'priority', 'payload', 'received_at', 'ended_at', 'ended_by', 'rics', 'vehicles', 'persons'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float', 'longitude' => 'float', 'priority' => 'boolean', 'payload' => 'array', 'rics' => 'array', 'vehicles' => 'array', 'persons' => 'array',
            'received_at' => 'datetime', 'ended_at' => 'datetime',
        ];
    }

    public function isTest(): bool
    {
        return $this->source === 'test';
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
