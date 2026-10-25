<?php

namespace Modules\Kalender\src\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Kalender\src\Models\EventType;

/**
 * Admins: the Terminarten – name, colour, order, on/off, new ones. A type in use or one other modules
 * write (EventType::PROTECTED) can't be deleted; switched off it is no longer offered, existing
 * Termine keep it.
 */
class EventTypes extends Component
{
    /** @var array<int, array{label: string, color: string, is_active: bool}> */
    public array $rows = [];

    public string $newLabel = '';

    public string $newColor = '#1B2645';

    public ?string $flash = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->rows = EventType::ordered()->mapWithKeys(fn (EventType $t) => [$t->id => ['label' => $t->label, 'color' => $t->color, 'is_active' => $t->is_active]])->all();
    }

    private function rules(string $prefix): array
    {
        return [$prefix.'label' => ['required', 'string', 'max:60'], $prefix.'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']];
    }

    public function save(): void
    {
        $this->flash = null;
        foreach (array_keys($this->rows) as $id) {
            $this->validate($this->rules('rows.'.$id.'.'), ['required' => 'Bitte einen Namen angeben.', 'regex' => 'Bitte eine Farbe wählen.']);
        }
        foreach ($this->rows as $id => $row) {
            EventType::whereKey($id)->first()?->update(['label' => trim($row['label']), 'color' => strtoupper($row['color']), 'is_active' => (bool) $row['is_active']]);
        }
        if (EventType::where('is_active', true)->doesntExist()) {
            EventType::orderBy('sort_order')->first()?->update(['is_active' => true]);
        }
        $this->load();
        $this->flash = 'Terminarten gespeichert.';
    }

    public function add(): void
    {
        $this->flash = null;
        $this->validate(['newLabel' => ['required', 'string', 'max:60'], 'newColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']],
            ['required' => 'Bitte einen Namen angeben.', 'regex' => 'Bitte eine Farbe wählen.']);
        $base = Str::limit(Str::slug(str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower(trim($this->newLabel))), ''), 16, '') ?: 'art';
        $key = $base;
        for ($i = 2; EventType::where('key', $key)->exists(); $i++) {
            $key = $base.$i;
        }
        EventType::create(['key' => $key, 'label' => trim($this->newLabel), 'color' => strtoupper($this->newColor),
            'sort_order' => (int) EventType::max('sort_order') + 1, 'is_active' => true]);
        $this->reset('newLabel', 'newColor');
        $this->load();
        $this->flash = 'Terminart angelegt.';
    }

    /** Up (-1) or down (+1) in the list */
    public function move(int $id, int $direction): void
    {
        $types = EventType::ordered()->values();
        $index = $types->search(fn (EventType $t) => $t->id === $id);
        $other = $index === false ? null : $types->get($index + $direction);
        if ($other) {
            DB::transaction(function () use ($types, $index, $direction) {
                $list = $types->all();
                [$list[$index], $list[$index + $direction]] = [$list[$index + $direction], $list[$index]];
                foreach ($list as $i => $type) {
                    $type->update(['sort_order' => $i + 1]);
                }
            });
        }
        $this->load();
    }

    public function delete(int $id): void
    {
        $type = EventType::findOrFail($id);
        $this->flash = null;
        if (in_array($type->key, EventType::PROTECTED, true) || self::inUse($type->key)) {
            $this->addError('delete', '„'.$type->label.'“ wird verwendet und bleibt. Zum Ausblenden ausschalten.');

            return;
        }
        $type->delete();
        $this->load();
        $this->flash = 'Terminart gelöscht.';
    }

    /** Used by a Termin, a Terminabfrage or an external calendar */
    public static function inUse(string $key): bool
    {
        return DB::table('kal_events')->where('type', $key)->exists() || DB::table('kal_polls')->where('type', $key)->exists()
            || DB::table('kal_sources')->where('type', $key)->exists();
    }

    public function render()
    {
        $types = EventType::ordered();

        return view('kalender::livewire.event-types', [
            'types' => $types,
            'locked' => $types->filter(fn (EventType $t) => in_array($t->key, EventType::PROTECTED, true) || self::inUse($t->key))->keys()->all(),
        ]);
    }
}
