<?php

namespace App\Platform\GitHub;

use App\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The GitHub REST calls deploy.me makes, authenticated with one token. The
 * token only ever travels in the Authorization header.
 */
final class GitHubClient
{
    public function __construct(private readonly string $token) {}

    public static function for(Credential $credential): self
    {
        return new self($credential->token);
    }

    /**
     * The token's account, with its scopes and expiry from the response headers.
     *
     * @return array{login: string, id: int, name: ?string, scopes: list<string>, expires_at: ?string}
     */
    public function whoami(): array
    {
        $response = $this->send('get', '/user');

        return [
            'login' => (string) $response->json('login'),
            'id' => (int) $response->json('id'),
            'name' => $response->json('name'),
            'scopes' => array_values(array_filter(array_map('trim', explode(',', $response->header('X-OAuth-Scopes'))))),
            'expires_at' => $response->header('github-authentication-token-expiration') ?: null,
        ];
    }

    /**
     * Every repository the token can see: its account's, its organizations'
     * and those it collaborates on, most recently updated first.
     *
     * @return list<array<string, mixed>>
     */
    public function repositories(int $maxPages = 10): array
    {
        $repositories = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->send('get', '/user/repos', [
                'per_page' => 100,
                'page' => $page,
                'sort' => 'updated',
                'affiliation' => 'owner,collaborator,organization_member',
            ]);
            $chunk = $response->json() ?? [];
            array_push($repositories, ...$chunk);

            if (count($chunk) < 100 || ! str_contains($response->header('Link'), 'rel="next"')) {
                break;
            }
        }

