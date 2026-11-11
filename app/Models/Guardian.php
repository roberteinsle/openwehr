<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * An Erziehungsberechtigte(r) of a Kamerad, e.g. in the Jugendfeuerwehr. They get the Kalender mails of
 * the child and answer for it with a signed link, or sign in with their address (guard "guardian") to the
 * Eltern-Bereich. One row per child: siblings share the address, App\Services\ParentAccess joins them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $email
 * @property ?string $phone
 * @property ?Carbon $last_login_at
 */
class Guardian extends Model implements AuthenticatableContract
{
    use Authenticatable, Notifiable;

    protected $fillable = ['user_id', 'name', 'email', 'phone'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
