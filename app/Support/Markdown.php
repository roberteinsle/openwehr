<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Markdown typed by Kameraden (e.g. Mitteilungen): bold, lists, links, headings – but no HTML of
 * their own, no javascript: links and no images loaded from elsewhere (they become a link). A single
 * line break stays a line break, as in a plain text field, so older texts look as before.
 */
class Markdown
{
    public static function html(?string $text): HtmlString
    {
        if (blank($text)) {
            return new HtmlString('');
        }

        $html = Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        // An image would load from another server (tracking, mixed content): shown as a link instead
        $html = preg_replace_callback('/<img src="([^"]*)" alt="([^"]*)"[^>]*>/', fn ($m) => '<a href="'.$m[1].'">'.($m[2] !== '' ? $m[2] : 'Bild').'</a>', $html) ?? '';
        // Links leave the app in a new tab and tell nothing about it
        $html = str_replace('<a href=', '<a target="_blank" rel="noopener noreferrer nofollow" href=', $html);

        return new HtmlString($html);
    }
}
