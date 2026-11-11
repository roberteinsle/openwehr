<?php

namespace Modules\Anmeldungen\src\Services;

use App\Models\Group;
use App\Models\Guardian;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Support\Csv;
use App\Support\SignatureImage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Notifications\FormOpenedNotification;
use Modules\Anmeldungen\src\Notifications\GuardianFormNotification;
use Throwable;

/**
 * Anmeldungen & Einverständnisse: forms, registrations and their signatures. Every change goes through
 * here. A Kamerad registers themselves; members with Erziehungsberechtigten are registered by them in the
 * Eltern-Bereich when the form asks for it (guardian_required). Health answers are wiped after the event.
 */
class RegistrationService
{
    public const MODULE = 'anmeldungen';

    public const ROLE = 'organisator';

    public static function canManage(User $user): bool
    {
        return $user->is_active && ($user->is_admin || ($user->canUseModule(self::MODULE) && $user->hasModuleRole(self::MODULE, self::ROLE)));
    }

    // ─── forms ───────────────────────────────────────────────────────────────

    /**
     * A sensible start for a Fahrt or a Zeltlager: Notfallkontakt, Gesundheit, Schwimmer, Freizeit and
     * Fotoerlaubnis, plus the usual declarations. The organiser changes everything.
     *
     * @return array{fields: list<array<string, mixed>>, consents: list<array{key: string, text: string}>}
     */
    public static function template(): array
    {
        $field = fn (string $label, string $type, bool $required = false, bool $health = false, array $options = [], ?string $help = null) => ['key' => Str::slug($label, '_'), 'label' => $label, 'type' => $type, 'required' => $required, 'health' => $health, 'options' => $options, 'help' => $help];

        return [
            'fields' => [
                $field('Notfallkontakt während der Fahrt (Name)', 'text', true),
                $field('Telefon Notfallkontakt', 'phone', true),
                $field('Weitere erreichbare Person mit Telefon', 'text'),
                $field('Regelmäßige Medikamente mit Dosierung und Uhrzeit', 'textarea', false, true, [], 'Medikamente bitte persönlich übergeben. Ohne Angabe werden keine Medikamente ausgegeben.'),
                $field('Allergien, Unverträglichkeiten, gesundheitliche Einschränkungen', 'textarea', false, true),
                $field('Krankenkasse', 'text', false, true),
                $field('Schwimmer', 'yesno', true),
                $field('Darf sich in der Freizeit von der Gruppe entfernen', 'choice', true, false, ['allein', 'mindestens zu zweit', 'nur mit Betreuer']),
                $field('Fotos dürfen veröffentlicht werden', 'yesno', true),
            ],
            'consents' => [
                ['key' => 'aufsicht', 'text' => 'Wir übertragen die Aufsichtspflicht für die Dauer der Veranstaltung an die Jugendwarte und Betreuer. Uns ist bewusst, dass die Aufsicht nur in zumutbarem Umfang möglich ist, etwa während der Nachtruhe.'],
                ['key' => 'anordnungen', 'text' => 'Unser Kind folgt den Anordnungen der Jugendwarte und Betreuer. Bei schweren Verstößen kann es von der Veranstaltung ausgeschlossen werden; dann holen wir es ab oder sorgen für die Heimfahrt.'],
                ['key' => 'gesundheit', 'text' => 'Unser Kind ist gesund und frei von ansteckenden Krankheiten. Ändert sich das vor der Abfahrt, informieren wir die Jugendwarte sofort.'],
                ['key' => 'notfall', 'text' => 'Im Notfall dürfen Ärzte und Rettungsdienst die Jugendwarte und Betreuer informieren, damit sie uns benachrichtigen und unser Kind weiter betreuen können.'],
            ],
        ];
    }

