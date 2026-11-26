<?php

namespace Modules\Fahrzeugbuchung;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Modules\Fahrzeugbuchung\src\Filament\Pages\FahrzeugbuchungDashboard;

class FahrzeugbuchungPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'fahrzeugbuchung';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(in: __DIR__.'/src/Filament/Resources', for: 'Modules\\Fahrzeugbuchung\\src\\Filament\\Resources')
            ->discoverPages(in: __DIR__.'/src/Filament/Pages', for: 'Modules\\Fahrzeugbuchung\\src\\Filament\\Pages')
            ->discoverWidgets(in: __DIR__.'/src/Filament/Widgets', for: 'Modules\\Fahrzeugbuchung\\src\\Filament\\Widgets')
            ->navigationItems([
                NavigationItem::make('Fahrzeug-Monitor')
                    ->url(fn () => route('fahrzeugbuchung.monitor'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-tv')
                    ->group('Fahrzeugbuchung')
                    ->sort(90)
                    ->visible(fn () => FahrzeugbuchungDashboard::canAccess()),
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
