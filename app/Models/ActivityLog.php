<?php

namespace App\Models;

use App\Services\ModuleRegistry;
use App\Support\Retention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of the admin "Logs": a change, a login or a sent e-mail; kept as long as set under Analyse (audit log, default 180 days) */
class ActivityLog extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 180;

    public const UPDATED_AT = null;

    public const EVENTS = [
        'created' => 'Angelegt',
        'updated' => 'Geändert',
        'deleted' => 'Gelöscht',
        'transferred' => 'Übertragen und gelöscht',
        'login' => 'Anmeldung',
        'login_failed' => 'Anmeldung fehlgeschlagen',
        'login_throttled' => 'Anmeldung gesperrt (zu viele Versuche)',
        'impersonate' => 'Als Kamerad angesehen',
        'mail' => 'E-Mail',
    ];

    /** German names of the models; unknown ones show their class name */
    public const TYPES = [
        'User' => 'Kamerad', 'Group' => 'Abteilung', 'MemberFunction' => 'Funktion', 'Module' => 'Modul', 'ModuleUser' => 'Modulzugang',
        'Setting' => 'Einstellung', 'UserAvatar' => 'Profilbild', 'FeedbackThread' => 'Feedback', 'FeedbackMessage' => 'Feedback-Nachricht',
        'Booking' => 'Buchung', 'BookingComment' => 'Kommentar', 'BookingDecision' => 'Entscheidung', 'Vehicle' => 'Fahrzeug', 'MailTemplate' => 'E-Mail-Text',
        'Announcement' => 'Mitteilung', 'Record' => 'Anmeldung', 'Course' => 'Lehrgang', 'Session' => 'Lehrgangstag', 'Service' => 'Übungsdienst',
        'Plan' => 'Schichtplan', 'Station' => 'Station', 'Assignment' => 'Einteilung', 'Participant' => 'Teilnehmer', 'Slot' => 'Zeitblock', 'Template' => 'Vorlage',
        'Event' => 'Termin', 'Response' => 'Rückmeldung', 'Source' => 'Externer Kalender',
        'Defect' => 'Mangel', 'DefectPhoto' => 'Foto', 'Device' => 'Gerät', 'Equipment' => 'Gerät', 'Loan' => 'Ausleihe', 'Damage' => 'Schaden', 'Recall' => 'Rückruf',
        'Location' => 'Standort', 'Inspection' => 'Prüfung', 'Category' => 'Kategorie', 'Compartment' => 'Fach', 'LoadPlanItem' => 'Beladung',
        'Item' => 'Einzelteil', 'ItemType' => 'Artikeltyp', 'ItemCategory' => 'Kategorie', 'ItemGroup' => 'Artikelgruppe', 'ItemTreatment' => 'Behandlung',
        'Report' => 'Bericht', 'ReportVehicle' => 'Fahrzeug', 'CrewMember' => 'Besatzung',
        'Entry' => 'Finanzbuchung', 'Budget' => 'Budget', 'CostCenter' => 'Kostenstelle', 'Receipt' => 'Beleg',
        'IssuedItem' => 'Ausgabe', 'Stock' => 'Bestand', 'Size' => 'Größe', 'MemberSize' => 'Größe', 'EquipmentMessage' => 'Nachricht',
    ];

    protected $fillable = ['created_at', 'user_id', 'impersonator_id', 'event', 'subject_type', 'subject_id', 'subject_label', 'changes', 'mail_log_id', 'ip'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'changes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function mailLog(): BelongsTo
    {
        return $this->belongsTo(MailLog::class);
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    /** "Fahrzeugbuchung: Buchung", "Kamerad" */
    public static function typeLabel(?string $class): string
    {
        if (blank($class)) {
            return '';
        }

        $name = self::TYPES[class_basename($class)] ?? class_basename($class);
        $module = preg_match('/^Modules\\\\(\w+)\\\\/', $class, $m) ? app(ModuleRegistry::class)->get(mb_strtolower($m[1]))['name'] ?? $m[1] : null;

        return $module ? $module.': '.$name : $name;
    }

    /** "Name: alt → neu · …" */
    public function changesSummary(int $limit = 3): string
    {
        return collect($this->changes ?? [])->take($limit)
            ->map(fn ($pair, $field) => $field.': '.self::show($pair[0] ?? null).' → '.self::show($pair[1] ?? null))
            ->implode(' · ').(count($this->changes ?? []) > $limit ? ' …' : '');
    }

    public static function show(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '–',
            is_bool($value) => $value ? 'ja' : 'nein',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => mb_strimwidth((string) $value, 0, 80, '…'),
        };
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(Retention::of('audit')));
    }
}
