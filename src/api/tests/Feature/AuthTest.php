<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->withoutVite());

it('sends guests to the sign-in page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('offers a passkey first and a password beside it', function () {
    User::factory()->create();

    $this->get('/login')
        ->assertOk()
        ->assertSeeInOrder(['Sign in with a passkey', 'Sign in with password'])
        ->assertSee('type="password"', false);
});

it('refuses a password nobody chose, the random one an account starts with', function () {
    $user = User::factory()->create(['password' => 'a-password-nobody-should-use']);

    $this->post('/login', ['email' => $user->email, 'password' => 'a-password-nobody-should-use'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

it('serves passkey sign-in options', function () {
    $this->getJson('/passkeys/login/options')
        ->assertOk()
        ->assertJsonStructure(['options' => ['challenge']]);
});

it('asks for a passkey to confirm sensitive actions', function () {
    $user = User::factory()->create();
    DB::table('passkeys')->insert(['user_id' => $user->id, 'name' => 'MacBook', 'credential_id' => 'credential-1', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($user)
        ->get('/user/confirm-password')
        ->assertOk()
        ->assertSee('Confirm with a passkey');
});

it('explains how to get a passkey when there is none to confirm with', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user/confirm-password')
        ->assertOk()
        ->assertSee('make enroll')
        ->assertDontSee('Confirm with a passkey');
});

it('renders every page for a signed-in user', function (string $uri, string $heading) {
    $this->actingAs(User::factory()->create())
        ->get($uri)
        ->assertOk()
        ->assertSee($heading);
})->with([
    ['/', 'Dashboard'],
    ['/servers', 'Servers'],
    ['/projects', 'Projects'],
    ['/deployments', 'Deployments'],
    ['/settings', 'GitHub tokens'],
    ['/settings?tab=ssh', 'known_hosts'],
    ['/settings?tab=account', 'Passkeys'],
]);

it('has no registration or email password reset: make enroll is the way back in', function () {
    $this->get('/register')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
    // The code prompt only follows a correct password.
    $this->get('/two-factor-challenge')->assertRedirect('/login');
});
