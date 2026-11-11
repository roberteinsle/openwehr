<?php

namespace Modules\Kalender\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedToken extends Model
{
    protected $table = 'kal_feed_tokens';

    protected $fillable = ['user_id', 'parent_hash', 'token_hash', 'token_encrypted'];

    protected $hidden = ['token_encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
