<?php

namespace Modules\Kleiderkammer\src\Filament\Resources\MemberResource\Pages;

use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Modules\Kleiderkammer\src\Filament\Pages\StorePage;
use Modules\Kleiderkammer\src\Filament\Resources\MemberResource;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return 'Kleiderkammerprofil: '.$this->getRecord()->name;
    }

    // Only the notes are editable here; never let anything else through to the user record.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ['notes' => $data['notes'] ?? null];
    }

    /** The same Zugang and Abgang as in the Lager; a Zugang can go to this Kamerad straight away */
    protected function getHeaderActions(): array
    {
        $member = $this->getRecord();

        return [
            StorePage::receiveAction(for: $member instanceof User && $member->is_active ? $member : null),
            StorePage::disposeAction(),
        ];
    }
}
