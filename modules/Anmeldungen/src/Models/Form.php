<?php

namespace Modules\Anmeldungen\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An Anmeldung for a Fahrt, Zeltlager or Veranstaltung: own fields (Allergien, Schwimmer, Notfallnummer,
 * Fotoerlaubnis …), declarations to accept and how it is signed.
 *
 * @property list<array{key: string, label: string, type: string, required: bool, health: bool, options: list<string>, help: ?string}> $fields
 * @property list<array{key: string, text: string}> $consents
 * @property list<int> $group_ids
 * @property int $id
 * @property string $title
 * @property ?string $description
 * @property ?string $location
 * @property ?Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property ?Carbon $deadline
 * @property bool $require_signature
 * @property bool $guardian_required
 * @property bool $member_signature
 * @property ?int $max_participants
 * @property int $purge_days
 * @property string $status
 * @property ?Carbon $opened_at
 * @property ?Carbon $health_purged_at
 * @property int|null $n count from withCount
 */
class Form extends Model
{
    protected $table = 'an_forms';

    public const TYPES = [
        'text' => 'Text (eine Zeile)',
        'textarea' => 'Text (mehrere Zeilen)',
        'phone' => 'Telefonnummer',
        'date' => 'Datum',
        'yesno' => 'Ja / Nein',
        'choice' => 'Auswahl',
    ];

    public const STATUSES = ['draft' => 'Entwurf', 'open' => 'Offen', 'closed' => 'Geschlossen'];

    protected $fillable = ['title', 'description', 'location', 'starts_on', 'ends_on', 'deadline', 'fields', 'consents', 'group_ids',
        'require_signature', 'guardian_required', 'member_signature', 'max_participants', 'purge_days', 'status', 'opened_at',
        'health_purged_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'deadline' => 'datetime', 'opened_at' => 'datetime', 'health_purged_at' => 'datetime',
            'fields' => 'array', 'consents' => 'array', 'group_ids' => 'array',
            'require_signature' => 'boolean', 'guardian_required' => 'boolean', 'member_signature' => 'boolean',
        ];
    }

    /** @return HasMany<Registration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'form_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Open and before the Anmeldeschluss */
    public function acceptsRegistrations(): bool
    {
        return $this->isOpen() && ($this->deadline === null || now()->lt($this->getAttribute('deadline')));
    }

    public function isFull(): bool
    {
        return $this->max_participants !== null && $this->registrations()->where('status', 'registered')->count() >= $this->max_participants;
    }

    /** @return list<array{key: string, label: string, type: string, required: bool, health: bool, options: list<string>, help: ?string}> */
    public function healthFields(): array
    {
        return array_values(array_filter($this->fields, fn (array $f) => (bool) $f['health']));
    }

    /** When the health answers are wiped: some days after the end (or the Anmeldeschluss) */
    public function purgeDate(): ?Carbon
    {
        $base = $this->ends_on ?? $this->deadline?->copy()->setTimezone('Europe/Berlin')->startOfDay();

        return $base?->copy()->addDays($this->purge_days);
    }

    /** "Fr, 11.12. – So, 13.12.2027" */
    public function dateLabel(): ?string
    {
        if (! $this->starts_on) {
            return null;
        }
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $start = $days[$this->starts_on->dayOfWeek].', '.$this->starts_on->format('d.m.Y');
        if (! $this->ends_on || $this->ends_on->isSameDay($this->starts_on)) {
            return $start;
        }

        return $days[$this->starts_on->dayOfWeek].', '.$this->starts_on->format('d.m.').' – '.$days[$this->ends_on->dayOfWeek].', '.$this->ends_on->format('d.m.Y');
    }
}
