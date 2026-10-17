<?php

namespace App\Platform\Detection;

use App\Enums\DeliveryStrategyKey;
use App\Support\Names;

/**
 * What an import found in a project (stored in projects.detection) and the
 * deploy settings that follow from it. Every key is optional, so a detection
 * stored by an older version still reads.
 */
final class Detection
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public static function from(?array $data): self
    {
        return new self($data ?? []);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Local repository facts, or null when the folder isn't a git repository.
     *
     * @return array{branch: ?string, commits: int, dirty: int, upstream: ?string, ahead: ?int, behind: ?int, head: ?array, branches: list<string>}|null
     */
    public function git(): ?array
    {
        return $this->data['git'] ?? null;
    }

    /**
     * @return array{owner: string, repo: string, remote: ?string, transport: string, private?: bool, default_branch?: string}|null
     */
    public function github(): ?array
    {
        return $this->data['github'] ?? null;
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    public function remotes(): array
    {
        return $this->data['remotes'] ?? [];
    }

    public function workflows(): array
    {
        return $this->data['workflows'] ?? [];
    }

    /**
     * Compose files, the most deploy-like first.
     */
    public function composeFiles(): array
    {
        return $this->data['compose'] ?? [];
    }

    public function primaryCompose(): ?array
    {
        return $this->composeFiles()[0] ?? null;
    }

    public function dockerfiles(): array
    {
        return $this->data['dockerfiles'] ?? [];
    }

    /**
     * @return array{name: string, version: ?string, path: string, extras: list<string>}|null
     */
    public function framework(): ?array
    {
        return $this->data['framework']['primary'] ?? null;
    }

    public function apps(): array
    {
        return $this->data['framework']['apps'] ?? [];
    }

    public function envTemplates(): array
    {
        return $this->data['env_templates'] ?? [];
    }

    public function deployScripts(): array
    {
        return $this->data['deploy_scripts'] ?? [];
    }

    public function truncated(): bool
    {
        return (bool) ($this->data['truncated'] ?? false);
    }

    public function frameworkLabel(): ?string
    {
        $framework = $this->framework();

        return $framework ? trim($framework['name'].' '.($framework['version'] ?? '')) : null;
    }

    /**
     * The first workflow that pushes commit-tagged images to ghcr.io.
     *
     * @return array{workflow: string, images: list<array{image: string, tag: string, count: int}>, count: int, tag: string, branches: ?list<string>}|null
     */
    public function imagePublishing(): ?array
    {
        foreach ($this->workflows() as $workflow) {
            $images = $workflow['images'] ?? [];

            if ($images !== []) {
                return [
                    'workflow' => $workflow['file'],
                    'images' => $images,
                    'count' => array_sum(array_map(fn (array $image) => (int) ($image['count'] ?? 1), $images)),
                    'tag' => $images[0]['tag'],
                    'branches' => $workflow['triggers']['push'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Workflows that deploy to a server themselves: they would race deploy.me.
     *
     * @return list<array{via: string, script: ?string, path: ?string, workflow: string, name: string, branches: ?list<string>, automatic: bool}>
     */
    public function ciDeploys(): array
    {
        $deploys = [];

        foreach ($this->workflows() as $workflow) {
            if (! empty($workflow['deploys'])) {
                $deploys[] = $workflow['deploys'] + [
                    'workflow' => $workflow['file'],
                    'name' => $workflow['name'] ?? $workflow['file'],
                    'branches' => $workflow['triggers']['push'] ?? null,
                    'automatic' => ($workflow['triggers']['push'] ?? null) !== null,
                ];
            }
        }

        return $deploys;
    }

    public function suggestedStackName(string $fallback): string
    {
        $name = $this->primaryCompose()['name'] ?? null;

        return Names::stack(is_string($name) && $name !== '' ? $name : $fallback);
    }

    /**
     * @return list<string>
     */
    public function composeServices(): array
    {
        return $this->primaryCompose()['services'] ?? [];
    }

    /**
     * Services built from this project or running its image: the ones a
     * deploy recreates.
     *
     * @return list<string>
     */
    public function suggestedAppServices(): array
    {
        return $this->primaryCompose()['app_services'] ?? [];
    }

    public function suggestedMigrateService(): ?string
    {
        if (($this->framework()['name'] ?? null) !== 'Laravel') {
            return null;
        }

        // A dedicated one-off service (warehouse-migrate), usually behind a profile so
        // `up` leaves it alone, and so not among the app services: look at them all.
        foreach ($this->composeServices() as $service) {
            if (preg_match('/migrat/', $service)) {
                return $service;
            }
        }

        $services = $this->suggestedAppServices();

        // `compose run` can't start a service with a fixed container_name
        // while it runs: one without, when there is one.
        $free = array_values(array_diff($services, $this->primaryCompose()['fixed_names'] ?? []));
        $services = $free !== [] ? $free : $services;

        // Then the app itself.
        foreach ($services as $service) {
            if (preg_match('/(^|[-_])(api|app|php|laravel|backend)([-_]|$)/', $service)) {
                return $service;
            }
        }

        return $services[0] ?? null;
    }

    /**
     * The repository's own deploy script: the one a CI deploy job runs, or
     * the first deploy*.sh found.
     */
    public function suggestedActivationScript(): ?string
    {
        foreach ($this->ciDeploys() as $deploy) {
            if (($deploy['script'] ?? null) && in_array($deploy['script'], $this->deployScripts(), true)) {
                return $deploy['script'];
            }
        }

        return $this->deployScripts()[0] ?? null;
    }

    /**
     * The default priority list: the image CI publishes, then an archive of
     * the commit, then the folder as it is (local folders only). Server git
     * is listed for GitHub projects but starts switched off.
     *
     * @return list<array{key: string, enabled: bool}>
     */
    public function suggestedStrategies(bool $localFolder): array
    {
        $git = ! $localFolder || $this->git() !== null;
        $list = [];

        if ($this->imagePublishing() !== null && $this->primaryCompose() !== null) {
            $list[] = ['key' => DeliveryStrategyKey::GhcrImage->value, 'enabled' => true];
        }
        if ($git) {
            $list[] = ['key' => DeliveryStrategyKey::PushArchive->value, 'enabled' => true];
        }
        if ($localFolder) {
            $list[] = ['key' => DeliveryStrategyKey::PushRsync->value, 'enabled' => ! $git];
        }
        if ($this->github() !== null) {
            $list[] = ['key' => DeliveryStrategyKey::ServerGit->value, 'enabled' => false];
        }

        foreach (DeliveryStrategyKey::cases() as $key) {
            if (! in_array($key->value, array_column($list, 'key'), true)) {
                $list[] = ['key' => $key->value, 'enabled' => false];
            }
        }

        return $list;
    }

    /**
     * Whether a strategy can work for this project, and why, in one sentence.
     *
     * @return array{ok: bool, text: string}
     */
    public function strategyNote(DeliveryStrategyKey $key, bool $localFolder): array
    {
        $git = ! $localFolder || $this->git() !== null;
        $publishing = $this->imagePublishing();

        return match ($key) {
            DeliveryStrategyKey::GhcrImage => $publishing !== null
                ? ['ok' => true, 'text' => sprintf(
                    '%s pushes %d %s tagged %s%s%s.',
                    $publishing['workflow'],
                    $publishing['count'],
                    $publishing['count'] === 1 ? 'image' : 'images',
                    $publishing['tag'],
                    $publishing['branches'] ? ' on '.implode(', ', $publishing['branches']) : '',
                    $this->github() === null ? ', once the repository is on GitHub' : '',
                )]
                : ['ok' => false, 'text' => 'No workflow pushes an image to ghcr.io.'],
            DeliveryStrategyKey::PushArchive => $git
                ? ['ok' => true, 'text' => 'A git archive of the commit is uploaded'.($this->primaryCompose() ? ', then docker compose build runs on the server.' : '.')]
                : ['ok' => false, 'text' => 'Needs a git repository.'],
            DeliveryStrategyKey::PushRsync => $localFolder
                ? ['ok' => true, 'text' => 'The folder is uploaded as it is on disk, uncommitted changes included.']
                : ['ok' => false, 'text' => 'Only for projects imported from a local folder.'],
            DeliveryStrategyKey::ServerGit => $this->github() !== null
                ? ['ok' => true, 'text' => 'The server fetches from GitHub itself. Needs a deploy key on each server.']
                : ['ok' => false, 'text' => 'Needs a GitHub repository.'],
        };
    }
}
