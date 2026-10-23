<?php

use App\Support\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

// Outbound requests to typed-in addresses resolve to a public test address (no DNS in tests)
uses()->beforeEach(fn () => SafeUrl::resolveUsing(fn (string $host) => in_array($host, ['localhost', 'intern.example.com'], true) ? ['127.0.0.1'] : ['93.184.216.34']))->in('Feature');

// Deferred work (e.g. the login mail, sent after the response) runs right away; tests that check the deferral use withDefer()
uses()->beforeEach(fn () => $this->withoutDefer())->in('Feature');

// The booking tests use fixed dates in October 2026; the clock stands before them so they stay in the future
uses()->beforeEach(fn () => $this->travelTo(Carbon\Carbon::parse('2026-10-01 12:00', 'Europe/Berlin')))->in('Feature/Booking');

/** A signature as the pad sends it: a stroke on a transparent PNG */
function signaturePng(bool $blank = false): string
{
    $image = imagecreatetruecolor(600, 160);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    if (! $blank) {
        imagesetthickness($image, 3);
        imageline($image, 40, 120, 300, 40, imagecolorallocate($image, 17, 24, 39));
        imageline($image, 300, 40, 560, 110, imagecolorallocate($image, 17, 24, 39));
    }
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);

    return 'data:image/png;base64,'.base64_encode($png);
}
