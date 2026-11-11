<?php

namespace Modules\Anmeldungen\src\Livewire;

use App\Services\ParentAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Services\RegistrationService;

/** Eltern-Bereich: the open Anmeldungen of the child, each filled in and signed right here */
class ParentForms extends Component
{
    #[Locked]
    public int $childId;

    public function mount(): void
    {
        abort_unless(app(ParentAccess::class)->child($this->childId) !== null, 404);
    }

    public function hydrate(): void
    {
        $this->mount();
    }

    public function render(RegistrationService $service)
    {
        $child = app(ParentAccess::class)->child($this->childId);
        $open = $service->openFor($child);
        // Closed ones the child is registered for stay visible until they are over
        $registered = Form::whereIn('id', Registration::where('user_id', $child->id)->where('status', 'registered')->pluck('form_id'))
            ->where('status', 'closed')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now('Europe/Berlin')->toDateString()))->get();

        return view('anmeldungen::livewire.parent-forms', ['forms' => $open->concat($registered)->unique('id')->values(), 'child' => $child]);
    }
}
