<?php

namespace App\Filament\Pages;

use App\Models\Group;
use App\Models\User;
use App\Services\MonitorRegistry;
use App\Support\DisplayToken;
use App\Support\MonitorSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Settings of the Wachen-Monitor (/monitor): the link for the station PC, how many fields the screen
 * has and what each of them shows. Admins only.
 */
class MonitorSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'wachen-monitor';

    protected static ?string $navigationLabel = 'Wachen-Monitor';

    protected static ?string $title = 'Wachen-Monitor';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tv';

    /** Between Einstellungen (5) and Feedback (7) */
    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.monitor-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Where each field sits, by number of fields */
    private const PLACES = [
        1 => ['ganzer Bildschirm'],
        2 => ['links', 'rechts'],
        4 => ['links oben', 'rechts oben', 'links unten', 'rechts unten'],
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_admin;
    }

    public function mount(): void
    {
        $settings = MonitorSettings::get();

        $this->getSchema('form')->fill([
            'fields' => (string) $settings['fields'],
            'panel_0' => $settings['panels'][0],
            'panel_1' => $settings['panels'][1],
            'panel_2' => $settings['panels'][2],
            'panel_3' => $settings['panels'][3],
            'group_ids' => $settings['group_ids'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $options = app(MonitorRegistry::class)->options();

        $panelSelect = fn (int $i) => Forms\Components\Select::make('panel_'.$i)
            ->label(fn (Get $get) => 'Feld '.($i + 1).' ('.(self::PLACES[(int) $get('fields')][$i] ?? '').')')
            ->options($options)
            ->placeholder('Leer')
            ->in(array_keys($options))
            ->nullable()
            ->visible(fn (Get $get) => $i < (int) $get('fields'));

        return $schema
            ->components([
                Section::make('Aufteilung')
                    ->description('Wie viele Felder der Bildschirm zeigt und was in jedem Feld steht. Über den Feldern stehen immer Wehrname, Logo, Uhrzeit und Datum.')
                    ->schema([
                        Forms\Components\ToggleButtons::make('fields')
                            ->label('Anzahl Felder')
                            ->options(['1' => '1 Feld', '2' => '2 Felder', '4' => '4 Felder'])
                            ->inline()
                            ->required()
                            ->in(['1', '2', '4'])
                            ->live(),
                        $panelSelect(0),
                        $panelSelect(1),
                        $panelSelect(2),
                        $panelSelect(3),
                    ])
                    ->columnSpanFull(),

                Section::make('Abteilungen')
                    ->description('Für die Übersichten je Abteilung (z. B. Aktive, Jugendfeuerwehr und Musikzug nebeneinander).')
                    ->schema([
                        Forms\Components\Select::make('group_ids')
                            ->label('Abteilungen in den Übersichten')
                            ->multiple()
                            ->options(fn () => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                            ->helperText('Leer: alle Abteilungen, die etwas geplant haben. Nebeneinander passen bis zu drei (bei einem Feld vier).'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->getSchema('form')->getState();
        $fields = (int) $data['fields'];

        MonitorSettings::save(
            $fields,
            array_map(fn (int $i) => $i < $fields ? ($data['panel_'.$i] ?? null) : (MonitorSettings::get()['panels'][$i] ?? null), range(0, MonitorSettings::MAX_FIELDS - 1)),
            $data['group_ids'] ?? [],
        );

        Notification::make()->title('Wachen-Monitor gespeichert')->body('Der Bildschirm übernimmt es spätestens in einer Minute.')->success()->send();
    }

    public function resetToDefaults(): void
    {
        MonitorSettings::save(MonitorSettings::MAX_FIELDS, MonitorSettings::defaultPanels(), []);
        $this->mount();

        Notification::make()->title('Standard wiederhergestellt')->success()->send();
    }

    protected function getViewData(): array
    {
        return [
            'adminUrl' => route('monitor'),
            'stationUrl' => DisplayToken::url('monitor'),
            'contents' => app(MonitorRegistry::class)->available(),
        ];
    }
}
