<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Analytics;
use App\Services\Analytics as AnalyticsService;
use App\Services\SystemStatus;
use Filament\Widgets\Widget;

/**
 * Admin dashboard: the most important figures of the Analyse at a glance – version, TLS certificate and
 * resources, below mail, backups and response times (7 days). Details on the Analyse page.
 */
class SystemStatusWidget extends Widget
{
    protected static bool $isLazy = true;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.system-status';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    protected function getViewData(): array
    {
        $status = app(SystemStatus::class);
        $analytics = app(AnalyticsService::class);

        return [
            'version' => $status->version(),
            'certificate' => $status->certificate(),
            'resources' => rescue(fn () => $status->resources(), null, false),
            'mail' => $analytics->mail(),
            'backup' => $status->lastBackup(),
            'requests' => $analytics->requests(7),
            'analyseUrl' => Analytics::getUrl(),
        ];
    }
}
