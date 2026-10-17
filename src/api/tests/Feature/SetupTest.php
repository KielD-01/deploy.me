<?php

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\User;

beforeEach(fn () => $this->withoutVite());

it('offers to create the account while there is none', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Create your account')
        ->assertDontSee('Sign in with password');

    $this->get('/setup')->assertOk()->assertSee('Next you’ll choose a passkey or a password');
});

it('creates the one account, signs it in, and goes on to choosing a passkey or password', function () {
    $this->post('/setup', ['name' => 'Roman', 'email' => 'Roman@Example.com'])
        ->assertRedirect(route('settings', ['enroll' => 1]));

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);

    expect($user->email)->toBe('roman@example.com')
        ->and($user->hasPassword())->toBeFalse()
        ->and(session('auth.password_confirmed_at'))->not->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::AccountCreated)->sole()->detail)->toBe('in the browser');

    $this->get(route('settings', ['enroll' => 1]))->assertOk()->assertSee('set a password below');
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