    /**
     * Create or change a form. Field keys stay stable once there are registrations, so answers keep their field.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $by, ?Form $form = null): Form
    {
        $this->assertManager($by);
        $title = trim((string) ($data['title'] ?? ''));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Bitte einen Titel angeben.';
        }

        $keys = [];
        $fields = [];
        foreach (array_values($data['fields'] ?? []) as $i => $f) {
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = array_key_exists($f['type'] ?? '', Form::TYPES) ? $f['type'] : 'text';
            $options = $type === 'choice' ? array_values(array_filter(array_map('trim', is_array($f['options'] ?? null) ? $f['options'] : explode("\n", (string) ($f['options'] ?? ''))))) : [];
            if ($type === 'choice' && count($options) < 2) {
                $errors['fields.'.$i] = '„'.$label.'“: Bitte mindestens zwei Antworten angeben (eine pro Zeile).';
            }
            $key = filled($f['key'] ?? null) ? (string) $f['key'] : (Str::slug($label, '_') ?: 'feld');
            $base = $key;
            for ($n = 2; in_array($key, $keys, true); $n++) {
                $key = $base.'_'.$n;
            }
            $keys[] = $key;
            $fields[] = ['key' => $key, 'label' => mb_substr($label, 0, 200), 'type' => $type, 'required' => (bool) ($f['required'] ?? false),
                'health' => (bool) ($f['health'] ?? false), 'options' => $options, 'help' => filled($f['help'] ?? null) ? mb_substr(trim((string) $f['help']), 0, 300) : null];
        }

        $consents = [];
        foreach (array_values($data['consents'] ?? []) as $c) {
            $text = trim((string) ($c['text'] ?? ''));
            if ($text !== '') {
                $consents[] = ['key' => filled($c['key'] ?? null) ? (string) $c['key'] : 'c'.Str::lower(Str::random(6)), 'text' => mb_substr($text, 0, 2000)];
            }
        }

        $startsOn = filled($data['starts_on'] ?? null) ? Carbon::parse($data['starts_on']) : null;
        $endsOn = filled($data['ends_on'] ?? null) ? Carbon::parse($data['ends_on']) : null;
        if ($startsOn && $endsOn && $endsOn->lt($startsOn)) {
            $errors['ends_on'] = 'Das Ende liegt vor dem Beginn.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $values = [
            'title' => mb_substr($title, 0, 150),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'location' => filled($data['location'] ?? null) ? mb_substr(trim((string) $data['location']), 0, 200) : null,
            'starts_on' => $startsOn, 'ends_on' => $endsOn,
            'deadline' => filled($data['deadline'] ?? null) ? Carbon::parse($data['deadline'], 'Europe/Berlin')->utc() : null,
            'fields' => $fields, 'consents' => $consents,
            'group_ids' => Group::whereIn('id', array_map('intval', $data['group_ids'] ?? []))->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'require_signature' => (bool) ($data['require_signature'] ?? true),
            'guardian_required' => (bool) ($data['guardian_required'] ?? true),
            'member_signature' => (bool) ($data['member_signature'] ?? false),
            'max_participants' => filled($data['max_participants'] ?? null) ? max(1, (int) $data['max_participants']) : null,
            'purge_days' => max(0, min(365, (int) ($data['purge_days'] ?? 14))),
        ];

        if ($form) {
            $form->update($values);

            return $form;
        }

        return Form::create($values + ['status' => 'draft', 'created_by' => $by->id]);
    }

    /** A copy for the next Fahrt: fields, declarations and audience, without dates and registrations */
    public function duplicate(Form $form, User $by): Form
    {
        $this->assertManager($by);

        return Form::create($form->only(['description', 'location', 'fields', 'consents', 'group_ids', 'require_signature', 'guardian_required', 'member_signature', 'max_participants', 'purge_days'])
            + ['title' => mb_substr($form->title.' (Kopie)', 0, 150), 'status' => 'draft', 'created_by' => $by->id]);
    }

    /** Open the Anmeldung: the Kameraden of the audience (and their Erziehungsberechtigten) are told */
    public function open(Form $form, User $by): int
    {
        $this->assertManager($by);
        if ($form->fields === [] && $form->consents === []) {
            throw ValidationException::withMessages(['status' => 'Bitte zuerst Felder oder Erklärungen anlegen.']);
        }
        $first = $form->opened_at === null;
        $form->update(['status' => 'open', 'opened_at' => $form->opened_at ?? now()]);

        return $first ? $this->tell($form, $this->audience($form)) : 0;
    }

    public function close(Form $form, User $by): void
    {
        $this->assertManager($by);
        $form->update(['status' => 'closed']);
    }

    /** Remind everyone of the audience who isn't registered yet (and their Erziehungsberechtigten) */
    public function remind(Form $form, User $by): int
    {
        $this->assertManager($by);
        if (! $form->acceptsRegistrations()) {
            throw ValidationException::withMessages(['status' => 'Die Anmeldung ist nicht offen.']);
        }
        $done = $form->registrations()->pluck('user_id')->all();

        return $this->tell($form, $this->audience($form)->reject(fn (User $u) => in_array($u->id, $done, true)), reminder: true);
    }

    public function delete(Form $form, User $by): void
    {
        $this->assertManager($by);
        app(ActivityRecorder::class)->record('deleted', Form::class, $form->id, $form->title.' ('.$form->registrations()->count().' Anmeldungen)');
        $form->delete();
    }

