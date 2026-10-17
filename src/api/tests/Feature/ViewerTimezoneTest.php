<?php

use App\Deploy\Logging\StepLogger;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\User;
use App\Support\ViewerTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

/**
 * Two runs either side of midnight in Kyiv: 22:30 UTC on the 12th is 01:30 on the 13th
 * there, so the 22:16 run is today in Kyiv and the 20:50 one (23:50 there) is yesterday,
 * while in UTC both are today. Both are finished: a target holds one active run at a time.
 */
function runsAroundKyivMidnight(): void
{
    test()->travelTo(Carbon::parse('2026-09-12 22:30:00', 'UTC'));
    $target = demoTarget();

    foreach (['2026-09-12 22:16:00', '2026-09-12 20:50:00'] as $at) {
        Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Succeeded, 'created_at' => Carbon::parse($at, 'UTC')]);
    }
}

it('lists runs in the timezone the browser reports', function () {
    runsAroundKyivMidnight();

    $this->withUnencryptedCookie(ViewerTimezone::COOKIE, 'Europe/Kyiv')
        ->get(route('deployments.index'))
        ->assertOk()
        ->assertSee('01:16')
        ->assertSee('Yesterday')
        ->assertDontSee('22:16')
        ->assertDontSee('20:50');
});

it('lists runs in UTC until the browser names a zone PHP knows', function (?string $zone) {
    runsAroundKyivMidnight();

    $this->withUnencryptedCookies($zone === null ? [] : [ViewerTimezone::COOKIE => $zone])
        ->get(route('deployments.index'))
        ->assertOk()
        ->assertSee('22:16')
        ->assertSee('20:50')
        ->assertDontSee('01:16');
})->with(['no cookie yet' => [null], 'not a zone' => ['Mars/Olympus']]);

it('shows a server’s probe times in the timezone the browser reports, in either theme', function (string $theme) {
    // 21:40 UTC on the 12th is 00:40 on the 13th in Kyiv.
    $this->travelTo(Carbon::parse('2026-09-12 22:30:00', 'UTC'));
    $server = \App\Models\Server::factory()->create(['slug' => 'edge-fra']);
    $server->snapshots()->create([
        'captured_at' => Carbon::parse('2026-09-12 21:40:00', 'UTC'), 'cpu_usage' => 12.5, 'mem_total' => 8 * 1024 ** 3, 'mem_available' => 4 * 1024 ** 3,
        'processes' => ['window' => 1.0, 'cpu' => [['pid' => 1, 'name' => 'php', 'container' => null, 'cpu' => 12.0]], 'mem' => [], 'net' => [], 'net_note' => null],
    ]);
    auth()->user()->forceFill(['ui' => ['theme' => $theme]])->save();

    $this->withUnencryptedCookie(ViewerTimezone::COOKIE, 'Europe/Kyiv')
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertSee('00:40')
        ->assertDontSee('21:40');
})->with(['classic', 'console']);

it('takes a real zone, old spellings included, and nothing else', function (string $cookie, string $zone) {
    app()->instance('request', Request::create('/', cookies: [ViewerTimezone::COOKIE => $cookie]));

    expect(ViewerTimezone::name())->toBe($zone)
        ->and(Carbon::parse('2026-09-12 22:16:00', 'UTC')->forViewer()->getTimezone()->getName())->toBe($zone);
})->with([
    'Europe/Kyiv' => ['Europe/Kyiv', 'Europe/Kyiv'],
    'Europe/Kiev' => ['Europe/Kiev', 'Europe/Kiev'],
    'a path' => ['../../etc/passwd', 'UTC'],
    'empty' => ['', 'UTC'],
]);

it('sends each log line with its UTC instant, for the run page to show on the viewer\'s clock', function (string $stored, string $sent) {
    expect(StepLogger::instant($stored))->toBe($sent);
})->with([
    'as written' => ['2026-09-12 22:16:42.123', '2026-09-12T22:16:42.123Z'],
    'without milliseconds' => ['2026-09-12 22:16:42', '2026-09-12T22:16:42Z'],
    'with a zone suffix' => ['2026-09-12 22:16:42.1+00', '2026-09-12T22:16:42.1Z'],
]);
