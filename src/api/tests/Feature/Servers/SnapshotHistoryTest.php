<?php

use App\Actions\Maintenance\PruneOldData;
use App\Livewire\Dashboard;
use App\Livewire\Servers\Show;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    // A bucket boundary, so the five-minute steps below fall where they are written.
    Carbon::setTestNow('2026-10-17 12:00:00');
});

/**
 * A reading a number of minutes ago.
 *
 * @param  array<string, mixed>  $extra
 */
function readingAgo(Server $server, int $minutes, float $cpu, int $memAvailable = 500, array $extra = []): ServerSnapshot
{
    return $server->snapshots()->create([
        'captured_at' => now()->subMinutes($minutes),
        'cpu_usage' => $cpu,
        'mem_total' => 1000,
        'mem_available' => $memAvailable,
        ...$extra,
    ]);
}

it('averages a day of readings into five-minute points', function () {
    $server = Server::factory()->create();
    // 11:55-11:59 is one bucket (10, 20, 30), 11:50-11:54 another (40).
    readingAgo($server, 5, 10.0, 500);
    readingAgo($server, 4, 20.0, 400);
    readingAgo($server, 3, 30.0, 300);
    readingAgo($server, 10, 40.0, 900);
    // Older than a day: left out.
    readingAgo($server, 25 * 60, 99.0);

    $points = ServerSnapshot::history([$server->id], now()->subDay())->get($server->id);

    expect($points->pluck('cpu')->all())->toBe([40.0, 20.0])
        ->and($points->pluck('mem')->all())->toBe([10.0, 60.0])
        ->and($points->first()->at->format('H:i'))->toBe('11:50');
});

it('draws a full day from at most 288 points, however many probes there were', function () {
    $server = Server::factory()->create();
    $rows = collect(range(1, 1440))->map(fn (int $minute) => [
        'server_id' => $server->id, 'captured_at' => now()->subMinutes($minute), 'cpu_usage' => $minute % 100, 'mem_total' => 1000, 'mem_available' => 500,
    ])->all();
    ServerSnapshot::query()->insert($rows);

    expect(ServerSnapshot::history([$server->id], now()->subDay())->get($server->id)->count())->toBeLessThanOrEqual(289);
});

it('keeps servers apart and asks nothing for none', function () {
    [$a, $b] = Server::factory()->count(2)->create();
    readingAgo($a, 2, 10.0);
    readingAgo($b, 2, 70.0);

    $history = ServerSnapshot::history([$a->id, $b->id], now()->subDay());

    expect($history->get($a->id)->pluck('cpu')->all())->toBe([10.0])
        ->and($history->get($b->id)->pluck('cpu')->all())->toBe([70.0])
        ->and(ServerSnapshot::history([], now()->subDay()))->toBeEmpty();
});

it('draws the dashboard and the server page from the buckets', function () {
    $server = Server::factory()->create(['name' => 'Build box']);
    foreach (range(1, 30) as $minute) {
        readingAgo($server, $minute, 20.0 + $minute);
    }

    Livewire::test(Dashboard::class)
        ->assertViewHas('cpuHistory', fn ($history) => count($history[$server->id]) === 6);

    Livewire::test(Show::class, ['server' => $server])
        ->assertViewHas('cpuHistory', fn ($points) => count($points) === 6)
        ->assertViewHas('memHistory', fn ($points) => count($points) === 6);
});

it('offers the last probe of each five minutes, with process lists, from the last day', function () {
    $server = Server::factory()->create();
    $list = ['cpu' => [], 'mem' => [], 'net' => []];
    $first = readingAgo($server, 9, 1.0, 500, ['processes' => $list]);
    $second = readingAgo($server, 6, 1.0, 500, ['processes' => $list]);
    $third = readingAgo($server, 5, 1.0, 500, ['processes' => $list]);
    readingAgo($server, 3, 1.0);
    readingAgo($server, 26 * 60, 1.0, 500, ['processes' => $list]);

    // 11:51, 11:54 and 11:55 -> the steps 11:50 and 11:55; the 11:54 probe is the later of its step.
    $times = ServerSnapshot::processTimes($server->id, now()->subDay());

    expect($times->keys()->all())->toBe([$third->id, $second->id])
        ->and($times->has($first->id))->toBeFalse();

    Livewire::test(Show::class, ['server' => $server])
        ->assertViewHas('processTimes', fn ($times) => $times->count() === 2);
});

it('drops the process lists after two days and keeps the readings', function () {
    $server = Server::factory()->create();
    $list = ['cpu' => [['pid' => 1, 'name' => 'php', 'container' => null, 'cpu' => 1]], 'mem' => [], 'net' => []];
    $old = readingAgo($server, 3 * 24 * 60, 5.0, 500, ['processes' => $list]);
    $recent = readingAgo($server, 24 * 60, 6.0, 500, ['processes' => $list]);

    $pruned = app(PruneOldData::class)();

    expect($pruned['processes'])->toBe(1)
        ->and($old->fresh()->processes)->toBeNull()
        ->and($old->fresh()->cpu_usage)->toBe(5.0)
        ->and($recent->fresh()->processes)->toBe($list)
        // Not past the snapshots' own 30 days.
        ->and($pruned['snapshots'])->toBe(0);
});
