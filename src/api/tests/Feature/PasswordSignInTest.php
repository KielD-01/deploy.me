<?php

use App\Enums\AuditAction;
use App\Livewire\Settings\Index as Settings;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(fn () => $this->withoutVite());

function passwordUser(array $attributes = []): User
{
    return User::factory()->create(['email' => 'me@deployme.localhost', 'password' => 'correct horse battery', 'password_set_at' => now(), ...$attributes]);
}

function addPasskey(User $user, string $name = 'MacBook'): void
{
    DB::table('passkeys')->insert(['user_id' => $user->id, 'name' => $name, 'credential_id' => 'credential-'.$name, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
}

it('signs in with a password its owner chose', function () {
    $user = passwordUser();

    $this->post('/login', ['email' => 'Me@deployme.localhost', 'password' => 'correct horse battery'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('refuses a wrong password, and slows down after five', function () {
    passwordUser();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'me@deployme.localhost', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'me@deployme.localhost', 'password' => 'correct horse battery'])->assertStatus(429);
    $this->assertGuest();
});

it('asks for the authenticator code after the password when it is on', function () {
    $user = passwordUser();
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->post('/login', ['email' => 'me@deployme.localhost', 'password' => 'correct horse battery'])
        ->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertOk()->assertSee('Authenticator code');

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('confirms sensitive actions with the password too', function () {
    $user = passwordUser();

    $this->actingAs($user)->get('/user/confirm-password')
        ->assertOk()
        ->assertSee('Confirm with password')
        ->assertDontSee('Confirm with a passkey');

    $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->actingAs($user)->post('/user/confirm-password', ['password' => 'correct horse battery'])->assertSessionHasNoErrors();
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('sets a password from the enrollment link, which counts as the confirmation', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['enroll' => 1])
        ->test(Settings::class)
        ->assertSee('set a password below')
        ->call('savePassword') // nothing typed yet: it isn't confirmed, so it asks
        ->assertRedirect(route('password.confirm'));

    session()->passwordConfirmed();

    Livewire::actingAs($user)
        ->test(Settings::class, ['tab' => 'account'])
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('savePassword')
        ->assertSet('problem', 'Use at least 12 characters.')
        ->set('password', 'a long enough password')
        ->set('passwordConfirmation', 'a long enough password')
        ->call('savePassword')
        ->assertSet('password', '')
        ->assertSet('notice', 'Password set. You can sign in with it on any device.');

    expect($user->fresh()->hasPassword())->toBeTrue()
        ->and(AuditEntry::query()->where('action', AuditAction::PasswordSet)->exists())->toBeTrue();

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'a long enough password'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('keeps the last way in: no removing the only password or the only passkey', function () {
    $user = passwordUser();
    session()->passwordConfirmed();

    Livewire::actingAs($user)->test(Settings::class, ['tab' => 'account'])
        ->call('removePassword')
        ->assertSet('problem', 'Your password is your only way in. Add a passkey first.');
    expect($user->fresh()->hasPassword())->toBeTrue();

    $passkeyOnly = User::factory()->create();
    addPasskey($passkeyOnly);
    $passkey = $passkeyOnly->passkeys()->first();

    $this->actingAs($passkeyOnly)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('passkey.destroy', $passkey))
        ->assertStatus(422)
        ->assertJsonValidationErrors('passkey');
    expect($passkeyOnly->passkeys()->count())->toBe(1);
});

it('removes the password, and its authenticator codes with it, once a passkey is there', function () {
    $user = passwordUser();
    addPasskey($user);
    app(EnableTwoFactorAuthentication::class)($user);
    session()->passwordConfirmed();

    Livewire::actingAs($user)->test(Settings::class, ['tab' => 'account'])
        ->call('removePassword')
        ->assertSet('notice', 'Password removed. You sign in with a passkey only.');

    $user->refresh();
    expect($user->hasPassword())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull();
});

it('turns authenticator codes on with a code from the app, and shows the recovery codes once', function () {
    $user = passwordUser();
    session()->passwordConfirmed();

    $page = Livewire::actingAs($user)->test(Settings::class, ['tab' => 'account'])
        ->call('enableTwoFactor')
        ->assertSee('Scan this with');

    $page->set('twoFactorCode', '000000')->call('confirmTwoFactor')
        ->assertSet('problem', 'That code didn’t match. Check the app’s clock, and use the newest code.');

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    $page->set('twoFactorCode', $code)->call('confirmTwoFactor')
        ->assertSee('Recovery codes');

    expect($user->fresh()->hasTwoFactor())->toBeTrue()
        ->and($page->get('recoveryCodes'))->toHaveCount(8);
});

it('offers authenticator codes only with a password', function () {
    Livewire::actingAs(User::factory()->create())->test(Settings::class, ['tab' => 'account'])
        ->assertSee('Set password')
        ->assertDontSee('Authenticator codes');
});
