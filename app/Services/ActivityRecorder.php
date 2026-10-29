<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EmailChangeRequest;
use App\Models\Guardian;
use App\Models\LoginToken;
use App\Models\MailLog;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserModulePreference;
use Illuminate\Database\Eloquent\Model;
use Modules\Atemschutz\src\Models\Reading as AtemschutzReading;
use Modules\Atemschutz\src\Models\Warning as AtemschutzWarning;
use Modules\Ausruestung\src\Models\EquipmentEvent;
use Modules\Einsatzbericht\src\Models\DiveraRequest;
use Modules\Expositionen\src\Models\Dismissal as ExposureDismissal;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Models\ExposureEvent;
use Modules\Fahrtenbuch\src\Models\Revision as FahrtenbuchRevision;
use Modules\Fahrzeugbuchung\src\Models\BookingCommentRevision;
use Modules\Fahrzeuge\src\Models\StatusEvent as FahrzeugStatusEvent;
use Modules\Fuehrerscheine\src\Models\Photo as LicensePhoto;
use Modules\Kalender\src\Models\FeedToken;
use Modules\Kleiderkammer\src\Models\Movement as KleiderkammerMovement;
use Modules\Maengelmanagement\src\Models\DefectEvent;
use Modules\Verbandbuch\src\Models\AccessLog as VerbandbuchAccessLog;
use Modules\Verbandbuch\src\Models\Amendment as VerbandbuchAmendment;
use Modules\Verbandbuch\src\Models\Entry as VerbandbuchEntry;
use Modules\Verbandbuch\src\Models\Signature as VerbandbuchSignature;
use Modules\Wasserkarte\src\Models\StatusEvent as WasserkarteStatusEvent;
use Throwable;

/**
 * Writes every created, changed and deleted record of the app to the activity log, with who did
 * it. Secrets and file contents are never written; pure bookkeeping updates (reminder sent,
 * last login, last seen) are left out. A failure here never breaks the change itself.
 */
class ActivityRecorder
{
    /**
     * Logs themselves, tokens, and models that are a history of their own. The Verbandbuch keeps
     * its own access log, so its health data never lands here.
     */
    private const SKIP = [
        ActivityLog::class, MailLog::class, LoginToken::class, PushSubscription::class, EmailChangeRequest::class, UserModulePreference::class,
        FeedToken::class, BookingCommentRevision::class, DefectEvent::class, EquipmentEvent::class, DiveraRequest::class,
        VerbandbuchEntry::class, VerbandbuchSignature::class, VerbandbuchAmendment::class, VerbandbuchAccessLog::class,
        AtemschutzReading::class, AtemschutzWarning::class, Exposure::class, ExposureEvent::class, ExposureDismissal::class, LicensePhoto::class, FahrzeugStatusEvent::class, FahrtenbuchRevision::class, WasserkarteStatusEvent::class,
        KleiderkammerMovement::class,
    ];

    /** Changes of only these fields are bookkeeping, not worth an entry */
    private const BOOKKEEPING = '/^(updated_at|created_at|last_login_at|last_seen_at|last_synced_at|remember_token|.*_reminded_at|.*_sent_at|.*_mailed_at|reminder_sent_at|sort_order|modules_locked)$/';

    /** Never written to the log */
    private const SECRET = '/password|token|secret|hash$|_key$|^data$|^value$|signature|^photo$|^receipt$/i';

    /** Personal data of a Kamerad: only the Personalverwaltung sees it, so the log shows that it changed, not to what */
    private const PERSONAL = ['street', 'postal_code', 'city', 'phone', 'birth_date', 'birth_place'];
    // Erziehungsberechtigte: mail and phone are hidden the same way

    private bool $paused = false;

    public function handle(string $eventName, array $payload): void
    {
        if ($this->paused || ! preg_match('/^eloquent\.(created|updated|deleted): (.+)$/', $eventName, $m)) {
            return;
        }

        /** @var Model $model */
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || in_array($m[2], self::SKIP, true) || ! str_starts_with($m[2], 'App\\') && ! str_starts_with($m[2], 'Modules\\')) {
            return;
        }

        $changes = match ($m[1]) {
            'updated' => collect($model->getChanges())->except(['updated_at'])
                ->reject(fn ($v, $field) => preg_match(self::BOOKKEEPING, $field))
                ->mapWithKeys(fn ($new, $field) => [$field => [$this->safe($model, $field, $model->getOriginal($field)), $this->safe($model, $field, $new)]])
                ->all(),
            'created' => collect($model->getAttributes())->except(['id', 'created_at', 'updated_at'])
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->mapWithKeys(fn ($v, $field) => [$field => [null, $this->safe($model, $field, $v)]])->all(),
            default => [],
        };

        if ($m[1] === 'updated' && $changes === []) {
            return;
        }

        $this->record($m[1], $model::class, $model->getKey(), $this->label($model), $changes);
    }

    public function record(string $event, ?string $type = null, mixed $id = null, ?string $label = null, array $changes = [], ?int $mailLogId = null, ?int $userId = null): void
    {
        try {
            $this->paused = true;
            ActivityLog::create([
                'created_at' => now(),
                'user_id' => $userId ?? auth()->id(),
                'impersonator_id' => app()->bound('session') ? session(Impersonation::SESSION_KEY) : null,
                'event' => $event,
                'subject_type' => $type,
                'subject_id' => is_numeric($id) ? (int) $id : null,
                'subject_label' => $label !== null ? mb_substr($label, 0, 500) : null,
                'changes' => $changes ?: null,
                'mail_log_id' => $mailLogId,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->paused = false;
        }
    }

    private function safe(Model $model, string $field, mixed $value): mixed
    {
        // Settings hold API keys and the logo
        if ((preg_match(self::SECRET, $field) && ! ($model instanceof Setting && $field === 'value' && ! preg_match('/key|token|secret|logo/i', (string) $model->key)))
            || ($model instanceof User && in_array($field, self::PERSONAL, true))
            || ($model instanceof Guardian && in_array($field, ['email', 'phone'], true))) {
            return $value === null ? null : '(verborgen)';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        return is_string($value) ? mb_strimwidth($value, 0, 500, '…') : $value;
    }

    private function label(Model $model): string
    {
        foreach (['name', 'title', 'subject', 'key', 'code', 'number', 'email'] as $attribute) {
            if (filled($model->getAttribute($attribute)) && is_scalar($model->getAttribute($attribute))) {
                return (string) $model->getAttribute($attribute);
            }
        }

        $owner = $model->getAttribute('user_id') ? User::find($model->getAttribute('user_id'))?->name : null;

        return '#'.$model->getKey().($owner ? ' ('.$owner.')' : '');
    }
}
