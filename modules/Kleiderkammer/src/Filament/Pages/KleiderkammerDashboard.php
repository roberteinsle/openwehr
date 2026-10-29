<?php

namespace Modules\Kleiderkammer\src\Filament\Pages;

use App\Filament\ModuleDashboard;
use App\Models\User;
use Modules\Kleiderkammer\src\Filament\Widgets\OverviewWidget;
use Modules\Kleiderkammer\src\Filament\Widgets\ReportWidget;

// /admin/kleiderkammer: the Kleiderkammer area of the admin panel
class KleiderkammerDashboard extends ModuleDashboard
{
    protected static string $routePath = 'kleiderkammer';

    protected static ?string $slug = 'kleiderkammer';

    protected static ?string $title = 'Kleiderkammer';

    protected static string|\UnitEnum|null $navigationGroup = 'Kleiderkammer';

    // Admins and Kleiderwarte
    public static function allows(User $user): bool
    {
        return $user->canManageKleiderkammer();
    }

    public function getWidgets(): array
    {
        return [
            OverviewWidget::class,
            ReportWidget::class,
        ];
    }
}
