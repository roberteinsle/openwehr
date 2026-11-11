<?php

namespace Modules\Anmeldungen\src\Livewire;

use Livewire\Component;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Services\RegistrationService;

/** /anmeldungen: the open Anmeldungen for the Kamerad; organisers see every form by state with the numbers */
class FormList extends Component
{
    public function render(RegistrationService $service)
    {
        $user = auth()->user();
        $manager = RegistrationService::canManage($user);
        $mine = Registration::where('user_id', $user->id)->get()->keyBy('form_id');

        return view('anmeldungen::livewire.form-list', [
            'manager' => $manager,
            'open' => $service->openFor($user),
            'mine' => $mine,
            'all' => $manager ? Form::withCount(['registrations as registered_count' => fn ($q) => $q->where('status', 'registered')])
                ->orderByRaw("case status when 'open' then 0 when 'draft' then 1 else 2 end")->orderByDesc('starts_on')->orderByDesc('id')->get()->groupBy('status') : collect(),
        ])->layout('components.layouts.app', ['title' => 'Anmeldungen']);
    }
}
