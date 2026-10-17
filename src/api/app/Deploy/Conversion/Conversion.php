<?php

namespace App\Deploy\Conversion;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\StepFailed;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * A conversion as its run carries it (options.conversion): what moves where,
 * what stays, the first release's commit, whether the stack restarts, and the
 * project's shared paths once converted (and before, for undo).
 */
final readonly class Conversion
{
    public const RELEASE = 'release';

    public const SHARED = 'shared';

    public const STAYS = 'stays';

    /**
     * @param  list<array{path: string, type: string, to: string}>  $items  what moves; a path with a slash is inside an item the release takes
     * @param  list<string>  $stays  what's left where it is
     * @param  list<string>  $sharedPaths  the project's shared paths once converted
     * @param  list<string>|null  $sharedPathsBefore  the setting before (null: the default)
     */
    public function __construct(
        public array $items,
        public array $stays = [],
        public ?string $commit = null,
        public ?string $message = null,
        public bool $restart = false,
        public array $sharedPaths = [],
        public ?array $sharedPathsBefore = null,
    ) {}

    /**
     * @throws StepFailed
     */
    public static function of(Deployment $deployment): self
    {
        $data = $deployment->options['conversion'] ?? null;

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new StepFailed('This run has no plan of what moves where.');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws StepFailed
     */
    public static function fromArray(array $data): self
    {
        $items = [];

        foreach ((array) ($data['items'] ?? []) as $item) {
            $path = is_array($item) ? (string) ($item['path'] ?? '') : '';
            $type = is_array($item) ? (string) ($item['type'] ?? '') : '';
            $to = is_array($item) ? (string) ($item['to'] ?? '') : '';

            if (! self::safePath($path) || ! in_array($type, ['d', 'f', 'l'], true) || ! in_array($to, [self::RELEASE, self::SHARED], true)
                || (str_contains($path, '/') && $to !== self::SHARED)) {
                throw new StepFailed('The plan has an item deploy.me can’t move: '.Str::limit($path, 80).'.');
            }

            $items[] = ['path' => $path, 'type' => $type, 'to' => $to];
        }

        if ($items === []) {
            throw new StepFailed('The plan moves nothing.');
        }

        $commit = preg_match('/^[0-9a-f]{40}$/', (string) ($data['commit'] ?? '')) === 1 ? (string) $data['commit'] : null;

        return new self(
            $items,
            array_values(array_filter(array_map('strval', (array) ($data['stays'] ?? [])), fn (string $path) => self::safePath($path) && ! str_contains($path, '/'))),
            $commit,
            $commit !== null && is_string($data['message'] ?? null) ? Str::limit($data['message'], 250) : null,
            (bool) ($data['restart'] ?? false),
            Project::normalizePaths((array) ($data['shared_paths'] ?? [])),
            is_array($data['shared_paths_before'] ?? null) ? Project::normalizePaths($data['shared_paths_before']) : null,
        );
    }

    /**
     * A path inside the folder: not absolute, no "." or "..", nothing that
     * would break a line of the script's input.
     */
    public static function safePath(string $path): bool
    {
        $segments = explode('/', $path);

        return $path !== ''
            && preg_match('/[\t\n\r\0]/', $path) !== 1
            && ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'stays' => $this->stays,
            'commit' => $this->commit,
            'message' => $this->message,
            'restart' => $this->restart,
            'shared_paths' => $this->sharedPaths,
            'shared_paths_before' => $this->sharedPathsBefore,
        ];
    }

    /**
     * The folder's own items that go straight into the release.
     */
    public function toRelease(): int
    {
        return count(array_filter($this->items, fn (array $item) => $item['to'] === self::RELEASE));
    }

    /**
     * Everything that ends up in shared/, from the folder or from inside the release.
     *
     * @return list<string>
     */
    public function shared(): array
    {
        return array_values(array_map(fn (array $item) => $item['path'], array_filter($this->items, fn (array $item) => $item['to'] === self::SHARED)));
    }

    /**
     * What deploy-convert.sh needs for one of its modes.
     *
     * @return array<string, string>
     */
    public function vars(DeployContext $context, string $release, string $mode): array
    {
        return [
            'TARGET' => $context->layout->root,
            'RELEASE' => $release,
            'MODE' => $mode,
            'ITEMS' => implode("\n", array_map(fn (array $item) => "{$item['to']}\t{$item['type']}\t{$item['path']}", $this->items)),
            'STAYS' => implode("\n", $this->stays),
            'STACK' => (string) $context->project->stack_name,
            'FILES' => implode("\n", Compose::files($context->project)),
        ];
    }

    /**
     * The script's input for a mode that needs no plan (undo, drop-backup).
     *
     * @return array<string, string>
     */
    public static function bare(string $root, string $release, string $mode): array
    {
        return ['TARGET' => $root, 'RELEASE' => $release, 'MODE' => $mode, 'ITEMS' => '', 'STAYS' => '', 'STACK' => '', 'FILES' => ''];
    }
}
