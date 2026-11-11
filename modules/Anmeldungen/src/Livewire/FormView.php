<?php

namespace Modules\Anmeldungen\src\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Services\RegistrationService;

/**
 * One Anmeldung: the Kamerad fills it in (or sees that the Erziehungsberechtigten do); organisers see the
 * Teilnehmerliste with answers and signatures, open, close, remind, export, copy and delete.
 */
class FormView extends Component
{
    #[Locked]
    public int $formId;

    public ?int $showId = null;

    public ?string $flash = null;

    public function mount(Form $form): void
    {
        $this->formId = $form->id;
        $this->authorizeView();
    }

    public function hydrate(): void
    {
        $this->authorizeView();
    }

    private function form(): Form
    {
        return Form::findOrFail($this->formId);
    }

    private function manager(): bool
    {
        return RegistrationService::canManage(auth()->user());
    }

    /** Organisers see every form; Kameraden open forms meant for them and ones they are registered for */
    private function authorizeView(): void
    {
        $form = $this->form();
        abort_unless($this->manager() || ($form->status !== 'draft' && (app(RegistrationService::class)->isFor($form, auth()->user())
            || Registration::where('form_id', $form->id)->where('user_id', auth()->id())->exists())), 404);
    }

    private function act(callable $action, string $done): void
    {
        abort_unless($this->manager(), 403);
        $this->resetErrorBag();
        try {
            $result = $action(app(RegistrationService::class), $this->form());
        } catch (ValidationException $e) {
            $this->addError('status', collect($e->errors())->flatten()->first());

            return;
        }
        $this->flash = str_replace(':n', (string) (is_int($result) ? $result : 0), $done);
    }

    public function open(): void
    {
        $this->act(fn ($s, $f) => $s->open($f, auth()->user()), 'Die Anmeldung ist offen. :n Benachrichtigungen verschickt.');
    }

    public function close(): void
    {
        $this->act(fn ($s, $f) => $s->close($f, auth()->user()), 'Die Anmeldung ist geschlossen.');
    }

    public function remind(): void
    {
        $this->act(fn ($s, $f) => $s->remind($f, auth()->user()), ':n Erinnerungen verschickt.');
    }

    public function duplicate(RegistrationService $service): void
    {
        abort_unless($this->manager(), 403);
        $copy = $service->duplicate($this->form(), auth()->user());
        $this->redirectRoute('anmeldungen.edit', $copy);
    }

    public function delete(RegistrationService $service): void
    {
        abort_unless($this->manager(), 403);
        $service->delete($this->form(), auth()->user());
        $this->redirectRoute('anmeldungen.index');
    }

    public function cancelFor(int $registrationId, RegistrationService $service): void
    {
        abort_unless($this->manager(), 403);
        $service->cancel(Registration::where('form_id', $this->formId)->findOrFail($registrationId), auth()->user()->name, asManager: true);
        $this->flash = 'Abgemeldet.';
    }

    public function render(RegistrationService $service)
    {
        $form = $this->form();
        $manager = $this->manager();
        $registrations = $manager ? $form->registrations()->with('user')->get()->sortBy(fn (Registration $r) => [$r->isActive() ? 0 : 1, $r->user->name])->values() : collect();
        $done = $registrations->pluck('user_id')->all();

        return view('anmeldungen::livewire.form-view', [
            'form' => $form,
            'manager' => $manager,
            'registrations' => $registrations,
            'missing' => $manager && $form->status !== 'draft' ? $service->audience($form)->reject(fn ($u) => in_array($u->id, $done, true))->values() : collect(),
            'canRegister' => $form->status !== 'draft' && $service->isFor($form, auth()->user()),
        ])->layout('components.layouts.app', ['title' => $form->title]);
    }
}
