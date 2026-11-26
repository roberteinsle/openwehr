<?php

namespace App\Filament\Pages;

use App\Models\Alarm;
use App\Models\User;
use App\Services\AlarmService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Settings of the DIVERA webhook (POST application/json → /webhooks/alarm/{key}): on/off, the address
 * for DIVERA, how long an alarm stays on the Wachen-Monitor, locating by OpenStreetMap, a test alarm
 * and the last alarms. Admins only.
 */
class DiveraWebhookPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'divera-webhook';

    protected static ?string $navigationLabel = 'DIVERA-Webhook';

    protected static ?string $title = 'DIVERA-Webhook';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    /** Between Wachen-Monitor (6) and Feedback (8) */
    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.divera-webhook';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public ?array $test = [];

    /** The alarm being edited under "Letzte Alarme" */
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public ?array $edit = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin;
    }

    public function mount(): void
    {
        $settings = AlarmService::settings();
        $this->getSchema('form')->fill([
            'enabled' => $settings['enabled'],
            'show_minutes' => (string) $settings['show_minutes'],
            'osm' => $settings['osm'],
            'fields' => $settings['fields'],
        ]);
        $this->getSchema('testForm')->fill(['keyword' => 'B 2 – Brand Gebäude', 'message' => 'Rauchentwicklung aus Kellerfenster', 'address' => 'Hauptstraße 1, Musterstadt',
            'ric' => 'Löschzug Musterstadt', 'vehicle' => 'HLF 20, DLK 23/12', 'priority' => true, 'via_url' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Einstellungen')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Webhook eingeschaltet')
                            ->helperText('Aus: Die Adresse antwortet mit 404, DIVERA-Alarme kommen nicht an.'),
                        Forms\Components\Select::make('show_minutes')
                            ->label('Alarm auf dem Wachen-Monitor zeigen')
                            ->options(collect(AlarmService::SHOW_MINUTES)->mapWithKeys(fn (int $m) => [(string) $m => match ($m) {
                                0 => 'gar nicht', 60 => '1 Stunde', 120 => '2 Stunden', 240 => '4 Stunden', default => $m.' Minuten',
                            }])->all())
                            ->in(array_map('strval', AlarmService::SHOW_MINUTES))
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Ab dem Alarm bzw. seiner letzten Änderung in DIVERA; „Beenden“ unten nimmt ihn sofort herunter.'),
                        Forms\Components\Toggle::make('osm')
                            ->label('Adresse ohne Koordinaten über OpenStreetMap suchen')
                            ->helperText('Nur, wenn DIVERA keine Koordinaten schickt und kein Google-Schlüssel (Einstellungen → Dienste) hinterlegt ist. Die Einsatzadresse geht dann an den Suchdienst Nominatim der OpenStreetMap Foundation.'),
                        Forms\Components\CheckboxList::make('fields')
                            ->label('Auf dem Alarm-Monitor zeigen')
                            ->options(AlarmService::FIELDS)
                            ->in(array_keys(AlarmService::FIELDS))
                            ->columns(2)
                            ->helperText('Was DIVERA nicht mitschickt, bleibt ohnehin leer. Personen sind anfangs aus – der Bildschirm hängt offen in der Wache.'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function testForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('keyword')->label('Stichwort')->required()->maxLength(150),
                    Forms\Components\TextInput::make('address')->label('Adresse')->maxLength(255),
                    Forms\Components\Textarea::make('message')->label('Meldung')->maxLength(2000)->rows(2)->columnSpanFull(),
                    Forms\Components\TextInput::make('latitude')->label('Breitengrad (optional)')->numeric()->minValue(-90)->maxValue(90),
                    Forms\Components\TextInput::make('longitude')->label('Längengrad (optional)')->numeric()->minValue(-180)->maxValue(180),
                    Forms\Components\TextInput::make('ric')->label('RIC (kommagetrennt)')->maxLength(500),
                    Forms\Components\TextInput::make('vehicle')->label('Fahrzeuge (kommagetrennt)')->maxLength(500),
                    Forms\Components\TextInput::make('person')->label('Personen (kommagetrennt)')->maxLength(500),
                    Forms\Components\Toggle::make('priority')->label('Mit Sonderrechten (Priorität)'),
                    Forms\Components\Toggle::make('via_url')->label('Über die Webhook-Adresse senden')
                        ->helperText('Prüft auch Proxy, HTTPS und Schlüssel – wie ein echter Aufruf von DIVERA.'),
                ]),
            ])
            ->statePath('test');
    }

    public function editForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('keyword')->label('Stichwort')->maxLength(150),
                    Forms\Components\TextInput::make('address')->label('Adresse')->maxLength(255),
                    Forms\Components\Textarea::make('message')->label('Meldung')->maxLength(5000)->rows(3)->columnSpanFull(),
                    Forms\Components\TextInput::make('latitude')->label('Breitengrad')->numeric()->minValue(-90)->maxValue(90)
                        ->helperText('Leer lassen: openwehr sucht die Adresse neu.'),
                    Forms\Components\TextInput::make('longitude')->label('Längengrad')->numeric()->minValue(-180)->maxValue(180),
                    Forms\Components\TextInput::make('rics')->label('RIC (kommagetrennt)')->maxLength(2000),
                    Forms\Components\TextInput::make('vehicles')->label('Fahrzeuge (kommagetrennt)')->maxLength(2000),
                    Forms\Components\TextInput::make('persons')->label('Personen (kommagetrennt)')->maxLength(2000),
                    Forms\Components\Toggle::make('priority')->label('Mit Sonderrechten (Priorität)'),
                ]),
            ])
            ->statePath('edit');
    }

    public function startEdit(int $id): void
    {
        $alarm = Alarm::findOrFail($id);
        $this->editingId = $alarm->id;
        $this->getSchema('editForm')->fill([
            'keyword' => $alarm->keyword, 'address' => $alarm->address, 'message' => $alarm->message,
            'latitude' => $alarm->latitude, 'longitude' => $alarm->longitude, 'priority' => $alarm->priority,
            'rics' => implode(', ', $alarm->rics ?? []), 'vehicles' => implode(', ', $alarm->vehicles ?? []), 'persons' => implode(', ', $alarm->persons ?? []),
        ]);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->edit = [];
    }

    public function saveEdit(AlarmService $alarms): void
    {
        $alarm = Alarm::find($this->editingId);
        if (! $alarm) {
            $this->cancelEdit();

            return;
        }
        $data = $this->getSchema('editForm')->getState();
        $number = fn ($v) => filled($v) ? (float) $v : null;
        $alarms->update($alarm, [
            'keyword' => filled($data['keyword'] ?? null) ? trim($data['keyword']) : null,
            'address' => filled($data['address'] ?? null) ? trim($data['address']) : null,
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
            'latitude' => $number($data['latitude'] ?? null),
            'longitude' => $number($data['longitude'] ?? null),
            'priority' => (bool) ($data['priority'] ?? false),
            'rics' => AlarmService::splitList($data['rics'] ?? null),
            'vehicles' => AlarmService::splitList($data['vehicles'] ?? null),
            'persons' => AlarmService::splitList($data['persons'] ?? null),
        ]);
        $this->cancelEdit();

        Notification::make()->title('Alarm gespeichert')->body('Läuft er noch, zeigt der Wachen-Monitor die Änderung in wenigen Sekunden.')->success()->send();
    }

    public function retrigger(int $id, AlarmService $alarms): void
    {
        if ($alarm = Alarm::find($id)) {
            $alarms->retrigger($alarm);
            Notification::make()->title('Alarm erneut ausgelöst')->body('Der Wachen-Monitor zeigt ihn wieder.')->success()->send();
        }
    }

    public function deleteAlarm(int $id): void
    {
        Alarm::whereKey($id)->delete();
        if ($this->editingId === $id) {
            $this->cancelEdit();
        }

        Notification::make()->title('Alarm gelöscht')->success()->send();
    }

    public function save(): void
    {
        $data = $this->getSchema('form')->getState();
        AlarmService::saveSettings((bool) $data['enabled'], (int) $data['show_minutes'], (bool) $data['osm'], array_values((array) ($data['fields'] ?? [])));

        Notification::make()->title('DIVERA-Webhook gespeichert')->success()->send();
    }

    public function regenerateKey(): void
    {
        AlarmService::regenerateKey();

        Notification::make()->title('Neue Adresse erzeugt')->body('Die alte Adresse funktioniert nicht mehr – bitte in DIVERA eintragen.')->warning()->send();
    }

    public function sendTest(AlarmService $alarms): void
    {
        $data = $this->getSchema('testForm')->getState();

        try {
            $alarm = $alarms->test([
                'keyword' => $data['keyword'],
                'message' => $data['message'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => filled($data['latitude'] ?? null) ? (float) $data['latitude'] : null,
                'longitude' => filled($data['longitude'] ?? null) ? (float) $data['longitude'] : null,
                'priority' => (bool) ($data['priority'] ?? false),
                'ric' => filled($data['ric'] ?? null) ? $data['ric'] : null,
                'vehicle' => filled($data['vehicle'] ?? null) ? $data['vehicle'] : null,
                'person' => filled($data['person'] ?? null) ? $data['person'] : null,
            ], (bool) ($data['via_url'] ?? false));
        } catch (Throwable $e) {
            Notification::make()->title('Test-Alarm fehlgeschlagen')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Test-Alarm angekommen')
            ->body($alarm->hasPosition() ? 'Der Wachen-Monitor zeigt ihn mit Karte.' : 'Der Wachen-Monitor zeigt ihn – ohne Karte, die Adresse ließ sich nicht finden.')
            ->success()->send();
    }

    public function endAlarm(int $id, AlarmService $alarms): void
    {
        $alarm = Alarm::find($id);
        $user = auth()->user();
        if ($alarm && $user instanceof User) {
            $alarms->end($alarm, $user->name);
            Notification::make()->title('Alarm beendet')->success()->send();
        }
    }

    protected function getViewData(): array
    {
        return [
            'url' => AlarmService::enabled() ? AlarmService::url() : null,
            'monitorUrl' => route('monitor'),
            'alarms' => Alarm::latest('received_at')->latest('id')->limit(10)->get(),
            'running' => AlarmService::running()->pluck('id')->all(),
        ];
    }
}
