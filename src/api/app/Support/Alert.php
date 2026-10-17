<?php

namespace App\Support;

use App\Enums\DeploymentStatus;
use App\Enums\TargetHealth;
use App\Models\Deployment;
use App\Models\Target;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telegram messages for the two things worth interrupting someone over: a run
 * that failed and a site that stopped answering. Nothing is sent until a bot
 * token and a chat are set in Settings → Alerts, and a message that can't be
 * sent never fails the deploy or the check it reports on.
 */
final class Alert
{
    // A cancelled run is someone at the keyboard already; the rest are news.
    private const WORTH_SAYING = [DeploymentStatus::Failed, DeploymentStatus::RolledBack, DeploymentStatus::Stalled];

    public static function configured(): bool
    {
        return Settings::string('telegram_bot_token') !== '' && Settings::string('telegram_chat_id') !== '';
    }

    public static function deploymentFinished(Deployment $deployment): void
    {
        if (! in_array($deployment->status, self::WORTH_SAYING, true)) {
            return;
        }

        $deployment->loadMissing(['project', 'server']);

        self::send(implode("\n", array_filter([
            sprintf('✗ %s: %s', $deployment->project?->name ?? 'A deploy', Str::lower($deployment->status->label())),
            $deployment->server?->name !== null ? 'on '.$deployment->server->name : null,
            Str::limit((string) $deployment->outcome, 300),
            url("/deployments/{$deployment->id}"),
        ])));
    }

    public static function healthChanged(Target $target, TargetHealth $health): void
    {
        if ($health === TargetHealth::Unknown) {
            return;
        }

        $target->loadMissing(['project', 'server']);
        $name = $target->project?->name ?? $target->path;

        self::send($health === TargetHealth::Unhealthy
            ? sprintf("✗ %s stopped answering on %s\n%s", $name, $target->server?->name ?? 'its server', Str::limit((string) $target->health_detail, 300))
            : sprintf('✓ %s is answering again', $name));
    }

    /**
     * True when Telegram took it. Everything else is logged and swallowed:
     * an alert is never the reason a deploy fails.
     */
    public static function send(string $text): bool
    {
        $token = Settings::string('telegram_bot_token');
        $chat = Settings::string('telegram_chat_id');

        if ($token === '' || $chat === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);

            if ($response->failed()) {
                // The token is in the URL, so the response is all that's logged.
                Log::warning('Telegram refused an alert', ['status' => $response->status(), 'body' => Str::limit($response->body(), 200)]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            // Guzzle ends a connection error's message with "for <url>", and the
            // token is in that URL: redact it from whatever is logged.
            Log::warning('Could not send an alert', [
                'exception' => $e::class,
                'error' => str_replace([$token, rawurlencode($token)], '[token]', $e->getMessage()),
            ]);

            return false;
        }
    }
}