        return $repositories;
    }

    /**
     * @return array<string, mixed>
     */
    public function repository(string $owner, string $repo): array
    {
        return $this->send('get', "/repos/{$owner}/{$repo}")->json();
    }

    public function isOrganization(string $owner): bool
    {
        return $this->send('get', "/users/{$owner}")->json('type') === 'Organization';
    }

    /**
     * How many commits a branch has, and its newest one. The count comes from
     * the last page number of a one-per-page listing.
     *
     * @return array{count: int, head: ?array{sha: string, subject: string, date: ?string}}
     */
    public function commits(string $owner, string $repo, string $ref): array
    {
        // 409: the repository is empty.
        $response = $this->send('get', "/repos/{$owner}/{$repo}/commits", ['sha' => $ref, 'per_page' => 1], allow: [409]);

        if ($response->status() === 409) {
            return ['count' => 0, 'head' => null];
        }

        $first = $response->json('0');
        $count = preg_match('/[?&]page=(\d+)[^>]*>;\s*rel="last"/', $response->header('Link'), $match)
            ? (int) $match[1]
            : count($response->json() ?? []);

        return [
            'count' => $count,
            'head' => is_array($first) ? [
                'sha' => (string) $first['sha'],
                'subject' => (string) strtok((string) ($first['commit']['message'] ?? ''), "\n"),
                'date' => $first['commit']['committer']['date'] ?? null,
            ] : null,
        ];
    }

    /**
     * Every path at a ref. GitHub truncates very large trees.
     *
     * @return array{tree: list<array{path: string, type: string}>, truncated: bool}
     */
    public function tree(string $owner, string $repo, string $ref): array
    {
        $response = $this->send('get', "/repos/{$owner}/{$repo}/git/trees/".rawurlencode($ref), ['recursive' => 1], allow: [404, 409]);

        if (! $response->successful()) {
            return ['tree' => [], 'truncated' => false];
        }

        return ['tree' => $response->json('tree') ?? [], 'truncated' => (bool) $response->json('truncated')];
    }

    public function raw(string $owner, string $repo, string $path, string $ref): ?string
    {
        return $this->rawMany($owner, $repo, [$path], $ref)[$path] ?? null;
    }

    /**
     * Several files' raw contents at once; null for any that doesn't exist.
     *
     * @param  list<string>  $paths
     * @return array<string, ?string>
     */
    public function rawMany(string $owner, string $repo, array $paths, string $ref): array
    {
        if ($paths === []) {
            return [];
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $path) => $this->configure($pool->as($path))
                    ->withHeaders(['Accept' => 'application/vnd.github.raw'])
                    ->get($this->url("/repos/{$owner}/{$repo}/contents/".self::encodePath($path)), ['ref' => $ref]),
                $paths,
            ));
        } catch (ConnectionException) {
            throw GitHubException::unreachable();
        }

        $files = [];
        foreach ($paths as $path) {
            $response = $responses[$path] ?? null;
            $files[$path] = $response instanceof Response && $response->successful() ? $response->body() : null;
        }

        return $files;
    }

    /**
     * The newest workflow runs on a branch. With an ETag, an unchanged answer
     * is a 304 that doesn't count against the rate limit.
     *
     * @return array{changed: bool, etag: ?string, runs: list<array<string, mixed>>}
     */
    public function workflowRuns(string $owner, string $repo, string $branch, ?string $etag = null): array
    {
        $response = $this->send(
            'get',
            "/repos/{$owner}/{$repo}/actions/runs",
            ['branch' => $branch, 'per_page' => 20, 'exclude_pull_requests' => 'true'],
            headers: $etag !== null ? ['If-None-Match' => $etag] : [],
            allow: [304],
        );

        if ($response->status() === 304) {
            return ['changed' => false, 'etag' => $etag, 'runs' => []];
        }

        return ['changed' => true, 'etag' => $response->header('ETag') ?: null, 'runs' => $response->json('workflow_runs') ?? []];
    }

    /**
     * The commit a branch points at right now, or null when there's no such
     * branch. The runs list can't answer this: GitHub has served it with old
     * runs first, and empty.
     */
    public function branchHead(string $owner, string $repo, string $branch): ?string
    {
        $response = $this->send('get', "/repos/{$owner}/{$repo}/git/ref/heads/".self::encodePath($branch), allow: [404]);

        $sha = $response->status() === 404 ? null : $response->json('object.sha');

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /**
     * Where $head stands against $base: ahead, behind, identical or diverged.
     * Null when GitHub doesn't know one of the two commits.
     */
    public function compare(string $owner, string $repo, string $base, string $head): ?string
    {
        $response = $this->send('get', "/repos/{$owner}/{$repo}/compare/{$base}...{$head}", ['per_page' => 1], allow: [404]);

        $status = $response->status() === 404 ? null : $response->json('status');

        return is_string($status) ? $status : null;
    }

    /**
     * The workflow runs for one commit, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function runsForCommit(string $owner, string $repo, string $sha): array
    {
        return $this->send('get', "/repos/{$owner}/{$repo}/actions/runs", ['head_sha' => $sha, 'per_page' => 20, 'exclude_pull_requests' => 'true'])
            ->json('workflow_runs') ?? [];
    }

    /**
     * The jobs of one workflow run, in order.
     *
     * @return list<array{name: string, status: ?string, conclusion: ?string, started_at: ?string, completed_at: ?string}>
     */
    public function runJobs(string $owner, string $repo, int $runId): array
    {
        $jobs = $this->send('get', "/repos/{$owner}/{$repo}/actions/runs/{$runId}/jobs", ['per_page' => 50])->json('jobs') ?? [];

        return array_values(array_map(fn (array $job) => [
            'name' => (string) ($job['name'] ?? ''),
            'status' => $job['status'] ?? null,
            'conclusion' => $job['conclusion'] ?? null,
            'started_at' => $job['started_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
        ], $jobs));
    }

    /**
     * An empty repository under the token's own account or one of its organizations.
     *
     * @return array<string, mixed>
     */
    public function createRepository(string $owner, string $name, bool $private, bool $organization): array
    {
        return $this->send('post', $organization ? "/orgs/{$owner}/repos" : '/user/repos', [
            'name' => $name,
            'private' => $private,
            'auto_init' => false,
        ])->json();
    }

    /**
     * @param  array<string, mixed>  $data  query for GET, JSON body otherwise
     * @param  array<string, string>  $headers
     * @param  list<int>  $allow  non-2xx statuses the caller handles itself
     */
    private function send(string $method, string $path, array $data = [], array $headers = [], array $allow = []): Response
    {
        try {
            $response = $this->configure(Http::withHeaders($headers))->{$method}($this->url($path), $data);
        } catch (ConnectionException) {
            throw GitHubException::unreachable();
        }

        if (! $response->successful() && ! in_array($response->status(), $allow, true)) {
            throw GitHubException::from($response);
        }

        return $response;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withToken($this->token)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'deploy.me'])
            ->accept('application/vnd.github+json')
            ->timeout((int) config('deployme.github.timeout', 20))
            ->connectTimeout(10);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('deployme.github.api'), '/').$path;
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
