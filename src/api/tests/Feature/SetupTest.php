<?php

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    // Off by default; the tests below that want the page switch it on.
    config(['deployme.web_setup' => true]);
});

it('is off unless DEPLOYME_WEB_SETUP=true', function () {
    // The default as shipped: nothing in the test environment sets the variable.
    expect(getenv('DEPLOYME_WEB_SETUP'))->toBeFalse();

    $config = require config_path('deployme.php');
    config(['deployme.web_setup' => $config['web_setup']]);

    expect(config('deployme.web_setup'))->toBeFalse();
    $this->get('/login')->assertOk()->assertDontSee('Create your account');
    $this->get('/setup')->assertRedirect(route('login'));
    $this->post('/setup', ['name' => 'Roman', 'email' => 'roman@example.com'])->assertRedirect(route('login'));

    expect(User::query()->exists())->toBeFalse();
});

it('offers to create the account while there is none', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Create your account')
        ->assertDontSee('Sign in with password');

    $this->get('/setup')->assertOk()->assertSee('Next you’ll choose a passkey or a password');
});

it('creates the one account and hands it an enrollment link, like make enroll does', function () {
    $response = $this->post('/setup', ['name' => 'Roman', 'email' => 'Roman@Example.com']);

    $user = User::query()->sole();
    expect($user->email)->toBe('roman@example.com')
        ->and($user->hasPassword())->toBeFalse()
        ->and(AuditEntry::query()->where('action', AuditAction::AccountCreated)->sole()->detail)->toBe('in the browser');

    // The setup request itself is neither signed in nor confirmed.
    $this->assertGuest();
    expect(session('auth.password_confirmed_at'))->toBeNull();

    $location = $response->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith(url('/enroll/'));

    // Following it is what signs in and confirms, then goes on to choosing a passkey or password.
    $this->get($location)->assertRedirect(route('settings', ['enroll' => 1]));
    $this->assertAuthenticatedAs($user);
    expect(session('auth.password_confirmed_at'))->not->toBeNull();

    $this->get(route('settings', ['enroll' => 1]))->assertOk()->assertSee('set a password below');

    // And it works once, like any other.
    auth()->logout();
    $this->get($location)->assertRedirect(route('login'));
});

it('is gone once an account exists, and never makes a second one', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->get('/login')->assertOk()->assertSee('Sign in with a passkey')->assertDontSee('Create your account');
    $this->get('/setup')->assertRedirect(route('login'));

    $this->post('/setup', ['name' => 'Someone', 'email' => 'someone@example.com'])->assertRedirect(route('login'));

    expect(User::query()->pluck('email')->all())->toBe(['owner@example.com']);
    $this->assertGuest();
});

it('checks again under its lock, so the second of two racing forms loses', function () {
    // The page was opened while there was no account; by the time it's sent, there is.
    $this->get('/setup')->assertOk();
    User::factory()->create(['email' => 'first@example.com']);

    $this->post('/setup', ['name' => 'Second', 'email' => 'second@example.com'])->assertRedirect(route('login'));
    expect(User::query()->count())->toBe(1);
});

it('can be switched off, leaving make enroll as the only way to create the account', function () {
    config(['deployme.web_setup' => false]);

    $this->get('/login')->assertOk()->assertDontSee('Create your account')->assertSee('make enroll');
    $this->get('/setup')->assertRedirect(route('login'));
    $this->post('/setup', ['name' => 'Roman', 'email' => 'roman@example.com'])->assertRedirect(route('login'));

    expect(User::query()->exists())->toBeFalse();
});

it('wants a name and an email', function () {
    $this->post('/setup', ['name' => '', 'email' => 'not-an-email'])->assertSessionHasErrors(['name', 'email']);
    expect(User::query()->exists())->toBeFalse();
});

it('records an account make enroll creates, too', function () {
    $this->artisan('deployme:enroll', ['--name' => 'Admin', '--email' => 'admin@example.com'])->assertSuccessful();

    expect(AuditEntry::query()->where('action', AuditAction::AccountCreated)->sole()->detail)->toBe('by make enroll');
});
