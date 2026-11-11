<?php

namespace Modules\Anmeldungen\src\Livewire;

use App\Models\Group;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Anmeldungen\src\Models\Form;
use Modules\Anmeldungen\src\Services\RegistrationService;

/** Organisers: create or change an Anmeldung – dates, audience, own fields, declarations and signatures */
class FormEditor extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $title = '';

    public string $description = '';

    public string $location = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $deadline = '';

    /** @var list<string> */
    public array $groupIds = [];

    /** @var list<array{key: ?string, label: string, type: string, required: bool, health: bool, options: string, help: string}> */
    public array $fields = [];

    /** @var list<array{key: ?string, text: string}> */
    public array $consents = [];

    public bool $requireSignature = true;

    public bool $guardianRequired = true;

    public bool $memberSignature = false;

    public string $maxParticipants = '';

    public string $purgeDays = '14';

    public function boot(): void
    {
        abort_unless(RegistrationService::canManage(auth()->user()), 403);
    }

    public function mount(?Form $form = null): void
    {
        if ($form?->exists) {
            $this->editingId = $form->id;
            $this->fill([
                'title' => $form->title, 'description' => (string) $form->description, 'location' => (string) $form->location,
                'startsOn' => (string) $form->starts_on?->toDateString(), 'endsOn' => (string) $form->ends_on?->toDateString(),
                'deadline' => (string) $form->deadline?->copy()->setTimezone('Europe/Berlin')->format('Y-m-d\TH:i'),
                'groupIds' => array_map('strval', $form->group_ids), 'requireSignature' => $form->require_signature,
                'guardianRequired' => $form->guardian_required, 'memberSignature' => $form->member_signature,
                'maxParticipants' => (string) ($form->max_participants ?? ''), 'purgeDays' => (string) $form->purge_days,
            ]);
            $this->fields = array_map(fn (array $f) => [...$f, 'options' => implode("\n", $f['options']), 'help' => (string) $f['help']], $form->fields);
            $this->consents = $form->consents;
        } else {
            $this->useTemplate();
        }
    }

    /** Start with the usual fields and declarations of a Fahrt or a Zeltlager */
    public function useTemplate(): void
    {
        $template = RegistrationService::template();
        $this->fields = array_map(fn (array $f) => [...$f, 'key' => null, 'options' => implode("\n", $f['options']), 'help' => (string) $f['help']], $template['fields']);
        $this->consents = $template['consents'];
    }

    public function addField(): void
    {
        $this->fields[] = ['key' => null, 'label' => '', 'type' => 'text', 'required' => false, 'health' => false, 'options' => '', 'help' => ''];
    }

    public function removeField(int $i): void
    {
        unset($this->fields[$i]);
        $this->fields = array_values($this->fields);
    }

    public function moveField(int $i, int $by): void
    {
        $j = $i + $by;
        if (isset($this->fields[$i], $this->fields[$j])) {
            [$this->fields[$i], $this->fields[$j]] = [$this->fields[$j], $this->fields[$i]];
        }
    }

    public function addConsent(): void
    {
        $this->consents[] = ['key' => null, 'text' => ''];
    }

    public function removeConsent(int $i): void
    {
        unset($this->consents[$i]);
        $this->consents = array_values($this->consents);
    }

    public function save(RegistrationService $service): void
    {
        $this->resetErrorBag();
        try {
            $form = $service->save([
                'title' => $this->title, 'description' => $this->description, 'location' => $this->location,
                'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn, 'deadline' => $this->deadline, 'group_ids' => $this->groupIds,
                'fields' => $this->fields, 'consents' => $this->consents, 'require_signature' => $this->requireSignature,
                'guardian_required' => $this->guardianRequired, 'member_signature' => $this->memberSignature,
                'max_participants' => $this->maxParticipants, 'purge_days' => $this->purgeDays,
            ], auth()->user(), $this->editingId ? Form::findOrFail($this->editingId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->redirectRoute('anmeldungen.show', $form);
    }

    public function render()
    {
        return view('anmeldungen::livewire.form-editor', [
            'groups' => Group::orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
        ])->layout('components.layouts.app', ['title' => $this->editingId ? 'Anmeldung bearbeiten' : 'Neue Anmeldung']);
    }
}
