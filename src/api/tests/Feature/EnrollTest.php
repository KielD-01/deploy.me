<?php

use App\Auth\EnrollmentLinks;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

it('lets only one of two requests that arrive at once use a link', function () {
    // A store where a second request reads the link while the first one has
    // read it but not yet removed it.
    $store = new class extends ArrayStore
    {
        public ?Closure $meanwhile = null;

        public function get($key)
        {
            $value = parent::get($key);

            if ($this->meanwhile !== null && str_starts_with((string) $key, 'deployme:enroll:')) {
                [$then, $this->meanwhile] = [$this->meanwhile, null];
                $then();
            }

            return $value;
        }
    };
    Cache::extend('racy', fn () => Cache::repository($store));
    config(['cache.stores.racy' => ['driver' => 'racy'], 'cache.default' => 'racy']);

    $links = app(EnrollmentLinks::class);
    $token = basename(parse_url($links->create(User::factory()->create()), PHP_URL_PATH));
    $second = null;
    $store->meanwhile = function () use ($links, $token, &$second) {
        $second = $links->consume($token);
    };

    $first = $links->consume($token);

    expect(array_filter([$first, $second]))->toHaveCount(1);
});

it('lets only one of two requests use a link on the database store too, whose forget() always says yes', function () {
    // The slim flavour's cache. DatabaseStore::forget() answers true whether or
    // not there was a row to delete, so it can't tell the two requests apart.
    $store = new class(DB::connection(), 'cache') extends DatabaseStore
    {
        public ?Closure $meanwhile = null;

        public function get($key)
        {
            $value = parent::get($key);

            if ($this->meanwhile !== null && str_starts_with((string) $key, 'deployme:enroll:')) {
                [$then, $this->meanwhile] = [$this->meanwhile, null];
                $then();
            }

            return $value;
        }
    };
    Cache::extend('racy-database', fn () => Cache::repository($store));
    config(['cache.stores.racy-database' => ['driver' => 'racy-database'], 'cache.default' => 'racy-database']);

    expect(Cache::forget('deployme:enroll:never-there'))->toBeTrue();

    $links = app(EnrollmentLinks::class);
    $token = basename(parse_url($links->create(User::factory()->create()), PHP_URL_PATH));
    $second = null;
    $store->meanwhile = function () use ($links, $token, &$second) {
        $second = $links->consume($token);
    };

    $first = $links->consume($token);

    expect(array_filter([$first, $second]))->toHaveCount(1)
        ->and($links->consume($token))->toBeNull();
});
