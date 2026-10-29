<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Kleiderkammer\src\Filament\Concerns\KleiderkammerArea;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataTransferPage extends Page implements HasForms
{
    use InteractsWithForms;
    use KleiderkammerArea;

    protected static ?string $slug = 'kleiderkammer/datentransfer';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Datentransfer';

    protected static ?string $title = 'Datentransfer';

    protected static ?int $navigationSort = 99;

    protected string $view = 'kleiderkammer::filament.pages.data-transfer';

    /** @var array{parts?: string[]} */
    public ?array $exportData = [];

    /** @var array{file?: mixed, parts?: string[], types?: string[]} */
    public ?array $importData = [];

    /** What the uploaded file contains: part → row count, plus the item type names */
    public array $fileInfo = [];

    // Admins only, not every Kleiderwart
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin && KleiderkammerDashboard::canAccess();
    }

    public function mount(): void
    {
        // Catalog and stock by default; member data only on request
        $this->exportForm->fill(['parts' => ['categories', 'item_types', 'items', 'stock', 'treatments']]);
        $this->importForm->fill();
    }

    public function exportForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Was soll exportiert werden?')->schema([
                Forms\Components\CheckboxList::make('parts')
                    ->hiddenLabel()
                    ->options(DataTransferService::PARTS)
                    ->descriptions([
                        'issuances' => 'Nimmt automatisch die Mitglieder mit (Name, E-Mail, Abteilung …).',
                        'members' => 'Persönliche Daten – für reine Katalog- und Bestandsübernahmen nicht nötig.',
                    ])
                    ->columns(['default' => 1, 'md' => 2])
                    ->bulkToggleable()
                    ->required(),
            ])->columnSpanFull(),
        ])->statePath('exportData');
    }

    public function export(): StreamedResponse
    {
        $parts = $this->exportForm->getState()['parts'] ?? [];

        $json = json_encode(
            app(DataTransferService::class)->export($parts),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // Livewire only turns streamed responses into a browser download
        return response()->streamDownload(
            fn () => print ($json),
            'kleiderkammer-export-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function importForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('JSON-Datei hochladen')->schema([
                Forms\Components\FileUpload::make('file')
                    ->label('Export-Datei (JSON)')
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->disk('local')
                    ->directory('kleiderkammer-imports')
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->analyse($state))
                    ->helperText('Der Import ergänzt vorhandene Datensätze (upsert), löscht aber keine bestehenden. Mitglieder werden über die E-Mail-Adresse den Nutzern zugeordnet oder neu angelegt; Mitglieder ohne E-Mail werden nicht übernommen.')
                    ->required(),

                Forms\Components\CheckboxList::make('parts')
                    ->label('Was soll importiert werden?')
                    ->options(fn () => collect($this->fileInfo['parts'] ?? [])
                        ->mapWithKeys(fn ($count, $part) => [$part => DataTransferService::PARTS[$part].' ('.$count.')'])
                        ->all())
                    ->descriptions(['issuances' => 'Ausgaben brauchen die Mitglieder aus derselben Datei; sie werden mit übernommen.'])
                    ->columns(['default' => 1, 'md' => 2])
                    ->bulkToggleable()
                    ->visible(fn () => ! empty($this->fileInfo['parts'])),

                Forms\Components\Select::make('types')
                    ->label('Nur diese Artikel')
                    ->options(fn () => collect($this->fileInfo['types'] ?? [])->mapWithKeys(fn ($name) => [$name => $name])->all())
                    ->multiple()
                    ->searchable()
                    ->placeholder('Alle Artikel')
                    ->helperText('Leer lassen, um alle zu importieren. Gilt für Artikel, Einzelteile, Bestand, Behandlungen, Ausgaben und Größen.')
                    ->visible(fn () => ! empty($this->fileInfo['types'])),
            ])->columnSpanFull(),
        ])->statePath('importData');
    }

    /** Reads the freshly uploaded file to offer what is in it */
    private function analyse(mixed $state): void
    {
        $this->fileInfo = [];

        $file = is_array($state) ? Arr::first($state) : $state;
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $payload = json_decode($file->get(), true, 512, JSON_THROW_ON_ERROR);
            $info = app(DataTransferService::class)->inspect($payload);
        } catch (\Throwable) {
            Notification::make()->title('Das ist keine gültige Export-Datei')->danger()->send();

            return;
        }

        $this->fileInfo = $info;
        // Everything in the file is selected at first; the admin narrows it down
        $this->importData['parts'] = array_keys($info['parts']);
        $this->importData['types'] = [];
    }

    public function import(): void
    {
        $state = $this->importForm->getState();

        $files = $state['file'] ?? [];
        if (empty($files)) {
            Notification::make()->title('Keine Datei ausgewählt')->warning()->send();

            return;
        }

        $parts = $state['parts'] ?? [];
        if ($parts === []) {
            Notification::make()->title('Nichts zum Importieren ausgewählt')->warning()->send();

            return;
        }

        // getState() has already moved the upload onto the disk; $path is relative to it.
        $path = is_array($files) ? reset($files) : $files;

        try {
            $payload = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);

            $report = app(DataTransferService::class)->import($payload, $parts, $state['types'] ?? []);

            $this->fileInfo = [];
            $this->importForm->fill();

            $body = "{$report['issued_items']} Ausgabe(n) übernommen.";
            if ($report['skipped_members'] !== []) {
                $body .= ' Ohne E-Mail nicht übernommen: '.implode(', ', $report['skipped_members']).'.';
            }
            if ($report['skipped_issued_items'] > 0) {
                $body .= " {$report['skipped_issued_items']} Ausgabe(n) konnten keinem Mitglied oder Teil zugeordnet werden.";
            }

            $notification = Notification::make()->title('Import erfolgreich')->body($body);
            ($report['skipped_members'] === [] && $report['skipped_issued_items'] === 0)
                ? $notification->success()
                : $notification->warning()->persistent();
            $notification->send();
        } catch (\JsonException $e) {
            Notification::make()
                ->title('Ungültige JSON-Datei')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Fehler beim Import')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }
}
