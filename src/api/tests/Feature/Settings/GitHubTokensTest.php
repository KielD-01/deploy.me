<?php

use App\Livewire\Settings\Index;
use App\Models\Credential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    $this->token = 'ghp_'.str_repeat('a', 32).'3f9A';
});

function fakeGitHubAccount(): void
{
    Http::fake([
        'api.github.com/user/repos*' => Http::response([
            ['full_name' => 'KielD-01/example.dev', 'owner' => ['login' => 'KielD-01']],
            ['full_name' => 'MR-Studio-KZ-UA/mr-erp', 'owner' => ['login' => 'MR-Studio-KZ-UA']],
            ['full_name' => 'Acme-Corp/shop-prod', 'owner' => ['login' => 'MR-Studio-KZ-UA']],
        ]),
        'api.github.com/user' => Http::response(['login' => 'KielD-01', 'id' => 1], 200, [
            'X-OAuth-Scopes' => 'repo, read:packages',
            'github-authentication-token-expiration' => '2026-12-12 00:00:00 UTC',
        ]),
    ]);
}

it('asks for a passkey before a token can be added', function () {
    Livewire::test(Index::class)
        ->call('startAddingToken')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('settings', ['tab' => 'github', 'adding' => 1]));
});

it('checks a token with GitHub and stores it encrypted', function () {
    fakeGitHubAccount();
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(Index::class)
        ->call('startAddingToken')
        ->assertSet('adding', true)
        ->set('token', $this->token)
        ->call('addToken')
        ->assertSet('token', '')
        ->assertSet('adding', false)
        ->assertSee('Saved KielD-01’s token. It reaches 2 owners.')
        ->assertSee('ghp_••••3f9A')
        ->assertSee('Also MR-Studio-KZ-UA')
        ->assertDontSee($this->token);

    $credential = Credential::query()->sole();

    expect($credential->owners)->toBe(['KielD-01', 'MR-Studio-KZ-UA'])
        ->and($credential->scopes)->toBe(['repo', 'read:packages'])
        ->and($credential->token_type)->toBe('classic')
        ->and($credential->expires_at->toDateString())->toBe('2026-12-12')
        ->and($credential->token)->toBe($this->token)
        ->and(DB::table('credentials')->value('token'))->not->toContain($this->token);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', "Bearer {$this->token}"));
});

it('refuses a token GitHub rejects', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(Index::class)
        ->set('adding', true)
        ->set('token', $this->token)
        ->call('addToken')
        ->assertSet('token', '')
        ->assertSee('GitHub rejected the token');

    expect(Credential::query()->count())->toBe(0);
});

it('refuses something that isn’t a token without asking GitHub', function () {
    Http::fake();
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(Index::class)
        ->set('adding', true)
        ->set('token', 'hunter2')
        ->call('addToken')
        ->assertSee('doesn’t look like a GitHub token');

    Http::assertNothingSent();
});

it('removes a token after a passkey confirmation', function () {
    $credential = Credential::factory()->create();
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(Index::class)
        ->call('removeToken', $credential->id)
        ->assertSee('Removed KielD-01’s token.');

    expect(Credential::query()->count())->toBe(0);
});

it('opens on the passkeys after a one-time sign-in link', function () {
    $this->get('/settings?enroll=1')->assertOk()->assertSee('Register a passkey now');
});
