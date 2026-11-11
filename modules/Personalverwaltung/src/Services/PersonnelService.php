<?php

namespace Modules\Personalverwaltung\src\Services;

use App\Models\Group;
use App\Models\Guardian;
use App\Models\MemberFunction;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use App\Services\OrganizationSettings;
use App\Support\Csv;
use App\Support\FireRanks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\Response;
use Modules\Kalender\src\Support\Audience;
use Modules\Personalverwaltung\src\Models\ChangeRequest;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\HistoryFile;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Notifications\ChangeRequestAnsweredNotification;
use Modules\Personalverwaltung\src\Notifications\ChangeRequestedNotification;
use Modules\Personalverwaltung\src\Notifications\PersonalDataChangedNotification;
use Modules\Personalverwaltung\src\Support\ServiceTime;
use Throwable;

/**
 * Personalverwaltung: who may see whose file (Personalverwalter, optionally only some Abteilungen),
 * the career that writes itself from every change of Dienstgrad, Abteilung, Funktion and status,
 * Ehrungen with the ones due by Dienstjahre, and the Dienstbeteiligung from the Kalender attendance.
 */
class PersonnelService
{
    public const MODULE = 'personalverwaltung';

    /** Kalender types that count as Dienst for the Dienstbeteiligung by default */
    public const DUTY_TYPES = ['uebung', 'dienst', 'arbeitsdienst'];

    /** Personal data the Personalverwalter can switch on; the columns live on users */
    public const FIELDS = ['address' => 'Anschrift', 'birth_date' => 'Geburtsdatum', 'phone' => 'Mobilnummer'];

    private const SETTINGS_KEY = 'personalverwaltung.fields';

