<?php

namespace App\Models;

use App\Support\Retention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sent e-mail; kept for 180 days */
class MailLog extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 180;

    /** Readable names of the mail types; unknown ones show their class name */
    public const TYPES = [
        'MagicLinkNotification' => 'Login-Link',
        'EmailChangeConfirmNotification' => 'E-Mail-Bestätigung',
        'EmailChangedNotification' => 'E-Mail geändert',
        'FeedbackReceivedNotification' => 'Feedback eingegangen',
        'FeedbackAnsweredNotification' => 'Feedback beantwortet',
        'AnnouncementNotification' => 'Mitteilung',
        'PlanNotification' => 'Schichtplan',
        'RequestReceivedNotification' => 'Lehrgang: Interesse',
        'CourseConfirmedNotification' => 'Lehrgang: Termine',
        'CourseReminderNotification' => 'Lehrgang: Erinnerung',
        'RegistrationCancelledNotification' => 'Lehrgang: Absage',
        'RegistrationRejectedNotification' => 'Lehrgang: Ablehnung',
        'resend' => 'Erneut gesendet',
    ];

    protected $fillable = ['sent_at', 'to', 'user_id', 'subject', 'type', 'html', 'text', 'redacted', 'resent_from_id', 'resent_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'redacted' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resent_by');
    }

    public function typeLabel(): string
    {
        $short = class_basename((string) $this->type);

        return self::TYPES[$short] ?? ($short ?: 'E-Mail');
    }

    public function prunable(): Builder
    {
        return static::where('sent_at', '<', now()->subDays(Retention::of('mail')));
    }
}