    /** @return EloquentCollection<int, User> active Kameraden the form is meant for (members of its Abteilungen, everyone if none) */
    public function audience(Form $form): EloquentCollection
    {
        return User::active()->when($form->group_ids !== [], fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $form->group_ids)))
            ->orderBy('name')->get()->filter(fn (User $u) => $u->canUseModule(self::MODULE))->values();
    }

    public function isFor(Form $form, User $member): bool
    {
        return $member->is_active && $member->canUseModule(self::MODULE)
            && ($form->group_ids === [] || $member->groups()->whereIn('groups.id', $form->group_ids)->exists());
    }

    /** @return EloquentCollection<int, Form> open forms for this Kamerad, by deadline */
    public function openFor(User $member): EloquentCollection
    {
        return Form::where('status', 'open')->orderByRaw('deadline is null, deadline')->orderBy('starts_on')->get()
            ->filter(fn (Form $f) => $this->isFor($f, $member))->values();
    }

    /** The Kamerad registers themselves – unless their Erziehungsberechtigten have to (guardian_required) */
    public function maySelfRegister(Form $form, User $member): bool
    {
        return ! ($form->guardian_required && $member->guardians()->exists());
    }

    // ─── registrations ───────────────────────────────────────────────────────

    /**
     * Register $member: answers checked against the fields, all declarations accepted, signed. $guardian is
     * set when an Erziehungsberechtigte(r) registers the child in the Eltern-Bereich, $by when a Kamerad
     * registers themselves. A registration can be changed until the Anmeldeschluss (it is signed again).
     *
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $consents  accepted declaration keys
     */
    public function register(Form $form, User $member, array $answers, array $consents, ?string $signature, ?string $memberSignature = null, ?Guardian $guardian = null, ?User $by = null): Registration
    {
        if (! $form->acceptsRegistrations()) {
            throw ValidationException::withMessages(['form' => 'Die Anmeldung ist geschlossen.']);
        }
        if (! $this->isFor($form, $member)) {
            throw ValidationException::withMessages(['form' => 'Diese Anmeldung ist nicht für '.$member->name.'.']);
        }
        if ($guardian === null && ! $this->maySelfRegister($form, $member)) {
            throw ValidationException::withMessages(['form' => 'Die Anmeldung machen deine Erziehungsberechtigten im Eltern-Bereich.']);
        }
        if ($guardian !== null && $guardian->user_id !== $member->id) {
            throw ValidationException::withMessages(['form' => 'Keine Berechtigung.']);
        }
        $existing = Registration::where('form_id', $form->id)->where('user_id', $member->id)->first();
        if ((! $existing || ! $existing->isActive()) && $form->isFull()) {
            throw ValidationException::withMessages(['form' => 'Alle Plätze sind vergeben.']);
        }

        [$plain, $health] = $this->checkAnswers($form, $answers);

        $missing = array_diff(array_column($form->consents, 'key'), $consents);
        if ($missing !== []) {
            throw ValidationException::withMessages(['consents' => 'Bitte alle Erklärungen bestätigen.']);
        }
        $png = $form->require_signature ? SignatureImage::fromDataUrl((string) $signature, 'signature') : null;
        $memberPng = $form->require_signature && $form->member_signature && $guardian !== null
            ? SignatureImage::fromDataUrl((string) $memberSignature, 'member_signature') : null;

        $registration = DB::transaction(fn () => Registration::updateOrCreate(['form_id' => $form->id, 'user_id' => $member->id], [
            'status' => 'registered', 'answers' => $plain, 'health' => $health, 'consents' => array_column($form->consents, 'key'),
            'guardian_id' => $guardian?->id, 'submitted_by' => $by?->id, 'signer_name' => $guardian !== null ? $guardian->name : $member->name,
            'signature' => $png, 'member_signature' => $memberPng, 'signed_at' => now(), 'cancelled_at' => null,
        ]));

        app(ActivityRecorder::class)->record($existing ? 'updated' : 'created', Registration::class, $registration->id,
            'Anmeldung '.$member->name.' – '.$form->title.($guardian ? ' (durch '.$guardian->name.')' : ''));

        return $registration;
    }

    /**
     * Withdraw a registration: the Kamerad or the Erziehungsberechtigten until the Anmeldeschluss, organisers
     * any time. Health answers go at once – nobody needs them any more.
     */
    public function cancel(Registration $registration, string $byName, bool $asManager = false): void
    {
        if (! $asManager && ! $registration->form->acceptsRegistrations()) {
            throw ValidationException::withMessages(['form' => 'Nach dem Anmeldeschluss bitte direkt bei den Organisatoren abmelden.']);
        }
        $registration->update(['status' => 'cancelled', 'cancelled_at' => now(), 'health' => null]);
        app(ActivityRecorder::class)->record('updated', Registration::class, $registration->id, 'Abmeldung '.$registration->user->name.' – '.$registration->form->title.' (durch '.$byName.')');
    }

    /**
     * Wipes the health answers of forms whose end lies purge_days back (daily, anmeldungen:purge-health).
     * Answers, declarations and signatures stay as the record of the consent.
     */
    public function purgeHealth(): int
    {
        $count = 0;
        Form::whereNull('health_purged_at')->get()->each(function (Form $form) use (&$count) {
            $date = $form->purgeDate();
            // Calendar days in Berlin: the dates are stored without a time zone
            if ($date === null || Carbon::now('Europe/Berlin')->toDateString() < $date->toDateString()) {
                return;
            }
            $count += Registration::where('form_id', $form->id)->whereNotNull('health')->update(['health' => null]);
            $form->update(['health_purged_at' => now()]);
            app(ActivityRecorder::class)->record('updated', Form::class, $form->id, $form->title.' – Gesundheitsangaben gelöscht');
        });

        return $count;
    }

    /** Teilnehmerliste for Excel: every field, health answers while they exist, who signed */
    public function csv(Form $form, User $by): string
    {
        $this->assertManager($by);
        app(ActivityRecorder::class)->record('exported', Form::class, $form->id, $form->title.' – Teilnehmerliste exportiert');

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Name', 'Abteilungen', 'Status', 'Angemeldet am', 'Unterschrieben von', ...array_column($form->fields, 'label'), 'Erklärungen bestätigt'], ';');
        foreach ($form->registrations()->with(['user.groups', 'form'])->get()->sortBy(fn (Registration $r) => $r->user->name) as $r) {
            Csv::put($out, [
                $r->user->name, $r->user->groups->pluck('name')->implode(', '), $r->isActive() ? 'angemeldet' : 'abgemeldet',
                $r->signed_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i'), $r->signer_name,
                ...array_map(fn (array $f) => $r->answerLabel($f), $form->fields),
                count($r->consents ?? []) >= count($form->consents) ? 'ja' : 'nein',
            ], ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $answers
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} plain and health answers
     */
    private function checkAnswers(Form $form, array $answers): array
    {
        $plain = [];
        $health = [];
        $errors = [];
        foreach ($form->fields as $f) {
            $value = $answers[$f['key']] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            if ($f['type'] === 'yesno') {
                $value = $value === null || $value === '' ? null : in_array($value, [true, 1, '1', 'ja', 'yes'], true);
            } elseif ($f['type'] === 'choice' && $value !== null && $value !== '' && ! in_array($value, $f['options'], true)) {
                $errors['answers.'.$f['key']] = '„'.$f['label'].'“: Bitte eine der Antworten wählen.';
            } elseif ($f['type'] === 'date' && filled($value)) {
                try {
                    $value = Carbon::parse((string) $value)->toDateString();
                } catch (Throwable) {
                    $errors['answers.'.$f['key']] = '„'.$f['label'].'“: Bitte ein Datum angeben.';
                }
            } elseif (is_string($value)) {
                $value = mb_substr($value, 0, $f['type'] === 'textarea' ? 2000 : 300);
            }
            if ($f['required'] && ($value === null || $value === '')) {
                $errors['answers.'.$f['key']] = '„'.$f['label'].'“ fehlt.';
            }
            if ($f['health']) {
                $health[$f['key']] = $value;
            } else {
                $plain[$f['key']] = $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$plain, $health];
    }

    /** Kameraden get bell and mail, their Erziehungsberechtigten a mail leading to the Eltern-Bereich */
    private function tell(Form $form, $members, bool $reminder = false): int
    {
        $sent = 0;
        foreach ($members as $member) {
            if ($this->maySelfRegister($form, $member)) {
                $this->notify($member, new FormOpenedNotification($form, $reminder));
                $sent++;
            }
            foreach ($member->guardians as $guardian) {
                $this->notify($guardian, new GuardianFormNotification($form, $member, $reminder));
                $sent++;
            }
        }

        return $sent;
    }

    /** A mail error never stops the action */
    private function notify(object $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function assertManager(User $by): void
    {
        if (! self::canManage($by)) {
            throw ValidationException::withMessages(['form' => 'Das dürfen nur Organisatoren.']);
        }
    }
}
