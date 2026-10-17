<?php

use App\Livewire\Settings\Index as Settings;
use App\Models\Server;
use App\Models\User;
use App\Support\Ui;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    Ui::reset();
});

it('gives everyone the Console theme until they pick another', function () {
    $user = User::factory()->create();

    expect(Ui::preferences($user))->toBe(['theme' => 'console', 'mode' => 'dark', 'density' => 'compact', 'layouts' => []])
        ->and(Ui::preferences(null)['theme'])->toBe('console');
});

it('saves a choice, refuses one that isn’t offered, and lets a new theme bring its own mode', function () {
    $user = User::factory()->create();

    Ui::save($user, ['theme' => 'classic']);
    expect(Ui::preferences($user->fresh()))->toMatchArray(['theme' => 'classic', 'mode' => 'system', 'density' => 'comfortable']);

    Ui::save($user, ['mode' => 'dark']);
    expect(Ui::preferences($user->fresh())['mode'])->toBe('dark');

    expect(fn () => Ui::save($user, ['theme' => 'command']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Ui::save($user, ['font' => 'comic sans']))->toThrow(InvalidArgumentException::class);
});

it('renders the dashboard as a tmux session in the Console theme', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('data-ui="console"', false)
        ->assertSee('tmux-status', false)
        ->assertSee('0:ops*')
        ->assertSee('data-pane="p0"', false)
        ->assertSee('for the commands');
});

it('opens a page the theme has no view of its own for as a pane, with the base view inside', function () {
    Server::factory()->create(['name' => 'Prod EU', 'slug' => 'prod-eu']);

    $this->actingAs(User::factory()->create())
        ->get('/servers')
        ->assertOk()
        ->assertSee('tmux-pane', false)
        ->assertSee('2:servers*')
        ->assertSee('Prod EU');
});

it('keeps the classic pages for someone who picks Classic', function () {
    $user = User::factory()->create(['ui' => ['theme' => 'classic', 'mode' => 'light']]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('data-ui="classic"', false)
        ->assertSee('data-theme="deployme"', false)
        ->assertDontSee('tmux-status', false);
});

it('signs in through a console window', function () {
    User::factory()->create();

    $this->get('/login')
        ->assertOk()
        ->assertSee('data-ui="console"', false)
        ->assertSee('$ deploy.me sign in', false)
        ->assertSee('Sign in with a passkey');
});

it('switches theme, mode and density on Settings → Appearance', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Settings::class, ['tab' => 'appearance'])
        ->assertSee('Release stream')
        ->call('appearance', 'theme', 'classic')
        ->assertRedirect(route('settings', ['tab' => 'appearance']));

    expect(Ui::preferences($user->fresh())['theme'])->toBe('classic');

    Livewire::actingAs($user->fresh())->test(Settings::class, ['tab' => 'appearance'])
        ->call('appearance', 'theme', 'nonsense')
        ->assertSet('problem', 'That isn’t one of the choices.');
});

it('leaves no theme’s views behind when the next request is in another theme', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertSee('tmux-status', false);

    Ui::save($user, ['theme' => 'classic']);
    $this->actingAs($user->fresh())->get('/')->assertSee('data-ui="classic"', false)->assertDontSee('tmux-status', false);
});
