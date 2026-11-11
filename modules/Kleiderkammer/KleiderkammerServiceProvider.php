<?php

namespace Modules\Kleiderkammer;

use App\Models\User;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\ParentRegistry;
use App\Services\ScanRegistry;
use App\Support\ModuleNav;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Kleiderkammer\src\Filament\Pages\KleiderkammerDashboard;
use Modules\Kleiderkammer\src\Filament\Resources\ItemResource;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;
use Modules\Kleiderkammer\src\Livewire\EquipmentMessageForm;
use Modules\Kleiderkammer\src\Livewire\ParentEquipment;
use Modules\Kleiderkammer\src\Livewire\WashingRequestForm;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Services\BarcodeRenderer;
use Modules\Kleiderkammer\src\Services\CodeGenerator;
use Modules\Kleiderkammer\src\Services\CodeResolver;
use Modules\Kleiderkammer\src\Services\DataTransferService;
use Modules\Kleiderkammer\src\Services\EquipmentMessageService;
use Modules\Kleiderkammer\src\Services\InventoryReport;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Modules\Kleiderkammer\src\Services\LabelSheetGenerator;
use Modules\Kleiderkammer\src\Services\QrCodeRenderer;

class KleiderkammerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CodeGenerator::class);
        $this->app->singleton(CodeResolver::class);
        $this->app->singleton(IssuingService::class);
        $this->app->singleton(InventoryReport::class);
        $this->app->singleton(EquipmentMessageService::class);
        $this->app->singleton(DataTransferService::class);
        $this->app->singleton(QrCodeRenderer::class);
        $this->app->singleton(BarcodeRenderer::class);
        $this->app->singleton(LabelSheetGenerator::class);
    }

    public function boot(): void
    {
        // The core User model doesn't know this module; attach the equipment relations here.
        User::resolveRelationUsing('kleiderkammerIssuances', fn (User $user) => $user->hasMany(IssuedItem::class));
        User::resolveRelationUsing('kleiderkammerItems', fn (User $user) => $user->hasMany(IssuedItem::class)->whereNull('returned_at'));
        User::resolveRelationUsing('kleiderkammerSizes', fn (User $user) => $user->hasMany(MemberSize::class));

        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'kleiderkammer');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Livewire::component('kleiderkammer.equipment-message-form', EquipmentMessageForm::class);
        Livewire::component('kleiderkammer.parent-equipment', ParentEquipment::class);

        // Eltern-Bereich: the child's Ausrüstung, once something is issued to it
        app(ParentRegistry::class)->section('kleiderkammer', 'Ausrüstung', 'kleiderkammer.parent-equipment', order: 30,
            visible: fn (User $child) => IssuedItem::where('user_id', $child->id)->whereNull('returned_at')->exists());
        Livewire::component('kleiderkammer.washing-request-form', WashingRequestForm::class);

        app(ModuleRegistry::class)->register(
            'kleiderkammer',
            'Kleiderkammer',
            'Verwaltung der Bekleidungsausgabe für die Feuerwehr',
            roles: ['kleiderwart'],
            provider: self::class,
            route: 'kleiderkammer.index',
        );

        // /i/{code} runs outside every Filament panel, so the panel must be named explicitly.
        app(ScanRegistry::class)->register('kleiderkammer', function (string $code): ?string {
            $item = Item::withTrashed()->where('code', $code)->first();

            if ($item !== null) {
                // An item that is out with someone opens that member, otherwise the item itself
                $holder = $item->currentIssuance()->latest('issued_at')->value('user_id');

                return $holder !== null
                    ? MemberResource::getUrl('edit', ['record' => $holder])
                    : ItemResource::getUrl('edit', ['record' => $item->getKey()]);
            }

            return null;
        });

        // Unknown code: whoever manages the Kleiderkammer can register the item right away.
        // A fallback, so codes of other modules are not mistaken for new items.
        app(ScanRegistry::class)->registerFallback('kleiderkammer', fn (string $code): ?string => auth()->user()?->canManageKleiderkammer()
            ? ItemResource::getUrl('create', ['code' => $code])
            : null);

        // Bestand: Kleidung by code or Art, for those who run the Kleiderkammer
        app(InventoryRegistry::class)->register('kleiderkammer', 'Kleiderkammer', function (string $term, User $user) {
            if (! $user->canManageKleiderkammer()) {
                return null;
            }
            $like = '%'.$term.'%';

            return Item::with(['itemType', 'size'])->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhereHas('itemType', fn ($t) => $t->where('name', 'ilike', $like)))
                ->orderBy('code')->limit(50)->get()
                ->map(fn (Item $item) => ['title' => trim(($item->itemType?->name ?? 'Teil').' '.($item->size?->name ?? '')), 'code' => $item->code, 'place' => null,
                    'state' => $item->status->label(), 'tone' => $item->isRetired() ? 'gray' : ($item->isIssued() ? 'blue' : 'green'),
                    'url' => ItemResource::getUrl('edit', ['record' => $item->getKey()])]);
        }, order: 40);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('kleiderkammer', fn (User $u) => [
            'tabs' => [ModuleNav::tab('Meine Ausrüstung', 'kleiderkammer.index')],
            'manage' => $u->canManageKleiderkammer() ? KleiderkammerDashboard::getUrl() : null,
        ]);
    }
}
