<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Pages;

use App\Filament\ModuleDashboard;
use App\Models\User;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\MonitorLinkWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\TopBookersWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\UpcomingBookingsWidget;
use Modules\Fahrzeugbuchung\src\Filament\Widgets\VehicleBookingsWidget;

// /admin/fahrzeugbuchung: the Fahrzeugbuchung area of the admin panel
class FahrzeugbuchungDashboard extends ModuleDashboard
{
    protected static string $routePath = 'fahrzeugbuchung';

    protected static ?string $slug = 'fahrzeugbuchung';

    protected static ?string $title = 'Fahrzeugbuchung';

    protected static string|\UnitEnum|null $navigationGroup = 'Fahrzeugbuchung';

    // Only admins manage vehicles, bookings and mail texts
    public static function allows(User $user): bool
    {
        return $user->is_admin;
    }

    public function getWidgets(): array
    {
        return [
            // Top 5 and the coming bookings side by side (desktop), the vehicles below, the Fahrzeug-Monitor last
            TopBookersWidget::class,
            UpcomingBookingsWidget::class,
            VehicleBookingsWidget::class,
            MonitorLinkWidget::class,
        ];
    }
}
