<?php

use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://openwehr.ff-musterstadt.de', 'app.alias_urls' => ['https://musterstadt.openwehr.de', 'alt.example.com']]);
});

test('page requests to an alias go permanently to the same path under APP_URL', function () {
    $this->get('https://musterstadt.openwehr.de/kalender?ansicht=liste')
        ->assertStatus(301)->assertRedirect('https://openwehr.ff-musterstadt.de/kalender?ansicht=liste');
    $this->get('https://alt.example.com/anmelden')->assertRedirect('https://openwehr.ff-musterstadt.de/anmelden');
    $this->get('https://MUSTERSTADT.openwehr.de/')->assertStatus(301);
});

test('an alias serves a service worker that removes itself instead of a redirect', function () {
    $this->get('https://musterstadt.openwehr.de/sw.js')->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertSee('self.registration.unregister()', false)->assertDontSee('OFFLINE_PAGES', false);
    $this->get('https://openwehr.ff-musterstadt.de/sw.js')->assertOk()->assertSee('OFFLINE_PAGES', false);
});

test('the main address, other hosts and POSTs are not redirected', function () {
    $this->get('https://openwehr.ff-musterstadt.de/anmelden')->assertOk();
    $this->get('http://localhost/anmelden')->assertOk(); // e.g. the container's own healthcheck
    $this->actingAs(User::factory()->create())
        ->post('https://musterstadt.openwehr.de/abmelden')->assertStatus(302)->assertRedirectContains('/anmelden');
    $this->assertGuest(); // handled, not redirected
});

test('without aliases nothing changes', function () {
    config(['app.alias_urls' => []]);
    $this->get('https://musterstadt.openwehr.de/anmelden')->assertOk();
});
