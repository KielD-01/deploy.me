<?php

use App\Enums\DeploymentStatus;
use App\Enums\TargetHealth;
use App\Models\Deployment;
use App\Models\Setting;
use App\Support\Alert;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;

// A stub registered first wins, so each test says what Telegram answers
// rather than sharing one answer here.
beforeEach(function () {
    Http::preventStrayRequests();
});

function alertsConfigured(string $answer = 'ok'): void
{
    Http::fake(['api.telegram.org/*' => $answer === 'ok'
        ? Http::response(['ok' => true])
        : Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

    Settings::setString('telegram_bot_token', '123456:test-token');
    Settings::setString('telegram_chat_id', '4242');
}

it('says nothing until a bot and a chat are set', function () {
    Alert::healthChanged(demoTarget(), TargetHealth::Unhealthy);

    expect(Alert::configured())->toBeFalse();
    Http::assertNothingSent();
});

it('keeps the token encrypted in the row it stores', function () {
    alertsConfigured();

    expect(Settings::string('telegram_bot_token'))->toBe('123456:test-token')
        ->and((string) Setting::query()->find('telegram_bot_token')?->value)->not->toContain('test-token');
});

it('reports a failed run and stays quiet about a good one', function () {
    alertsConfigured();
    $target = demoTarget();
    $failed = Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Failed, 'outcome' => 'The migrations failed.']);
    $fine = Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Succeeded, 'outcome' => 'Release 20260912-101010 is live.']);

    Alert::deploymentFinished($failed);
    Alert::deploymentFinished($fine);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123456:test-token/sendMessage')
        && $request['chat_id'] === '4242'
        && str_contains($request['text'], 'The migrations failed.')
        && str_contains($request['text'], "/deployments/{$failed->id}"));
});

it('says when a site stops answering, and when it answers again', function () {
    alertsConfigured();
    $target = demoTarget();
    $target->update(['health_detail' => 'https://demo.test/up gave 502']);

    Alert::healthChanged($target->refresh(), TargetHealth::Unhealthy);
    Alert::healthChanged($target, TargetHealth::Healthy);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['text'], 'stopped answering') && str_contains($request['text'], '502'));
    Http::assertSent(fn ($request) => str_contains($request['text'], 'answering again'));
});

it('swallows a refusal rather than failing what it reports on', function () {
    alertsConfigured(answer: 'refused');

    expect(Alert::send('anything'))->toBeFalse();
});

it('asks for a passkey before alerts go to another chat, or stop', function () {
    alertsConfigured();
    $this->actingAs(App\Models\User::factory()->create());

    // Whoever sets the chat reads every failure the runs report.
    Livewire\Livewire::test(App\Livewire\Settings\Index::class)
        ->set('telegramChatId', '9999')
        ->call('saveAlerts')
        ->assertRedirect(route('password.confirm'));
    Livewire\Livewire::test(App\Livewire\Settings\Index::class)
        ->call('forgetAlerts')
        ->assertRedirect(route('password.confirm'));

    expect(Settings::string('telegram_chat_id'))->toBe('4242')
        ->and(Settings::string('telegram_bot_token'))->toBe('123456:test-token');

    session(['auth.password_confirmed_at' => time()]);
    Livewire\Livewire::test(App\Livewire\Settings\Index::class)
        ->set('telegramChatId', '9999')
        ->call('saveAlerts')
        ->assertHasNoErrors();

    expect(Settings::string('telegram_chat_id'))->toBe('9999');
});
