<?php

namespace Modules\Fahrzeuge\src\Livewire;

use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Services\FleetService;

/** Funkrufnamen auf einen Blick: every vehicle in service with its Funkrufname, compact and printable */
class CallSigns extends Component
{
    public string $search = '';

    public function render(FleetService $fleet)
    {
        $vehicles = Vehicle::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $profiles = Profile::whereIn('vehicle_id', $vehicles->pluck('id'))->get()->keyBy('vehicle_id');
        $term = mb_strtolower(trim($this->search));
        $rows = $vehicles->map(fn (Vehicle $v) => ['vehicle' => $v, 'profile' => $profiles[$v->id] ?? null])
            ->filter(fn ($r) => $term === '' || str_contains(mb_strtolower(implode(' ', [$r['vehicle']->name, $r['profile']?->call_sign,
                $r['profile']?->license_plate, $r['profile']?->vehicle_type])), $term))
            ->values();

        return view('fahrzeuge::livewire.call-signs', [
            'rows' => $rows->filter(fn ($r) => filled($r['profile']?->call_sign))->values(),
            'without' => $rows->reject(fn ($r) => filled($r['profile']?->call_sign))->values(),
            'states' => $fleet->states($vehicles),
            'canManage' => FleetService::canManage(auth()->user()),
        ]);
    }
}
