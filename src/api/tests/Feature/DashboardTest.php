<?php

use App\Enums\ServerStatus;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

it('invites you to add a server when there is none', function () {
    $this->get('/')->assertOk()->assertSee('Add your first server');
});

it('shows every server with its latest readings', function () {
    Server::factory()->create(['name' => 'Build box'])->snapshots()->create([
        'captured_at' => now(),
        'cpu_usage' => 12.5,
        'mem_total' => 1000,
        'mem_available' => 400,
        'disks' => [['source' => '/dev/sda1', 'fstype' => 'ext4', 'size' => 1000, 'used' => 390, 'available' => 610, 'mount' => '/']],
    ]);
    Project::factory()->count(2)->create();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Add your first server')
        ->assertSee('Build box')
        ->assertSee(['13%', '60%', '39%'])
        ->assertSee('1 server · 2 projects')
        ->assertSee('Nothing needs attention.');
});

it('lists unreachable servers under needs attention', function () {
    Server::factory()->create([
        'name' => 'Edge',
        'status' => ServerStatus::Offline,
        'status_reason' => 'Connection timed out after 10 s.',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Edge is unreachable')
        ->assertSee('Connection timed out after 10 s.')
        ->assertDontSee('Nothing needs attention.');
});
