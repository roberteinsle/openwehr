<?php

namespace App\Livewire\Search;

use App\Models\Contact;
use App\Models\User;
use App\Services\InventoryRegistry;
use App\Services\ModuleOverview;
use App\Services\ParentAccess;
use App\Services\ScanRegistry;
use App\Services\SearchRegistry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The results page of the header search (/suche?q=…): first the modules and pages whose name fits –
 * to open a module quickly –, then what the modules find (SearchRegistry) and the Bestand
 * (InventoryRegistry). A code that a module knows (QR label, barcode) is offered at the top.
 */
class Results extends Component
{
    #[Url(as: 'q')]
    public string $q = '';

    /** Lower case without umlauts, so "Mangel" finds "Mängel" and "Uebung" finds "Übung" */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'ae' => 'a', 'oe' => 'o', 'ue' => 'u']);
    }

    /** @return Collection<int, array{title: string, detail: string, url: string, module: ?string}> */
    private function pages(string $term, User $user): Collection
    {
        $needle = self::fold($term);
        $matches = fn (string ...$texts) => collect($texts)->contains(fn ($t) => str_contains(self::fold($t), $needle));

        $modules = collect(app(ModuleOverview::class)->for($user))
            ->filter(fn ($m) => $matches($m['name'], $m['description']))
            ->sortBy(fn ($m) => str_starts_with(self::fold($m['name']), $needle) ? 0 : 1)
            ->map(fn ($m, $key) => ['title' => $m['name'], 'detail' => $m['description'], 'url' => $m['url'], 'module' => $key]);

        $core = collect([
            ['title' => 'Startseite', 'detail' => 'Übersicht mit deinen Karten', 'url' => route('home'), 'show' => true],
            ['title' => 'Profil', 'detail' => 'Deine Daten, Personalakte, Benachrichtigungen und Feedback', 'url' => route('profile'), 'show' => true],
            ['title' => 'Benachrichtigungen', 'detail' => 'Alles aus der Glocke', 'url' => route('notifications.index'), 'show' => true],
            ['title' => 'Ansprechpartner', 'detail' => 'Telefonliste der Wehr', 'url' => route('contacts'), 'show' => Contact::anyFor($user)],
            ['title' => 'Bestand', 'detail' => 'Fahrzeuge, Geräte, Kleidung und Schlüssel suchen oder scannen', 'url' => route('inventory'), 'show' => app(InventoryRegistry::class)->availableFor($user)],
            ['title' => 'Eltern-Bereich', 'detail' => 'Termine und Anmeldungen deiner Kinder', 'url' => route('parents.index'), 'show' => app(ParentAccess::class)->hasChildren()],
        ])->filter(fn ($p) => $p['show'] && $matches($p['title'], $p['detail']))
            ->map(fn ($p) => ['title' => $p['title'], 'detail' => $p['detail'], 'url' => $p['url'], 'module' => null]);

        return $modules->values()->concat($core->values());
    }

    public function render(SearchRegistry $search, InventoryRegistry $inventory, ScanRegistry $scan)
    {
        $user = auth()->user();
        $term = SearchRegistry::clean($this->q);
        $ready = $user instanceof User && mb_strlen($term) >= SearchRegistry::MIN_LENGTH;

        $sections = collect();
        $code = null;
        if ($ready) {
            $code = preg_match('/^\S{3,}$/', $term) ? $scan->resolveKnown($term) : null;
            $sections = $search->search($term, $user)->values()->concat(
                $inventory->search($term, $user)->map(fn ($s) => [
                    'label' => 'Bestand: '.$s['label'],
                    'rows' => collect($s['rows'])->take(SearchRegistry::LIMIT)->map(fn ($r) => [
                        'title' => $r['title'],
                        'detail' => collect([$r['code'] ?? null, $r['place'] ?? null, $r['state'] ?? null])->filter()->implode(' · '),
                        'url' => $r['url'] ?? null,
                    ]),
                ])->values()
            );
        }

        return view('livewire.search.results', [
            'term' => $term,
            'ready' => $ready,
            'tooShort' => $term !== '' && ! $ready,
            'pages' => $ready ? $this->pages($term, $user) : collect(),
            'sections' => $sections,
            'code' => $code,
        ]);
    }
}