    /** How the Dienstzeit is counted (Jugendfeuerwehr) */
    private const RULES_KEY = 'personalverwaltung.service_rules';

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    // ─── access ──────────────────────────────────────────────────────────────

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'personalverwalter'));
    }

    /** @return list<int> the Abteilungen a Personalverwalter is limited to; empty = all */
    public static function scopeOf(User $user): array
    {
        if ($user->is_admin) {
            return [];
        }

        return DB::table('pm_scopes')->where('user_id', $user->id)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The Kameraden whose file $manager may open */
    public static function visibleMembers(User $manager): Builder
    {
        $scope = self::scopeOf($manager);

        return User::query()->when($scope !== [], fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $scope)));
    }

    /** Edits the file of $member (Personalverwalter within their Abteilungen) */
    public static function mayView(User $manager, User $member): bool
    {
        return self::canManage($manager) && self::visibleMembers($manager)->whereKey($member->id)->exists();
    }

    /** Personaleinsicht: may look at the files (e.g. the Wehrführung), without changing anything or seeing personal data */
    public static function canView(User $user): bool
    {
        return self::canManage($user) || ($user->is_active && $user->canUseModule(self::MODULE) && $user->hasModuleRole(self::MODULE, 'personaleinsicht'));
    }

    public static function mayRead(User $viewer, User $member): bool
    {
        return self::canView($viewer) && self::visibleMembers($viewer)->whereKey($member->id)->exists();
    }

    // ─── new members ─────────────────────────────────────────────────────────

    /** The Personalverwalter takes in a new Kamerad (no admin needed); within their Abteilungen when they are limited */
    public function createMember(User $by, array $data): User
    {
        $this->assertManager($by);
        $clean = $this->memberData($by, $data, null);
        $member = DB::transaction(function () use ($clean) {
            $member = User::create([...collect($clean)->except('group_ids')->all(), 'status' => 'active']);
            $member->groups()->sync($clean['group_ids']);
            if (! $member->canUseModule(self::MODULE)) {
                $member->moduleAccess()->create(['module' => self::MODULE]);
            }

            return $member;
        });
        $this->syncHistory($member->fresh());

        return $member;
    }

    /**
     * Mitglieder from a CSV (e.g. the old Excel list): new ones by e-mail are created, known ones get the given
     * values (Abteilungen are added). First a preview.
     *
     * @return list<array{line: int, name: string, email: string, action: string, label: string, data: array}>
     */
    public function importPreview(User $by, string $csv): array
    {
        $this->assertManager($by);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (! mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $csv), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['import' => 'Die Datei braucht eine Kopfzeile und mindestens eine Zeile.']);
        }
        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $aliases = ['name' => ['name'], 'first' => ['vorname'], 'last' => ['nachname', 'familienname'], 'email' => ['e-mail', 'email', 'mail', 'e-mail-adresse'],
            'groups' => ['abteilung', 'abteilungen'], 'rank' => ['dienstgrad'], 'joined_at' => ['eintritt', 'eintrittsdatum', 'mitglied seit'],
            'birth_date' => ['geburtsdatum', 'geboren'], 'gender' => ['geschlecht'], 'phone' => ['mobil', 'mobilnummer', 'handy', 'telefon'],
            'street' => ['straße', 'strasse', 'anschrift'], 'postal_code' => ['plz', 'postleitzahl'], 'city' => ['ort', 'wohnort']];
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv($lines[0], $delimiter));
        $col = [];
        foreach ($aliases as $field => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $col[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($col['email']) || ! (isset($col['name']) || isset($col['last']))) {
            throw ValidationException::withMessages(['import' => 'Die Kopfzeile braucht „E-Mail“ und „Name“ (oder „Vorname“ und „Nachname“).']);
        }

        $rows = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            $cells = str_getcsv($line, $delimiter);
            $get = fn (string $f) => isset($col[$f]) ? trim((string) ($cells[$col[$f]] ?? '')) : '';
            $name = $get('name') !== '' ? $get('name') : trim($get('first').' '.$get('last'));
            $email = mb_strtolower($get('email'));
            $existing = $email !== '' ? User::whereRaw('lower(email) = ?', [$email])->first() : null;
            $date = fn (string $v) => collect(['!d.m.Y', '!Y-m-d'])->map(fn ($f) => rescue(fn () => Carbon::createFromFormat($f, $v), null, false))->filter()->first()?->toDateString();
            $gender = match (mb_strtolower(mb_substr($get('gender'), 0, 1))) {
                'm' => 'male', 'w', 'f' => 'female', 'd' => 'diverse', default => null
            };
            $data = array_filter(['name' => $name, 'email' => $email, 'rank' => $get('rank'), 'joined_at' => $get('joined_at') !== '' ? $date($get('joined_at')) : null,
                'birth_date' => $get('birth_date') !== '' ? $date($get('birth_date')) : null, 'gender' => $gender, 'phone' => $get('phone'),
                'street' => $get('street'), 'postal_code' => $get('postal_code'), 'city' => $get('city'),
                'group_names' => array_values(array_filter(array_map('trim', preg_split('/[,|]/', $get('groups')) ?: [])))], fn ($v) => $v !== '' && $v !== null && $v !== []);
            try {
                // The import finds members by address, so it keeps needing one (members without login are created by hand)
                if ($email === '') {
                    throw ValidationException::withMessages(['member_email' => 'E-Mail-Adresse fehlt – Mitglieder ohne Login einzeln unter „Neues Mitglied“ anlegen.']);
                }
                $this->memberData($by, $data, $existing);
                $action = $existing ? (self::mayView($by, $existing) ? 'update' : 'error') : 'new';
                $label = ['new' => 'wird angelegt', 'update' => 'wird ergänzt', 'error' => 'gehört nicht zu deinen Abteilungen'][$action];
            } catch (ValidationException $e) {
                [$action, $label] = ['error', collect($e->errors())->flatten()->first()];
            }
            $rows[] = ['line' => $n + 2, 'name' => $name, 'email' => $email, 'action' => $action, 'label' => $label, 'data' => $data];
        }

        return $rows;
    }

    /** @return array{created: int, updated: int} */
    public function importApply(User $by, string $csv): array
    {
        $result = ['created' => 0, 'updated' => 0];
        foreach ($this->importPreview($by, $csv) as $row) {
            if ($row['action'] === 'new') {
                $this->createMember($by, $row['data']);
                $result['created']++;
            } elseif ($row['action'] === 'update') {
                $member = User::whereRaw('lower(email) = ?', [$row['email']])->sole();
                $clean = $this->memberData($by, $row['data'], $member);
                DB::transaction(function () use ($member, $clean) {
                    $member->update(collect($clean)->except(['group_ids', 'email'])->filter(fn ($v) => $v !== null)->all());
                    $member->groups()->syncWithoutDetaching($clean['group_ids']);
                });
                $this->syncHistory($member->fresh());
                $result['updated']++;
            }
        }

        return $result;
    }

    /** Checked member data: name, e-mail (unique), Abteilungen by id or name (within the scope), the rest optional */
    private function memberData(User $by, array $data, ?User $existing): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['member_name' => 'Bitte den Namen angeben.']);
        }
        // Without an address the member is kept without login (e.g. Jugendfeuerwehr, Musikzug)
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['member_email' => 'Bitte eine gültige E-Mail-Adresse angeben (oder leer lassen: Mitglied ohne Login).']);
        }
        if (! $existing && $email !== '' && User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['member_email' => 'Diese E-Mail-Adresse gehört schon einem Kameraden.']);
        }
        $groupIds = collect($data['group_ids'] ?? [])->map(fn ($id) => (int) $id);
        foreach ($data['group_names'] ?? [] as $groupName) {
            $group = Group::whereRaw('lower(name) = ?', [mb_strtolower($groupName)])->first()
                ?? throw ValidationException::withMessages(['member_groups' => 'Die Abteilung „'.$groupName.'“ gibt es nicht.']);
            $groupIds->push($group->id);
        }
        $scope = self::scopeOf($by);
        if ($scope !== [] && ($groupIds->isEmpty() || $groupIds->diff($scope)->isNotEmpty())) {
            throw ValidationException::withMessages(['member_groups' => 'Bitte eine deiner Abteilungen wählen.']);
        }
        $date = fn ($v) => filled($v) ? rescue(fn () => Carbon::parse($v)->toDateString(), null, false) : null;
        $fields = self::activeFields();

        return [
            'name' => $name, 'email' => $email !== '' ? $email : null, 'group_ids' => $groupIds->unique()->values()->all(),
            'rank' => filled($data['rank'] ?? null) ? mb_substr(trim($data['rank']), 0, 100) : null,
            'joined_at' => $date($data['joined_at'] ?? null), 'gender' => in_array($data['gender'] ?? null, ['male', 'female', 'diverse'], true) ? $data['gender'] : null,
            // Personal data only while the Personalverwaltung keeps it
            'birth_date' => in_array('birth_date', $fields, true) ? $date($data['birth_date'] ?? null) : null,
            'phone' => in_array('phone', $fields, true) && filled($data['phone'] ?? null) ? mb_substr(trim($data['phone']), 0, 50) : null,
            'street' => in_array('address', $fields, true) && filled($data['street'] ?? null) ? mb_substr(trim($data['street']), 0, 150) : null,
            'postal_code' => in_array('address', $fields, true) && filled($data['postal_code'] ?? null) ? mb_substr(trim($data['postal_code']), 0, 10) : null,
            'city' => in_array('address', $fields, true) && filled($data['city'] ?? null) ? mb_substr(trim($data['city']), 0, 100) : null,
        ];
    }

    /** Admins only: limit a Personalverwalter to Abteilungen (empty = all) */
    public function setScope(User $by, User $manager, array $groupIds): void
    {
        if (! $by->is_admin) {
            throw ValidationException::withMessages(['scope' => 'Das dürfen nur Admins.']);
        }
        DB::transaction(function () use ($manager, $groupIds) {
            DB::table('pm_scopes')->where('user_id', $manager->id)->delete();
            $ids = Group::whereIn('id', array_map('intval', $groupIds))->pluck('id');
            DB::table('pm_scopes')->insert($ids->map(fn ($id) => ['user_id' => $manager->id, 'group_id' => $id])->all());
        });
    }

    // ─── personal data ───────────────────────────────────────────────────────

    /**
     * Which personal data the Personalverwaltung keeps (all off until switched on) and whether
     * Kameraden keep their own in the Personalakte.
     *
     * The Dienstausweis: digital_id switches it on; id_issuer is the Ausstellende Behörde (e.g. the
     * Gemeinde), id_birth_date shows the Geburtsdatum on the card, id_birth_on_check also on the
     * checker's page, id_require_number shows it only once a Ausweisnummer is entered.
     *
     * @return array{address: bool, birth_date: bool, phone: bool, self_service: bool, digital_id: bool, id_issuer: string, id_birth_date: bool, id_birth_on_check: bool, id_require_number: bool}
     */
    public static function settings(): array
    {
        $stored = json_decode((string) Setting::get(self::SETTINGS_KEY, ''), true) ?: [];

        return ['address' => (bool) ($stored['address'] ?? false), 'birth_date' => (bool) ($stored['birth_date'] ?? false),
            'phone' => (bool) ($stored['phone'] ?? false), 'self_service' => (bool) ($stored['self_service'] ?? true),
            'digital_id' => (bool) ($stored['digital_id'] ?? false), 'id_issuer' => (string) ($stored['id_issuer'] ?? ''),
            'id_birth_date' => (bool) ($stored['id_birth_date'] ?? true), 'id_birth_on_check' => (bool) ($stored['id_birth_on_check'] ?? false),
            'id_require_number' => (bool) ($stored['id_require_number'] ?? false)];
    }

    /** @return list<string> the switched-on fields */
    public static function activeFields(): array
    {
        return array_keys(array_filter(array_intersect_key(self::settings(), self::FIELDS)));
    }

    /** Kameraden see "Persönliche Daten" in their Personalakte */
    public static function selfService(): bool
    {
        return self::activeFields() !== [] && self::settings()['self_service'];
    }

    public function saveSettings(User $by, array $data): void
    {
        $this->assertManager($by);
        Setting::set(self::SETTINGS_KEY, json_encode([
            'address' => (bool) ($data['address'] ?? false), 'birth_date' => (bool) ($data['birth_date'] ?? false),
            'phone' => (bool) ($data['phone'] ?? false), 'self_service' => (bool) ($data['self_service'] ?? false),
            'digital_id' => (bool) ($data['digital_id'] ?? false), 'id_issuer' => mb_substr(trim((string) ($data['id_issuer'] ?? '')), 0, 150),
            'id_birth_date' => (bool) ($data['id_birth_date'] ?? false), 'id_birth_on_check' => (bool) ($data['id_birth_on_check'] ?? false),
            'id_require_number' => (bool) ($data['id_require_number'] ?? false),
        ]));
    }

    /**
     * Saves the switched-on personal data of $member, by a Personalverwalter or (self-service) by the
     * Kamerad. A change by the Kamerad goes to the Personalverwalter under the bell and by mail –
     * which fields, not the values. Returns the labels of the changed fields.
     *
     * @return list<string>
     */
    public function savePersonalData(User $by, User $member, array $data): array
    {
        $self = $by->is($member);
        if ($self ? ! (self::selfService() && $member->canUseModule(self::MODULE)) : ! self::mayView($by, $member)) {
            throw ValidationException::withMessages(['personal' => 'Diese Daten darfst du nicht ändern.']);
        }
        $fields = self::activeFields();
        $values = [];
        if (in_array('phone', $fields, true)) {
            $phone = trim((string) ($data['phone'] ?? ''));
            if ($phone !== '' && (mb_strlen($phone) > 30 || ! preg_match('/^[0-9+\-\s()\/.]+$/', $phone))) {
                throw ValidationException::withMessages(['phone' => 'Bitte eine gültige Telefonnummer angeben, z. B. 0171 1234567.']);
            }
            $values['phone'] = $phone !== '' ? $phone : null;
        }
        if (in_array('birth_date', $fields, true)) {
            $birth = $this->date($data['birth_date'] ?? null, 'birth_date');
            if ($birth && ($birth->gt(self::today()) || $birth->year < 1900)) {
                throw ValidationException::withMessages(['birth_date' => 'Bitte ein gültiges Geburtsdatum angeben.']);
            }
            $values['birth_date'] = $birth?->toDateString();
        }
        if (in_array('address', $fields, true)) {
            $postal = trim((string) ($data['postal_code'] ?? ''));
            if ($postal !== '' && ! preg_match('/^[0-9]{4,5}$/', $postal)) {
                throw ValidationException::withMessages(['postal_code' => 'Bitte eine Postleitzahl mit 4 oder 5 Ziffern angeben.']);
            }
            foreach (['street' => 150, 'city' => 100] as $key => $max) {
                $values[$key] = filled($data[$key] ?? null) ? mb_substr(trim($data[$key]), 0, $max) : null;
            }
            $values['postal_code'] = $postal !== '' ? $postal : null;
        }

        // Compare with what is stored, not with an object that may hold more (e.g. a time with the date)
        $member->refresh()->fill($values);
        $changed = collect(array_keys($member->getDirty()))
            ->map(fn ($column) => match ($column) {
                'phone' => 'Mobilnummer', 'birth_date' => 'Geburtsdatum', default => 'Anschrift'
            })
            ->unique()->sortBy(fn ($label) => array_search($label, array_values(self::FIELDS), true))->values()->all();
        $member->save();

        if ($self && $changed !== []) {
            $this->responsibleManagers($member)->each(function (User $manager) use ($member, $changed) {
                try {
                    $manager->notify(new PersonalDataChangedNotification($member, $changed));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $changed;
    }

    /** The Personalverwalter whose Abteilungen include the Kamerad (unlimited ones always); without any, the admins */
    public function responsibleManagers(User $member): Collection
    {
        $groups = $member->groups()->pluck('groups.id')->all();
        $managers = User::active()->whereKeyNot($member->id)
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'personalverwalter'))->get()
            ->filter(fn (User $m) => ($scope = self::scopeOf($m)) === [] || array_intersect($scope, $groups) !== [])->values();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->whereKeyNot($member->id)->get();
    }

    /**
     * Round birthdays of the year (50, 60 … and from 70 every five years) – for congratulations and
     * honours; only while the birth date is kept.
     *
     * @return Collection<int, array{user: User, age: int, on: Carbon}>
     */
    public function roundBirthdays(User $manager, int $year): Collection
    {
        if (! in_array('birth_date', self::activeFields(), true)) {
            return collect();
        }

        return self::visibleMembers($manager)->where('status', 'active')->whereNotNull('birth_date')->get()
            ->map(fn (User $u) => ['user' => $u, 'age' => $year - $u->birth_date->year, 'on' => $u->birth_date->copy()->setYear($year)])
            ->filter(fn ($row) => $row['age'] >= 50 && ($row['age'] % 10 === 0 || ($row['age'] >= 70 && $row['age'] % 5 === 0)))
            ->sortBy(fn ($row) => $row['on']->timestamp)->values();
    }

    /** CSV of the switched-on personal data, for letters and lists */
    public function addressCsv(User $manager): string
    {
        $fields = self::activeFields();
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Name', 'E-Mail', 'Abteilungen', ...(in_array('address', $fields, true) ? ['Straße', 'PLZ', 'Ort'] : []),
            ...(in_array('birth_date', $fields, true) ? ['Geburtsdatum'] : []), ...(in_array('phone', $fields, true) ? ['Mobilnummer'] : [])], ';');
        foreach (self::visibleMembers($manager)->where('status', 'active')->with('groups')->orderBy('name')->get() as $m) {
            Csv::put($out, [$m->name, $m->email, $m->groups->pluck('name')->implode(', '),
                ...(in_array('address', $fields, true) ? [$m->street, $m->postal_code, $m->city] : []),
                ...(in_array('birth_date', $fields, true) ? [$m->birth_date?->format('d.m.Y')] : []),
                ...(in_array('phone', $fields, true) ? [$m->phone] : [])], ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ─── career ──────────────────────────────────────────────────────────────

    /**
     * Brings the career up to date with the member data: a new Dienstgrad, status, Abteilung or
     * Funktion opens a stage, a former one is closed. The very first entries take the known dates
     * (Dienstgrad since, Eintritt); later changes count from today. Returns the number of changes.
     */
    public function syncHistory(User $user): int
    {
        $user->loadMissing(['groups', 'functions.parent']);
        $today = self::today();
        $changes = 0;

        $single = function (string $kind, ?string $label, ?Carbon $firstSince) use ($user, $today, &$changes) {
            $open = HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->whereNull('until')->latest('id')->first();
            if ($open?->label === $label) {
                return;
            }
            $first = ! HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->exists();
            if ($open) {
                $open->update(['until' => $today]);
                $changes++;
            }
            if ($label !== null) {
                HistoryEntry::create(['user_id' => $user->id, 'kind' => $kind, 'label' => $label, 'since' => $first ? $firstSince : $today]);
                $changes++;
            }
        };

        $many = function (string $kind, Collection $current) use ($user, $today, &$changes) {
            $first = ! HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->exists();
            $open = HistoryEntry::where('user_id', $user->id)->where('kind', $kind)->whereNull('until')->whereNotNull('ref_id')->get()->keyBy('ref_id');
            foreach ($open as $refId => $entry) {
                if (! $current->has($refId)) {
                    $entry->update(['until' => $today]);
                    $changes++;
                }
            }
            foreach ($current as $refId => $label) {
                if (! $open->has($refId)) {
                    HistoryEntry::create(['user_id' => $user->id, 'kind' => $kind, 'ref_id' => $refId, 'label' => $label, 'since' => $first ? $user->joined_at : $today]);
                    $changes++;
                }
            }
        };

        DB::transaction(function () use ($user, $single, $many) {
            $single('rank', filled($user->rank) ? $user->rank : null, $user->rank_since);
            $single('status', $user->status?->label(), $user->joined_at);
            $many('group', $user->groups->mapWithKeys(fn (Group $g) => [$g->id => $g->name]));
            $many('function', $user->functions->mapWithKeys(fn (MemberFunction $f) => [$f->id => $f->fullLabel()]));
        });

        return $changes;
    }

    public function syncAll(): int
    {
        $changes = 0;
        User::query()->orderBy('id')->each(function (User $user) use (&$changes) {
            try {
                $changes += $this->syncHistory($user);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $changes;
    }

    /** A former stage from the paper file, e.g. "Oberfeuerwehrmann 2008–2012" */
    public function addHistory(User $by, User $member, array $data): HistoryEntry
    {
        $this->assertMayView($by, $member);
        [$kind, $label, $since, $until] = $this->validStage($data, ($data['kind'] ?? '') !== 'event');

        return HistoryEntry::create(['user_id' => $member->id, 'kind' => $kind, 'label' => $label, 'since' => $since, 'until' => $until,
            'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 300) : null, 'created_by' => $by->id]);
    }

    /** Correct the dates or the note of a stage; the label of a current stage comes from the member data */
    public function updateHistory(User $by, HistoryEntry $entry, array $data): void
    {
        $this->assertMayView($by, $entry->user);
        [, $label, $since, $until] = $this->validStage([...$data, 'kind' => $entry->kind, 'label' => $data['label'] ?? $entry->label], ! $entry->isCurrent() && $entry->kind !== 'event');
        $entry->update(['label' => $entry->isCurrent() ? $entry->label : $label, 'since' => $since,
            'until' => $entry->isCurrent() ? null : $until, 'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 300) : null]);
    }

    public function deleteHistory(User $by, HistoryEntry $entry): void
    {
        $this->assertMayView($by, $entry->user);
        if ($entry->isCurrent()) {
            throw ValidationException::withMessages(['history' => 'Die aktuelle Station kommt aus den Mitgliedsdaten. Dort ändern, dann wird sie hier abgeschlossen.']);
        }
        $entry->delete();
    }

    /** A Nachweis (PDF or photo) at an entry of the Diensthistorie */
    public function attachHistoryFile(User $by, HistoryEntry $entry, UploadedFile $file): HistoryFile
    {
        $this->assertMayView($by, $entry->user);
        if (! in_array($file->getMimeType(), HistoryFile::MIMES, true) || $file->getSize() > HistoryFile::MAX_KB * 1024) {
            throw ValidationException::withMessages(['upload' => 'Bitte ein PDF oder ein Foto (JPG, PNG) bis 10 MB.']);
        }

        return HistoryFile::create([
            'entry_id' => $entry->id, 'filename' => mb_substr($file->getClientOriginalName() ?: 'Nachweis', 0, 200), 'mime' => $file->getMimeType(),
            'size' => $file->getSize(), 'data' => base64_encode((string) file_get_contents($file->getRealPath())), 'uploaded_by' => $by->id,
        ]);
    }

    public function deleteHistoryFile(User $by, HistoryFile $file): void
    {
        $this->assertMayView($by, $file->entry->user);
        $file->delete();
    }

    // ─── Auswertungen ────────────────────────────────────────────────────────

    public const REPORTS = ['funktionen' => 'Funktionsträger', 'dienstgrade' => 'Dienstgrade', 'befoerderungen' => 'Beförderungen', 'ehrungen' => 'Ehrungen'];

    /**
     * An Auswertung for Kommune or Verband from the Diensthistorie, within the Kameraden this Personalverwalter
     * sees: rows of [Gruppe, Name, Angabe, Datum]. Funktionsträger and Dienstgrade show today's state, Beförderungen
     * and Ehrungen the period.
     *
     * @return list<array{group: string, name: string, detail: string, date: ?Carbon}>
     */
    public function report(User $by, string $kind, Carbon $from, Carbon $to): array
    {
        if (! self::canView($by)) {
            throw ValidationException::withMessages(['report' => 'Das dürfen Personalverwalter und Personaleinsicht.']);
        }
        $ids = self::visibleMembers($by)->pluck('id');
        $active = self::visibleMembers($by)->where('status', 'active')->pluck('id');
        $since = fn (string $k, ?int $ref, int $user) => HistoryEntry::where('user_id', $user)->where('kind', $k)->whereNull('until')
            ->when($ref, fn ($q) => $q->where('ref_id', $ref))->latest('id')->value('since');

        return match ($kind) {
            'funktionen' => MemberFunction::with(['parent', 'users' => fn ($q) => $q->whereIn('users.id', $active)->orderBy('name')])->get()
                ->filter(fn (MemberFunction $f) => $f->users->isNotEmpty())->sortBy(fn (MemberFunction $f) => $f->fullLabel())
                ->flatMap(fn (MemberFunction $f) => $f->users->map(fn (User $u) => ['group' => $f->fullLabel(), 'name' => $u->name, 'detail' => $u->rank ?? '',
                    'date' => ($d = $since('function', $f->id, $u->id)) ? Carbon::parse($d) : null]))->values()->all(),
            'dienstgrade' => User::whereIn('id', $active)->whereNotNull('rank')->orderBy('name')->get()
                ->sortBy(fn (User $u) => [-(FireRanks::stepOf($u->rank) ?? -1), $u->name])
                ->map(fn (User $u) => ['group' => $u->rank, 'name' => $u->name, 'detail' => $u->groups()->pluck('name')->implode(', '), 'date' => $u->rank_since])->values()->all(),
            'befoerderungen' => HistoryEntry::with('user')->where('kind', 'rank')->whereIn('user_id', $ids)
                ->whereNotIn('id', HistoryEntry::where('kind', 'rank')->selectRaw('min(id)')->groupBy('user_id'))
                ->whereBetween('since', [$from->toDateString(), $to->toDateString()])->orderBy('since')->get()
                ->map(fn (HistoryEntry $e) => ['group' => $e->since->format('Y'), 'name' => $e->user?->name ?? '', 'detail' => $e->label, 'date' => $e->since])->all(),
            'ehrungen' => Honor::with('user')->whereIn('user_id', $ids)->whereBetween('awarded_on', [$from->toDateString(), $to->toDateString()])->orderBy('awarded_on')->get()
                ->map(fn (Honor $h) => ['group' => $h->title, 'name' => $h->user?->name ?? '', 'detail' => (string) $h->awarded_by, 'date' => $h->awarded_on])->all(),
            default => throw ValidationException::withMessages(['report' => 'Unbekannte Auswertung.']),
        };
    }

    // ─── Sammelverarbeitung ──────────────────────────────────────────────────

    /** The Kameraden of $ids this Personalverwalter may edit */
    private function editable(User $by, array $ids): EloquentCollection
    {
        $this->assertManager($by);
        $members = self::visibleMembers($by)->whereIn('id', array_map('intval', $ids))->orderBy('name')->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['bulk' => 'Bitte mindestens einen Kameraden auswählen.']);
        }

        return $members;
    }

    /**
     * Beförderung for several at once: one step up, or to the chosen step, in the form of each Kamerad
     * (Oberfeuerwehrmann / Oberfeuerwehrfrau). The Diensthistorie gets the date of the Beförderung.
     *
     * @return array{done: list<string>, skipped: list<string>}
     */
    public function promote(User $by, array $ids, ?int $step, ?string $on): array
    {
        $members = $this->editable($by, $ids);
        $date = $this->date($on, 'bulk') ?? self::today();
        $result = ['done' => [], 'skipped' => []];
        foreach ($members as $member) {
            $current = FireRanks::stepOf($member->rank);
            $target = $step ?? ($current !== null ? $current + 1 : null);
            $gender = FireRanks::for($member->gender) !== null ? $member->gender : (in_array($member->rank, FireRanks::options('female'), true) ? 'female' : 'male');
            $rank = $target !== null ? FireRanks::label($target, $gender) : null;
            if ($rank === null || ($current !== null && $target <= $current)) {
                $result['skipped'][] = $member->name;

                continue;
            }
            DB::transaction(function () use ($member, $rank, $date) {
                $member->update(['rank' => $rank, 'rank_since' => $date]);
                $this->syncHistory($member);
                // The new stage starts on the day of the Beförderung, the old one ends there
                $open = HistoryEntry::where('user_id', $member->id)->where('kind', 'rank')->whereNull('until')->latest('id')->first();
                $open?->update(['since' => $date]);
                HistoryEntry::where('user_id', $member->id)->where('kind', 'rank')->whereKeyNot($open?->id)->whereDate('until', self::today())->latest('id')->first()
                    ?->update(['until' => $date]);
            });
            $result['done'][] = $member->name.' → '.$rank;
        }

        return $result;
    }

    /** An Ehrung for several at once; who already has this kind is skipped */
    public function awardMany(User $by, array $ids, array $data): array
    {
        $members = $this->editable($by, $ids);
        $result = ['done' => [], 'skipped' => []];
        foreach ($members as $member) {
            if (filled($data['honor_type_id'] ?? null) && Honor::where('user_id', $member->id)->where('honor_type_id', (int) $data['honor_type_id'])->exists()) {
                $result['skipped'][] = $member->name;

                continue;
            }
            $this->addHonor($by, $member, $data);
            $result['done'][] = $member->name;
        }

        return $result;
    }

    /** Gives or takes a Funktion for several at once; the Diensthistorie follows */
    public function setFunction(User $by, array $ids, int $functionId, bool $give): array
    {
        $members = $this->editable($by, $ids);
        $function = MemberFunction::assignable()->find($functionId) ?? throw ValidationException::withMessages(['bulk' => 'Bitte eine Funktion wählen.']);
        foreach ($members as $member) {
            $give ? $member->functions()->syncWithoutDetaching([$function->id]) : $member->functions()->detach($function->id);
            $this->syncHistory($member->load('functions'));
        }

        return ['done' => $members->pluck('name')->all(), 'skipped' => []];
    }

    // ─── Ehrungen ────────────────────────────────────────────────────────────

    /**
     * Usual kinds to start with. The exact names differ from Land to Land and in the Verbände – adjust them
     * afterwards; existing names are left alone.
     */
    public const HONOR_PRESETS = [
        ['Feuerwehr-Ehrenzeichen 10 Jahre', 10], ['Feuerwehr-Ehrenzeichen 25 Jahre', 25], ['Feuerwehr-Ehrenzeichen 40 Jahre', 40],
        ['Feuerwehr-Ehrenzeichen 50 Jahre', 50], ['Feuerwehr-Ehrenzeichen 60 Jahre', 60], ['Leistungsabzeichen', null],
        ['Ehrennadel des Kreisfeuerwehrverbandes', null], ['Deutsches Feuerwehr-Ehrenkreuz', null],
    ];

    /** @return int how many were added */
    public function addHonorPresets(User $by): int
    {
        $this->assertManager($by);
        $added = 0;
        foreach (self::HONOR_PRESETS as [$name, $years]) {
            if (! HonorType::where('name', $name)->exists()) {
                $this->saveHonorType($by, null, $name, $years);
                $added++;
            }
        }

        return $added;
    }

    /** A copy to adapt, e.g. "… 25 Jahre" → "… 30 Jahre" */
    public function copyHonorType(User $by, HonorType $type): HonorType
    {
        $name = $type->name.' (Kopie)';
        for ($i = 2; HonorType::where('name', $name)->exists(); $i++) {
            $name = $type->name.' (Kopie '.$i.')';
        }

        return $this->saveHonorType($by, null, mb_substr($name, 0, 150), $type->after_years);
    }

    public function saveHonorType(User $by, ?HonorType $type, string $name, ?int $afterYears): HonorType
    {
        $this->assertManager($by);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['type_name' => 'Bitte einen Namen angeben (höchstens 150 Zeichen).']);
        }
        if ($afterYears !== null && ($afterYears < 1 || $afterYears > 80)) {
            throw ValidationException::withMessages(['type_years' => 'Dienstjahre zwischen 1 und 80, oder leer lassen.']);
        }
        if (HonorType::where('name', $name)->when($type, fn ($q) => $q->whereKeyNot($type->id))->exists()) {
            throw ValidationException::withMessages(['type_name' => 'Diese Ehrung gibt es schon.']);
        }

        $type ??= new HonorType(['sort_order' => (int) HonorType::max('sort_order') + 1]);
        $type->fill(['name' => $name, 'after_years' => $afterYears])->save();

        return $type;
    }

    public function deleteHonorType(User $by, HonorType $type): void
    {
        $this->assertManager($by);
        $type->delete();   // received Ehrungen keep their title
    }

    public function addHonor(User $by, User $member, array $data): Honor
    {
        $this->assertMayView($by, $member);
        $type = filled($data['honor_type_id'] ?? null) ? HonorType::find((int) $data['honor_type_id']) : null;
        $title = trim((string) ($data['title'] ?? '')) ?: $type?->name;
        if (! $title) {
            throw ValidationException::withMessages(['honor_title' => 'Bitte die Ehrung wählen oder benennen.']);
        }
        $awarded = $this->date($data['awarded_on'] ?? null, 'honor_date') ?? throw ValidationException::withMessages(['honor_date' => 'Bitte das Datum der Ehrung angeben.']);
        if ($awarded->gt(self::today())) {
            throw ValidationException::withMessages(['honor_date' => 'Das Datum liegt in der Zukunft.']);
        }

        return Honor::create(['user_id' => $member->id, 'honor_type_id' => $type?->id, 'title' => mb_substr($title, 0, 200), 'awarded_on' => $awarded,
            'awarded_by' => filled($data['awarded_by'] ?? null) ? mb_substr(trim($data['awarded_by']), 0, 150) : null,
            'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 300) : null, 'created_by' => $by->id]);
    }

    public function deleteHonor(User $by, Honor $honor): void
    {
        $this->assertMayView($by, $honor->user);
        $honor->delete();
    }

    /** Full Dienstjahre on a day, counted from the Eintritt */
    /** Anrechenbare Dienstjahre from the Diensthistorie (see ServiceTime) */
    public static function serviceYears(User $user, ?Carbon $on = null): ?int
    {
        return ServiceTime::years($user, $on);
    }

    /**
     * How Jugendfeuerwehr time counts: fully, only from an age, or not at all – for the Abteilung chosen as
     * Jugendfeuerwehr (without one, nothing is deducted).
     *
     * @return array{youth_group_id: ?int, youth_rule: string, youth_age: int}
     */
    public static function serviceRules(): array
    {
        $stored = json_decode((string) Setting::get(self::RULES_KEY, ''), true) ?: [];
        $rule = in_array($stored['youth_rule'] ?? null, ['full', 'age', 'none'], true) ? $stored['youth_rule'] : 'full';

        return ['youth_group_id' => filled($stored['youth_group_id'] ?? null) ? (int) $stored['youth_group_id'] : null, 'youth_rule' => $rule,
            'youth_age' => max(6, min(27, (int) ($stored['youth_age'] ?? 16)))];
    }

    public function saveServiceRules(User $by, ?int $groupId, string $rule, int $age): void
    {
        $this->assertManager($by);
        if ($groupId !== null && ! Group::whereKey($groupId)->exists()) {
            throw ValidationException::withMessages(['rules' => 'Diese Abteilung gibt es nicht.']);
        }
        if (! in_array($rule, ['full', 'age', 'none'], true) || $age < 6 || $age > 27) {
            throw ValidationException::withMessages(['rules' => 'Bitte eine Regel und ein Alter zwischen 6 und 27 wählen.']);
        }
        Setting::set(self::RULES_KEY, json_encode(['youth_group_id' => $groupId, 'youth_rule' => $rule, 'youth_age' => $age]));
    }

    /**
     * Ehrungen that are due by the end of $year (for the Jahreshauptversammlung): every kind with
     * Dienstjahre that an active Kamerad reaches by then and hasn't received yet.
     *
     * @return Collection<int, array{user: User, type: HonorType, reached_on: Carbon, overdue: bool}>
     */
    public function dueHonors(User $manager, int $year, ?User $only = null): Collection
    {
        $types = HonorType::whereNotNull('after_years')->orderBy('after_years')->get();
        if ($types->isEmpty()) {
            return collect();
        }
        $endOfYear = Carbon::create($year, 12, 31);
        $members = ($only ? User::whereKey($only->id) : self::visibleMembers($manager))
            ->where('status', 'active')->whereNotNull('joined_at')->orderBy('name')->get();
        // Which kinds each Kamerad already has (no relation on the core User model)
        $received = Honor::whereIn('user_id', $members->pluck('id'))->whereNotNull('honor_type_id')->get()
            ->groupBy('user_id')->map(fn ($honors) => $honors->pluck('honor_type_id')->all());

        // When each Ehrung is reached follows the anrechenbare Dienstzeit, not just the Eintritt
        return $members->flatMap(fn (User $member) => $types
            ->reject(fn (HonorType $t) => in_array($t->id, $received[$member->id] ?? [], true))
            ->map(fn (HonorType $t) => ['user' => $member, 'type' => $t, 'reached_on' => ServiceTime::reachedOn($member, $t->after_years)])
            ->filter(fn ($row) => $row['reached_on'] && $row['reached_on']->lte($endOfYear))
            ->map(fn ($row) => [...$row, 'overdue' => $row['reached_on']->year < $year]))
            ->sortBy(fn ($row) => $row['reached_on']->timestamp)->values();
    }

    // ─── Dienstausweis ───────────────────────────────────────────────────────

    public function saveIdCard(User $by, User $member, ?string $number, ?string $validUntil, ?string $birthPlace = null): MemberProfile
    {
        $this->assertMayView($by, $member);

        return MemberProfile::updateOrCreate(['user_id' => $member->id], [
            'id_card_number' => filled($number) ? mb_substr(trim($number), 0, 50) : null,
            'id_card_valid_until' => $this->date($validUntil, 'id_card_valid_until'),
            'birth_place' => filled($birthPlace) ? mb_substr(trim($birthPlace), 0, 100) : null,
        ]);
    }

    // ─── Dienstbeteiligung ───────────────────────────────────────────────────

    public static function attendanceAvailable(): bool
    {
        return app(ModuleRegistry::class)->isActive('kalender');
    }

    /**
     * From the Kalender: the Dienste of $year (types, not cancelled, over) that were meant for the
     * Kamerad and where attendance was taken, and how many of them he attended. Before the Eintritt
     * nothing counts.
     *
     * @return array{total: int, attended: int, percent: ?int}
     */
    public function attendance(User $member, int $year, array $types = self::DUTY_TYPES): array
    {
        $from = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc();
        $until = Carbon::create($year + 1, 1, 1, 0, 0, 0, 'Europe/Berlin')->utc()->min(now());
        if ($member->joined_at && $member->joined_at->gt($from)) {
            $from = $member->joined_at->copy()->setTimezone('Europe/Berlin')->startOfDay()->utc();
        }

        $events = Audience::scope(Event::query(), $member, events: true)
            ->whereNull('source_id')->whereNull('cancelled_at')->whereIn('type', $types)
            ->where('starts_at', '>=', $from)->where('ends_at', '<=', $until)
            // Attendance was taken: someone is marked present or absent
            ->whereHas('responses', fn ($q) => $q->whereNotNull('attended'))
            ->pluck('id');
        $attended = $events->isEmpty() ? 0 : Response::whereIn('event_id', $events)->where('user_id', $member->id)->where('attended', true)->count();
        $total = $events->count();

        return ['total' => $total, 'attended' => $attended, 'percent' => $total > 0 ? (int) round($attended / $total * 100) : null];
    }

    /** CSV for Excel: one line per Kamerad */
    public function attendanceCsv(User $manager, int $year, array $types): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        Csv::put($out, ['Name', 'Abteilungen', 'Dienste', 'Anwesend', 'Dienstbeteiligung %'], ';');
        foreach (self::visibleMembers($manager)->where('status', 'active')->with('groups')->orderBy('name')->get() as $member) {
            $a = $this->attendance($member, $year, $types);
            Csv::put($out, [$member->name, $member->groups->pluck('name')->implode(', '), $a['total'], $a['attended'], $a['percent'] ?? ''], ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ─── Jahresbericht ───────────────────────────────────────────────────────

    /**
     * The members part of the Jahresbericht, from the career: strength per Abteilung at the end of
     * the year (today for the running year), Eintritte, Austritte, Beförderungen, Ehrungen.
     */
    public function annual(int $year): array
    {
        $end = Carbon::create($year, 12, 31)->min(self::today());
        $from = Carbon::create($year, 1, 1);
        $covers = fn ($q) => $q->where(fn ($w) => $w->whereNull('since')->orWhere('since', '<=', $end->toDateString()))
            ->where(fn ($w) => $w->whereNull('until')->orWhere('until', '>', $end->toDateString()));
        $active = HistoryEntry::where('kind', 'status')->where('label', 'Aktiv')->where($covers)->pluck('user_id');
        $firstRank = HistoryEntry::where('kind', 'rank')->selectRaw('user_id, min(id) as id')->groupBy('user_id')->pluck('id');

        return [
            'end' => $end,
            'active' => $active->count(),
            'byGroup' => HistoryEntry::where('kind', 'group')->where($covers)->whereIn('user_id', $active)
                ->selectRaw('label, count(*) as total')->groupBy('label')->orderBy('label')->pluck('total', 'label'),
            'joined' => User::whereBetween('joined_at', [$from->toDateString(), $end->toDateString()])->orderBy('joined_at')->get(),
            'left' => HistoryEntry::with('user')->where('kind', 'status')->where('label', 'Ausgetreten')->whereBetween('since', [$from->toDateString(), $end->toDateString()])->orderBy('since')->get(),
            'promotions' => HistoryEntry::with('user')->where('kind', 'rank')->whereNotIn('id', $firstRank)
                ->whereBetween('since', [$from->toDateString(), $end->toDateString()])->orderBy('since')->get(),
            'honors' => Honor::with('user')->whereYear('awarded_on', $year)->orderBy('awarded_on')->get(),
            'attendance' => self::attendanceAvailable()
                ? User::where('status', 'active')->get()->map(fn (User $u) => $this->attendance($u, $year)['percent'])->filter(fn ($p) => $p !== null)->avg()
                : null,
        ];
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: ?Carbon, 3: ?Carbon} */
    private function validStage(array $data, bool $needsUntil): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! array_key_exists($kind, HistoryEntry::KINDS)) {
            throw ValidationException::withMessages(['history_kind' => 'Bitte wählen, was es war.']);
        }
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 200) {
            throw ValidationException::withMessages(['history_label' => 'Bitte angeben, z. B. den Dienstgrad oder die Abteilung.']);
        }
        $since = $this->date($data['since'] ?? null, 'history_since');
        $until = $this->date($data['until'] ?? null, 'history_until');
        if ($kind === 'event') {
            // An Ereignis has one day
            if (! $since) {
                throw ValidationException::withMessages(['history_since' => 'Bitte das Datum des Ereignisses angeben.']);
            }
            if ($since->gt(self::today())) {
                throw ValidationException::withMessages(['history_since' => 'Das Datum liegt in der Zukunft.']);
            }

            return [$kind, $label, $since, null];
        }
        if ($kind === 'service' && ! $since) {
            throw ValidationException::withMessages(['history_since' => 'Bitte angeben, seit wann – sonst lässt sich die Dienstzeit nicht anrechnen.']);
        }
        if ($needsUntil && ! $until) {
            throw ValidationException::withMessages(['history_until' => 'Eine frühere Station braucht ein Ende. Die aktuelle kommt aus den Mitgliedsdaten.']);
        }
        if ($since && $until && $until->lt($since)) {
            throw ValidationException::withMessages(['history_until' => '„Bis“ liegt vor „seit“.']);
        }
        if (($until ?? $since)?->gt(self::today())) {
            throw ValidationException::withMessages(['history_until' => 'Das Datum liegt in der Zukunft.']);
        }

        return [$kind, $label, $since, $until];
    }

    private function date(?string $value, string $field): ?Carbon
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Bitte ein gültiges Datum angeben.']);
        }
    }

    // ─── Änderungswünsche ────────────────────────────────────────────────────

    /** A Kamerad reports what is wrong in their Personalakte; the responsible Personalverwalter hear it */
    public function requestChange(User $member, string $topic, string $message): ChangeRequest
    {
        $message = trim($message);
        $errors = array_filter([
            'request_topic' => array_key_exists($topic, ChangeRequest::TOPICS) ? null : 'Bitte wählen, worum es geht.',
            'request_message' => $message === '' || mb_strlen($message) > 2000 ? 'Bitte beschreiben, was geändert werden soll (höchstens 2000 Zeichen).' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (ChangeRequest::where('user_id', $member->id)->where('status', 'open')->count() >= 5) {
            throw ValidationException::withMessages(['request_message' => 'Du hast schon fünf offene Änderungswünsche – bitte warte auf die Antwort.']);
        }

        $request = ChangeRequest::create(['user_id' => $member->id, 'topic' => $topic, 'message' => $message]);
        $this->responsibleManagers($member)->each(fn (User $manager) => rescue(fn () => $manager->notify(new ChangeRequestedNotification($request->load('user')))));

        return $request;
    }

    /** Done or not taken over, with an optional answer; the Kamerad hears it */
    public function answerChange(User $by, ChangeRequest $request, string $status, ?string $answer): void
    {
        $this->assertMayView($by, $request->user);
        if (! in_array($status, ['done', 'declined'], true) || $request->status !== 'open') {
            throw ValidationException::withMessages(['request' => 'Dieser Änderungswunsch ist schon beantwortet.']);
        }
        if ($status === 'declined' && blank($answer)) {
            throw ValidationException::withMessages(['request' => 'Bitte kurz begründen, warum die Änderung nicht übernommen wird.']);
        }

        $request->update(['status' => $status, 'answer' => filled($answer) ? mb_substr(trim($answer), 0, 2000) : null, 'handled_by' => $by->id, 'handled_at' => now()]);
        rescue(fn () => $request->user->notify(new ChangeRequestAnsweredNotification($request)));
    }

    /** Open Änderungswünsche of the Kameraden this Personalverwalter looks after */
    public function openRequests(User $manager): EloquentCollection
    {
        return ChangeRequest::with('user')->where('status', 'open')
            ->whereIn('user_id', self::visibleMembers($manager)->select('id'))->oldest()->get();
    }

    // ─── Digitaler Dienstausweis ─────────────────────────────────────────────

    /**
     * The Dienstausweis on the phone for every active Kamerad – no access to the module needed –
     * while the module is on and the Personalverwalter switched it on.
     */
    public static function digitalIdFor(User $user): ?array
    {
        if (! $user->is_active || ! app(ModuleRegistry::class)->isActive(self::MODULE) || ! self::settings()['digital_id']) {
            return null;
        }
        $settings = self::settings();
        $profile = MemberProfile::find($user->id);
        if ($settings['id_require_number'] && blank($profile?->id_card_number)) {
            return null;
        }

        return [
            'user' => $user->loadMissing('groups'), 'number' => $profile?->id_card_number, 'valid_until' => $profile?->id_card_valid_until,
            'expired' => $profile?->id_card_valid_until?->lt(self::today()) ?? false,
            'birth_date' => $settings['birth_date'] && $settings['id_birth_date'] ? $user->birth_date : null, 'birth_on_check' => $settings['id_birth_on_check'],
            'birth_place' => $profile?->birth_place, 'issuer' => $settings['id_issuer'] ?: null,
            'brigade' => app(OrganizationSettings::class)->name(),
        ];
    }

    // ─── Erziehungsberechtigte ───────────────────────────────────────────────

    /** An Erziehungsberechtigte(r) of the Kamerad: gets the Kalender mails and answers for the child */
    public function addGuardian(User $by, User $member, array $data): Guardian
    {
        $this->assertMayView($by, $member);
        $name = trim((string) ($data['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $errors = array_filter([
            'guardian_name' => $name === '' || mb_strlen($name) > 150 ? 'Bitte den Namen angeben.' : null,
            'guardian_email' => ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255 ? 'Bitte eine gültige E-Mail-Adresse angeben.'
                : ($member->guardians()->where('email', $email)->exists() ? 'Diese Adresse ist schon eingetragen.' : null),
            'guardian_phone' => mb_strlen($phone) > 50 ? 'Die Telefonnummer ist zu lang.' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $member->guardians()->create(['name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null]);
    }

    public function deleteGuardian(User $by, Guardian $guardian): void
    {
        $this->assertMayView($by, $guardian->user);
        $guardian->delete();
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['personal' => 'Das dürfen nur Personalverwalter und Admins.']);
        }
    }

    private function assertMayView(User $by, User $member): void
    {
        if (! self::mayView($by, $member)) {
            throw ValidationException::withMessages(['personal' => 'Diese Personalakte darfst du nicht bearbeiten.']);
        }
    }
}
