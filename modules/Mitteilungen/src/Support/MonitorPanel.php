<?php

namespace Modules\Mitteilungen\src\Support;

use App\Support\Markdown;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Modules\Mitteilungen\src\Models\Announcement;

/**
 * "Mitteilungen" on the Wachen-Monitor: the current ones the author ticked for it (on_monitor), grouped
 * under "Alle" and the Abteilungen they are for, important first. Ones for Funktionen stay off even if
 * ticked – the screen hangs in the station, without login.
 */
final class MonitorPanel
{
    private const COUNT = ['full' => 8, 'half' => 6, 'third' => 8, 'quarter' => 3];

    private const TEXT = ['full' => 300, 'half' => 200, 'third' => 140, 'quarter' => 100];

    /** @param  array{size: string, group_ids: list<int>}  $options */
    public static function render(array $options): View
    {
        $size = $options['size'];

        $announcements = Announcement::current()->where('on_monitor', true)
            ->doesntHave('functions')
            ->with('groups')
            ->orderByDesc('important')->orderByDesc('created_at')
            ->limit(self::COUNT[$size] ?? 3)->get();

        // "Alle" first, then by the order of the (first) Abteilung; several Abteilungen share one heading
        $sections = $announcements
            ->groupBy(fn (Announcement $a) => self::heading($a))
            ->map(fn ($items, string $heading) => [
                'heading' => $heading,
                'sort' => $items->first()->groups->isEmpty() ? -1 : (int) $items->first()->groups->min('sort_order'),
                'items' => $items->values(),
            ])
            ->sortBy([['sort', 'asc'], ['heading', 'asc']])
            ->values();

        return view('mitteilungen::monitor.current', [
            'sections' => $sections,
            'excerpt' => fn (Announcement $a) => Str::limit(
                trim((string) preg_replace('/\s+/', ' ', strip_tags((string) Markdown::html($a->body)))),
                self::TEXT[$size] ?? 100
            ),
        ]);
    }

    /** "Alle" or the names of its Abteilungen, e.g. "Jugendfeuerwehr" */
    private static function heading(Announcement $announcement): string
    {
        return $announcement->groups->isEmpty()
            ? 'Alle'
            : $announcement->groups->sortBy([['sort_order', 'asc'], ['name', 'asc']])->pluck('name')->implode(', ');
    }
}
