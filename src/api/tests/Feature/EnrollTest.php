<?php

use App\Auth\EnrollmentLinks;
use App\Models\User;

beforeEach(fn () => $this->withoutVite());

it('creates the user and prints a one-time link', function () {
    $this->artisan('deployme:enroll', ['--name' => 'Admin', '--email' => 'admin@example.com'])
        ->expectsOutputToContain('/enroll/')
        ->assertSuccessful();

    expect(User::query()->sole()->email)->toBe('admin@example.com');
});

it('reuses the existing user', function () {
    User::factory()->create();

    $this->artisan('deployme:enroll', ['--email' => 'second@example.com'])->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});

it('signs in once and counts as a fresh confirmation', function () {
    $user = User::factory()->create();
    $url = app(EnrollmentLinks::class)->create($user);

    $this->get($url)->assertRedirect(route('settings', ['enroll' => 1]));
    $this->assertAuthenticatedAs($user);
    expect(session('auth.password_confirmed_at'))->not->toBeNull();

    auth()->logout();

    $this->get($url)->assertRedirect(route('login'));
    $this->assertGuest();
});

it('refuses links it never issued', function () {
    $this->get('/enroll/'.str_repeat('a', 48))->assertRedirect(route('login'));

    $this->assertGuest();
});
