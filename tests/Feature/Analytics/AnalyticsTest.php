<?php

use App\Filament\Pages\Analytics;
use App\Filament\Pages\ManageModules;
use App\Filament\Resources\LogResource;
use App\Models\ActivityLog;
use App\Models\AppError;
use App\Models\Module;
use App\Models\User;
use App\Notifications\AppErrorNotification;
use App\Notifications\HealthProblemsNotification;
use App\Services\ErrorRecorder;
use App\Services\MagicLinkService;
use App\Services\SystemStatus;
use App\Support\Retention;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('only admins open the Analyse, between Module and Logs', function () {
    $this->actingAs($this->admin)->get('/admin/analyse')->assertOk()->assertSee('Betrieb')->assertSee('Sicherheit und Nachvollziehbarkeit')
        ->assertSee('Nutzung')->assertSee('Löschfristen');
    $this->actingAs($this->member)->get('/admin/analyse')->assertForbidden();
    expect(Analytics::getNavigationSort())->toBeGreaterThan(ManageModules::getNavigationSort())
        ->and(Analytics::getNavigationSort())->toBeLessThan(LogResource::getNavigationSort());
});

test('errors are grouped by place and the admins hear once a day', function () {
    Notification::fake();
    $recorder = app(ErrorRecorder::class);
    $boom = fn () => new RuntimeException('Datenbank weg');
    $e = $boom();
    $recorder->record($e);
    $recorder->record($e);
    expect(AppError::sole())->count->toBe(2)->class->toBe(RuntimeException::class);
    Notification::assertSentToTimes($this->admin, AppErrorNotification::class, 1);

    $this->travel(25)->hours();
    $recorder->record($e);
    Notification::assertSentToTimes($this->admin, AppErrorNotification::class, 2);

    Livewire::actingAs($this->admin)->test(Analytics::class)->assertSee('Datenbank weg')->assertSee('RuntimeException');
});

test('uptime comes from the heartbeat; scheduled commands, backups and the daily check are shown', function () {
    Notification::fake();
    $status = app(SystemStatus::class);
    $this->travelTo(Carbon::parse('2027-03-10 10:00'));
    foreach (range(0, 59) as $m) {
        DB::table('heartbeats')->insertOrIgnore(['minute' => now()->subMinutes(120)->addMinutes($m)]);
    }
    // 20 minutes without heartbeat, then up again
    foreach (range(0, 40) as $m) {
        DB::table('heartbeats')->insertOrIgnore(['minute' => now()->subMinutes(40)->addMinutes($m)]);
    }
    $uptime = $status->uptime(7);
    expect($uptime['percent'])->toBeLessThan(90)->toBeGreaterThan(75)->and($uptime['gaps'][0]['minutes'])->toBe(20);

    $status->heartbeat();
    expect($status->version()['version'])->toBe(config('app.version'))->and($status->version()['since'])->not->toBeNull();

    // A backup reported by webhook; wrong tokens get a 404
    $this->get('/webhooks/backup/'.str_repeat('x', 40))->assertNotFound();
    $this->post('/webhooks/backup/'.$status->backupToken().'?note=nachts')->assertOk();
    expect($status->lastBackup())->ok->toBeTrue()->source->toBe('Webhook');
    $this->travel(30)->hours();
    expect($status->problems())->toContain('Letztes gemeldetes Backup ist 30 Stunden alt.');

    DB::table('schedule_runs')->insert(['command' => 'lehrgaenge:remind-refresh', 'runs' => 3, 'failures' => 1, 'last_failed_at' => now(), 'last_error' => 'Exit-Code 1']);
    $status->dailyCheck();
    Notification::assertSentTo($this->admin, HealthProblemsNotification::class);

    Livewire::actingAs($this->admin)->test(Analytics::class)->assertSee('lehrgaenge:remind-refresh')->assertSee('Exit-Code 1')
        ->call('confirmBackup')->assertSee('von Hand bestätigt von Anna Admin');
});

test('failed and throttled logins and rights changes are logged; usage per module, not per person', function () {
    expect(fn () => app(MagicLinkService::class)->consumeToken(str_repeat('a', 64), Request::create('/')))->toThrow(ValidationException::class);
    expect(ActivityLog::where('event', 'login_failed')->count())->toBe(1);

    $this->actingAs($this->admin);
    $this->member->update(['is_admin' => true]);
    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    Livewire::actingAs($this->admin)->test(Analytics::class)
        ->assertSee('Anmeldung fehlgeschlagen')->assertSee('Rechte- und Rollenänderungen')->assertSee('Hanna Hydrant')
        ->assertSee('Kalender & Termine')->assertSee('nie genutzt');
});

test('page requests are counted per hour and area with time and server errors', function () {
    config(['app.record_request_metrics' => true]);
    $this->actingAs($this->admin)->get('/admin/analyse')->assertOk();
    $this->actingAs($this->admin)->get('/profil');
    expect(DB::table('request_metrics')->where('area', 'admin')->value('count'))->toBe(1)
        ->and(DB::table('request_metrics')->where('area', 'kern')->sum('count'))->toBeGreaterThanOrEqual(1);
});

test('admins set the retention; old technical logs and audit entries are pruned', function () {
    Livewire::actingAs($this->admin)->test(Analytics::class)->set('retention.technical', 30)->set('retention.audit', 400)->call('saveRetention');
    expect(Retention::days())->toBe(['technical' => 30, 'audit' => 400, 'mail' => 180]);

    AppError::create(['fingerprint' => sha1('alt'), 'class' => 'X', 'message' => 'alt', 'first_at' => now()->subDays(40), 'last_at' => now()->subDays(40)]);
    DB::table('activity_logs')->insert(['created_at' => now()->subDays(300), 'event' => 'login']);
    $this->artisan('model:prune', ['--model' => [AppError::class, ActivityLog::class]])->assertSuccessful();
    expect(AppError::count())->toBe(0)->and(ActivityLog::where('created_at', '<', now()->subDays(299))->count())->toBe(1);
});
