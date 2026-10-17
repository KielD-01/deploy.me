<?php

namespace App\Platform\GitHub;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class GitHubException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self('GitHub didn’t answer. Check the network and try again.');
    }

    public static function from(Response $response): self
    {
        $status = $response->status();
        $detail = trim((string) ($response->json('message') ?? ''));
        $errors = collect($response->json('errors') ?? [])
            ->map(fn ($error) => is_array($error) ? ($error['message'] ?? null) : $error)
            ->filter()
            ->implode('; ');

        $message = match (true) {
            $status === 401 => 'GitHub rejected the token. It may have expired or been revoked.',
            $status === 403 && $response->header('X-RateLimit-Remaining') === '0' => 'GitHub’s rate limit is used up until '
                .now()->setTimestamp((int) $response->header('X-RateLimit-Reset'))->forViewer()->format('H:i').'.',
            $status === 403 => 'The token isn’t allowed to do this'.($detail !== '' ? ": {$detail}" : '.'),
            $status === 404 => 'Not found on GitHub, or the token can’t see it.',
            $status === 422 => 'GitHub refused it: '.($errors ?: $detail ?: 'invalid request').'.',
            default => "GitHub answered {$status}".($detail !== '' ? ": {$detail}" : '.'),
        };

        return new self($message, $status);
    }
}
