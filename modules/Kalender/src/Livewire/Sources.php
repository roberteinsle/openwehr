<?php

namespace Modules\Kalender\src\Livewire;

use Illuminate\Validation\Rule;
use Livewire\Component;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Models\EventType;
use Modules\Kalender\src\Models\Source;
use Modules\Kalender\src\Services\SourceSync;

/** External iCal calendars for the Terminverwalter */
class Sources extends Component
{
    public string $name = '';

    public string $url = '';

    public string $type = 'veranstaltung';

    public ?string $flash = null;

    public function add(SourceSync $sync): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:1000', 'regex:#^(https?|webcal)://#i'],
            'type' => ['required', Rule::in(array_keys(EventType::labels()))],
        ], ['url.regex' => 'Bitte eine Adresse mit https:// oder webcal:// angeben.']);

        $source = Source::create(['name' => trim($this->name), 'url' => trim($this->url), 'type' => $this->type]);
        $count = $sync->sync($source);

        $this->flash = $count < 0 ? 'Gespeichert, aber der Abruf ist fehlgeschlagen: '.$source->refresh()->last_error : $count.' Termine aus „'.$source->name.'“ übernommen.';
        $this->reset('name', 'url');
    }

    public function syncNow(int $id, SourceSync $sync): void
    {
        $source = Source::findOrFail($id);
        $count = $sync->sync($source);
        $this->flash = $count < 0 ? 'Abruf fehlgeschlagen: '.$source->refresh()->last_error : $count.' Termine aktualisiert.';
    }

    public function toggle(int $id): void
    {
        $source = Source::findOrFail($id);
        $source->update(['is_active' => ! $source->is_active]);
    }

    public function remove(int $id): void
    {
        Source::findOrFail($id)->delete(); // its events go with it
        $this->flash = 'Kalender und seine Termine entfernt.';
    }

    public function render()
    {
        return view('kalender::livewire.sources', [
            'sources' => Source::withCount('events')->orderBy('name')->get(),
            'types' => Event::types(),
        ]);
    }
}
