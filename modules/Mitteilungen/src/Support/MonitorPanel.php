<?php

namespace Modules\Mitteilungen\src\Support;

use App\Support\Markdown;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Modules\Mitteilungen\src\Models\Announcement;

/**
 * "Mitteilungen" on the Wachen-Monitor: the current ones meant for everyone, important first. Ones for
 * single Abteilungen or Funktionen stay off – the screen hangs in the station, without login.
 */
final class MonitorPanel
{
    private const COUNT = ['full' => 5, 'half' => 4, 'quarter' => 2];

    private const TEXT = ['full' => 400, 'half' => 260, 'quarter' => 160];

    /** @param  array{size: string, group_ids: list<int>}  $options */
    public static function render(array $options): View
    {
        $size = $options['size'];

        $announcements = Announcement::current()
            ->doesntHave('groups')->doesntHave('functions')
            ->orderByDesc('important')->orderByDesc('created_at')
            ->limit(self::COUNT[$size] ?? 2)->get();

        return view('mitteilungen::monitor.current', [
            'announcements' => $announcements,
            'excerpt' => fn (Announcement $a) => Str::limit(
                trim((string) preg_replace('/\s+/', ' ', strip_tags((string) Markdown::html($a->body)))),
                self::TEXT[$size] ?? 160
            ),
        ]);
    }
}
