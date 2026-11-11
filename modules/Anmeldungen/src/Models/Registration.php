<?php

namespace Modules\Anmeldungen\src\Models;

use App\Models\Guardian;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Kamerad's registration with answers, accepted declarations and the signature. Answers, health
 * answers and signatures are encrypted; the model is kept out of the activity log (ActivityRecorder::SKIP),
 * RegistrationService records what happened without the content.
 */
/**
 * @property int $id
 * @property int $form_id
 * @property int $user_id
 * @property string $status
 * @property ?array<string, mixed> $answers
 * @property ?array<string, mixed> $health
 * @property ?list<string> $consents
 * @property ?int $guardian_id
 * @property ?int $submitted_by
 * @property string $signer_name
 * @property ?string $signature
 * @property ?string $member_signature
 * @property Carbon $signed_at
 * @property ?Carbon $cancelled_at
 */
class Registration extends Model
{
    protected $table = 'an_registrations';

    protected $fillable = ['form_id', 'user_id', 'status', 'answers', 'health', 'consents', 'guardian_id', 'submitted_by', 'signer_name',
        'signature', 'member_signature', 'signed_at', 'cancelled_at'];

    protected $hidden = ['answers', 'health', 'signature', 'member_signature'];

    protected function casts(): array
    {
        return [
            'answers' => 'encrypted:array', 'health' => 'encrypted:array', 'consents' => 'array',
            'signature' => 'encrypted', 'member_signature' => 'encrypted',
            'signed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'registered';
    }

    /** The answer to a field, health answers included while they exist */
    public function answer(string $key): mixed
    {
        return ($this->answers ?? [])[$key] ?? ($this->health ?? [])[$key] ?? null;
    }

    /** "ja", "nein", the text or "–" */
    public function answerLabel(array $field): string
    {
        $value = $this->answer($field['key']);
        if (($field['health'] ?? false) && $this->form?->health_purged_at) {
            return '(gelöscht)';
        }

        return match (true) {
            $value === null || $value === '' => '–',
            $field['type'] === 'yesno' => $value ? 'ja' : 'nein',
            $field['type'] === 'date' => Carbon::parse($value)->format('d.m.Y'),
            default => (string) $value,
        };
    }
}
