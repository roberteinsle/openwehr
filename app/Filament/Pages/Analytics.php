<?php

namespace App\Filament\Pages;

use App\Filament\Resources\LogResource;
use App\Services\Analytics as AnalyticsService;
use App\Services\ServiceHealth;
use App\Services\SystemStatus;
use App\Support\Retention;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admins: is the app running and does anything need them (errors, uptime, backups, jobs, mail,
 * disk, certificate, version), security (logins, rights, audit log) and usage (last logins, usage
 * per module, data growth). Usage is aggregated per module, never per person. Also the retention
 * of the logs.
 */
class Analytics extends Page
{
    protected static ?string $slug = 'analyse';

    protected static ?string $navigationLabel = 'Analyse';

    protected static ?string $title = 'Analyse';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    /** Between Module (10) and Logs (20) */
    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.analytics';

    /** @var array<string, int> technical, audit, mail (days) */
    public array $retention = [];

    public bool $showToken = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->retention = Retention::days();
    }

    public function checkCertificate(SystemStatus $status): void
    {
        $status->checkCertificate();
        Notification::make()->title('Zertifikat geprüft')->success()->send();
    }

    public function confirmBackup(SystemStatus $status): void
    {
        $status->reportBackup('von Hand bestätigt von '.auth()->user()->name);
        Notification::make()->title('Backup als geprüft vermerkt')->success()->send();
    }

    public function newBackupToken(SystemStatus $status): void
    {
        $status->newBackupToken();
        $this->showToken = true;
        Notification::make()->title('Neue Webhook-Adresse erzeugt – die alte gilt nicht mehr')->success()->send();
    }

    public function checkServices(ServiceHealth $health): void
    {
        $health->status(fresh: true);
    }

    public function saveRetention(): void
    {
        Retention::save($this->retention);
        $this->retention = Retention::days();
        Notification::make()->title('Löschfristen gespeichert')->body('Ältere Einträge löscht die nächtliche Bereinigung.')->success()->send();
    }

    protected function getViewData(): array
    {
        $status = app(SystemStatus::class);
        $analytics = app(AnalyticsService::class);

        return [
            'appErrors' => $analytics->errors(7),
            'requests' => $analytics->requests(7),
            'uptime7' => $status->uptime(7),
            'uptime30' => $status->uptime(30),
            'backup' => $status->lastBackup(),
            'backupUrl' => route('webhooks.backup', $status->backupToken()),
            'runs' => $analytics->scheduleRuns(),
            'mail' => $analytics->mail(),
            'services' => app(ServiceHealth::class)->status(),
            'resources' => rescue(fn () => $status->resources(), null, false),
            'certificate' => $status->certificate(),
            'version' => $status->version(),
            'problems' => $status->problems(),
            'security' => $analytics->security(30),
            'audit' => $analytics->latestAudit(5),
            'logsUrl' => LogResource::getUrl('index'),
            'logins' => $analytics->lastLogins(),
            'usage' => $analytics->moduleUsage(),
            'records' => $analytics->records(),
            'limits' => Retention::LIMITS,
        ];
    }
}
