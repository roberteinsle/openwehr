<?php

namespace Modules\Anmeldungen\src\Livewire;

use App\Models\User;
use App\Services\ParentAccess;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Models\Registration;
use Modules\Anmeldungen\src\Services\RegistrationService;

/**
 * Fill in and sign an Anmeldung: the Kamerad for themselves, or – with childId – an Erziehungsberechtigte(r)
 * for the child in the Eltern-Bereich. A registration can be changed or withdrawn until the Anmeldeschluss.
 */
class RegistrationForm extends Component
{
    #[Locked]
    public int $formId;

    #[Locked]
    public ?int $childId = null;

    /** @var array<string, mixed> */
    public array $answers = [];

    /** @var array<string, bool> */
    public array $consents = [];

    public string $signature = '';

    public string $memberSignature = '';

    public bool $editing = false;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->member();
        $registration = $this->registration();
        if ($registration?->isActive()) {
            foreach ($this->form()->fields as $f) {
                $value = $registration->answer($f['key']);
                $this->answers[$f['key']] = $f['type'] === 'yesno' ? ($value === null ? '' : ($value ? '1' : '0')) : $value;
            }
        }
    }

    public function hydrate(): void
    {
        $this->member();
    }

    private function form(): Form
    {
        return Form::findOrFail($this->formId);
    }

    /** The Kamerad, or the child of the signed-in parent */
    private function member(): User
    {
        $member = app(ParentAccess::class)->memberFor($this->childId);
        abort_unless($member !== null, 404);

        return $member;
    }

    private function registration(): ?Registration
    {
        return Registration::where('form_id', $this->formId)->where('user_id', $this->member()->id)->first();
    }

    public function submit(RegistrationService $service): void
    {
        $this->resetErrorBag();
        $member = $this->member();
        $guardian = $this->childId !== null ? app(ParentAccess::class)->guardianFor($member) : null;
        abort_if($this->childId !== null && ! $guardian, 404);
        $form = $this->form();
        abort_unless($service->isFor($form, $member), 404);

        try {
            $service->register($form, $member, $this->answers, array_keys(array_filter($this->consents)), $this->signature ?: null,
                $this->memberSignature ?: null, $guardian, $this->childId === null ? $member : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->reset('signature', 'memberSignature', 'consents', 'editing');
        $this->flash = $member->name.' ist angemeldet. Danke!';
    }

    public function withdraw(RegistrationService $service): void
    {
        $registration = $this->registration();
        abort_unless($registration !== null, 404);
        try {
            $service->cancel($registration, (string) app(ParentAccess::class)->name());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->reset('answers', 'editing');
        $this->flash = 'Abgemeldet.';
    }

    public function render(RegistrationService $service)
    {
        $member = $this->member();
        $form = $this->form();

        return view('anmeldungen::livewire.registration-form', [
            'form' => $form,
            'member' => $member,
            'registration' => $this->registration(),
            'asParent' => $this->childId !== null,
            'self' => $this->childId !== null || $service->maySelfRegister($form, $member),
        ]);
    }
}
