<?php

namespace App\Platform\GitHub;

use App\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks ghcr.io whether an image tag exists, with a pull token for the
 * repository exchanged from the GitHub token (which needs read:packages).
 */
final class GhcrClient
{
    private const ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, '
        .'application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    public function __construct(
        private readonly string $username,
        private readonly string $token,
    ) {}

    public static function for(Credential $credential): self
    {
        return new self($credential->login, $credential->token);
    }

    /**
     * True when the tag is there, false when it isn't, null when the
     * registry wouldn't say (no access, or unreachable).
     */
    public function exists(string $image, string $tag): ?bool
    {
        $repository = strtolower((string) preg_replace('#^ghcr\.io/#i', '', $image));
        $registry = rtrim((string) config('deployme.github.registry', 'https://ghcr.io'), '/');

        try {
            $token = Http::withBasicAuth($this->username, $this->token)
                ->timeout(15)
                ->get("{$registry}/token", ['scope' => "repository:{$repository}:pull", 'service' => 'ghcr.io'])
                ->json('token');

            if (! is_string($token) || $token === '') {
                return null;
            }

            $response = Http::withToken($token)
                ->timeout(15)
                ->accept(self::ACCEPT)
                ->head("{$registry}/v2/{$repository}/manifests/".rawurlencode($tag));
        } catch (ConnectionException) {
            return null;
        }

        return match (true) {
            $response->successful() => true,
            $response->status() === 404 => false,
            default => null,
        };
    }
}
