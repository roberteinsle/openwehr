<?php

namespace App\Models;

use App\Support\Retention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** An exception, grouped by class and place: how often and when first and last (App\Services\ErrorRecorder) */
class AppError extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['fingerprint', 'class', 'message', 'location', 'url', 'count', 'first_at', 'last_at', 'notified_at'];

    protected function casts(): array
    {
        return ['first_at' => 'datetime', 'last_at' => 'datetime', 'notified_at' => 'datetime', 'count' => 'integer'];
    }

    public function prunable(): Builder
    {
        return static::where('last_at', '<', now()->subDays(Retention::of('technical')));
    }

    /** "QueryException" instead of the whole namespace */
    public function shortClass(): string
    {
        return class_basename($this->class);
    }
}
